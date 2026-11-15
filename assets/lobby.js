(() => {
  'use strict';
  const root = document.querySelector('.lobby-grid');
  const csrf = root.dataset.csrf;
  const $ = s => document.querySelector(s);
  let mode = 2, polling = null;

  async function api(action, body) {
    const opt = body === undefined ? { credentials: 'same-origin' }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) };
    const r = await fetch('api/queue.php?action=' + action, opt);
    if (r.status === 401) { location.href = 'login.php'; throw new Error('logged out'); }
    return r.json();
  }

  document.querySelectorAll('#modeSeg button').forEach(b => b.onclick = () => {
    if (polling) return;
    document.querySelectorAll('#modeSeg button').forEach(x => x.classList.remove('on'));
    b.classList.add('on'); mode = +b.dataset.mode;
  });

  function showSearching(on) {
    $('#searching').hidden = !on; $('#findBtn').hidden = on;
    document.querySelectorAll('#modeSeg button').forEach(b => b.disabled = on);
  }
  function render(s) {
    if (s.counts) {
      $('#qCounts').textContent = `In queue now: 1 v 1 · ${s.counts[2]}   3 tanks · ${s.counts[3]}   4 tanks · ${s.counts[4]}`;
    }
    if (s.match_id) { $('#sTitle').textContent = 'Match found!'; location.href = 'play.php?id=' + s.match_id; return; }
    if (s.queued) {
      const m = s.waited, mm = Math.floor(m / 60), ss = String(m % 60).padStart(2, '0');
      $('#sTitle').textContent = `Searching… ${mm}:${ss}`;
      $('#sInfo').textContent = `Looking for ${s.mode === 2 ? '1 opponent' : (s.mode - 1) + ' opponents'} rated ${s.rating - s.window}–${s.rating + s.window}`;
    }
  }
  async function poll() {
    try {
      const s = await api('status');
      render(s);
      if (!s.queued && !s.match_id) stop();
    } catch (e) { /* keep trying */ }
  }
  function start() { showSearching(true); poll(); polling = setInterval(poll, 2000); }
  function stop() { clearInterval(polling); polling = null; showSearching(false); }

  $('#findBtn').onclick = async () => {
    $('#mmErr').hidden = true;
    const s = await api('join', { mode });
    if (s.error === 'in_match') { location.href = 'play.php?id=' + s.match_id; return; }
    if (s.error) { $('#mmErr').textContent = 'Could not join the queue (' + s.error + ').'; $('#mmErr').hidden = false; return; }
    render(s); if (!s.match_id) start();
  };
  $('#cancelBtn').onclick = async () => { stop(); await api('leave', {}); };

  // Resume a search already in progress (e.g. after a page reload) and show live queue counts.
  api('status').then(s => { render(s); if (s.queued) start(); });
  setInterval(() => { if (!polling) api('status').then(render).catch(() => {}); }, 10000);
})();
