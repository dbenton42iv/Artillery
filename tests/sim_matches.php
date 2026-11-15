<?php
/*
 * Command-line only. Plays full matches with the real engine (no database) and writes what the server
 * would store: the starting terrain, every event, and the final state. tests/replay_parity.js then replays
 * those events the way the browser does and checks they agree. tests/crate_gate.php reads the same file.
 *   php tests/sim_matches.php [seed] [turns] [seeds per map] > /tmp/sims.json
 *
 * Every map is played with 2, 3 and 4 tanks, both as Ranked (ranked-safe power-ups, fair crates) and as
 * Casual (every power-up, crates anywhere). Tanks shoot at enemies, buildings and crates, and use every
 * power-up they pick up (shell power-ups are fired; Double Shot fires twice).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8, 'move_per_turn' => 10, 'regen_per_turn' => 3];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/engine.php';

function resolve_deaths_sim(array &$players, int $turn): array
{
    $dead = [];
    foreach ($players as $s => &$p) if ($p['alive'] && $p['hp'] <= 0) { $p['alive'] = false; $p['death_turn'] = $turn; $dead[] = $s; }
    return $dead;
}
/** Aim roughly at a point, with noise so some shells miss, splash or hit buildings and crates. */
function aim_at(array $me, float $tx, float $tz, array $rules): array
{
    $dx = $tx - $me['x']; $dz = $tz - $me['z']; $dist = hypot($dx, $dz);
    $yaw = fmod(rad2deg(atan2($dx, -$dz)) + 360 + (rnd() - .5) * 10, 360);
    $elev = 30 + rnd() * 40;
    $v = sqrt($dist * $rules['gravity'] / max(.2, sin(2 * deg2rad($elev))));
    return [$yaw, $elev, min(100.0, max(10.0, $v / ENG_VMAX * 100 * (0.92 + rnd() * .16)))];
}
$jsonClean = fn($x) => json_decode(json_encode($x, JSON_PRESERVE_ZERO_FRACTION), true);

