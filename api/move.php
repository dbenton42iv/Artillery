<?php
declare(strict_types=1);
/*
 * Drive your tank before you fire. Body: {"match_id", "turn_no", "x", "z"}
 * The tank heads in a straight line toward (x, z) and stops when it gets there, when this turn's driving
 * allowance runs out, or at a slope, water, the map edge, another tank, a building or a crate.
 * Doesn't end the turn. Reply: {"ok", "event_id", "left"} (metres still allowed this turn).
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$in = json_body();
$res = drive((int)($in['match_id'] ?? 0), (int)$u['id'], $in);
json_out($res, isset($res['error']) ? 409 : 200);
