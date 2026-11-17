<?php
declare(strict_types=1);
/*
 * Admin: the "restart" page. Lists running matches and can end them with no result, clear the matchmaking queue,
 * close open private rooms and reload PHP's code cache. Only for usernames listed under 'admins' in config.php.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/migrate.php';
require __DIR__ . '/includes/admin.php';
ensure_schema();

$u = require_login();
if (!is_admin($u)) { http_response_code(403); page_head('Admin', 'auth'); ?>
<div class="panel auth-card"><h1 class="logo">ADMIN</h1><p class="muted">This page is only for the site's admins. To make an account an admin, add its
username to <code>'admins' =&gt; ['yourname']</code> in <code>includes/config.php</code>.</p><a class="btn ghost" href="lobby.php">Back to the lobby</a></div>
<?php page_foot(); exit; }

$msg = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) $msg[] = ['err', 'Your session expired. Try again.'];
    else {
        $do = (string)($_POST['do'] ?? '');
        $code = function () { $r = admin_reload_code(); return $r === null ? 'This host has no PHP code cache, so new files are used straight away.' : ($r ? 'PHP code cache cleared: the files on disk are used from now on.' : 'The host refused to clear the PHP code cache.'); };
        switch ($do) {
            case 'end':
                $mid = (int)($_POST['match_id'] ?? 0);
                $msg[] = admin_end_match($mid) ? ['ok', "Match #$mid ended. No result, ratings unchanged."] : ['err', "Match #$mid wasn't running."];
                break;
            case 'end_all': $msg[] = ['ok', admin_end_all() . ' running match(es) ended. No results, ratings unchanged.']; break;
            case 'queue':   $msg[] = ['ok', admin_clear_queue() . ' matchmaking entr(ies) cleared.']; break;
            case 'rooms':   $msg[] = ['ok', admin_close_rooms() . ' open room(s) closed.']; break;
            case 'code':    $msg[] = ['ok', $code()]; break;
            case 'ban': case 'pause':
                $hours = $do === 'ban' ? null : (int)($_POST['hours'] ?? 0);
                if ($do === 'pause' && !isset(PAUSE_HOURS[$hours])) { $msg[] = ['err', 'Pick how long to pause for.']; break; }
                $err = admin_ban($u, (int)($_POST['user_id'] ?? 0), $hours, (string)($_POST['reason'] ?? ''));
                $msg[] = $err ? ['err', $err] : ['ok', ($do === 'ban' ? 'Banned' : 'Paused for ' . PAUSE_HOURS[$hours]) . '. They are signed out, withdrawn from any running battle and out of the queue and rooms.'];
                break;
            case 'unban':
                $msg[] = admin_unban((int)($_POST['user_id'] ?? 0)) ? ['ok', 'Access restored: they can log in again.'] : ['err', "That player wasn't banned or paused."];
                break;
            case 'restart':
                $msg[] = ['ok', sprintf('Restarted: %d match(es) ended, %d queue entr(ies) cleared, %d room(s) closed. %s',
                    admin_end_all(), admin_clear_queue(), admin_close_rooms(), $code())];
                break;
        }
        error_log('[artillery3d] admin ' . $u['username'] . ': ' . $do . (isset($_POST['match_id']) ? ' #' . (int)$_POST['match_id'] : '')
            . (isset($_POST['user_id']) ? ' user #' . (int)$_POST['user_id'] : '') . (isset($_POST['hours']) ? ' ' . (int)$_POST['hours'] . 'h' : ''));
    }
}
$st = admin_status();
$q = (string)($_GET['q'] ?? $_POST['q'] ?? '');
$pl = admin_players($q);
$left = function (array $r) { $s = (int)$r['ban_left'];
    return $s > 3 * 365 * 86400 ? 'Banned' : 'Paused, ' . ($s >= 172800 ? floor($s / 86400) . ' d ' . floor($s % 86400 / 3600) . ' h' : ($s >= 3600 ? floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min' : max(1, (int)ceil($s / 60)) . ' min')) . ' left'; };
$ago = function (int $s) { return $s < 60 ? "{$s}s" : ($s < 3600 ? intdiv($s, 60) . 'm' : intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm'); };
$confirm = fn(string $q) => 'onsubmit="return confirm(' . h(json_encode($q)) . ')"';
page_head('Admin', 'lobby'); topbar($u); ?>
<main class="admin">
  <?php foreach ($msg as [$k, $t]): ?><div class="<?= $k ?>"><?= h($t) ?></div><?php endforeach; ?>

  <section class="panel card">
    <h2>Missions</h2>
    <p class="muted small">Build co-op missions on any map, test-play them on your own, then publish them to the lobby's Missions tab.
      The seven campaign missions can be edited or unpublished there too.</p>
    <a class="btn" href="editor.php">Open the mission editor</a>
  </section>

  <section class="panel card">
    <h2>Restart the game</h2>
    <p class="muted small">There's no game server process to restart: each request runs PHP and reads the database. "Restart" ends every
      running match with no result (ratings, wins and matches played don't change), clears the matchmaking queue, closes open private rooms
      and makes PHP reload the game's files. Players on a battle page see "Ended by the site admin".</p>
    <div class="admin-stats">
      <div><b><?= count($st['matches']) ?></b><span>running matches</span></div>
      <div><b><?= $st['queue'] ?></b><span>searching for a match</span></div>
      <div><b><?= $st['rooms'] ?></b><span>open private rooms</span></div>
      <div><b><?= $st['opcache'] ? 'on' : 'off' ?></b><span>PHP code cache</span></div>
    </div>
    <form method="post" <?= $confirm('Restart the game? Every running match ends with no result, the queue is cleared and open rooms close.') ?>>
      <?= csrf_field() ?><input type="hidden" name="do" value="restart"><button class="btn danger">Restart everything</button>
    </form>
    <div class="admin-row">
      <form method="post" <?= $confirm('End every running match with no result?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="end_all"><button class="btn ghost">End all matches</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="queue"><button class="btn ghost">Clear the queue</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="rooms"><button class="btn ghost">Close open rooms</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="code"><button class="btn ghost" title="Use after uploading new files">Reload code</button></form>
    </div>
  </section>

  <section class="panel card">
    <h2>Running matches</h2>
    <?php if (!$st['matches']): ?><p class="muted">None.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>#</th><th>Mode</th><th>Map</th><th>Players</th><th class="r">Turn</th><th class="r">Started</th><th class="r">Last move</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($st['matches'] as $m): $stuck = $m['idle'] > 300 || ($m['online'] === 0 && $m['idle'] > 60); ?>
        <tr<?= $stuck ? ' class="stuck"' : '' ?>>
          <td><?= (int)$m['id'] ?></td>
          <td><?= h(GAME_MODES[$m['game_mode']] ?? $m['game_mode']) ?></td>
          <td><?= h(MAPS[$m['map_id'] ?? 'highlands']['name'] ?? (string)$m['map_id']) ?></td>
          <td><?= implode(', ', array_map(fn($p) => h(display_name($p)) . ($p['is_bot'] ? ' <span class="muted small">CPU</span>' : '')
                 . ' <span class="muted small">' . ($p['alive'] ? (int)$p['hp'] . ' hp' : 'out') . '</span>', $m['players'])) ?>
              <?php if (!$m['online']): ?><span class="muted small"> · nobody online</span><?php endif; ?></td>
          <td class="r"><?= (int)$m['turn_no'] ?></td>
          <td class="r"><?= $ago((int)$m['age']) ?> ago</td>
          <td class="r"><?= $ago($m['idle']) ?> ago<?= $stuck ? ' <span class="warn">stuck?</span>' : '' ?></td>
          <td class="r"><form method="post" <?= $confirm("End match #{$m['id']} with no result?") ?>><?= csrf_field() ?><input type="hidden" name="do" value="end">
            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>"><button class="btn ghost sm">End</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="panel card" id="players">
    <h2>Players: ban or pause</h2>
    <p class="muted small">A <b>pause</b> keeps a player out for a set time; a <b>ban</b> until you lift it. Either way they are signed out at once,
      can't log in (they're told why, with your reason if you give one), their tanks in running battles are withdrawn as if they had left,
      and they leave the queue and any private room. Their rating and history stay. Admins and computer players can't be banned.</p>
    <form method="get" class="admin-search" action="admin.php#players">
      <input name="q" value="<?= h($q) ?>" placeholder="Username or name" aria-label="Find a player">
      <button class="btn ghost">Find</button>
    </form>
    <?php
    $rows = $pl['found'];
    foreach ($pl['blocked'] as $b) if (!in_array((int)$b['id'], array_map(fn($r) => (int)$r['id'], $rows), true)) $rows[] = $b;
    if ($q !== '' && !$pl['found']): ?><p class="muted">No player matches "<?= h($q) ?>".</p><?php endif;
    if (!$rows): ?><p class="muted">Nobody is banned or paused. Find a player above.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl players">
      <thead><tr><th>Player</th><th class="r">Rating</th><th class="r">Matches</th><th>Last login</th><th>Status</th><th>Action</th></tr></thead>
      <tbody><?php foreach ($rows as $r): $self = (int)$r['id'] === (int)$u['id'] || is_admin($r); ?>
        <tr<?= $r['blocked'] ? ' class="stuck"' : '' ?>>
          <td><b><?= h($r['username']) ?></b><?= $r['alias'] ? ' <span class="muted small">' . h($r['alias']) . '</span>' : '' ?></td>
          <td class="r"><?= (int)$r['rating'] ?></td><td class="r"><?= (int)$r['games_played'] ?></td>
          <td class="small"><?= h($r['last_login'] ?? '–') ?></td>
          <td><?php if ($r['blocked']): ?><span class="warn"><?= h($left($r)) ?></span><div class="muted small"><?= h(trim(($r['ban_reason'] ? '“' . $r['ban_reason'] . '” ' : '') . 'by ' . $r['banned_by'])) ?></div>
              <?php else: ?><span class="muted">Active</span><?php endif; ?></td>
          <td><?php if ($self): ?><span class="muted small">Admin</span>
            <?php elseif ($r['blocked']): ?>
              <form method="post" action="admin.php#players"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>"><button class="btn ghost small-btn" name="do" value="unban">Restore access</button></form>
            <?php else: ?>
              <form method="post" action="admin.php#players" class="ban-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                <input name="reason" maxlength="200" placeholder="Reason (they see it)" aria-label="Reason">
                <select name="hours" aria-label="Pause for"><?php foreach (PAUSE_HOURS as $hr => $lbl): ?><option value="<?= $hr ?>"><?= h($lbl) ?></option><?php endforeach; ?></select>
                <button class="btn ghost small-btn" name="do" value="pause">Pause</button>
                <button class="btn danger small-btn" name="do" value="ban" <?= str_replace('onsubmit=', 'onclick=', $confirm('Ban ' . $r['username'] . '? They stay out until you restore access.')) ?>>Ban</button>
              </form>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="panel card">
    <h2>Loadouts in ranked</h2>
    <?php $los = admin_loadout_stats(); ?>
    <p class="muted small">How often each loadout wins finished ranked matches (a draw counts half). In 1 v 1 an average build wins 50%;
      with more tanks, "vs fair share" compares its wins with 1 in 3 or 1 in 4. The phase 4 gate is that no loadout wins more than 55%.
      Builds with fewer than 30 matches are too few to judge.</p>
    <?php if (!$los): ?><p class="muted">No finished ranked matches with loadouts yet.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Loadout</th><th class="r">Matches</th><th class="r">Wins</th><th class="r">Win rate</th><th class="r">vs fair share</th></tr></thead>
      <tbody><?php foreach (array_slice($los, 0, 20) as $l): $hot = $l['n'] >= 30 && $l['share'] > 1.1; ?>
        <tr<?= $hot ? ' class="stuck"' : '' ?>><td><?= h($l['name']) ?></td><td class="r"><?= $l['n'] ?></td><td class="r"><?= rtrim(rtrim(number_format($l['w'], 1), '0'), '.') ?></td>
          <td class="r"><?= round(100 * $l['rate']) ?>%</td><td class="r"><?= sprintf('%.2f×', $l['share']) ?><?= $hot ? ' <span class="warn">wins too often?</span>' : '' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="panel card">
    <h2>Recent problems</h2>
    <?php if (!$st['errors']): ?><p class="muted">None logged.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>When</th><th>From</th><th>Match</th><th>Message</th></tr></thead>
      <tbody><?php foreach ($st['errors'] as $e): ?>
        <tr><td class="nowrap"><?= h($e['created_at']) ?></td><td><?= h($e['source']) ?></td><td><?= $e['match_id'] ? (int)$e['match_id'] : '' ?></td><td class="msg"><?= h($e['message']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
</main>
<?php page_foot();
