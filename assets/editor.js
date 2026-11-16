/*
 * Mission editor (phase 5). Admins build co-op missions: the map is drawn from above from the exact ground the server
 * will generate (api/editor.php?action=preview), and a click places whatever tool is chosen. Everything is saved as
 * the mission's JSON definition (see mission_clean() in includes/missions.php, which checks it again on save).
 */
(() => {
'use strict';
const $ = s => document.querySelector(s);
const root = $('.ed'), CSRF = root.dataset.csrf;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
async function call(url, body) {
  const opt = body === undefined ? { credentials: 'same-origin' }
    : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) };
  const r = await fetch('api/editor.php?action=' + url, opt);
  if (r.status === 401) { location.href = 'login.php'; throw new Error('logged out'); }
  return r.json();
}
const TOOLS = [
  ['select', '⬚', 'Select', 'Select and drag things. Delete removes the selected one.'],
  ['start', '●', 'Player start', 'Click to place a player start (one per player, in order).'],
  ['tank', '▲', 'Enemy tank', 'Click to place a computer tank. Set the round it arrives in to make waves.'],
  ['gun', '■', 'Gun', 'A dug-in gun: it never drives. Good targets for "Destroy the targets".'],
  ['behemoth', '⬢', 'Behemoth', 'The boss: a giant tank with three weak points (one per mission).'],
  ['target', '◎', 'Target', 'Something to destroy: a bunker, bridge span, radar post or fuel tanks.'],
  ['wall', '▬', 'Wall', 'A wall that stops shells until it is shot down.'],
  ['depot', '⌂', 'Depot', 'The players must keep this standing ("Protect the depot").'],
  ['crate', '▣', 'Crate', 'A supply crate for "Claim the supply crates" (or just a bonus).'],
  ['river', '≈', 'River', 'Click two points: a river channel runs between them.'],
  ['convoy', '⇢', 'Convoy', 'Click points along the route; the mission is lost if the convoy reaches the last one.'],
  ['erase', '✕', 'Erase', 'Click something to remove it.'],
];
const BLANK = () => ({ title: 'New mission', blurb: '', map: 'highlands', seed: 1 + Math.floor(Math.random() * 99999), size: 160, players: [1, 4],
  starts: [], enemies: [], objects: [], rivers: [], convoy: null, goal: { type: 'destroy_all' }, limit: 20, stars: { shots: 12, damage: 60 },
  opts: { night: false, flares: 6, items: 'safe', drops: true, erupt: 0, move: 10, regen: 3, wind: null, turn_seconds: 40 } });
const E = { list: [], meta: null, id: 0, status: 'draft', campaign: false, def: BLANK(), pv: null, tool: 'select', sel: null, drag: null, riverA: null, dirty: false };

/* ---------- the list ---------- */
async function loadList() {
  const r = await call('list'); E.meta = r; E.list = r.missions;
  if (!$('#fMap').options.length) {
    $('#fMap').innerHTML = Object.entries(r.maps).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
    $('#fGoal').innerHTML = Object.entries(r.goals).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
    for (const id of ['fPMin', 'fPMax']) $('#' + id).innerHTML = [1, 2, 3, 4].map(n => `<option>${n}</option>`).join('');
  }
  renderList();
}
function renderList() {
  $('#edList').innerHTML = E.list.map(m => `<button type="button" class="ed-item${m.id === E.id ? ' on' : ''}" data-id="${m.id}">
    <b>${esc(m.title)}</b><small>${m.campaign ? 'Campaign · ' : ''}${m.status === 'published' ? 'Published' : 'Draft'}${m.errors.length ? ` · <span class="bad">${m.errors.length} to fix</span>` : ''}</small></button>`).join('');
}
$('#edList').addEventListener('click', async ev => {
  const b = ev.target.closest('.ed-item'); if (!b) return;
  if (E.dirty && !confirm('Leave this mission without saving?')) return;
  await open(+b.dataset.id);
});
async function open(id) {
  const r = await call('get&id=' + id); if (r.error) return msg('Could not open that mission.');
  E.id = r.mission.id; E.status = r.mission.status; E.campaign = r.mission.campaign; E.def = r.mission.def;
  E.sel = null; E.dirty = false; E.riverA = null; fillForm(); renderList(); preview(true); props(); msg('');
}
$('#edNew').onclick = () => { if (E.dirty && !confirm('Leave this mission without saving?')) return;
  E.id = 0; E.status = 'draft'; E.campaign = false; E.def = BLANK(); E.sel = null; E.dirty = true; fillForm(); renderList(); preview(true); props(); msg('A new mission. Save it to keep it.'); };
