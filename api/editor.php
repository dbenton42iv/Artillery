<?php
declare(strict_types=1);
/*
 * Mission editor (phase 5), admins only.
 *   GET  ?action=list                          -> {"missions": [{id, title, status, campaign, errors}]}
 *   GET  ?action=get&id=N                      -> {"mission": {id, title, status, campaign, def}, "errors"}
 *   POST ?action=preview {"def"}               -> the ground the mission would be played on: {n, half, water, terrain (float32, base64), objects, errors}
 *   POST ?action=save {"id"?, "def", "status"} -> {"id", "status", "errors", "def"}  (publishing needs a mission with no errors)
 *   POST ?action=delete {"id"}                 (not campaign missions: unpublish those instead)
 *   POST ?action=test {"id"}                   -> {"match_id"}  play the saved mission alone; no progress or XP
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

$action = $_GET['action'] ?? 'list';
$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
if (!is_admin($u)) json_out(['error' => 'not_admin'], 403);
$body = $write ? json_body() : [];
missions_for($u);                                      // makes sure the campaign is in the table
$res = match (true) {
    !$write && $action === 'list' => ['missions' => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title'], 'status' => $r['status'], 'campaign' => $r['campaign'],
                                         'map' => $r['def']['map'] ?? '', 'updated' => $r['updated_at'], 'errors' => mission_clean($r['def'])[1]], missions_all(true)),
                                      'maps' => array_map(fn($m) => $m['name'], MAPS), 'goals' => MISSION_GOALS, 'kinds' => MISSION_KINDS, 'objects' => MISSION_OBJECTS,
                                      'levels' => AI_LEVEL_NAMES, 'boss_wp' => BOSS_WP],
    !$write && $action === 'get' => (function () {
        $r = mission_row((int)($_GET['id'] ?? 0));
        return $r ? ['mission' => array_diff_key($r, ['def' => 1]) + ['def' => mission_clean($r['def'])[0]], 'errors' => mission_clean($r['def'])[1]] : ['error' => 'no_mission'];
    })(),
    $write && $action === 'preview' => mission_preview(is_array($body['def'] ?? null) ? $body['def'] : []),
    $write && $action === 'save' => mission_save($u, $body),
    $write && $action === 'delete' => mission_delete((int)($body['id'] ?? 0)),
    $write && $action === 'test' => scenario_start_solo($u, ['mission' => (int)($body['id'] ?? 0), 'test' => true]),
    default => ['error' => 'bad_action'],
};
json_out($res, isset($res['error']) ? 409 : 200);
