/*
 * Replay check (phase 1 gate, extended for phase 2): "every map's replays match the server".
 * Reads matches simulated by tests/sim_matches.php and replays every event exactly as assets/game.js does:
 * terrain ops through assets/terrain.js, hits (after shields), fire damage, falls, building damage,
 * crate drops, claims and wrecks, and power-up use (repair, relocate). The browser's final heightmap
 * must equal the server's bit for bit; tank hp, position and held power-up, building hp, and each
 * crate's state and resting height must all agree.
 *   php tests/sim_matches.php > /tmp/sims.json && node tests/replay_parity.js /tmp/sims.json
 */
'use strict';
const fs = require('fs');
const AT = require('../assets/terrain.js');
const sims = JSON.parse(fs.readFileSync(process.argv[2] || '/tmp/sims.json', 'utf8'));
const f32 = b64 => { const b = Buffer.from(b64, 'base64'); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };

let regens = 0, failed = 0, drives = 0, driveBad = 0, allDrives = 0, allBad = 0;
for (const m of sims) {
  const H0 = f32(m.initial), n = Math.round(Math.sqrt(H0.length)) - 1;
  if (n !== m.rules.n) throw new Error('size mismatch');
  const T = AT.make(n, H0), WATER = m.rules.water === null ? -100 : m.rules.water;
  const P = m.spawns.map((s, i) => ({ hp: m.hp0 ? m.hp0[i] : 100, x: s[0], y: s[1], z: s[2], alive: true, item: null }));   // phase 4: 125 hp tanks and the like
  const O = m.objects.map(o => ({ ...o }));
  let ops = 0;
  const hurt = (seat, dmg) => { P[seat].hp = Math.max(0, P[seat].hp - dmg); };
  const boom = b => {
    if (!b) return;
    for (const h of b.hits) hurt(h.seat, h.dmg);
    for (const r of b.obj || []) O[r.id].hp = Math.max(0, O[r.id].hp - r.dmg);
    for (const c of b.crates || []) { O[c.id].state = c.by === null ? 'destroyed' : 'claimed'; if (c.by !== null) P[c.by].item = c.item; }
  };
  const restCrates = () => { for (const o of O) if (o.type === 'crate' && o.state === 'live') o.y = Math.max(AT.heightAt(T, o.x, o.z), WATER); };
  for (const e of m.events) {
    const d = e.data;
    if (e.type === 'move') {             // the browser's preview (AT.drive) must plan exactly what the server drove
      const q = d.req, r = AT.drive(T, WATER, q.blk, q.x0, q.z0, q.tx, q.tz, q.budget);
      drives++;
      if (r.x !== q.raw.x || r.z !== q.raw.z || r.used !== q.raw.used || r.stop !== q.raw.stop || r.path.length !== q.raw.n) driveBad++;
      [P[e.seat].x, P[e.seat].y, P[e.seat].z] = d.to;
      continue;
    }
    if (e.type === 'item') {
      P[e.seat].item = null;
      if (d.heal) P[e.seat].hp += d.heal;
      if (d.to) { [P[e.seat].x, P[e.seat].y, P[e.seat].z] = d.to; }
      continue;
    }
    if (e.type === 'shot' && d.aim && !['shell', 'heavy', 'cluster'].includes(d.aim.weapon)) P[e.seat].item = null;
    AT.applyAll(T, d.ops); ops += d.ops.length;
    for (const pr of d.bombs || []) boom(pr.boom);
    for (const b of d.burn || []) hurt(b.seat, b.dmg);
    for (const pr of d.projectiles || []) boom(pr.boom);
    if (d.sudden) for (const h of d.sudden.drain) hurt(h.seat, h.dmg);
    for (const f of d.falls) { P[f.seat].y = f.y; hurt(f.seat, f.dmg); }
    if (d.regen) { P[d.regen.seat].hp += d.regen.heal; regens++; }
    restCrates();
    for (const c of d.crates || []) O[c.id] = { ...c };
    for (const s of d.deaths) P[s].alive = false;
  }
  const Hs = f32(m.final);
  let diff = 0, maxd = 0;
  for (let k = 0; k < Hs.length; k++) if (Hs[k] !== T.H[k]) { diff++; maxd = Math.max(maxd, Math.abs(Hs[k] - T.H[k])); }
  const pBad = m.players_final.filter((p, i) => p.hp !== P[i].hp || Math.abs(p.y - P[i].y) > 1e-3 || Math.abs(p.x - P[i].x) > 1e-3
    || Math.abs(p.z - P[i].z) > 1e-3 || p.alive !== P[i].alive || p.item !== P[i].item).length;
  const oBad = m.objects_final.filter((o, i) => !O[i] || (o.type === 'building' ? o.hp !== O[i].hp
    : o.state !== O[i].state || (o.state === 'live' && Math.abs(o.y - O[i].y) > 1e-9))).length + Math.abs(m.objects_final.length - O.length);
  const crates = m.objects_final.filter(o => o.type === 'crate').length;
  const ok = diff === 0 && pBad === 0 && oBad === 0 && driveBad === 0;
  allDrives += drives; allBad += driveBad;
  if (!ok) failed++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${m.map.padEnd(12)} ${m.players_n}p ${m.game_mode.padEnd(6)} ${String(m.events.length).padStart(3)} events ${String(ops).padStart(3)} ops ` +
              `${String(crates).padStart(2)} crates  cells differing: ${diff}${diff ? ` (max ${maxd})` : ''}  tanks off: ${pBad}  objects off: ${oBad}  drives: ${drives}${driveBad ? ` (${driveBad} PLANNED DIFFERENTLY)` : ''}`);
  drives = 0; driveBad = 0;
}
console.log(`Heal-over-time events replayed: ${regens}`);
console.log(`Drives: ${allDrives}, browser preview planned differently from the server: ${allBad}`);
console.log(failed ? `\n${failed} match(es) FAILED` : `\nAll ${sims.length} matches replay identically.`);
process.exit(failed ? 1 : 0);
