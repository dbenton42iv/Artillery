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
            case 'restart':
                $msg[] = ['ok', sprintf('Restarted: %d match(es) ended, %d queue entr(ies) cleared, %d room(s) closed. %s',
                    admin_end_all(), admin_clear_queue(), admin_close_rooms(), $code())];
                break;
        }
        error_log('[artillery3d] admin ' . $u['username'] . ': ' . $do . (isset($_POST['match_id']) ? ' #' . (int)$_POST['match_id'] : ''));
    }
}
$st = admin_status();
$ago = function (int $s) { return $s < 60 ? "{$s}s" : ($s < 3600 ? intdiv($s, 60) . 'm' : intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm'); };
$confirm = fn(string $q) => 'onsubmit="return confirm(' . h(json_encode($q)) . ')"';
page_head('Admin', 'lobby'); topbar($u); ?>
<main class="admin">
  <?php foreach ($msg as [$k, $t]): ?><div class="<?= $k ?>"><?= h($t) ?></div><?php endforeach; ?>

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
