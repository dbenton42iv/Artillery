<?php
declare(strict_types=1);
/*
 * Scenarios (phase 5): the co-op missions.
 *   GET  ?action=list                       -> {"missions": [...], "stars": n, "max_stars": n}
 *   POST ?action=start {"mission": id}      -> {"match_id"}   play a mission alone
 * To play with friends, open a private room with a mission (api/room.php, setting "mission").
 * Admins may add "test": true to play any mission, drafts included, without saving progress or XP.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

$action = $_GET['action'] ?? 'list';
$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
if (!$write && $action === 'list') {
    $list = missions_for($u);
    json_out(['missions' => $list, 'stars' => array_sum(array_column($list, 'stars')), 'max_stars' => 3 * count($list), 'admin' => is_admin($u)]);
}
if ($write && $action === 'start') {
    $res = scenario_start_solo($u, json_body());
    json_out($res, isset($res['error']) ? 409 : 200);
}
json_out(['error' => 'bad_action'], 400);
