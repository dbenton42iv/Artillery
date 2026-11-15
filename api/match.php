<?php
declare(strict_types=1);
/*
 * Match state, polled about once a second by the game page.
 *   GET ?id=MATCH&since=LAST_EVENT_ID[&load=1]   load=1 also returns the terrain heightmap
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';
require dirname(__DIR__) . '/includes/ai.php';

$u = api_user(false);
$mid = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT seat FROM ' . T('match_players') . ' WHERE match_id = ? AND user_id = ?');
$st->execute([$mid, $u['id']]);
$seat = $st->fetchColumn();
if ($seat === false) json_out(['error' => 'not_in_match'], 404);

db()->prepare('UPDATE ' . T('match_players') . ' SET last_seen = ? WHERE match_id = ? AND seat = ?')->execute([time(), $mid, $seat]);
ai_act($mid, isset($_GET['shown']) ? (int)$_GET['shown'] : null);   // computer players take their turn (vs AI matches only)
check_timeout($mid);

$load = !empty($_GET['load']);
db()->beginTransaction();   // one consistent read: terrain, players and events all from the same moment
$snap = match_snapshot($mid, $load ? PHP_INT_MAX : max(0, (int)($_GET['since'] ?? 0)), $load, (int)$seat);
db()->commit();
$snap['me'] = (int)$seat;
json_out($snap);