$('#edDup').onclick = () => { E.id = 0; E.status = 'draft'; E.campaign = false; E.def = JSON.parse(JSON.stringify(E.def)); E.def.title = (E.def.title + ' (copy)').slice(0, 40);
  E.dirty = true; fillForm(); renderList(); msg('A copy. Save it to keep it.'); };

/* ---------- the form ---------- */
const num = (v, d) => v === '' || v == null || isNaN(+v) ? d : +v;
function fillForm() {
  const d = E.def, o = d.opts;
  $('#fTitle').value = d.title; $('#fBlurb').value = d.blurb || ''; $('#fMap').value = d.map; $('#fSize').value = String(d.size); $('#fSeed').value = d.seed;
  $('#fPMin').value = String(d.players[0]); $('#fPMax').value = String(d.players[1]);
  $('#fGoal').value = d.goal.type; $('#fRounds').value = d.goal.rounds ?? 10; $('#fCount').value = d.goal.count ?? 3; $('#fLimit').value = d.limit;
  $('#fShots').value = d.stars.shots; $('#fDamage').value = d.stars.damage;
  $('#fItems').value = o.items; $('#fDrops').checked = !!o.drops; $('#fNight').checked = !!o.night; $('#fFlares').value = o.flares; $('#fErupt').value = String(o.erupt || 0);
  $('#fMove').value = o.move; $('#fRegen').value = o.regen; $('#fWind').value = o.wind ?? ''; $('#fClock').value = String(o.turn_seconds);
  $('#edStatus').textContent = E.campaign ? 'CAMPAIGN' : E.status === 'published' ? 'PUBLISHED' : 'DRAFT';
  $('#edStatus').className = 'ed-badge ' + E.status;
  $('#edPublish').textContent = E.status === 'published' ? 'Unpublish' : 'Publish';
  $('#edDelete').hidden = E.campaign || !E.id;
  formVisibility();
}
function readForm() {
  const d = E.def, o = d.opts;
  d.title = $('#fTitle').value; d.blurb = $('#fBlurb').value; d.map = $('#fMap').value; d.size = +$('#fSize').value; d.seed = num($('#fSeed').value, 1);
  d.players = [+$('#fPMin').value, Math.max(+$('#fPMin').value, +$('#fPMax').value)];
  d.goal = { type: $('#fGoal').value };
  if (d.goal.type === 'survive' || d.goal.type === 'protect') d.goal.rounds = num($('#fRounds').value, 10);
  if (d.goal.type === 'crates') d.goal.count = num($('#fCount').value, 3);
  d.limit = num($('#fLimit').value, 0); d.stars = { shots: num($('#fShots').value, 12), damage: num($('#fDamage').value, 60) };
  Object.assign(o, { items: $('#fItems').value, drops: $('#fDrops').checked, night: $('#fNight').checked, flares: num($('#fFlares').value, 6), erupt: +$('#fErupt').value,
    move: num($('#fMove').value, 10), regen: num($('#fRegen').value, 3), wind: $('#fWind').value === '' ? null : num($('#fWind').value, null), turn_seconds: +$('#fClock').value });
  formVisibility();
}
function formVisibility() {
  const g = $('#fGoal').value;
  $('#wRounds').hidden = !(g === 'survive' || g === 'protect'); $('#wCount').hidden = g !== 'crates';
  $('#wFlares').hidden = !$('#fNight').checked; $('#wErupt').hidden = $('#fMap').value !== 'volcano';
}
const TERRAIN_FIELDS = ['fMap', 'fSize', 'fSeed'];
document.querySelectorAll('.ed-side input, .ed-side select, #fTitle, #fBlurb').forEach(el => el.addEventListener('change', () => {
  if (el.closest('#edProps')) return;
  readForm(); E.dirty = true; if (TERRAIN_FIELDS.includes(el.id)) preview(true); else preview(false); draw();
}));
$('#fSeedNew').onclick = () => { $('#fSeed').value = 1 + Math.floor(Math.random() * 999999); readForm(); E.dirty = true; preview(true); };

