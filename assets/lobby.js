(() => {
  'use strict';
  const root = document.querySelector('.lobby-grid');
  const csrf = root.dataset.csrf;
  const $ = s => document.querySelector(s);
  const $$ = s => document.querySelectorAll(s);
  let mode = 2, tab = 'ranked', polling = null, roomPoll = null, room = null;

  async function call(url, body) {
    const opt = body === undefined ? { credentials: 'same-origin' }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) };
    const r = await fetch(url, opt);
    if (r.status === 401) { location.href = 'login.php'; throw new Error('logged out'); }
    return r.json();
  }
  const api = (action, body) => call('api/queue.php?action=' + action, body);
  const roomApi = (action, body) => call('api/room.php?action=' + action, body);
  const ERR = { in_match: 'You already have a battle in progress.', no_room: 'No open room with that code.', room_full: 'That room is full (4 players).',
                need_players: 'You need at least one more player to start.', someone_in_match: 'Someone in the room is still in another battle.',
                not_host: 'Only the host can do that.', room_closed: 'The host closed the room.' };
  const showErr = e => { $('#mmErr').textContent = ERR[e] || 'Something went wrong (' + e + ').'; $('#mmErr').hidden = false; };

  /* ---------- tabs ---------- */
  function setTab(t) {
    tab = t;
    $$('.tabs button').forEach(b => { const on = b.dataset.tab === t; b.classList.toggle('on', on); b.setAttribute('aria-selected', String(on)); });
    $$('.tab').forEach(p => p.hidden = p.dataset.pane !== t);
    $('#queueBox').hidden = t === 'private' || t === 'ai';
    $('#findBtn').textContent = t === 'casual' ? 'Find casual match' : 'Find match';
    $('#mmErr').hidden = true;
  }
  $$('.tabs button').forEach(b => b.onclick = () => { if (polling || room) return; setTab(b.dataset.tab); });
  const lockTabs = on => $$('.tabs button').forEach(b => b.disabled = on && b.dataset.tab !== tab);

  /* ---------- ranked and casual queues ---------- */
  $$('#modeSeg button').forEach(b => b.onclick = () => {
    if (polling) return;
    $$('#modeSeg button').forEach(x => x.classList.remove('on'));
    b.classList.add('on'); mode = +b.dataset.mode;
  });
  const baseTitle = document.title;
  function showSearching(on) {
    $('#searching').hidden = !on; $('#findBtn').hidden = on;
    document.querySelector('.hero').classList.toggle('busy', on);
    document.title = on ? 'Searching… · ' + baseTitle : baseTitle;   // visible in the browser tab while you wait
    $$('#modeSeg button').forEach(b => b.disabled = on);
    $('#casualMap').disabled = on;
    lockTabs(on);
  }
  function render(s) {
    if (s.counts) {
      const c = s.counts[tab === 'casual' ? 'casual' : 'ranked'];
      if (c) $('#qCounts').textContent = `In queue now: 1 v 1 · ${c[2]}   3 tanks · ${c[3]}   4 tanks · ${c[4]}`;
    }
    if (s.match_id) { $('#sTitle').textContent = 'Match found!'; location.href = 'play.php?id=' + s.match_id; return; }
    if (s.queued) {
      if (s.kind && s.kind !== tab) setTab(s.kind);
      const m = s.waited, mm = Math.floor(m / 60), ss = String(m % 60).padStart(2, '0');
      const foes = s.mode === 2 ? '1 opponent' : (s.mode - 1) + ' opponents';
      $('#sTitle').textContent = `Searching… ${mm}:${ss}`;
      $('#sInfo').textContent = s.kind === 'casual' ? `Casual: looking for ${foes}${s.map ? ' who want this map' : ''}`
        : `Looking for ${foes} rated ${s.rating - s.window}–${s.rating + s.window}`;
    }
  }
  async function poll() {
    try { const s = await api('status'); render(s); if (!s.queued && !s.match_id) stop(); } catch (e) { /* keep trying */ }
  }
  function start() { showSearching(true); poll(); polling = setInterval(poll, 2000); }
  function stop() { clearInterval(polling); polling = null; showSearching(false); }

  $('#findBtn').onclick = async () => {
    $('#mmErr').hidden = true;
    const s = await api('join', { mode, kind: tab, map: $('#casualMap').value });
    if (s.error === 'in_match') { location.href = 'play.php?id=' + s.match_id; return; }
    if (s.error) { showErr(s.error); return; }
    render(s); if (!s.match_id) start();
  };
  $('#cancelBtn').onclick = async () => { stop(); await api('leave', {}); };

  /* ---------- private rooms ---------- */
  const MAPNAMES = Object.fromEntries([...$('#roomSet select[name=map]').options].map(o => [o.value, o.textContent]));
  const ITEMS = { all: 'All power-ups', safe: 'Ranked-safe power-ups', off: 'No power-ups' };
  const settingsFrom = el => Object.fromEntries([...el.querySelectorAll('select')].map(s => [s.name, s.value]));
  function renderRoom(r) {
    room = r;
    $('#roomNone').hidden = !!r; $('#roomIn').hidden = !r;
    lockTabs(!!r);
    if (!r) return;
    if (tab !== 'private') setTab('private');
    $('#roomCodeShow').textContent = r.code;
    const link = new URL('join.php?code=' + r.code, location.href).href;
    if ($('#roomLink').value !== link) $('#roomLink').value = link;
    $('#roomInvite').hidden = r.members.length >= r.max;
    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    let li = r.members.map(m => `<li><span style="color:${m.rank.color}">${esc(m.name)}${m.me ? ' <small>YOU</small>' : ''}</span><small>${m.host ? 'HOST' : esc(m.rank.name)}</small></li>`);
    for (let k = r.members.length; k < r.max; k++) li.push('<li class="empty">Waiting for a player…</li>');
    $('#roomMembers').innerHTML = li.join('');
    const st = r.settings, box = $('#roomSetIn');
    if (r.host) {
      if (!box.querySelector('select')) {                     // the host edits a copy of the form
        box.innerHTML = $('#roomSet').innerHTML;
        box.querySelectorAll('select').forEach(sel => sel.onchange = () => roomApi('update', settingsFrom(box)));
      }
      if (document.activeElement.closest && !document.activeElement.closest('#roomSetIn')) {
        box.querySelector('[name=map]').value = st.map; box.querySelector('[name=items]').value = st.items; box.querySelector('[name=turn_seconds]').value = String(st.turn_seconds);
      }
    } else {
      box.innerHTML = `<div class="set-ro">Map <b>${esc(MAPNAMES[st.map] || st.map)}</b></div><div class="set-ro"><b>${ITEMS[st.items]}</b></div><div class="set-ro">Shot clock <b>${st.turn_seconds} s</b></div>`;
    }
    $('#roomStart').hidden = !r.host;
    $('#roomStart').disabled = r.members.length < 2;
    $('#roomHint').textContent = r.host ? (r.members.length < 2 ? 'Tap Invite friends to send them the link (or give them the code). You can start when at least one has joined.' : `Start when everyone is in (${r.members.length} of ${r.max}).`)
      : 'Waiting for the host to start the battle.';
  }
  async function roomTick() {
    try {
      const s = await roomApi('status');
      if (s.match_id) { location.href = 'play.php?id=' + s.match_id; return; }
      if (!s.room) { if (room) { stopRoom(); if (s.closed) showErr('room_closed'); } return; }
      renderRoom(s.room);
    } catch (e) { /* keep trying */ }
  }
  function startRoom() { if (!roomPoll) roomPoll = setInterval(roomTick, 2000); roomTick(); }
  function stopRoom() { clearInterval(roomPoll); roomPoll = null; renderRoom(null); }

  $('#roomCreate').onclick = async () => {
    $('#mmErr').hidden = true;
    const s = await roomApi('create', settingsFrom($('#roomSet')));
    if (s.error === 'in_match') { location.href = 'play.php?id=' + s.match_id; return; }
    if (s.error) return showErr(s.error);
    startRoom();
  };
  $('#roomJoin').onclick = async () => {
    $('#mmErr').hidden = true;
    const code = $('#roomCode').value.trim();
    if (code.length < 6) return showErr('no_room');
    const s = await roomApi('join', { code });
    if (s.error === 'in_match') { location.href = 'play.php?id=' + s.match_id; return; }
    if (s.error) return showErr(s.error);
    startRoom();
  };
  $('#roomCode').addEventListener('keydown', e => { if (e.key === 'Enter') $('#roomJoin').click(); });
  $('#roomStart').onclick = async () => {
    $('#roomStart').disabled = true;
    const s = await roomApi('start', {});
    if (s.match_id) { location.href = 'play.php?id=' + s.match_id; return; }
    if (s.error) { showErr(s.error); $('#roomStart').disabled = false; }
  };
  $('#roomLeave').onclick = async () => { await roomApi('leave', {}); stopRoom(); };
  /* invite friends: the phone's share sheet where there is one, otherwise copy the link */
  $('#roomInvite').onclick = async () => {
    const url = $('#roomLink').value, code = $('#roomCodeShow').textContent;
    const text = `Join my Artillery 3D battle! Tap the link, or enter code ${code} under Private in the lobby.`;
    const say = t => { $('#inviteMsg').textContent = t; $('#inviteMsg').hidden = false; };
    if (navigator.share) {
      try { await navigator.share({ title: 'Artillery 3D battle', text, url }); say('Invite sent. The room stays open while you wait for them.'); return; }
      catch (e) { if (e && e.name === 'AbortError') return; }            // closed the share sheet
    }
    try { await navigator.clipboard.writeText(url); say('Invite link copied. Paste it into a message to your friends.'); }
    catch (e) { $('#roomLink').select(); say('Copy the link above and send it to your friends.'); }
  };
  $('#roomCopy').onclick = () => { try { navigator.clipboard.writeText($('#roomCodeShow').textContent); $('#roomCopy').textContent = 'Copied'; setTimeout(() => $('#roomCopy').textContent = 'Copy', 1500); } catch (e) {} };

  /* ---------- vs AI ---------- */
  let aiBots = 1;
  $$('#aiBots button').forEach(b => b.onclick = () => { $$('#aiBots button').forEach(x => x.classList.remove('on')); b.classList.add('on'); aiBots = +b.dataset.n; });
  $('#aiStart').onclick = async () => {
    $('#mmErr').hidden = true; $('#aiStart').disabled = true;
    const s = await call('api/ai.php', { ...settingsFrom($('#aiSet')), bots: aiBots });
    if (s.match_id) { location.href = 'play.php?id=' + s.match_id; return; }
    $('#aiStart').disabled = false; if (s.error) showErr(s.error);
  };

  /* ---------- loadout (phase 4): presets or a custom build of unlocked skills ---------- */
  const LO = { d: null, pick: null, rp: null };     // d: GET api/loadout.php; pick: the loadout being edited
  const loApi = body => call('api/loadout.php', body);
  const LOERR = { locked: "That skill isn't unlocked at your level yet.", bad_loadout: 'Pick a doctrine and up to 2 perks.', ranked_preset: 'Pick a preset for ranked.' };
  function partsOf(lo) {
    const c = LO.d.catalog;
    if (lo.preset) { const p = c.presets[lo.preset]; return { name: p.name, doctrine: p.doctrine, perks: p.perks, preset: lo.preset }; }
    return { name: c.doctrines[lo.doctrine].name, doctrine: lo.doctrine, perks: lo.perks || [], preset: null };
  }
  function skillLine(lo) {
    const c = LO.d.catalog, p = partsOf(lo);
    return `${p.preset ? `<b>${esc(p.name)}</b> · ` : ''}<b>${c.doctrines[p.doctrine].icon} ${esc(c.doctrines[p.doctrine].name)}</b>`
      + (p.perks.length ? ' · ' + p.perks.map(k => `${c.perks[k].icon} ${esc(c.perks[k].name)}`).join(' · ') : ' · <span class="muted">no perks</span>');
  }
  const esc = s => String(s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
  function renderLoSummary() {
    const d = LO.d, u = d.uses, same = JSON.stringify(u.ranked) === JSON.stringify(u.other);
    $('#loSummary').innerHTML = `<div>${skillLine(u.other)}</div>`
      + (same ? '' : `<div class="lo-ranked">Ranked: ${skillLine(u.ranked)} <span class="muted">(custom builds in ranked from level ${d.catalog.ranked_free})</span></div>`);
  }
  function card(kind, id, def, on, locked) {
    const c = LO.d.catalog;
    const body = kind === 'preset'
      ? `<div class="lo-desc">${esc(def.blurb)}</div><div class="lo-desc muted">${c.doctrines[def.doctrine].icon} ${esc(c.doctrines[def.doctrine].name)} · ${def.perks.map(k => c.perks[k].icon + ' ' + esc(c.perks[k].name)).join(' · ')}</div>`
      : `<div class="lo-desc"><span class="gain">+</span> ${esc(def.gain)}</div><div class="lo-desc"><span class="cost">−</span> ${esc(def.cost)}</div>`;
    return `<button type="button" class="lo-card${on ? ' on' : ''}${locked ? ' locked' : ''}" data-kind="${kind}" data-id="${id}" ${locked ? 'aria-disabled="true"' : ''} aria-pressed="${on}">
      <div class="lo-name">${def.icon ? `<i>${def.icon}</i>` : ''}${esc(def.name)}${locked ? `<small>Level ${def.level}</small>` : ''}</div>${body}</button>`;
  }
  function renderPicker() {
    const d = LO.d, c = d.catalog, pk = LO.pick, lvl = d.level;
    const custom = !pk.preset;
    $('#loPresets').innerHTML = Object.entries(c.presets).map(([id, p]) => card('preset', id, p, pk.preset === id, false)).join('');
    const doc = custom ? pk.doctrine : null, perks = custom ? pk.perks : [];
    $('#loDoctrines').innerHTML = Object.entries(c.doctrines).map(([id, x]) => card('doctrine', id, x, doc === id, x.level > lvl)).join('');
    $('#loPerks').innerHTML = Object.entries(c.perks).map(([id, x]) => card('perk', id, x, perks.includes(id), x.level > lvl)).join('');
    $('#loCustomNote').textContent = lvl >= c.ranked_free ? 'every skill unlocked' : 'skills unlock as you level up';
    const low = lvl < c.ranked_free;
    $('#loRankedBox').hidden = !(low && custom);
    $('#loRankedNote').textContent = `below level ${c.ranked_free}, ranked uses a preset`;
    $('#loRanked').innerHTML = Object.entries(c.presets).map(([id, p]) => `<button type="button" data-rp="${id}" class="${LO.rp === id ? 'on' : ''}">${esc(p.name)}</button>`).join('');
    $('#loErr').hidden = true;
  }
  $('#loadout').addEventListener('click', e => {
    const b = e.target.closest('.lo-card, #loRanked button'); if (!b || !LO.pick) return;
    if (b.dataset.rp) { LO.rp = b.dataset.rp; return renderPicker(); }
    if (b.classList.contains('locked')) { $('#loErr').textContent = `Unlocks at level ${b.querySelector('small').textContent.replace(/\D/g, '')}.`; $('#loErr').hidden = false; return; }
    const { kind, id } = b.dataset, pk = LO.pick;
    if (kind === 'preset') LO.pick = { preset: id };
    else {
      const cur = pk.preset ? { doctrine: 'standard', perks: [] } : { doctrine: pk.doctrine, perks: [...pk.perks] };
      if (kind === 'doctrine') cur.doctrine = id;
      else if (cur.perks.includes(id)) cur.perks = cur.perks.filter(k => k !== id);
      else { cur.perks.push(id); if (cur.perks.length > 2) cur.perks.shift(); }
      LO.pick = cur;
    }
    renderPicker();
  });
  const openPicker = on => {
    $('#loPicker').hidden = !on; $('#loEdit').textContent = on ? 'Close' : 'Change'; $('#loEdit').setAttribute('aria-expanded', String(on));
    document.querySelector('.hero').classList.toggle('picking', on);     // the picker takes the hero's full width
    if (on) { const s = LO.d.saved; LO.pick = s.preset ? { preset: s.preset } : { doctrine: s.doctrine, perks: [...s.perks] }; LO.rp = s.ranked_preset; renderPicker(); }
  };
  $('#loEdit').onclick = () => LO.d && openPicker($('#loPicker').hidden);
  $('#loCancel').onclick = () => openPicker(false);
  $('#loSave').onclick = async () => {
    $('#loSave').disabled = true;
    try {
      const r = await loApi({ loadout: LO.pick, ranked_preset: LO.rp });
      if (r.error) { $('#loErr').textContent = LOERR[r.error] || 'Could not save (' + r.error + ').'; $('#loErr').hidden = false; return; }
      LO.d = r; renderLoSummary(); openPicker(false);
    } finally { $('#loSave').disabled = false; }
  };
  loApi().then(d => { LO.d = d; renderLoSummary(); }).catch(() => { $('#loSummary').textContent = "Couldn't load your loadout."; });

  // Resume a search or room already in progress (e.g. after a page reload) and show live queue counts.
  api('status').then(s => { if (s.queued && s.kind) setTab(s.kind); render(s); if (s.queued) start(); });
  roomApi('status').then(s => { if (s.match_id) location.href = 'play.php?id=' + s.match_id; else if (s.room) { renderRoom(s.room); startRoom(); } });
  setInterval(() => { if (!polling) api('status').then(render).catch(() => {}); }, 10000);
})();
