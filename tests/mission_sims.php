<?php
/*
 * Phase 5: plays every campaign mission (1, 2 and 4 players) through tests/mission_bench.php and writes the matches in
 * the format tests/replay_parity.js reads, so missions are checked for replay parity too: waves arriving, flares,
 * the Behemoth's weak points, walls and bridge spans, mission crates.
 *   php tests/mission_sims.php [seed] > /tmp/msims.json && node tests/replay_parity.js /tmp/msims.json
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$argv0 = $argv; $argv = ['x'];
require __DIR__ . '/mission_bench.php';
$seed = (int)($argv0[1] ?? 7); $out = [];
$clean = fn($x) => json_decode(json_encode($x, JSON_PRESERVE_ZERO_FRACTION), true);
foreach (CAMPAIGN as $mi => $def) foreach ([1, 2, 4] as $nh) {
    $r = play_mission($def, $nh, 'veteran', true, $seed + $mi * 10 + $nh);
    $tr = $r['trace'];
    $out[] = ['map' => $def['slug'], 'players_n' => count($tr['players']), 'game_mode' => 'scenario', 'rules' => $tr['rules'], 'initial' => $tr['initial'], 'final' => $tr['final'],
              'spawns' => array_values(array_map(fn($s) => $s['pos'], $tr['start'])), 'hp0' => array_values(array_column($tr['start'], 'hp')),
              'alive0' => array_values(array_column($tr['start'], 'alive')), 'objects' => $tr['objects0'], 'objects_final' => $tr['objects'],
              'players_final' => array_values(array_map(fn($p) => ['hp' => $p['hp'], 'x' => round($p['x'], 3), 'y' => round($p['y'], 3), 'z' => round($p['z'], 3),
                                                                  'alive' => $p['alive'], 'item' => $p['item']], $tr['players'])),
              'events' => $clean($tr['events']), 'result' => $r['out']];
}
echo json_encode($out, JSON_PRESERVE_ZERO_FRACTION);
