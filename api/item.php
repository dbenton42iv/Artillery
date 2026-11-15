<?php
declare(strict_types=1);
/*
 * Use the power-up you hold. Body: {"match_id", "turn_no"}
 * Repair Kit, Shield Bubble, Weather Report, Double Shot and Rangefinder don't cost the turn; Relocate does.
 * Bunker Buster, Napalm and Earthmover are fired through api/shot.php with weapon = the item id.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$in = json_body();
$res = use_item((int)($in['match_id'] ?? 0), (int)$u['id'], $in);
json_out($res, isset($res['error']) ? 409 : 200);
