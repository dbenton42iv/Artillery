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

  return { CELL, make, heightAt, crater, raise, fill, apply, applyAll };
});
