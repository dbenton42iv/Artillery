<?php
declare(strict_types=1);
/*
 * A player's browser reports a script error (e.g. while replaying a shot). Body: {"match_id", "message", "stack"}
 * Stored in the errors table so problems on real devices can be found and fixed. At most 30 reports per player an hour.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$st = db()->prepare('SELECT COUNT(*) FROM ' . T('errors') . " WHERE source = 'client' AND user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
$st->execute([$u['id']]);
if ((int)$st->fetchColumn() >= 30) json_out(['ok' => true]);
$b = json_body();
$msg = substr((string)($b['message'] ?? ''), 0, 500) . "\n" . substr((string)($b['stack'] ?? ''), 0, 2500)
     . "\n" . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
$_SESSION['uid'] = (int)$u['id'];
log_problem('client', $msg, isset($b['match_id']) ? (int)$b['match_id'] : null);
json_out(['ok' => true]);
