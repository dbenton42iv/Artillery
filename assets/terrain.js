/*
 * Artillery 3D: terrain operations shared by the browser and the replay test.
 * Every function here has a twin in includes/engine.php (class Terrain) and must stay identical to it:
 * same loops, same arithmetic order, values stored as float32. That is what lets each browser keep an
 * exact copy of the server's heightmap by replaying the "ops" list recorded in every event.
 *
 * Op formats (as recorded by the server):
 *   ['c', x, z, R]            crater
 *   ['r', x, z, R, h, p]      raise a mound (p = 2) or cone (p = 3)
 *   ['f', rate, cap, minH, [[x, z, r], ...]]   snowfall: fill hollows, skipping circles around tanks
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.ArtTerrain = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  const CELL = 1.5;

  function make(n, H) {
    const w = n + 1;
    return { n, w, half: n * CELL / 2, H: H || new Float32Array(w * w) };
  }

  function heightAt(T, x, z) {
    const fx = (x + T.half) / CELL, fz = (z + T.half) / CELL, n = T.n, w = T.w, H = T.H;
    if (fx < 0 || fz < 0 || fx > n || fz > n) return -50;
    const i = Math.min(n - 1, Math.floor(fx)), j = Math.min(n - 1, Math.floor(fz)), u = fx - i, v = fz - j;
    return H[j*w+i]*(1-u)*(1-v) + H[j*w+i+1]*u*(1-v) + H[(j+1)*w+i]*(1-u)*v + H[(j+1)*w+i+1]*u*v;
  }

  /** Index range [i0, i1, j0, j1] covering a circle (one cell of margin). */
  function box(T, cx, cz, R) {
    const n = T.n, hf = T.half;
    return [Math.max(0, Math.floor((cx - R + hf) / CELL) - 1), Math.min(n, Math.ceil((cx + R + hf) / CELL) + 1),
            Math.max(0, Math.floor((cz - R + hf) / CELL) - 1), Math.min(n, Math.ceil((cz + R + hf) / CELL) + 1)];
  }

  function crater(T, cx, cz, R, depth) {
    if (depth === undefined) depth = .55;
    const H = T.H, w = T.w, R2 = R * R, b = box(T, cx, cz, R);
    for (let j = b[2]; j <= b[3]; j++) for (let i = b[0]; i <= b[1]; i++) {
      const dx = -T.half + i * CELL - cx, dz = -T.half + j * CELL - cz, d2 = dx * dx + dz * dz;
      if (d2 < R2) { const k = j * w + i; H[k] = Math.max(.3, H[k] - Math.sqrt(R2 - d2) * depth); }
    }
  }

  function raise(T, cx, cz, R, h, p) {
    const H = T.H, w = T.w, R2 = R * R, b = box(T, cx, cz, R);
    for (let j = b[2]; j <= b[3]; j++) for (let i = b[0]; i <= b[1]; i++) {
      const dx = -T.half + i * CELL - cx, dz = -T.half + j * CELL - cz, d2 = dx * dx + dz * dz;
      if (d2 < R2) {
        const t = 1 - d2 / R2; let a = h * t * t; if (p === 3) a *= t;
        const k = j * w + i; H[k] = H[k] + a;
      }
    }
  }

  function fill(T, rate, cap, minH, skips) {
    const H = T.H, O = H.slice(), w = T.w, n = T.n;
    for (let j = 1; j < n; j++) for (let i = 1; i < n; i++) {
      const k = j * w + i, h = O[k];
      if (h <= minH) continue;
      const x = -T.half + i * CELL, z = -T.half + j * CELL;
      let skip = false;
      for (const s of skips) { const dx = x - s[0], dz = z - s[1]; if (dx * dx + dz * dz < s[2] * s[2]) { skip = true; break; } }
      if (skip) continue;
      const avg = (O[k - 1] + O[k + 1] + O[k - w] + O[k + w]) * .25;
      if (avg > h) { let d = (avg - h) * rate; if (d > cap) d = cap; H[k] = h + d; }
    }
  }

  function apply(T, op) {
    switch (op[0]) {
      case 'c': crater(T, op[1], op[2], op[3]); break;
      case 'r': raise(T, op[1], op[2], op[3], op[4], op[5]); break;
      case 'f': fill(T, op[1], op[2], op[3], op[4]); break;
    }
  }
  function applyAll(T, ops) { if (ops) for (const op of ops) apply(T, op); }

  /*
   * Driving: twin of drive_path() in includes/engine.php, used to preview a hop before sending it.
   * blk = { c: [[x, z, r], ...] circles, b: [[x, z, halfWidth, halfDepth], ...] boxes }; water = sea level (-100 if none).
   * Returns { path: [[x, y, z], ...] every metre, x, z, used (metres along the ground), stop }.
   */
  const DRIVE = { STEP: .25, UP: 1.0, DOWN: 1.2, SAMPLE: 4, EDGE: 6.0, SHORE: .6, TANK_R: 4.5, CRATE_R: 2.6, BUILDING_PAD: 1.8, MIN: .5 };
  function drive(T, water, blk, x0, z0, tx, tz, budget) {
    const dx = tx - x0, dz = tz - z0, L = Math.sqrt(dx * dx + dz * dz);
    let x = x0, z = z0, h = heightAt(T, x, z), used = 0, k = 0, stop = 'arrived';
    const path = [[x, h, z]];
    if (L < 0.01) return { path, x, z, used: 0, stop };
    const ux = dx / L, uz = dz / L, lim = T.half - DRIVE.EDGE, shore = water + DRIVE.SHORE;
    outer: while (true) {
      const done = k * DRIVE.STEP;
      if (done >= L - 1e-9) break;
      const s = Math.min(DRIVE.STEP, L - done);
      const nx = x + ux * s, nz = z + uz * s;
      if (Math.abs(nx) > lim || Math.abs(nz) > lim) { stop = 'edge'; break; }
      const nh = heightAt(T, nx, nz);
      if (nh < shore) { stop = 'water'; break; }
      const g = (nh - h) / s;
      if (g > DRIVE.UP) { stop = 'steep'; break; }
      if (g < -DRIVE.DOWN) { stop = 'cliff'; break; }
      const seg = Math.sqrt(s * s + (nh - h) * (nh - h));
      if (used + seg > budget + 1e-9) { stop = 'fuel'; break; }
      for (const c of blk.c) {
        const a = nx - c[0], b = nz - c[1], d2 = a * a + b * b;
        if (d2 < c[2] * c[2]) { const a0 = x - c[0], b0 = z - c[1]; if (d2 < a0 * a0 + b0 * b0) { stop = 'blocked'; break outer; } }
      }
      for (const c of blk.b) if (Math.abs(nx - c[0]) < c[2] && Math.abs(nz - c[1]) < c[3]) { stop = 'blocked'; break outer; }
      x = nx; z = nz; h = nh; used += seg; k++;
      if (k % DRIVE.SAMPLE === 0) path.push([x, h, z]);
    }
    const last = path[path.length - 1];
    if (last[0] !== x || last[2] !== z) path.push([x, h, z]);
    return { path, x, z, used, stop };
  }

  return { CELL, make, heightAt, crater, raise, fill, apply, applyAll, DRIVE, drive };
});
