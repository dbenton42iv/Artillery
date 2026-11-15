<?php
declare(strict_types=1);
/*
 * Matchmaking queue.
 *   POST ?action=join   {"mode":2|3|4, "kind":"ranked"|"casual", "map":"any"|map id (casual only)}
 *   POST ?action=leave
 *   GET  ?action=status  -> runs the matchmaker, returns queue state or the new match id
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';
require dirname(__DIR__) . '/includes/rooms.php';

$action = $_GET['action'] ?? 'status';
$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
$uid = (int)$u['id'];
$now = time();

if ($write && $action === 'join') {
    $b = json_body();
    $mode = (int)($b['mode'] ?? 0);
    $kind = ($b['kind'] ?? 'ranked') === 'casual' ? 'casual' : 'ranked';
    $map = $kind === 'casual' && isset(MAPS[$b['map'] ?? '']) ? $b['map'] : null;
    if (!in_array($mode, [2, 3, 4], true)) json_out(['error' => 'bad_mode'], 400);
    if ($mid = active_match_for($uid)) json_out(['error' => 'in_match', 'match_id' => $mid], 409);
    room_leave($uid);
    db()->prepare('REPLACE INTO ' . T('queue') . ' (user_id, mode, kind, map_pref, rating, joined_at, last_poll, match_id) VALUES (?,?,?,?,?,?,?,NULL)')
        ->execute([$uid, $mode, $kind, $map, (int)$u['rating'], $now, $now]);
    run_matchmaker($mode, $kind);
    $action = 'status';
} elseif ($write && $action === 'leave') {
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$uid]);
    json_out(['queued' => false]);
}

if ($action !== 'status') json_out(['error' => 'bad_action'], 400);

$st = db()->prepare('SELECT mode, kind, map_pref, joined_at, match_id FROM ' . T('queue') . ' WHERE user_id = ?');
$st->execute([$uid]);
$row = $st->fetch();
if ($row && $row['match_id'] === null) {
    db()->prepare('UPDATE ' . T('queue') . ' SET last_poll = ? WHERE user_id = ?')->execute([$now, $uid]);
    run_matchmaker((int)$row['mode'], $row['kind']);
    $st->execute([$uid]);
    $row = $st->fetch();
}

$counts = ['ranked' => [2 => 0, 3 => 0, 4 => 0], 'casual' => [2 => 0, 3 => 0, 4 => 0]];
foreach (db()->query('SELECT kind, mode, COUNT(*) c FROM ' . T('queue') . ' WHERE match_id IS NULL AND last_poll > ' . ($now - MM_STALE) . ' GROUP BY kind, mode') as $c) {
    if (isset($counts[$c['kind']])) $counts[$c['kind']][(int)$c['mode']] = (int)$c['c'];
}

if ($row && $row['match_id'] !== null) {
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ?')->execute([$uid]);
    json_out(['queued' => false, 'match_id' => (int)$row['match_id'], 'counts' => $counts]);
}
if (!$row) json_out(['queued' => false, 'counts' => $counts, 'active_match' => active_match_for($uid)]);

$waited = $now - (int)$row['joined_at'];
json_out(['queued' => true, 'mode' => (int)$row['mode'], 'kind' => $row['kind'], 'map' => $row['map_pref'], 'waited' => $waited, 'window' => search_window($waited),
          'rating' => (int)$u['rating'], 'counts' => $counts]);