/* ---------- the ground: generated by the server from the map, seed and rivers ---------- */
let pvTimer = null, pvSeq = 0;
function preview(now) {
  clearTimeout(pvTimer);
  pvTimer = setTimeout(async () => {
    const seq = ++pvSeq; $('#edLoading').hidden = false;
    try {
      const r = await call('preview', { def: E.def });
      if (seq !== pvSeq) return;
      const bin = atob(r.terrain), bytes = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      E.pv = { n: r.n, half: r.half, water: r.water, H: new Float32Array(bytes.buffer), ruins: r.objects.filter(o => o.kind === 'ruin'), vent: r.vent };
      showErrors(r.errors); paintGround(); draw();
    } finally { if (seq === pvSeq) $('#edLoading').hidden = true; }
  }, now ? 0 : 700);
}
function showErrors(errs) {
  const el = $('#edErrors'); el.hidden = !errs.length;
  el.innerHTML = errs.length ? '<b>To fix before it can be published or played:</b><ul>' + errs.map(e => `<li>${esc(e)}</li>`).join('') + '</ul>' : '';
}
const cv = $('#edMap'), g2 = cv.getContext('2d'), ground = document.createElement('canvas');
function hAt(x, z) {
  const p = E.pv; if (!p) return 0;
  const n = p.n, fx = (x + p.half) / 1.5, fz = (z + p.half) / 1.5;
  if (fx < 0 || fz < 0 || fx > n || fz > n) return -50;
  const i = Math.min(n - 1, Math.floor(fx)), j = Math.min(n - 1, Math.floor(fz)), u = fx - i, v = fz - j, w = n + 1, H = p.H;
  return H[j*w+i]*(1-u)*(1-v) + H[j*w+i+1]*u*(1-v) + H[(j+1)*w+i]*(1-u)*v + H[(j+1)*w+i+1]*u*v;
}
function paintGround() {
  const p = E.pv, w = p.n + 1; ground.width = ground.height = w;
  const ctx = ground.getContext('2d'), img = ctx.createImageData(w, w), H = p.H;
  let lo = Infinity, hi = -Infinity; for (const v of H) { if (v < lo) lo = v; if (v > hi) hi = v; }
  const water = p.water ?? -100;
  for (let j = 0; j < w; j++) for (let i = 0; i < w; i++) {
    const k = j*w + i, h = H[k], dx = (H[k + (i < w-1 ? 1 : 0)] - H[k - (i > 0 ? 1 : 0)]), dz = (H[k + (j < w-1 ? w : 0)] - H[k - (j > 0 ? w : 0)]);
    let c, sh = Math.max(.4, Math.min(1.25, 1 - (dx - dz) * .25));
    if (h < water) { c = [44, 92, 138]; sh = 1; } else { const t = (h - lo) / Math.max(1, hi - lo); c = [86 + 120*t, 128 + 92*t, 70 + 84*t]; }
    img.data[k*4] = Math.min(255, c[0]*sh); img.data[k*4+1] = Math.min(255, c[1]*sh); img.data[k*4+2] = Math.min(255, c[2]*sh); img.data[k*4+3] = 255;
  }
  ctx.putImageData(img, 0, 0);
}

