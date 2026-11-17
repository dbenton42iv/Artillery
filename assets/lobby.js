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
                not_host: 'Only the host can do that.', room_closed: 'The host closed the room.',
                locked: 'Earn a star on the mission before it to open this one.', too_many_players: 'Too many players for this mission.',
                mission_invalid: 'This mission needs fixing in the editor first.', no_mission: 'That mission is not available.' };
  const showErr = e => { $('#mmErr').textContent = ERR[e] || 'Something went wrong (' + e + ').'; $('#mmErr').hidden = false; };

  /* ---------- tabs ---------- */
  function setTab(t) {
    tab = t;
    $$('.tabs button').forEach(b => { const on = b.dataset.tab === t; b.classList.toggle('on', on); b.setAttribute('aria-selected', String(on)); });
    $$('.tab').forEach(p => p.hidden = p.dataset.pane !== t);
    $('#queueBox').hidden = t === 'private' || t === 'ai' || t === 'missions';
    if (t === 'missions') loadMissions();
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

  /* ---------- missions (phase 5): the co-op campaign ---------- */
  let missions = null, openMission = null;
  const escH = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const starRow = n => [0, 1, 2].map(k => `<i class="${k < n ? 'on' : ''}">★</i>`).join('');
  async function loadMissions() {
    try { const s = await call('api/scenario.php?action=list'); missions = s; renderMissions(); } catch (e) { $('#missionList').innerHTML = '<p class="err">Could not load the missions.</p>'; }
  }
  function renderMissions() {
    const s = missions; if (!s) return;
    $('#msStars').textContent = `★ ${s.stars} / ${s.max_stars}`;
    $('#msNote').textContent = s.admin ? 'Admin: every mission is open. Build your own in the Mission editor.' : '';
    let n = 0;
    $('#missionList').innerHTML = s.missions.map(m => {
      const no = m.campaign ? ++n : null, open = openMission === m.id;
      const pl = m.players[0] === m.players[1] ? `${m.players[0]} player${m.players[0] > 1 ? 's' : ''}` : `${m.players[0]}–${m.players[1]} players`;
      return `<div class="ms-card${m.open ? '' : ' locked'}${open ? ' open' : ''}" data-id="${m.id}">
        <button class="ms-top" type="button" ${m.open ? '' : 'disabled'} aria-expanded="${open}">
          <span class="ms-no">${no ?? '✦'}</span>
          <span class="ms-t"><b>${escH(m.title)}</b><small>${escH(m.map_name)}${m.night ? ' · night' : ''} · ${escH(m.goal)}</small></span>
          <span class="ms-stars" title="${m.stars} of 3 stars">${m.open ? starRow(m.stars) : '🔒'}</span>
        </button>
        ${open ? `<div class="ms-body"><p>${escH(m.blurb)}</p>
          <div class="ms-rules small"><span>★ Complete it</span><span>★ ${m.stars_rule.shots} shots or fewer each</span><span>★ ${m.stars_rule.damage} damage or less each</span></div>
          <div class="muted small">${pl} · ${m.enemies === m.enemies_max ? `${m.enemies} enemies` : `${m.enemies} enemies, up to ${m.enemies_max} with more players`}${m.plays ? ` · played ${m.plays}, won ${m.wins}${m.best_shots != null ? ` · best: ${m.best_shots} shots, ${m.best_damage} damage` : ''}` : ''}</div>
          <div class="room-btns">${m.players[0] <= 1 ? '<button class="btn" data-act="solo" type="button">Play solo</button>' : ''}${m.players[1] >= 2 ? '<button class="btn ghost" data-act="coop" type="button">Play with friends</button>' : ''}</div>
        </div>` : m.open ? '' : '<div class="ms-lock small muted">Earn a star on the mission before this one to open it.</div>'}
      </div>`;
    }).join('') || '<p class="muted small">No missions yet.</p>';
  }
  $('#missionList').addEventListener('click', async ev => {
    const card = ev.target.closest('.ms-card'); if (!card) return;
    const id = +card.dataset.id, act = ev.target.closest('[data-act]');
    if (!act) { if (ev.target.closest('.ms-top')) { openMission = openMission === id ? null : id; renderMissions(); } return; }
    $('#mmErr').hidden = true; act.disabled = true;
    if (act.dataset.act === 'solo') {
      const s = await call('api/scenario.php?action=start', { mission: id });
      if (s.error === 'in_match' || s.match_id) { location.href = 'play.php?id=' + s.match_id; return; }
      act.disabled = false; return showErr(s.error);
    }
    const s = await roomApi('create', { mission: id, map: 'random', items: 'all', turn_seconds: 40 });   // a private room for this mission
    if (s.error === 'in_match') { location.href = 'play.php?id=' + s.match_id; return; }
    act.disabled = false;
    if (s.error) return showErr(s.error);
    setTab('private'); startRoom();
  });

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
    const mi = r.mission;                                       // phase 5: a co-op mission instead of a battle
    $('#roomMission').hidden = !mi; box.hidden = !!mi;
    if (mi) {
      $('#roomMission').innerHTML = `<div class="muted small">MISSION</div><b>${esc(mi.title)}</b><div class="small">${esc(mi.goal)}</div><div class="muted small">${esc(mi.map)} · ${mi.players[0] === mi.players[1] ? mi.players[0] : mi.players[0] + '–' + mi.players[1]} players</div>`
        + (r.host ? '<button class="link" id="roomToBattle" type="button">Play a battle instead</button>' : '');
      const tb = $('#roomToBattle'); if (tb) tb.onclick = () => roomApi('update', { map: st.map, items: st.items, turn_seconds: st.turn_seconds });
    } else if (r.host) {
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
    $('#roomStart').textContent = mi ? 'Start mission' : 'Start battle';
    $('#roomStart').disabled = mi ? r.members.length < mi.players[0] || r.members.length > mi.players[1] : r.members.length < 2;
    $('#roomHint').textContent = mi ? (r.host ? `Start when everyone is in (${r.members.length} of up to ${Math.min(r.max, mi.players[1])}). You can also start on your own.` : 'Waiting for the host to start the mission.')
      : r.host ? (r.members.length < 2 ? 'Tap Invite friends to send them the link (or give them the code). You can start when at least one has joined.' : `Start when everyone is in (${r.members.length} of ${r.max}).`)
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
