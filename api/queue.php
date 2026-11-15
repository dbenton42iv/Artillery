<?php
declare(strict_types=1);
/*
 * Matchmaking queue.
 *   POST ?action=join   {"mode":2|3|4}
 *   POST ?action=leave
 *   GET  ?action=status  -> runs the matchmaker, returns queue state or the new match id
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

$action = $_GET['action'] ?? 'status';
$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
$uid = (int)$u['id'];
$now = time();

if ($write && $action === 'join') {
    $mode = (int)(json_body()['mode'] ?? 0);
    if (!in_array($mode, [2, 3, 4], true)) json_out(['error' => 'bad_mode'], 400);
    if ($mid = active_match_for($uid)) json_out(['error' => 'in_match', 'match_id' => $mid], 409);
    db()->prepare('REPLACE INTO ' . T('queue') . ' (user_id, mode, rating, joined_at, last_poll, match_id) VALUES (?,?,?,?,?,NULL)')
        ->execute([$uid, $mode, (int)$u['rating'], $now, $now]);
    run_matchmaker($mode);
    $action = 'status';
} elseif ($write && $action === 'leave') {
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$uid]);
    json_out(['queued' => false]);
}

if ($action !== 'status') json_out(['error' => 'bad_action'], 400);

$st = db()->prepare('SELECT mode, joined_at, match_id FROM ' . T('queue') . ' WHERE user_id = ?');
$st->execute([$uid]);
$row = $st->fetch();
if ($row && $row['match_id'] === null) {
    db()->prepare('UPDATE ' . T('queue') . ' SET last_poll = ? WHERE user_id = ?')->execute([$now, $uid]);
    run_matchmaker((int)$row['mode']);
    $st->execute([$uid]);
    $row = $st->fetch();
}

$counts = [2 => 0, 3 => 0, 4 => 0];
foreach (db()->query('SELECT mode, COUNT(*) c FROM ' . T('queue') . ' WHERE match_id IS NULL AND last_poll > ' . ($now - MM_STALE) . ' GROUP BY mode') as $c) {
    $counts[(int)$c['mode']] = (int)$c['c'];
}

if ($row && $row['match_id'] !== null) {
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ?')->execute([$uid]);
    json_out(['queued' => false, 'match_id' => (int)$row['match_id'], 'counts' => $counts]);
}
if (!$row) json_out(['queued' => false, 'counts' => $counts, 'active_match' => active_match_for($uid)]);

$waited = $now - (int)$row['joined_at'];
json_out(['queued' => true, 'mode' => (int)$row['mode'], 'waited' => $waited, 'window' => search_window($waited),
          'rating' => (int)$u['rating'], 'counts' => $counts]);
