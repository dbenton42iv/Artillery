/*
 * Phase 1 gate: "every map's replays match the server".
 * Reads matches simulated by tests/sim_matches.php and replays every event exactly as assets/game.js
 * does (terrain ops through assets/terrain.js, hits, falls, building damage). The browser's final
 * heightmap must equal the server's bit for bit, and tank hp/height and building hp must agree.
 *   php tests/sim_matches.php > /tmp/sims.json && node tests/replay_parity.js /tmp/sims.json
 */
'use strict';
const fs = require('fs');
const AT = require('../assets/terrain.js');
const sims = JSON.parse(fs.readFileSync(process.argv[2] || '/tmp/sims.json', 'utf8'));
const f32 = b64 => { const b = Buffer.from(b64, 'base64'); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };

let failed = 0;
for (const m of sims) {
  const H0 = f32(m.initial), n = Math.round(Math.sqrt(H0.length)) - 1;
  if (n !== m.rules.n) throw new Error('size mismatch');
  const T = AT.make(n, H0);
  const P = m.spawns.map(s => ({ hp: 100, y: s[1], alive: true }));
  const O = m.objects.map(o => ({ ...o }));
  let ops = 0;
  const boom = b => { if (!b) return; for (const h of b.hits) P[h.seat].hp = Math.max(0, P[h.seat].hp - h.dmg); for (const r of b.obj || []) O[r.id].hp = Math.max(0, O[r.id].hp - r.dmg); };
  for (const e of m.events) {
    const d = e.data;
    AT.applyAll(T, d.ops); ops += d.ops.length;
    for (const pr of d.projectiles || []) boom(pr.boom);
    for (const pr of d.bombs || []) boom(pr.boom);
    if (d.sudden) for (const h of d.sudden.drain) P[h.seat].hp = Math.max(0, P[h.seat].hp - h.dmg);
    for (const f of d.falls) { P[f.seat].y = f.y; P[f.seat].hp = Math.max(0, P[f.seat].hp - f.dmg); }
    for (const s of d.deaths) P[s].alive = false;
  }
  const Hs = f32(m.final);
  let diff = 0, maxd = 0;
  for (let k = 0; k < Hs.length; k++) if (Hs[k] !== T.H[k]) { diff++; maxd = Math.max(maxd, Math.abs(Hs[k] - T.H[k])); }
  const pBad = m.players_final.filter((p, i) => p.hp !== P[i].hp || Math.abs(p.y - P[i].y) > 1e-3 || p.alive !== P[i].alive).length;
  const oBad = m.objects_final.filter((o, i) => o.hp !== O[i].hp).length;
  const ok = diff === 0 && pBad === 0 && oBad === 0;
  if (!ok) failed++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${m.map.padEnd(12)} ${m.players_n}p  ${String(m.events.length).padStart(3)} events  ${String(ops).padStart(3)} terrain ops  ` +
              `cells differing: ${diff}${diff ? ` (max ${maxd})` : ''}  tanks off: ${pBad}  buildings off: ${oBad}`);
}
console.log(failed ? `\n${failed} map(s) FAILED` : `\nAll ${sims.length} matches replay identically.`);
process.exit(failed ? 1 : 0);