/* ---------- drawing ---------- */
const K = () => cv.width / (E.pv ? E.pv.n * 1.5 : 240);
const half = () => E.pv ? E.pv.half : E.def.size * .75;
const toC = (x, z) => [(x + half()) * K(), (z + half()) * K()];
const toW = (cx, cy) => [cx / K() - half(), cy / K() - half()];
function items() {                                    // everything that can be clicked: [kind, index, x, z, radius in metres]
  const d = E.def, out = [];
  d.starts.forEach((p, i) => out.push(['start', i, p[0], p[1], 4]));
  d.enemies.forEach((e, i) => out.push(['enemy', i, e.x, e.z, e.kind === 'behemoth' ? 7 : 4]));
  d.objects.forEach((o, i) => out.push(['object', i, o.x, o.z, Math.max(3, (o.w || 2) / 2, (o.d || 2) / 2)]));
  d.rivers.forEach((r, i) => { out.push(['river', i, r.a[0], r.a[1], 4, 'a']); out.push(['river', i, r.b[0], r.b[1], 4, 'b']); });
  (d.convoy ? d.convoy.path : []).forEach((q, i) => out.push(['convoy', i, q[0], q[1], 4]));
  return out;
}
function draw() {
  const W = cv.width; g2.clearRect(0, 0, W, W);
  if (E.pv) { g2.imageSmoothingEnabled = true; g2.drawImage(ground, 0, 0, W, W); }
  else { g2.fillStyle = '#20251c'; g2.fillRect(0, 0, W, W); }
  const k = K(), hf = half();
  g2.strokeStyle = 'rgba(255,255,255,.12)'; g2.lineWidth = 1; g2.font = '10px system-ui'; g2.fillStyle = 'rgba(255,255,255,.55)';
  for (let m = -Math.floor(hf / 20) * 20; m <= hf; m += 20) { const [a] = toC(m, 0); g2.beginPath(); g2.moveTo(a, 0); g2.lineTo(a, W); g2.moveTo(0, a); g2.lineTo(W, a); g2.stroke(); }
  const d = E.def, sel = E.sel;
  const isSel = (kind, i) => sel && sel.kind === kind && sel.i === i;
  for (const o of E.pv ? E.pv.ruins : []) { const [x, y] = toC(o.x - o.hw, o.z - o.hd); g2.fillStyle = 'rgba(150,150,150,.75)'; g2.fillRect(x, y, o.hw*2*k, o.hd*2*k); }
  if (E.pv && E.pv.vent) { const [x, y] = toC(E.pv.vent[0], E.pv.vent[1]); g2.fillStyle = '#ff6a1a'; g2.beginPath(); g2.arc(x, y, 5, 0, 7); g2.fill(); }
  d.rivers.forEach((r, i) => { const [a, b] = toC(...r.a), [c, e] = toC(...r.b);
    g2.strokeStyle = isSel('river', i) ? '#fff' : 'rgba(120,200,255,.9)'; g2.lineWidth = Math.max(2, r.w * k * .35); g2.beginPath(); g2.moveTo(a, b); g2.lineTo(c, e); g2.stroke();
    for (const [x, y] of [[a, b], [c, e]]) { g2.fillStyle = '#78c8ff'; g2.beginPath(); g2.arc(x, y, 4, 0, 7); g2.fill(); } });
  if (E.riverA) { const [x, y] = toC(...E.riverA); g2.fillStyle = '#78c8ff'; g2.beginPath(); g2.arc(x, y, 6, 0, 7); g2.fill(); }
  if (d.convoy) { const pts = d.convoy.path.map(q => toC(...q));
    g2.strokeStyle = isSel('convoy', 0) || (sel && sel.kind === 'convoy') ? '#fff' : '#ff8c00'; g2.lineWidth = 3; g2.setLineDash([8, 6]); g2.beginPath(); pts.forEach(([x, y], i) => i ? g2.lineTo(x, y) : g2.moveTo(x, y)); g2.stroke(); g2.setLineDash([]);
    pts.forEach(([x, y], i) => { g2.fillStyle = i === pts.length - 1 ? '#ff4f2a' : '#ff8c00'; g2.beginPath(); g2.arc(x, y, i === pts.length - 1 ? 7 : 4, 0, 7); g2.fill(); }); }
  d.objects.forEach((o, i) => {
    const [x, y] = toC(o.x, o.z);
    if (o.type === 'crate') { g2.fillStyle = '#f0b429'; g2.fillRect(x - 5, y - 5, 10, 10); }
    else { const col = { target: '#ff4f6d', wall: '#d0ccc0', depot: '#3ddc84' }[o.type]; g2.fillStyle = col; g2.globalAlpha = .85;
      g2.fillRect(x - o.w/2*k, y - o.d/2*k, o.w*k, o.d*k); g2.globalAlpha = 1; }
    if (isSel('object', i)) { g2.strokeStyle = '#fff'; g2.lineWidth = 2; g2.strokeRect(x - 9, y - 9, 18, 18); }
  });
  d.starts.forEach((p, i) => { const [x, y] = toC(...p); dot(x, y, 8, '#3e8ef7', isSel('start', i)); label(x, y, 'P' + (i + 1)); });
  d.enemies.forEach((e, i) => { const [x, y] = toC(e.x, e.z);
    dot(x, y, e.kind === 'behemoth' ? 13 : 8, e.goal ? '#ff4f6d' : '#c0392b', isSel('enemy', i), e.kind === 'gun' ? 'sq' : 'c');
    label(x, y, (e.kind === 'behemoth' ? 'B' : e.kind === 'gun' ? 'G' : 'E') + (i + 1) + (e.round > 1 ? ' r' + e.round : '') + (e.min > 1 ? ' ' + e.min + 'p+' : '')); });
}
function dot(x, y, r, col, on, shape = 'c') {
  g2.fillStyle = col; g2.strokeStyle = on ? '#fff' : 'rgba(0,0,0,.6)'; g2.lineWidth = on ? 3 : 1.5;
  g2.beginPath(); if (shape === 'sq') g2.rect(x - r, y - r, r*2, r*2); else g2.arc(x, y, r, 0, 7); g2.fill(); g2.stroke();
}
function label(x, y, t) { g2.font = 'bold 12px system-ui'; g2.lineWidth = 3; g2.strokeStyle = 'rgba(0,0,0,.7)'; g2.strokeText(t, x + 10, y - 8); g2.fillStyle = '#fff'; g2.fillText(t, x + 10, y - 8); }

