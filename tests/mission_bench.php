<?php
/*
 * Phase 5: plays campaign missions with computer stand-ins for the players, through the real engine and the same
 * computer-player routine the server uses (ai_mission_decide()), to check that each mission can be won and to tune
 * the round limits and star thresholds. No database.
 *
 *   php tests/mission_bench.php SLUG|all [runs] [players] [level] [seed]
 * level: how well the stand-ins aim (rookie, veteran or ace; veteran by default). Players carry the Rifleman preset.
 * Prints one JSON line per run: {"m", "n", "out", "rounds", "shots", "taken", "stars", "turns", "secs"}.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8, 'move_per_turn' => 10, 'regen_per_turn' => 3];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require_once dirname(__DIR__) . '/includes/ai.php';
require dirname(__DIR__) . '/includes/campaign.php';

/** Play one mission. Returns the result line. $trace: also return every event (for replay checks). */
function play_mission(array $def, int $nh, string $level, bool $trace = false, ?int $seed = null): array
{
    $t0 = microtime(true);
    [$d, $err] = mission_clean($def);
    if ($err) throw new RuntimeException($def['slug'] . ': ' . implode(' ', $err));
    $gen = generate_mission_map($d, $nh); $t = $gen['terrain']; $objects = $gen['objects'];
    $initial = $t->pack();
    if ($seed !== null) mt_srand($seed);              // the battle's own random numbers, repeatable in tests
    $rules = mission_rules($d, $gen['extra'], 0);
    [$seats, $tanks] = mission_setup($d, $gen, $rules, $nh);
    $lo = ['preset' => 'rifleman']; $mods = skill_mods($lo);
    $players = [];
    foreach ($tanks as $s => $tk) {
        $bot = $s >= $nh;
        $p = ['seat' => $s, 'x' => $tk['x'], 'y' => $tk['y'], 'z' => $tk['z'], 'alive' => $tk['alive'], 'last_hit_by' => null, 'damage_dealt' => 0, 'kills' => 0,
              'item' => null, 'death_turn' => null, 'loadout' => $bot ? null : $lo, 'sk' => $bot ? [] : $mods, 'max_hp' => $bot ? MAX_HP : loadout_hp($lo)];
        $boss = $bot && $rules['kinds'][$s] === 'behemoth';
        $p['ammo_heavy'] = $boss ? 99 : (int)($p['sk']['heavy'] ?? START_AMMO['heavy']); $p['ammo_cluster'] = $boss ? 99 : (int)($p['sk']['cluster'] ?? START_AMMO['cluster']);
        $players[$s] = $p;
    }
    $players = seat_rules_sim($players, $rules);
    foreach ($players as &$p) $p['hp'] = max_hp($p);
    unset($p);
    FX::reset(); mission_fx_init($d, $seats, $players);
    $start = array_map(fn($p) => ['pos' => [$p['x'], $p['y'], $p['z']], 'hp' => $p['hp'], 'alive' => $p['alive']], $players);
    [$wa, $ws] = initial_wind($rules);
    $n = count($players); $seat = 0; $turn = 1; $events = []; $shots = array_fill(0, $nh, 0);
    $hpAfter = array_map(fn($p) => $p['hp'], $players);
    $out = null;
    $deaths = function () use (&$players, &$turn) { $dd = []; foreach ($players as $s => &$p) if ($p['alive'] && $p['hp'] <= 0) { $p['alive'] = false; $p['death_turn'] = $turn; $dd[] = $s; } return $dd; };
    $guard = 0;
    while ($out === null && $guard++ < 2000) {
        $me = $players[$seat];
        $L = $seat < $nh ? $level : $rules['bots'][$seat];
        $wind = wind_vec($wa, $ws);
        $fx = FX::$e + ['turn' => $turn];
        $dr = move_budget($rules) > 0 && empty($rules['nodrive'][$seat]) ? ['left' => move_budget($rules), 'hit' => $players[$seat]['hp'] < $hpAfter[$seat]] : null;
        for ($act = 0; $act < 6 && $out === null; $act++) {
            $plan = ai_decide($t, $players, $objects, $seat, $wind, $rules, $L, FX::$e + ['turn' => $turn], $dr);
            if (!empty($plan['move'])) {
                $ev = drive_tank($t, $players, $objects, $seat, $plan['move'][0], $plan['move'][1], $dr['left']); $dr = null;
                if ($trace && !isset($ev['error'])) $events[] = ['type' => 'move', 'seat' => $seat, 'turn' => $turn, 'data' => $ev];
                $out = mission_outcome($rules, $players, $objects);
                continue;
            }
            $dr = null;
            if (!empty($plan['use'])) {
                $item = $players[$seat]['item'];
                $res = item_effect($t, $players, $objects, $seat, $item, $turn);
                if (isset($res['error'])) { $players[$seat]['item'] = null; $events[] = ['type' => 'item', 'seat' => $seat, 'turn' => $turn, 'data' => ['item' => $item, 'dropped' => true]]; continue; }   // (tests only) can't be used: dropped
                $players[$seat]['item'] = null;
                if ($trace && !isset($res['error'])) $events[] = ['type' => 'item', 'seat' => $seat, 'turn' => $turn, 'data' => $res['event']];
                if (!empty($res['ends_turn'])) break;
                continue;
            }
            $w = $plan['weapon'];
            if ($w === 'flare') { if ((FX::$e['flare_ammo'][$seat] ?? 0) <= 0) $w = 'shell'; else FX::$e['flare_ammo'][$seat]--; }
            elseif (isset(POWERUPS[$w])) $players[$seat]['item'] = null;
            elseif ($w !== 'shell') { if ($players[$seat]['ammo_' . $w] <= 0) $w = 'shell'; else $players[$seat]['ammo_' . $w]--; }
            FX::$e['range'] = array_values(array_diff(FX::$e['range'], [$seat]));
            if (isset($plan['ranging'])) FX::$e['ranging'][$seat] = $plan['ranging'];
            if ($seat < $nh) $shots[$seat]++;
            $res = simulate_shot($t, $players, $objects, $seat, $plan['yaw'], $plan['elev'], min(max_power($players[$seat]), $plan['power']), $w, $wind, $rules);
            $res['deaths'] = $deaths();
            $res['aim'] = ['yaw' => $plan['yaw'], 'elev' => $plan['elev'], 'power' => $plan['power'], 'weapon' => $w];
            if ($trace) $events[] = ['type' => 'shot', 'seat' => $seat, 'turn' => $turn, 'data' => $res];
            $out = mission_outcome($rules, $players, $objects);
            $dbl = FX::$e['double'];
            if ($out === null && $dbl && $dbl['seat'] === $seat && $dbl['shots'] === 0 && $players[$seat]['alive']) { FX::$e['double']['shots'] = 1; continue; }
            break;
        }
        $hpAfter[$seat] = $players[$seat]['hp'];
        if ($out !== null) break;
        // next turn, as advance_or_finish() does
        $prev = $seat;
        do { $seat = ($seat + 1) % $n; } while (!$players[$seat]['alive']);
        $old = $turn; $turn++;
        if (isset(FX::$e['winds'][$turn])) { [$wa, $ws] = FX::$e['winds'][$turn]; unset(FX::$e['winds'][$turn]); } else next_wind($wa, $ws, $rules, $old, $turn);
        FX::$e['double'] = null; FX::$e['moved'] = null;
        $fxe = start_turn($t, $players, $objects, $rules, $turn, $prev, $seat);
        if ($fxe) { $fxe['deaths'] = $deaths(); if ($trace) $events[] = ['type' => 'turn', 'seat' => $seat, 'turn' => $turn, 'data' => $fxe + ['wind' => wind_vec($wa, $ws)]]; }
        $out = mission_outcome($rules, $players, $objects);
        if ($out === null && !$players[$seat]['alive']) do { $seat = ($seat + 1) % $n; } while (!$players[$seat]['alive']);
    }
    $out ??= 'stuck';
    $shotsEach = array_sum($shots) / $nh;
    $takenEach = array_sum(array_map(fn($s) => (int)(FX::$e['taken'][$s] ?? 0), range(0, $nh - 1))) / $nh;
    $stars = $out === 'stuck' ? [false, false, false] : mission_stars($rules, $out, $shotsEach, $takenEach);
    $r = ['m' => $def['slug'], 'n' => $nh, 'out' => $out, 'rounds' => (int)(FX::$e['round'] ?? 1), 'shots' => round($shotsEach, 1), 'taken' => round($takenEach, 1),
          'stars' => count(array_filter($stars)), 'turns' => $turn, 'secs' => round(microtime(true) - $t0, 1)];
    if ($trace) $r['trace'] = ['rules' => $rules, 'events' => $events, 'objects0' => $gen['objects'], 'objects' => $objects, 'initial' => base64_encode($initial),
                               'final' => base64_encode($t->pack()), 'players' => $players, 'start' => $start];
    return $r;
}
/** seat_rules() from game.php, which needs the database to load. */
function seat_rules_sim(array $players, array $rules): array
{
    foreach ($players as $s => &$p) {
        $p['team'] = (int)($rules['teams'][$s] ?? $s);
        if (isset($rules['mods'][$s])) $p['sk'] = $rules['mods'][$s] + $p['sk'];
        if (isset($rules['hp'][$s])) $p['max_hp'] = (int)$rules['hp'][$s];
    }
    return $players;
}

if (realpath($argv[0]) === __FILE__) {
    $which = $argv[1] ?? 'all'; $runs = (int)($argv[2] ?? 4); $nh = (int)($argv[3] ?? 1); $level = $argv[4] ?? 'veteran'; $seed = (int)($argv[5] ?? 1);
    foreach (CAMPAIGN as $mi => $def) {
        if ($which !== 'all' && $def['slug'] !== $which) continue;
        for ($k = 0; $k < $runs; $k++) {
            $r = play_mission($def, $nh, $level, false, $seed * 1000 + $mi * 97 + $k);
            echo json_encode($r), "\n";
        }
    }
}
