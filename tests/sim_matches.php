<?php
/*
 * Command-line only. Plays full matches on every map with the real engine (no database) and writes
 * what the server would store: the starting terrain, every event, and the final state.
 * tests/replay_parity.js then replays those events the way the browser does and checks they agree.
 *   php tests/sim_matches.php > /tmp/sims.json
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/engine.php';

function resolve_deaths_sim(array &$players, int $turn): array
{
    $dead = [];
    foreach ($players as $s => &$p) if ($p['alive'] && $p['hp'] <= 0) { $p['alive'] = false; $p['death_turn'] = $turn; $dead[] = $s; }
    return $dead;
}

$seed = (int)($argv[1] ?? 1234);
$turns = (int)($argv[2] ?? 44);
$out = [];
foreach (array_keys(MAPS) as $mi => $mapId) foreach ([2, 4] as $np) {
    mt_srand($seed + $mi * 10 + $np);
    $t0 = microtime(true);
    $gen = generate_map($mapId, $np);
    $t = $gen['terrain']; $rules = $gen['rules']; $objects = $gen['objects'];
    $genMs = (microtime(true) - $t0) * 1000;
    $players = [];
    foreach ($gen['spawns'] as $s => $p) $players[$s] = ['seat' => $s, 'x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'hp' => 100, 'alive' => true,
                                                        'last_hit_by' => null, 'damage_dealt' => 0, 'death_turn' => null];
    $initial = base64_encode($t->pack());
    $objects0 = $objects;
    [$wa, $ws] = initial_wind($rules);
    $events = []; $seat = 0; $turn = 1; $shotMs = 0; $shots = 0; $maxShot = 0;
    while ($turn <= $turns) {
        $alive = array_filter($players, fn($p) => $p['alive']);
        if (count($alive) <= 1) break;
        // aim roughly at a random enemy, with noise so some shells miss, splash or hit buildings
        $me = $players[$seat];
        $foes = array_values(array_filter($players, fn($p) => $p['alive'] && $p['seat'] !== $seat));
        $foe = $foes[mt_rand(0, count($foes) - 1)];
        $tx = $foe['x']; $tz = $foe['z'];
        $standing = array_values(array_filter($objects, fn($o) => $o['hp'] > 0));
        if ($standing && rnd() < .5) { $o = $standing[mt_rand(0, count($standing) - 1)]; $tx = $o['x']; $tz = $o['z']; }   // shoot buildings too
        $dx = $tx - $me['x']; $dz = $tz - $me['z']; $dist = hypot($dx, $dz);
        $yaw = fmod(rad2deg(atan2($dx, -$dz)) + 360 + (rnd() - .5) * 14, 360);
        $elev = 30 + rnd() * 40; $g = $rules['gravity'];
        $v = sqrt($dist * $g / max(.2, sin(2 * deg2rad($elev))));
        $power = min(100.0, max(10.0, $v / ENG_VMAX * 100 * (0.9 + rnd() * .2)));
        $wk = ['shell', 'shell', 'heavy', 'cluster'][mt_rand(0, 3)];
        $wind = wind_vec($wa, $ws);
        $s0 = microtime(true);
        $res = simulate_shot($t, $players, $objects, $seat, $yaw, $elev, $power, $wk, $wind, $rules);
        $ms = (microtime(true) - $s0) * 1000; $shotMs += $ms; $shots++; $maxShot = max($maxShot, $ms);
        $res['deaths'] = resolve_deaths_sim($players, $turn);
        $res['aim'] = compact('yaw', 'elev', 'power') + ['weapon' => $wk];
        $res['wind'] = $wind;
        $events[] = ['type' => 'shot', 'seat' => $seat, 'turn' => $turn, 'data' => json_decode(json_encode($res, JSON_PRESERVE_ZERO_FRACTION), true)];
        if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) break;
        do { $seat = ($seat + 1) % $np; } while (!$players[$seat]['alive']);
        $old = $turn; $turn++;
        next_wind($wa, $ws, $rules, $old, $turn);
        $s0 = microtime(true);
        $fx = start_turn($t, $players, $objects, $rules, $turn);
        $ms = (microtime(true) - $s0) * 1000; $maxShot = max($maxShot, $ms);
        if ($fx) {
            $fx['deaths'] = resolve_deaths_sim($players, $turn);
            $fx['wind'] = wind_vec($wa, $ws);
            $events[] = ['type' => 'turn', 'seat' => $seat, 'turn' => $turn, 'data' => json_decode(json_encode($fx, JSON_PRESERVE_ZERO_FRACTION), true)];
            if (!$players[$seat]['alive'] && count(array_filter($players, fn($p) => $p['alive'])) > 1) {
                do { $seat = ($seat + 1) % $np; } while (!$players[$seat]['alive']);
            }
        }
    }
    $out[] = ['map' => $mapId, 'players_n' => $np, 'rules' => $rules, 'initial' => $initial, 'final' => base64_encode($t->pack()),
              'spawns' => $gen['spawns'], 'objects' => $objects0, 'objects_final' => $objects,
              'players_final' => array_values(array_map(fn($p) => ['hp' => $p['hp'], 'y' => round($p['y'], 3), 'alive' => $p['alive']], $players)),
              'events' => $events, 'stats' => ['gen_ms' => round($genMs), 'avg_ms' => $shots ? round($shotMs / $shots) : 0,
              'max_ms' => round($maxShot), 'turns' => $turn, 'blob_kb' => round(strlen($t->pack()) / 1024)]];
}
echo json_encode($out, JSON_PRESERVE_ZERO_FRACTION);
