<?php
declare(strict_types=1);
/*
 * Fire! Body: {"match_id", "turn_no", "yaw", "elev", "power", "weapon"}
 * Only the aim is sent; the server flies the shell and decides the result.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$in = json_body();
$res = take_shot((int)($in['match_id'] ?? 0), (int)$u['id'], $in);
json_out($res, isset($res['error']) ? 409 : 200);
