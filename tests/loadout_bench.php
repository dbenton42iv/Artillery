<?php
/*
 * Phase 4 gate: "no loadout wins more than 55% of ranked matches".
 *
 * Computer players of equal skill (Veteran) fight 1 v 1 to ranked rules on the ranked map pool (ranked-safe crates,
 * driving, heal over time, sudden death), each bringing a loadout. With aim equal, any difference in win rate comes
 * from the loadouts. Seats alternate, so moving first doesn't favour anyone.
 *
 * Information skills don't help a computer player that already sees the exact wind and flies trial shells, so the AI
 * stands in for them with a little less aiming error (ai_aim in skills.php: Sharpshooter ×0.88, Meteorologist ×0.95,
 * Quick Loader ×0.96, Spotter ×0.97). These are assumptions about what the information is worth to a person; real
 * ranked results (the admin page's "Loadouts in ranked") are the final word.
 *
 *   php tests/loadout_bench.php pop  N [seed]          N matches, both sides drawn at random from every build and preset
 *   php tests/loadout_bench.php vs   KEY N [seed]      KEY (e.g. heavy+loader+scavenger, or preset:gunner) against random builds
 *   php tests/loadout_bench.php pair KEY1 KEY2 N [seed]
 *   php tests/loadout_bench.php ffa  TANKS N [seed]     3 or 4 tanks, free-for-all, every tank on a random build or preset
 * Prints one JSON line per match: {"a": key, "b": key, "w": "a"|"b"|null, "turns": n}, or for ffa
 * {"los": [key per seat], "w": winning seat or null}. tests/loadout_report.php sums them up.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8, 'move_per_turn' => 10, 'regen_per_turn' => 3];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/ai.php';

/** 'preset:gunner' or 'heavy+loader+scavenger' → loadout. */
function key_loadout(string $k): array
{
    if (str_starts_with($k, 'preset:')) return ['preset' => substr($k, 7)];
    $parts = explode('+', $k);
    return parse_loadout(['doctrine' => array_shift($parts), 'perks' => $parts]) ?? throw new RuntimeException("bad loadout $k");
}
function pop_loadout(): array
{
    static $all = null;
    $all ??= array_merge(all_loadouts(), array_map(fn($p) => ['preset' => $p], array_keys(PRESETS)));
    return $all[mt_rand(0, count($all) - 1)];
}