/* ---------- tools ---------- */
$('#edTools').innerHTML = TOOLS.map(([k, ic, name, tip]) => `<button type="button" class="ed-tool${k === 'select' ? ' on' : ''}" data-t="${k}" title="${esc(tip)}"><i>${ic}</i>${esc(name)}</button>`).join('');
$('#edTools').addEventListener('click', ev => { const b = ev.target.closest('.ed-tool'); if (!b) return; setTool(b.dataset.t); });
function setTool(t) {
  E.tool = t; E.riverA = null;
  document.querySelectorAll('.ed-tool').forEach(b => b.classList.toggle('on', b.dataset.t === t));
  $('#edHelp').textContent = TOOLS.find(x => x[0] === t)[3]; draw();
}
function evPos(ev) { const r = cv.getBoundingClientRect(); return [(ev.clientX - r.left) * cv.width / r.width, (ev.clientY - r.top) * cv.height / r.height]; }
function hit(cx, cy) {
  let best = null;
  for (const it of items()) { const [x, y] = toC(it[2], it[3]), dd = Math.hypot(x - cx, y - cy); if (dd < Math.max(12, it[4] * K()) && (!best || dd < best.dd)) best = { kind: it[0], i: it[1], part: it[5], dd }; }
  return best;
}
const R2 = v => Math.round(v * 2) / 2;
cv.addEventListener('pointerdown', ev => {
  ev.preventDefault();
  const [cx, cy] = evPos(ev), [wx, wz] = toW(cx, cy).map(R2), d = E.def, t = E.tool;
  if (Math.abs(wx) > half() - 8 || Math.abs(wz) > half() - 8) return msg('Too close to the edge of the map.');
  const add = (kind, arr, v) => { arr.push(v); E.sel = { kind, i: arr.length - 1 }; changed(true); };
  if (t === 'select' || t === 'erase') {
    const h = hit(cx, cy);
    if (t === 'erase') { if (h) { E.sel = h; removeSel(); } return; }
    E.sel = h; props(); draw();
    if (h) { E.drag = { id: ev.pointerId, moved: false }; cv.setPointerCapture(ev.pointerId); }
    return;
  }
  if (t === 'start') { if (d.starts.length >= 4) return msg('Four starts at most (one per player).'); add('start', d.starts, [wx, wz]); }
  else if (t === 'tank' || t === 'gun' || t === 'behemoth') {
    if (t === 'behemoth' && d.enemies.some(e => e.kind === 'behemoth')) return msg('Only one Behemoth per mission.');
    if (d.enemies.length >= 12) return msg('Twelve enemies at most.');
    add('enemy', d.enemies, { x: wx, z: wz, kind: t, level: 'rookie', hp: t === 'behemoth' ? 600 : t === 'gun' ? 150 : 100, round: 1, min: 1, name: '', goal: t !== 'tank' });
  }
  else if (t === 'crate') add('object', d.objects, { type: 'crate', x: wx, z: wz });
  else if (t === 'target') add('object', d.objects, { type: 'target', style: 'bunker', name: '', x: wx, z: wz, w: 6, d: 6, h: 5, hp: 120 });
  else if (t === 'wall') add('object', d.objects, { type: 'wall', name: '', x: wx, z: wz, w: 12, d: 2.5, h: 6, hp: 150 });
  else if (t === 'depot') add('object', d.objects, { type: 'depot', name: 'Depot', x: wx, z: wz, w: 10, d: 8, h: 6, hp: 200 });
  else if (t === 'river') {
    if (!E.riverA) { E.riverA = [wx, wz]; msg('Now click where the river ends.'); draw(); return; }
    d.rivers.push({ a: E.riverA, b: [wx, wz], w: 12 }); E.riverA = null; E.sel = { kind: 'river', i: d.rivers.length - 1 }; changed(true, true); msg('');
  }
  else if (t === 'convoy') {
    d.convoy = d.convoy || { path: [], speed: 12 };
    if (d.convoy.path.length >= 12) return msg('Twelve route points at most.');
    d.convoy.path.push([wx, wz]); E.sel = { kind: 'convoy', i: d.convoy.path.length - 1 }; changed(true);
    msg(d.convoy.path.length < 2 ? 'Keep clicking along the route. The last point is where it must not get to.' : '');
  }
});
cv.addEventListener('pointermove', ev => {
  const [cx, cy] = evPos(ev), [wx, wz] = toW(cx, cy);
  $('#edHelp').textContent = `x ${wx.toFixed(0)}, z ${wz.toFixed(0)} · ground ${E.pv ? hAt(wx, wz).toFixed(1) + ' m' : '…'}${E.pv && E.pv.water != null && hAt(wx, wz) < E.pv.water ? ' (water)' : ''} — ` + TOOLS.find(x => x[0] === E.tool)[3];
  if (!E.drag || E.drag.id !== ev.pointerId || !E.sel) return;
  const p = [R2(Math.max(-half() + 8, Math.min(half() - 8, wx))), R2(Math.max(-half() + 8, Math.min(half() - 8, wz)))];
  setPos(E.sel, p); E.drag.moved = true; draw();
});
cv.addEventListener('pointerup', ev => { if (E.drag && E.drag.id === ev.pointerId) { if (E.drag.moved) changed(true, E.sel.kind === 'river'); E.drag = null; } });
function setPos(sel, [x, z]) {
  const d = E.def;
  if (sel.kind === 'start') d.starts[sel.i] = [x, z];
  else if (sel.kind === 'enemy') Object.assign(d.enemies[sel.i], { x, z });
  else if (sel.kind === 'object') Object.assign(d.objects[sel.i], { x, z });
  else if (sel.kind === 'river') d.rivers[sel.i][sel.part || 'a'] = [x, z];
  else if (sel.kind === 'convoy') d.convoy.path[sel.i] = [x, z];
}
function removeSel() {
  const s = E.sel, d = E.def; if (!s) return;
  if (s.kind === 'start') d.starts.splice(s.i, 1);
  else if (s.kind === 'enemy') d.enemies.splice(s.i, 1);
  else if (s.kind === 'object') d.objects.splice(s.i, 1);
  else if (s.kind === 'river') d.rivers.splice(s.i, 1);
  else if (s.kind === 'convoy') { d.convoy.path.splice(s.i, 1); if (d.convoy.path.length === 0) d.convoy = null; }
  E.sel = null; changed(true, s.kind === 'river');
}
addEventListener('keydown', ev => { if ((ev.key === 'Delete' || ev.key === 'Backspace') && E.sel && !ev.target.closest('input,textarea,select')) { ev.preventDefault(); removeSel(); } });
function changed(redo, terrainNow) { E.dirty = true; props(); draw(); if (redo) preview(!!terrainNow); }

