<?php
declare(strict_types=1);
/*
 * Mission editor (phase 5), for admins. Pick a map recipe and a terrain seed, then click the map to place the players'
 * starts, computer tanks (with the round each one arrives), targets, walls, a depot to protect, crates, rivers and a
 * convoy route. Choose the goal and the star thresholds, test-play it on your own, then publish it to the Missions tab.
 * The page talks to api/editor.php; the ground it draws is exactly the ground the mission will be played on.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/game.php';

$u = require_login();
if (!is_admin($u)) { http_response_code(403); page_head('Mission editor', 'auth'); ?>
<div class="panel auth-card"><h1 class="logo">EDITOR</h1><p class="muted">The mission editor is only for the site's admins.</p><a class="btn ghost" href="lobby.php">Back to the lobby</a></div>
<?php page_foot(); exit; }
page_head('Mission editor', 'editor'); topbar($u); ?>
<main class="ed" data-csrf="<?= h(csrf_token()) ?>">
  <aside class="panel card ed-list">
    <div class="ed-head"><h2>Missions</h2><a class="link" href="admin.php">Admin</a></div>
    <div id="edList" class="ed-missions"></div>
    <div class="room-btns"><button class="btn" id="edNew" type="button">New mission</button><button class="btn ghost" id="edDup" type="button">Duplicate</button></div>
  </aside>

  <section class="panel card ed-main">
    <div class="ed-title"><input id="fTitle" maxlength="40" placeholder="Mission title" aria-label="Title"><span id="edStatus" class="ed-badge"></span></div>
    <textarea id="fBlurb" maxlength="240" rows="2" placeholder="Briefing: what the players are told (up to 240 characters)" aria-label="Briefing"></textarea>
    <div class="ed-tools" id="edTools" role="toolbar" aria-label="What a click on the map places"></div>
    <div class="ed-mapwrap">
      <canvas id="edMap" width="640" height="640" aria-label="The battlefield from above"></canvas>
      <div class="ed-loading" id="edLoading" hidden>Growing the terrain…</div>
    </div>
    <p class="muted small" id="edHelp"></p>
    <div class="ed-errors" id="edErrors" hidden></div>
    <div class="room-btns ed-save">
      <button class="btn" id="edSave" type="button">Save draft</button>
      <button class="btn ghost" id="edPublish" type="button">Publish</button>
      <button class="btn ghost" id="edTest" type="button">Test play</button>
      <button class="btn ghost danger" id="edDelete" type="button">Delete</button>
    </div>
    <p class="muted small" id="edMsg" role="status"></p>
  </section>

  <aside class="panel card ed-side">
    <h3>Battlefield</h3>
    <div class="ed-grid">
      <label class="field">Map <select id="fMap"></select></label>
      <label class="field">Size <select id="fSize"><option value="160">240 m</option><option value="240">360 m</option></select></label>
      <label class="field">Terrain seed <span class="ed-row"><input id="fSeed" type="number" min="1" step="1"><button class="btn ghost sm" id="fSeedNew" type="button" title="A new random terrain">🎲</button></span></label>
      <label class="field">Players <span class="ed-row"><select id="fPMin"></select>to<select id="fPMax"></select></span></label>
    </div>
    <h3>Goal</h3>
    <div class="ed-grid">
      <label class="field wide">Goal <select id="fGoal"></select></label>
      <label class="field" id="wRounds">Rounds to hold <input id="fRounds" type="number" min="1" max="60"></label>
      <label class="field" id="wCount">Crates needed <input id="fCount" type="number" min="1" max="20"></label>
      <label class="field">Round limit <input id="fLimit" type="number" min="0" max="80" title="0 = no limit"></label>
      <label class="field">★ Shots each <input id="fShots" type="number" min="1" max="200" title="2nd star: no more shots than this per player"></label>
      <label class="field">★ Damage each <input id="fDamage" type="number" min="0" max="2000" title="3rd star: no more damage taken than this per player"></label>
    </div>
    <h3>Rules</h3>
    <div class="ed-grid">
      <label class="field">Power-ups <select id="fItems"><option value="safe">Ranked-safe</option><option value="all">All</option><option value="off">Off</option></select></label>
      <label class="check"><input type="checkbox" id="fDrops"> Players' supply drops</label>
      <label class="check"><input type="checkbox" id="fNight"> Night (flares)</label>
      <label class="field" id="wFlares">Flares each <input id="fFlares" type="number" min="0" max="20"></label>
      <label class="field" id="wErupt">Erupts every <select id="fErupt"><option value="0">map default (4)</option><option value="2">2nd round</option><option value="3">3rd round</option><option value="5">5th round</option></select></label>
      <label class="field">Driving (m) <input id="fMove" type="number" min="0" max="20" step="1"></label>
      <label class="field">Repair / turn <input id="fRegen" type="number" min="0" max="10"></label>
      <label class="field">Wind max <input id="fWind" type="number" min="0" max="15" step="1" placeholder="map"></label>
      <label class="field">Shot clock <select id="fClock"><option>20</option><option>30</option><option selected>40</option><option>60</option></select></label>
    </div>
    <h3 id="selHead">Selected</h3>
    <div id="edProps" class="ed-grid"><p class="muted small">Click something on the map to change it.</p></div>
  </aside>
</main>
<script src="assets/editor.js?v=1"></script>
<?php page_foot();