$seed = (int)($argv[1] ?? 1234);
$turns = (int)($argv[2] ?? 44);
$perMap = (int)($argv[3] ?? 1);
$out = [];
foreach (array_keys(MAPS) as $mi => $mapId) foreach ([2, 3, 4] as $np) foreach (['ranked', 'casual'] as $gm) for ($rep = 0; $rep < $perMap; $rep++) {
    mt_srand($seed + $mi * 1000 + $np * 100 + ($gm === 'casual' ? 50 : 0) + $rep * 7);
    FX::reset();
    $gen = generate_map($mapId, $np);
    $t = $gen['terrain']; $rules = $gen['rules']; $objects = $gen['objects'];
    $rules['mode'] = $gm; $rules['ranked'] = $gm === 'ranked'; $rules['items'] = $gm === 'ranked' ? 'safe' : 'all';
    $players = [];
    foreach ($gen['spawns'] as $s => $p) $players[$s] = ['seat' => $s, 'x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'hp' => 100, 'alive' => true,
                                                        'last_hit_by' => null, 'damage_dealt' => 0, 'death_turn' => null, 'item' => null];
    $initial = base64_encode($t->pack());
    $objects0 = $objects;
    [$wa, $ws] = initial_wind($rules);
    $events = []; $seat = 0; $turn = 1; $stats = ['drops' => 0, 'claims' => 0, 'uses' => [], 'moves' => 0, 'stops' => []]; $fair = [];
    $rules['move'] = 10.0;
    $advance = function () use (&$players, &$seat, &$turn, &$wa, &$ws, &$events, &$objects, &$fair, &$stats, $t, $rules, $np, $jsonClean) {
        $prev = $seat;
        do { $seat = ($seat + 1) % $np; } while (!$players[$seat]['alive']);
        $old = $turn; $turn++;
        if (isset(FX::$e['winds'][$turn])) { [$wa, $ws] = FX::$e['winds'][$turn]; unset(FX::$e['winds'][$turn]); }
        else next_wind($wa, $ws, $rules, $old, $turn);
        FX::$e['double'] = null; FX::$e['moved'] = null;
        $fx = start_turn($t, $players, $objects, $rules, $turn, $prev, $seat);
        if ($fx && isset($fx['regen'])) $stats['regen'] = ($stats['regen'] ?? 0) + $fx['regen']['heal'];
        if ($fx) {
            foreach ($fx['crates'] ?? [] as $c) {
                $stats['drops']++;
                $fair[] = ['x' => $c['x'], 'z' => $c['z'], 'turn' => $turn,
                           'tanks' => array_values(array_map(fn($p) => [$p['x'], $p['z']], array_filter($players, fn($p) => $p['alive'])))];
            }
            $fx['deaths'] = resolve_deaths_sim($players, $turn);
            $fx['wind'] = wind_vec($wa, $ws);
            $events[] = ['type' => 'turn', 'seat' => $seat, 'turn' => $turn, 'data' => $jsonClean($fx)];
            if (!$players[$seat]['alive'] && count(array_filter($players, fn($p) => $p['alive'])) > 1) {
                do { $seat = ($seat + 1) % $np; } while (!$players[$seat]['alive']);
            }
        }
    };
    while ($turn <= $turns) {
        if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) break;
        // drive: half the time, one or two hops toward random points (some far off, so allowance, slopes and obstacles all get hit)
        if (rnd() < .5) for ($hop = 0, $left = move_budget($rules); $hop < 2 && $left >= DRIVE_MIN; $hop++) {
            $me = $players[$seat]; $a = rnd() * M_PI * 2; $d = rnd() * 25;
            $tx = $me['x'] + sin($a) * $d; $tz = $me['z'] - cos($a) * $d;
            $blk = drive_blockers($players, $objects, $seat);
            $raw = drive_path($t, $blk, (float)$me['x'], (float)$me['z'], $tx, $tz, $left);
            $ev = drive_tank($t, $players, $objects, $seat, $tx, $tz, $left);
            $stats['stops'][$raw['stop']] = ($stats['stops'][$raw['stop']] ?? 0) + 1;
            if (isset($ev['error'])) break;
            $left -= $ev['used']; $stats['moves']++;
            $ev['req'] = ['x0' => $me['x'], 'z0' => $me['z'], 'tx' => $tx, 'tz' => $tz, 'budget' => $left + $ev['used'], 'blk' => $blk,
                          'raw' => ['x' => $raw['x'], 'z' => $raw['z'], 'used' => $raw['used'], 'stop' => $raw['stop'], 'n' => count($raw['path'])]];
            $events[] = ['type' => 'move', 'seat' => $seat, 'turn' => $turn, 'data' => $jsonClean($ev)];
            if (rnd() < .5) break;
        }
        $me = $players[$seat]; $item = $me['item']; $wk = null;
        // use a held power-up
        if ($item && POWERUPS[$item]['kind'] !== 'shell' && rnd() < .8) {
            $r = item_effect($t, $players, $objects, $seat, $item, $turn);
            if (!isset($r['error'])) {
                $players[$seat]['item'] = null; $stats['uses'][$item] = ($stats['uses'][$item] ?? 0) + 1;
                if ($item === 'weather') {           // pre-roll the wind exactly as forecast_winds() does
                    $alive = count(array_filter($players, fn($p) => $p['alive'])); $a = $wa; $sp = $ws;
                    for ($k = $turn + 1; $k <= $turn + 2 * $alive; $k++) {
                        if (isset(FX::$e['winds'][$k])) { [$a, $sp] = FX::$e['winds'][$k]; continue; }
                        next_wind($a, $sp, $rules, $k - 1, $k); FX::$e['winds'][$k] = [$a, $sp];
                    }
                }
                $events[] = ['type' => 'item', 'seat' => $seat, 'turn' => $turn, 'data' => $jsonClean($r['event'])];
                if ($r['ends_turn']) { $advance(); continue; }
            }
        } elseif ($item && POWERUPS[$item]['kind'] === 'shell' && rnd() < .7) { $wk = $item; }
        $shots = FX::$e['double'] && FX::$e['double']['seat'] === $seat ? 2 : 1;
        for ($k = 0; $k < $shots; $k++) {
            $me = $players[$seat];
            if (!$me['alive'] || count(array_filter($players, fn($p) => $p['alive'])) <= 1) break;
            $foes = array_values(array_filter($players, fn($p) => $p['alive'] && $p['seat'] !== $seat));
            $foe = $foes[mt_rand(0, count($foes) - 1)]; $tx = $foe['x']; $tz = $foe['z'];
            $crates = array_values(array_filter($objects, fn($o) => $o['type'] === 'crate' && $o['state'] === 'live'));
            $standing = array_values(array_filter($objects, fn($o) => $o['type'] === 'building' && $o['hp'] > 0));
            if ($crates && rnd() < .45) { $c = $crates[mt_rand(0, count($crates) - 1)]; $tx = $c['x']; $tz = $c['z']; }
            elseif ($standing && rnd() < .4) { $o = $standing[mt_rand(0, count($standing) - 1)]; $tx = $o['x']; $tz = $o['z']; }
            [$yaw, $elev, $power] = aim_at($me, $tx, $tz, $rules);
            $w = $wk && $k === 0 ? $wk : ['shell', 'shell', 'heavy', 'cluster'][mt_rand(0, 3)];
            if ($w === $wk) { $players[$seat]['item'] = null; $stats['uses'][$w] = ($stats['uses'][$w] ?? 0) + 1; }
            FX::$e['range'] = array_values(array_diff(FX::$e['range'], [$seat]));
            $wind = wind_vec($wa, $ws);
            $res = simulate_shot($t, $players, $objects, $seat, $yaw, $elev, $power, $w, $wind, $rules);
            foreach ($res['projectiles'] as $pr) foreach ($pr['boom']['crates'] ?? [] as $c) if ($c['by'] !== null) $stats['claims']++;
            $res['deaths'] = resolve_deaths_sim($players, $turn);
            $res['aim'] = compact('yaw', 'elev', 'power') + ['weapon' => $w];
            $res['wind'] = $wind;
            if ($shots === 2 && $k === 0) { $res['double'] = 1; FX::$e['double']['shots'] = 1; }
            $events[] = ['type' => 'shot', 'seat' => $seat, 'turn' => $turn, 'data' => $jsonClean($res)];
        }
        if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) break;
        $advance();
    }
    $out[] = ['map' => $mapId, 'players_n' => $np, 'game_mode' => $gm, 'rules' => $rules, 'initial' => $initial, 'final' => base64_encode($t->pack()),
              'spawns' => $gen['spawns'], 'objects' => $objects0, 'objects_final' => $objects,
              'players_final' => array_values(array_map(fn($p) => ['hp' => $p['hp'], 'x' => round($p['x'], 3), 'y' => round($p['y'], 3), 'z' => round($p['z'], 3),
                                                                  'alive' => $p['alive'], 'item' => $p['item']], $players)),
              'events' => $events, 'crate_drops' => $fair, 'stats' => $stats + ['turns' => $turn]];
}
echo json_encode($out, JSON_PRESERVE_ZERO_FRACTION);
