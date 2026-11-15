<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/game.php';
require __DIR__ . '/includes/ai.php';

$u = require_login();
$rank = rank_for((int)$u['rating']);
$active = active_match_for((int)$u['id']);

$top = db()->query('SELECT id, username, alias, rating, games_played, wins FROM ' . T('users') . ' WHERE is_bot = 0 ORDER BY rating DESC, wins DESC, id LIMIT 25')->fetchAll();
$pos = db()->prepare('SELECT COUNT(*) + 1 FROM ' . T('users') . ' WHERE is_bot = 0 AND (rating > ? OR (rating = ? AND (wins > ? OR (wins = ? AND id < ?))))');
$pos->execute([$u['rating'], $u['rating'], $u['wins'], $u['wins'], $u['id']]);
$myPos = (int)$pos->fetchColumn();

$recent = db()->prepare('SELECT m.id, m.mode, m.game_mode, m.map_id, m.finished_at, p.placement, p.rating_before, p.rating_after, p.kills, p.damage_dealt
    FROM ' . T('match_players') . ' p JOIN ' . T('matches') . ' m ON m.id = p.match_id
    WHERE p.user_id = ? AND m.status = \'finished\' ORDER BY m.id DESC LIMIT 8');
$recent->execute([$u['id']]);
$recent = $recent->fetchAll();
$ord = fn(int $n) => [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$n] ?? (string)$n;

