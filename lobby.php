<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/game.php';

$u = require_login();
$rank = rank_for((int)$u['rating']);
$active = active_match_for((int)$u['id']);

$top = db()->query('SELECT id, username, alias, rating, games_played, wins FROM ' . T('users') . ' ORDER BY rating DESC, wins DESC, id LIMIT 25')->fetchAll();
$pos = db()->prepare('SELECT COUNT(*) + 1 FROM ' . T('users') . ' WHERE rating > ? OR (rating = ? AND (wins > ? OR (wins = ? AND id < ?)))');
$pos->execute([$u['rating'], $u['rating'], $u['wins'], $u['wins'], $u['id']]);
$myPos = (int)$pos->fetchColumn();

$recent = db()->prepare('SELECT m.id, m.mode, m.map_id, m.finished_at, p.placement, p.rating_before, p.rating_after, p.kills, p.damage_dealt
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
      <h2>Ranked match</h2>
      <p class="muted">You'll be paired with commanders near your rating. The longer you wait, the wider the search.</p>
      <p class="muted small">Map pool: <?= h(implode(' · ', array_map(fn($m) => MAPS[$m]['name'], ranked_pool()))) ?>. No map repeats until you've played them all this session.</p>
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
      <p class="err" id="mmErr" hidden></p>
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
      <?php if (!$recent): ?><p class="muted">No ranked matches yet.</p><?php else: ?>
      <table class="tbl">
        <thead><tr><th>Match</th><th>Map</th><th>Place</th><th class="r">Kills</th><th class="r">Damage</th><th class="r">Rating</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): $d = (int)$r['rating_after'] - (int)$r['rating_before']; ?>
          <tr>
            <td><?= (int)$r['mode'] === 2 ? '1 v 1' : (int)$r['mode'] . ' tanks' ?></td>
            <td><?= h(MAPS[$r['map_id'] ?? 'highlands']['name'] ?? 'Highlands') ?></td>
            <td><?= $ord((int)$r['placement']) ?></td>
            <td class="r"><?= (int)$r['kills'] ?></td><td class="r"><?= (int)$r['damage_dealt'] ?></td>
            <td class="r <?= $d >= 0 ? 'up' : 'down' ?>"><?= $d >= 0 ? '+' : '' ?><?= $d ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>
</main>
<script src="assets/lobby.js?v=1"></script>
<?php page_foot();