/* ---------- properties of the selected thing ---------- */
function field(label, key, val, type = 'number', opts = null, attrs = '') {
  if (opts) return `<label class="field">${label}<select data-k="${key}">${Object.entries(opts).map(([k, v]) => `<option value="${k}"${String(val) === String(k) ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>`;
  if (type === 'check') return `<label class="check"><input type="checkbox" data-k="${key}"${val ? ' checked' : ''}> ${label}</label>`;
  return `<label class="field">${label}<input data-k="${key}" type="${type}" value="${esc(val)}" ${attrs}></label>`;
}
function props() {
  const s = E.sel, d = E.def, box = $('#edProps');
  if (!s) { $('#selHead').textContent = 'Selected'; box.innerHTML = '<p class="muted small">Click something on the map to change it.</p>'; return; }
  let html = '';
  if (s.kind === 'start') { $('#selHead').textContent = `Player ${s.i + 1} start`; html = '<p class="muted small">Players take the starts in order: the host gets the first.</p>'; }
  if (s.kind === 'enemy') {
    const e = d.enemies[s.i]; $('#selHead').textContent = `Enemy ${s.i + 1}: ${E.meta.kinds[e.kind]}`;
    html = field('Name', 'name', e.name, 'text', null, 'maxlength="20" placeholder="automatic"') + field('Kind', 'kind', e.kind, '', E.meta.kinds) + field('Skill', 'level', e.level, '', E.meta.levels)
      + field('Hit points', 'hp', e.hp, 'number', null, 'min="10" max="2000"') + field('Arrives in round', 'round', e.round, 'number', null, 'min="1" max="60"')
      + field('Only with players', 'min', e.min || 1, '', { 1: '1 or more', 2: '2 or more', 3: '3 or more', 4: '4' }) + field('A target (for "Destroy the targets")', 'goal', e.goal, 'check');
  }
  if (s.kind === 'object') {
    const o = d.objects[s.i]; $('#selHead').textContent = E.meta.objects[o.type];
    if (o.type !== 'crate') html = field('Name', 'name', o.name || '', 'text', null, 'maxlength="24"')
      + (o.type === 'target' ? field('Looks like', 'style', o.style || 'bunker', '', { bunker: 'Bunker', span: 'Bridge span', radar: 'Radar post', fuel: 'Fuel tanks' }) : '')
      + field('Width (x, m)', 'w', o.w, 'number', null, 'min="1" max="40" step="0.5"') + field('Depth (z, m)', 'd', o.d, 'number', null, 'min="1" max="40" step="0.5"')
      + field(o.style === 'span' ? 'Deck above water (m)' : 'Height (m)', 'h', o.h, 'number', null, 'min="1" max="30" step="0.5"') + field('Hit points', 'hp', o.hp, 'number', null, 'min="10" max="2000"');
    else html = '<p class="muted small">Opened by driving over it or a shell within 4 m. A mission\'s crates can\'t be wrecked.</p>';
  }
  if (s.kind === 'river') { $('#selHead').textContent = `River ${s.i + 1}`; html = field('Width (m)', 'w', d.rivers[s.i].w, 'number', null, 'min="4" max="40"'); }
  if (s.kind === 'convoy') { $('#selHead').textContent = `Convoy route point ${s.i + 1}`; html = field('Speed (m per round)', 'speed', d.convoy.speed, 'number', null, 'min="2" max="80"')
    + '<p class="muted small">The convoy starts at point 1. If it reaches the last point, the mission is lost.</p>'; }
  box.innerHTML = html + '<div class="room-btns"><button class="btn ghost sm" id="propDel" type="button">Remove</button></div>';
  $('#propDel').onclick = removeSel;
  box.querySelectorAll('[data-k]').forEach(el => el.addEventListener('change', () => {
    const k = el.dataset.k, v = el.type === 'checkbox' ? el.checked : el.type === 'number' ? num(el.value, 0) : el.value;
    const target = s.kind === 'enemy' ? d.enemies[s.i] : s.kind === 'object' ? d.objects[s.i] : s.kind === 'river' ? d.rivers[s.i] : s.kind === 'convoy' ? d.convoy : null;
    if (!target) return;
    target[k] = k === 'min' ? +v : v;
    if (k === 'kind' && v === 'behemoth' && d.enemies.filter(e => e.kind === 'behemoth').length > 1) { target.kind = 'tank'; msg('Only one Behemoth per mission.'); }
    changed(s.kind === 'river' || k === 'w' || k === 'd', s.kind === 'river'); if (k === 'kind' || k === 'style') props();
  }));
}

/* ---------- save, publish, test, delete ---------- */
function msg(t) { $('#edMsg').textContent = t; }
async function save(status) {
  readForm();
  const r = await call('save', { id: E.id || undefined, def: E.def, status });
  if (r.error) { showErrors(r.errors || []); msg(r.error === 'mission_invalid' ? 'Fix the list above first.' : 'Could not save.'); return null; }
  E.id = r.id; E.status = r.status; E.def = r.def; E.dirty = false; showErrors(r.errors);
  fillForm(); await loadList(); msg(status === 'published' ? 'Published: players can now find it under Missions.' : 'Saved.');
  return r;
}
$('#edSave').onclick = () => save(E.status);
$('#edPublish').onclick = () => save(E.status === 'published' ? 'draft' : 'published');
$('#edTest').onclick = async () => {
  if (!(await save(E.status))) return;
  const r = await call('test', { id: E.id });
  if (r.match_id) location.href = 'play.php?id=' + r.match_id;
  else msg(r.error === 'in_match' ? 'You are still in another battle.' : r.error === 'mission_invalid' ? 'Fix the list above first.' : 'Could not start a test.');
};
$('#edDelete').onclick = async () => {
  if (!E.id || !confirm(`Delete "${E.def.title}"? This can't be undone.`)) return;
  const r = await call('delete', { id: E.id });
  if (r.error) return msg(r.error === 'campaign' ? 'Campaign missions can only be unpublished.' : 'Could not delete it.');
  E.id = 0; E.def = BLANK(); E.dirty = false; await loadList(); fillForm(); preview(true); msg('Deleted.');
};
addEventListener('beforeunload', e => { if (E.dirty) { e.preventDefault(); e.returnValue = ''; } });

(async () => {
  await loadList();
  const first = new URLSearchParams(location.search).get('id') || (E.list[0] && E.list[0].id);
  setTool('select');
  if (first) await open(+first); else { fillForm(); preview(true); }
})();
})();