page_head('Lobby', 'lobby'); topbar($u); ?>
<main class="lobby-grid" data-csrf="<?= h(csrf_token()) ?>">
  <section class="col">
    <div class="panel card profile">
      <div class="rank-badge" style="--rc:<?= h($rank['color']) ?>"><?= h(strtoupper($rank['name'])) ?></div>
      <div class="profile-name"><?= h($u['name']) ?></div>
      <?php if (!empty($u['alias'])): ?><div class="muted small">Logs in as <?= h($u['username']) ?></div><?php endif; ?>
      <?php if (empty($u['alias']) && !name_is_clean($u['username'])): ?>
      <p class="err">Your username can't be shown to other players, so you appear as <?= h($u['name']) ?>. <a href="account.php">Choose a name</a></p>
      <?php endif; ?>
      <a class="small" href="account.php">Change name</a>
      <div class="stats">
        <div><b><?= (int)$u['rating'] ?></b><span>Rating</span></div>
        <div><b>#<?= $myPos ?></b><span>Rank</span></div>
        <div><b><?= (int)$u['wins'] ?></b><span>Wins</span></div>
        <div><b><?= (int)$u['games_played'] ?></b><span>Matches</span></div>
      </div>
    </div>

    <?php if ($active): ?>
    <div class="panel card alert">
      <h2>Battle in progress</h2>
      <p class="muted">You still have a tank on the field.</p>
      <a class="btn" href="play.php?id=<?= $active ?>">Rejoin match</a>
    </div>
    <?php endif; ?>

    <div class="panel card" id="mm">
      <div class="tabs" role="tablist">
        <button role="tab" data-tab="ranked" class="on" aria-selected="true">Ranked</button><button role="tab" data-tab="casual" aria-selected="false">Casual</button><button role="tab" data-tab="private" aria-selected="false">Private</button><button role="tab" data-tab="ai" aria-selected="false">vs AI</button>
      </div>

      <div class="tab" data-pane="ranked">
        <p class="muted">You'll be paired with commanders near your rating. The longer you wait, the wider the search.</p>
        <p class="muted small">Map pool: <?= h(implode(' · ', array_map(fn($m) => MAPS[$m]['name'], ranked_pool()))) ?>. No map repeats until you've played them all this session.
          Supply crates bring ranked-safe power-ups and land in a circle between the tanks.</p>
      </div>
      <div class="tab" data-pane="casual" hidden>
        <p class="muted">Unrated. Any map, and every power-up in the crates. Try things out without risking your rank.</p>
        <label class="field">Map
          <select id="casualMap"><option value="any">Any map</option><?php foreach (MAPS as $id => $mp): ?><option value="<?= h($id) ?>"><?= h($mp['name']) ?></option><?php endforeach; ?></select>
        </label>
      </div>
      <div class="tab" data-pane="private" hidden>
        <div id="roomNone">
          <p class="muted">Unrated. Open a room, send your friends the code, and start when 2–4 of you are in.</p>
          <div class="room-set" id="roomSet">
            <label class="field">Map <select name="map"><option value="random">Random</option><?php foreach (MAPS as $id => $mp): ?><option value="<?= h($id) ?>"><?= h($mp['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field">Power-ups <select name="items"><option value="all">All</option><option value="safe">Ranked-safe only</option><option value="off">Off (no crates)</option></select></label>
            <label class="field">Shot clock <select name="turn_seconds"><option value="20">20 s</option><option value="30">30 s</option><option value="40" selected>40 s</option><option value="60">60 s</option></select></label>
          </div>
          <button class="btn big" id="roomCreate" <?= $active ? 'disabled' : '' ?>>Open a room</button>
          <div class="join-row">
            <input id="roomCode" maxlength="6" placeholder="CODE" autocomplete="off" autocapitalize="characters" aria-label="Invite code">
            <button class="btn ghost" id="roomJoin" <?= $active ? 'disabled' : '' ?>>Join</button>
          </div>
        </div>
        <div id="roomIn" hidden>
          <div class="room-code"><span class="muted small">INVITE CODE</span><b id="roomCodeShow"></b><button class="link" id="roomCopy" type="button">Copy</button></div>
          <div class="invite">
            <button class="btn" id="roomInvite" type="button">Invite friends</button>
            <input id="roomLink" readonly aria-label="Invite link" onclick="this.select()">
          </div>
          <p class="muted small" id="inviteMsg" hidden></p>
          <ul class="room-members" id="roomMembers"></ul>
          <div class="room-set" id="roomSetIn"></div>
          <p class="muted small" id="roomHint"></p>
          <div class="room-btns"><button class="btn" id="roomStart" hidden>Start battle</button><button class="btn ghost" id="roomLeave">Leave room</button></div>
        </div>
      </div>

      <div class="tab" data-pane="ai" hidden>
        <p class="muted">Practise against computer players. Unrated: ratings, wins and the leaderboard never change.
          Played to ranked rules unless you change the power-ups: ranked-safe power-ups.</p>
        <div class="room-set" id="aiSet">
          <label class="field">Map <select name="map">
            <option value="ranked">Random ranked map</option>
            <optgroup label="Ranked pool"><?php foreach (ranked_pool() as $id): ?><option value="<?= h($id) ?>"><?= h(MAPS[$id]['name']) ?></option><?php endforeach; ?></optgroup>
            <optgroup label="Other maps"><?php foreach (array_diff(array_keys(MAPS), ranked_pool()) as $id): ?><option value="<?= h($id) ?>"><?= h(MAPS[$id]['name']) ?></option><?php endforeach; ?></optgroup>
          </select></label>
          <label class="field">Skill <select name="level"><?php foreach (AI_LEVELS as $k => $lv): ?><option value="<?= h($k) ?>"<?= $k === 'veteran' ? ' selected' : '' ?>><?= h($lv['name']) ?></option><?php endforeach; ?></select></label>
          <label class="field">Power-ups <select name="items"><option value="safe">Ranked-safe</option><option value="all">All</option><option value="off">Off (no crates)</option></select></label>
        </div>
        <div class="seg" id="aiBots"><button data-n="1" class="on">1 AI</button><button data-n="2">2 AI</button><button data-n="3">3 AI</button></div>
        <button class="btn big" id="aiStart" <?= $active ? 'disabled' : '' ?>>Start battle</button>
      </div>

      <div id="queueBox">
        <div class="seg" id="modeSeg">
          <button data-mode="2" class="on">1 v 1</button><button data-mode="3">3 tanks</button><button data-mode="4">4 tanks</button>
        </div>
        <div class="queue-counts muted small" id="qCounts">&nbsp;</div>
        <button class="btn big" id="findBtn" <?= $active ? 'disabled' : '' ?>>Find match</button>
        <div class="searching" id="searching" hidden>
          <div class="radar"><i></i></div>
          <div>
            <b id="sTitle">Searching…</b>
            <div class="muted small" id="sInfo"></div>
          </div>
          <button class="btn ghost" id="cancelBtn">Cancel</button>
        </div>
      </div>
      <p class="err" id="mmErr" <?= isset($_GET['invite_error']) ? '' : 'hidden' ?>><?php
        $ie = (string)($_GET['invite_error'] ?? '');
        echo $ie === '' ? '' : h(['no_room' => "That invite has expired: the room was closed or the battle already started.",
                                  'room_full' => 'That room is already full (4 players).'][$ie] ?? "Couldn't join that room."); ?></p>
    </div>

    <div class="panel card">
      <h2>Practice</h2>
      <p class="muted">Hot-seat and CPU battles in your browser. No rating on the line.</p>
      <a class="btn ghost" href="practice.html">Play offline</a>
    </div>
  </section>

  <section class="col">
    <div class="panel card">
      <h2>Leaderboard</h2>
      <table class="tbl">
        <thead><tr><th>#</th><th>Commander</th><th>Rank</th><th class="r">Rating</th><th class="r">W / M</th></tr></thead>
        <tbody>
        <?php foreach ($top as $i => $row): $rk = rank_for((int)$row['rating']); ?>
          <tr class="<?= (int)$row['id'] === (int)$u['id'] ? 'me' : '' ?>">
            <td><?= $i + 1 ?></td><td><?= h(display_name($row)) ?></td>
            <td style="color:<?= h($rk['color']) ?>"><?= h($rk['name']) ?></td>
            <td class="r"><?= (int)$row['rating'] ?></td><td class="r"><?= (int)$row['wins'] ?> / <?= (int)$row['games_played'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel card">
      <h2>Your recent matches</h2>
      <?php if (!$recent): ?><p class="muted">No matches yet.</p><?php else: ?>
      <table class="tbl">
        <thead><tr><th>Match</th><th>Map</th><th>Place</th><th class="r">Kills</th><th class="r">Damage</th><th class="r">Rating</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): $d = (int)$r['rating_after'] - (int)$r['rating_before']; $rated = ($r['game_mode'] ?? 'ranked') === 'ranked'; ?>
          <tr>
            <td><?= (int)$r['mode'] === 2 ? '1 v 1' : (int)$r['mode'] . ' tanks' ?><?= $rated ? '' : ' <span class="muted small">' . h(GAME_MODES[$r['game_mode']] ?? '') . '</span>' ?></td>
            <td><?= h(MAPS[$r['map_id'] ?? 'highlands']['name'] ?? 'Highlands') ?></td>
            <td><?= $ord((int)$r['placement']) ?></td>
            <td class="r"><?= (int)$r['kills'] ?></td><td class="r"><?= (int)$r['damage_dealt'] ?></td>
            <?php if ($rated): ?><td class="r <?= $d >= 0 ? 'up' : 'down' ?>"><?= $d >= 0 ? '+' : '' ?><?= $d ?></td><?php else: ?><td class="r muted">–</td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>
</main>
<script src="assets/lobby.js?v=4"></script>
<?php page_foot();
