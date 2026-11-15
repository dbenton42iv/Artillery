<?php
declare(strict_types=1);
/*
 * Private matches by invite code.
 *   POST ?action=create  {"map","items","turn_seconds"}  -> {"code"}
 *   POST ?action=join    {"code"}
 *   POST ?action=update  {"map","items","turn_seconds"}  (host)
 *   POST ?action=start   (host, 2–4 players)              -> {"match_id"}
 *   POST ?action=leave
 *   GET  ?action=status  -> the room, or the match it started
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';
require dirname(__DIR__) . '/includes/rooms.php';

$action = $_GET['action'] ?? 'status';
$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
$uid = (int)$u['id'];
$body = $write ? json_body() : [];

if ($write && in_array($action, ['create', 'join', 'start'], true) && ($mid = active_match_for($uid))) {
    json_out(['error' => 'in_match', 'match_id' => $mid], 409);
}
$res = match (true) {
    $write && $action === 'create' => room_create($uid, $body),
    $write && $action === 'join'   => room_join($uid, (string)($body['code'] ?? '')),
    $write && $action === 'update' => room_update($uid, $body),
    $write && $action === 'start'  => room_start($uid),
    $write && $action === 'leave'  => (function () use ($uid) { room_leave($uid); return ['ok' => true]; })(),
    !$write && $action === 'status' => room_status($uid),
    default => ['error' => 'bad_action'],
};
json_out($res, isset($res['error']) ? 409 : 200);