/** One match to ranked rules. $los = [loadout per seat] (2–4 tanks). Returns [winning seat or null, turns]. */
function play(string $map, array $los, string $level = 'veteran', int $limit = 90): array
{
    FX::reset(); $np = count($los);
    $gen = generate_map($map, $np); $t = $gen['terrain']; $rules = $gen['rules']; $objects = $gen['objects'];
    $rules['ranked'] = true; $rules['items'] = 'safe'; $rules['mode'] = 'ranked'; $rules['move'] = 10.0; $rules['regen'] = 3;
    $players = [];
    foreach ($gen['spawns'] as $s => $p) {
        $mods = skill_mods($los[$s]);
        $players[$s] = ['seat' => $s, 'x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'alive' => true, 'last_hit_by' => null, 'damage_dealt' => 0, 'item' => null,
                        'loadout' => $los[$s], 'sk' => $mods, 'max_hp' => loadout_hp($los[$s]), 'hp' => loadout_hp($los[$s]),
                        'ammo_heavy' => (int)($mods['heavy'] ?? START_AMMO['heavy']), 'ammo_cluster' => (int)($mods['cluster'] ?? START_AMMO['cluster'])];
    }
    [$wa, $ws] = initial_wind($rules); $seat = 0; $turn = 1;
    $hpAfter = array_map(fn($p) => $p['hp'], $players);
    $dead = function () use (&$players, &$turn) { foreach ($players as &$p) if ($p['alive'] && $p['hp'] <= 0) { $p['alive'] = false; $p['death_turn'] = $turn; } };
    while ($turn <= $limit && count(array_filter($players, fn($p) => $p['alive'])) > 1) {
        $wind = wind_vec($wa, $ws);
        $dr = ['left' => 10.0, 'hit' => $players[$seat]['hp'] < $hpAfter[$seat]];
        for ($act = 0; $act < 5; $act++) {
            $plan = ai_decide($t, $players, $objects, $seat, $wind, $rules, $level, FX::$e, $dr);
            if (!empty($plan['move'])) { drive_tank($t, $players, $objects, $seat, $plan['move'][0], $plan['move'][1], $dr['left']); $dr = null; continue; }
            $dr = null;
            if (!empty($plan['use'])) {
                $item = $players[$seat]['item'];
                $res = item_effect($t, $players, $objects, $seat, $item, $turn);
                $players[$seat]['item'] = null;
                if (!empty($res['ends_turn'])) break;
                continue;
            }
            $w = $plan['weapon'];
            if (isset(POWERUPS[$w])) $players[$seat]['item'] = null;
            elseif ($w !== 'shell') { if ($players[$seat]['ammo_' . $w] <= 0) $w = 'shell'; else $players[$seat]['ammo_' . $w]--; }
            FX::$e['range'] = array_values(array_diff(FX::$e['range'], [$seat]));
            simulate_shot($t, $players, $objects, $seat, $plan['yaw'], $plan['elev'], min(max_power($players[$seat]), $plan['power']), $w, $wind, $rules);
            $dead();
            $dbl = FX::$e['double'];
            if ($dbl && $dbl['seat'] === $seat && $dbl['shots'] === 0 && $players[$seat]['alive']) { FX::$e['double']['shots'] = 1; continue; }
            break;
        }
        if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) break;
        $hpAfter[$seat] = $players[$seat]['hp'];
        $prev = $seat;
        do { $seat = ($seat + 1) % $np; } while (!$players[$seat]['alive']);
        $old = $turn; $turn++;
        if (isset(FX::$e['winds'][$turn])) { [$wa, $ws] = FX::$e['winds'][$turn]; unset(FX::$e['winds'][$turn]); } else next_wind($wa, $ws, $rules, $old, $turn);
        FX::$e['double'] = null; FX::$e['moved'] = null;
        start_turn($t, $players, $objects, $rules, $turn, $prev, $seat);
        $dead();
    }
    $alive = array_keys(array_filter($players, fn($p) => $p['alive']));
    return [count($alive) === 1 ? $alive[0] : null, $turn];
}

$cmd = $argv[1] ?? 'pop';
$pool = ['highlands', 'archipelago', 'canyon', 'volcano', 'arctic'];
if ($cmd === 'ffa') {
    $np = max(3, min(4, (int)($argv[2] ?? 3))); $n = (int)($argv[3] ?? 100); $seed = (int)($argv[4] ?? 1);
    for ($i = 0; $i < $n; $i++) {
        mt_srand($seed * 100003 + $i);
        $los = []; for ($k = 0; $k < $np; $k++) $los[] = pop_loadout();
        [$win, $turns] = play($pool[$i % count($pool)], $los);
        echo json_encode(['los' => array_map('loadout_key', $los), 'presets' => array_map(fn($l) => $l['preset'] ?? null, $los), 'w' => $win, 'turns' => $turns]), "\n";
    }
    exit;
}
[$n, $seed, $A, $B] = match ($cmd) {
    'pop'  => [(int)($argv[2] ?? 100), (int)($argv[3] ?? 1), null, null],
    'vs'   => [(int)($argv[3] ?? 100), (int)($argv[4] ?? 1), $argv[2], null],
    'pair' => [(int)($argv[4] ?? 100), (int)($argv[5] ?? 1), $argv[2], $argv[3]],
};
for ($i = 0; $i < $n; $i++) {
    mt_srand($seed * 100003 + $i);
    $la = $A ? key_loadout($A) : pop_loadout();
    $lb = $B ? key_loadout($B) : pop_loadout();
    $map = $pool[$i % count($pool)];
    $aSeat = $i % 2;                                  // alternate who shoots first
    $los = $aSeat === 0 ? [$la, $lb] : [$lb, $la];
    $t0 = microtime(true);
    [$win, $turns] = play($map, $los);
    $w = $win === null ? null : ($win === $aSeat ? 'a' : 'b');
    echo json_encode(['a' => loadout_key($la), 'b' => loadout_key($lb), 'pa' => $la['preset'] ?? null, 'pb' => $lb['preset'] ?? null,
                      'w' => $w, 'turns' => $turns, 'map' => $map, 's' => round(microtime(true) - $t0, 2)]), "\n";
}
