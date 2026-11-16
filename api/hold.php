<?php
declare(strict_types=1);
/*
 * Hold fire: end your turn without firing. Only while your tank is invisible (the Invisibility power-up), so it stays
 * hidden. Body: {"match_id", "turn_no"}
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$in = json_body();
$res = hold_fire((int)($in['match_id'] ?? 0), (int)$u['id'], $in);
json_out($res, isset($res['error']) ? 409 : 200);
