/* The garage: pick paint, barrel, trail, blast and title; a drawing of the tank shows the choice. */
(() => {
  const $ = s => document.querySelector(s), $$ = s => [...document.querySelectorAll(s)];
  const csrf = document.querySelector('main').dataset.csrf;
  const TEAM = '#e5484d';                                         // the preview shows the first seat's colour
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  let D = null, pick = null;
  const call = (body) => fetch('api/cosmetics.php', body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) } : {})
    .then(r => { if (r.status === 401) { location.href = 'login.php'; throw new Error('logged out'); } return r.json(); });

  const camoCache = {};
  function camoURL(it) { const k = it.camo + it.colors.join(); return camoCache[k] ??= ARTCAMO.canvas(it.camo, it.colors, 128).toDataURL(); }

  /** Draw the tank: paint on the hull, the team colour on the turret, the barrel model, a shell trail and its blast. */
  function draw() {
    const C = D.catalog, p = C.paint[pick.paint], b = C.barrel[pick.barrel].model, t = C.trail[pick.trail], bl = C.blast[pick.blast].style, ti = C.title[pick.title].text;
    const hull = p.camo ? 'url(#camo)' : (p.color || TEAM);
    const tc = t.color || TEAM;
    const blen = b === 'short' ? 55 : b === 'long' ? 102 : 76, A = 32 * Math.PI / 180;            // the barrel turns 32° up round (150, 92)
    const muzzle = [150 + blen * Math.cos(A), 92 - blen * Math.sin(A)];
    const arc = `M ${muzzle[0]} ${muzzle[1]} Q 285 -25 332 150`, GUN = '#55574f';
    let trail = '';
    if (t.style === 'rainbow') trail = `<path d="${arc}" fill="none" stroke="url(#rainbow)" stroke-width="4" stroke-linecap="round"/>`;
    else if (t.style === 'rings') trail = `<path d="${arc}" fill="none" stroke="${tc}" stroke-width="1.5" opacity=".5"/>` +
      [.15, .32, .5, .68, .85].map(k => { const [x, y] = qpt(muzzle, [285, -25], [332, 150], k); return `<circle cx="${x}" cy="${y}" r="${5 + k * 4}" fill="none" stroke="${tc}" stroke-width="2.5" opacity=".75"/>`; }).join('');
    else if (t.style === 'sparks') trail = `<path d="${arc}" fill="none" stroke="${tc}" stroke-width="2.5" opacity=".9"/>` +
      Array.from({ length: 14 }, (_, i) => { const [x, y] = qpt(muzzle, [285, -25], [332, 150], i / 14 + .03); return `<circle cx="${x + (i % 3 - 1) * 4}" cy="${y + (i % 2 ? 4 : -4)}" r="1.6" fill="#fff6c8"/>`; }).join('');
    else trail = `<path d="${arc}" fill="none" stroke="${tc}" stroke-width="3.5" stroke-linecap="round" opacity=".9"/>`;
    const bx = 330, by = 152;
    const blast = bl === 'sparks' ? Array.from({ length: 12 }, (_, i) => { const a = i / 12 * 6.28; return `<line x1="${bx}" y1="${by}" x2="${bx + Math.cos(a) * 22}" y2="${by + Math.sin(a) * 22}" stroke="#ffe08a" stroke-width="2"/>`; }).join('') + `<circle cx="${bx}" cy="${by}" r="9" fill="#ffc04a"/>`
      : bl === 'smoke' ? `<circle cx="${bx}" cy="${by}" r="20" fill="none" stroke="#6a6a66" stroke-width="7" opacity=".8"/><circle cx="${bx}" cy="${by}" r="9" fill="#ffc04a"/>`
      : bl === 'confetti' ? Array.from({ length: 16 }, (_, i) => { const a = i / 16 * 6.28, r = 10 + (i % 4) * 5; return `<rect x="${bx + Math.cos(a) * r}" y="${by + Math.sin(a) * r}" width="5" height="3" fill="${['#e5484d', '#4aa8ff', '#3ddc84', '#f0b429', '#b07cff'][i % 5]}" transform="rotate(${i * 37} ${bx + Math.cos(a) * r} ${by + Math.sin(a) * r})"/>`; }).join('') + `<circle cx="${bx}" cy="${by}" r="8" fill="#ffc04a"/>`
      : `<circle cx="${bx}" cy="${by}" r="15" fill="#ffc04a" opacity=".85"/><circle cx="${bx}" cy="${by}" r="8" fill="#fff1c0"/>`;
    const barrel = b === 'twin'
      ? `<g transform="rotate(-32 150 92)"><rect x="150" y="84" width="72" height="5" rx="2" fill="${GUN}"/><rect x="150" y="95" width="72" height="5" rx="2" fill="${GUN}"/></g>`
      : `<g transform="rotate(-32 150 92)"><rect x="150" y="${b === 'short' ? 86 : b === 'long' ? 89 : 88}" width="${blen}" height="${b === 'short' ? 11 : b === 'long' ? 6 : 8}" rx="3" fill="${GUN}"/>${b === 'short' ? `<rect x="${150 + blen - 6}" y="83" width="10" height="17" rx="2" fill="${GUN}"/>` : ''}</g>`;
    $('#gPrev').innerHTML = `<defs>
        ${p.camo ? `<pattern id="camo" patternUnits="userSpaceOnUse" width="90" height="90"><image href="${camoURL(p)}" width="90" height="90"/></pattern>` : ''}
        <linearGradient id="rainbow"><stop offset="0" stop-color="#e5484d"/><stop offset=".25" stop-color="#f0b429"/><stop offset=".5" stop-color="#3ddc84"/><stop offset=".75" stop-color="#4aa8ff"/><stop offset="1" stop-color="#b07cff"/></linearGradient>
      </defs>
      <ellipse cx="150" cy="178" rx="120" ry="9" fill="#000" opacity=".25"/>
      ${trail}${blast}
      ${barrel}
      <path d="M 105 92 A 45 40 0 0 1 195 92 Z" fill="${TEAM}" stroke="#000" stroke-opacity=".25"/>
      <path d="M 52 128 L 70 98 L 236 98 L 252 128 Z" fill="${hull}" stroke="#000" stroke-opacity=".3"/>
      <rect x="40" y="128" width="222" height="40" rx="18" fill="#2a2b28"/>
      ${[66, 106, 146, 186, 226].map(x => `<circle cx="${x}" cy="148" r="12" fill="#3d3f3a" stroke="#1d1e1b" stroke-width="3"/>`).join('')}
      ${ti ? `<text x="150" y="30" text-anchor="middle" font-family="Black Ops One, sans-serif" font-size="17" fill="#f0b429" letter-spacing="1">${esc(ti)}</text>` : ''}`;
  }
  function qpt(a, c, b, k) { const u = 1 - k; return [u * u * a[0] + 2 * u * k * c[0] + k * k * b[0], u * u * a[1] + 2 * u * k * c[1] + k * k * b[1]]; }

  function swatch(slot, it) {
    if (slot === 'paint') return it.camo ? `<span class="sw" style="background-image:url(${camoURL(it)})"></span>` : `<span class="sw" style="background:${it.color || TEAM}"></span>`;
    if (slot === 'trail') return `<span class="sw line" style="background:${it.style === 'rainbow' ? 'linear-gradient(90deg,#e5484d,#f0b429,#3ddc84,#4aa8ff,#b07cff)' : (it.color || TEAM)}"></span>`;
    return '';
  }
  function lockText(it) {
    const lv = it.level <= 50 ? `Level ${it.level}` : '';
    const w = it.map ? `${it.wins} wins on ${D.maps[it.map]}` : '';
    return [lv, w].filter(Boolean).join(' or ');
  }
  function render() {
    for (const grid of $$('.g-grid')) {
      const slot = grid.dataset.slot, items = D.catalog[slot], open = D.unlocked[slot];
      grid.innerHTML = Object.entries(items).map(([id, it]) => {
        const locked = !open.includes(id), on = pick[slot] === id;
        const prog = locked && it.map ? ` <small class="g-prog">${D.wins[it.map] || 0}/${it.wins}</small>` : '';
        return `<button type="button" class="g-item${on ? ' on' : ''}${locked ? ' locked' : ''}" data-slot="${slot}" data-id="${id}" aria-pressed="${on}" ${locked ? 'aria-disabled="true"' : ''}>
          ${swatch(slot, it)}<span class="g-name">${esc(it.name)}</span>${locked ? `<small class="g-lock">${esc(lockText(it))}${prog}</small>` : ''}</button>`;
      }).join('');
    }
    $('#gSave').disabled = JSON.stringify(pick) === JSON.stringify(D.saved);
    draw();
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('.g-item'); if (!b || !D) return;
    if (b.classList.contains('locked')) { $('#gMsg').textContent = `${D.catalog[b.dataset.slot][b.dataset.id].name} unlocks at ${lockText(D.catalog[b.dataset.slot][b.dataset.id])}.`; return; }
    pick[b.dataset.slot] = b.dataset.id; $('#gMsg').textContent = ''; render();
  });
  $('#gSave').onclick = async () => {
    $('#gSave').disabled = true;
    const r = await call(pick);
    if (r.error) { $('#gMsg').textContent = r.error === 'locked' ? "That isn't unlocked yet." : 'Could not save.'; render(); return; }
    D = r; pick = { ...r.saved }; render(); $('#gMsg').textContent = 'Saved. Your tank looks like this from your next battle.';
  };
  call().then(d => {
    D = d; pick = { ...d.saved };
    const n = Object.values(d.unlocked).reduce((a, l) => a + l.length, 0), all = Object.values(d.catalog).reduce((a, l) => a + Object.keys(l).length, 0);
    $('#gLevel').textContent = `Level ${d.level} · ${n} of ${all} unlocked. More unlock as you level up; camos and map titles also by winning on their map against other players.`;
    render();
  });
})();
