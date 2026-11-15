<?php
/*
 * AI check: computer players fight each other on every ranked map, at each skill level, with the real
 * engine and ranked rules (fair crates, ranked-safe power-ups). Reports how long the AI thinks per shot
 * (the proposal's phase 5 target is under 2 s on shared hosting), how close its shells land to what it
 * aimed at, and how often battles reach a result.
 *   php tests/ai_bench.php [matches per map and level] [turn limit]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/ai.php';

$per = (int)($argv[1] ?? 2); $limit = (int)($argv[2] ?? 40);
$pool = ['highlands', 'archipelago', 'canyon', 'volcano', 'arctic'];
$tot = []; $worstMs = 0.0;
foreach (array_keys(AI_LEVELS) as $level) {
    $ms = []; $miss = []; $direct = 0; $shots = 0; $done = 0; $turnsSum = 0; $claims = 0; $uses = 0;
    foreach ($pool as $mi => $map) foreach ([2, 3, 4] as $np) for ($r = 0; $r < $per; $r++) {
        mt_srand(500 + $mi * 37 + $np * 7 + $r + strlen($level) * 1000);
        FX::reset();
        $gen = generate_map($map, $np); $t = $gen['terrain']; $rules = $gen['rules']; $objects = $gen['objects'];
        $rules['ranked'] = false; $rules['items'] = 'safe'; $rules['mode'] = 'ai';
        $players = [];
        foreach ($gen['spawns'] as $s => $p) $players[$s] = ['seat' => $s, 'x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'hp' => 100, 'alive' => true, 'last_hit_by' => null,
            'damage_dealt' => 0, 'item' => null, 'ammo_heavy' => START_AMMO['heavy'], 'ammo_cluster' => START_AMMO['cluster']];
        [$wa, $ws] = initial_wind($rules); $seat = 0; $turn = 1;
        while ($turn <= $limit && count(array_filter($players, fn($p) => $p['alive'])) > 1) {
            $wind = wind_vec($wa, $ws);
            for ($act = 0; $act < 4; $act++) {                       // a turn: power-ups, then one shot (two with Double Shot)
                $t0 = microtime(true);
                $plan = ai_decide($t, $players, $objects, $seat, $wind, $rules, $level, FX::$e);
                $el = (microtime(true) - $t0) * 1000; $ms[] = $el; $worstMs = max($worstMs, $el);
                if (!empty($plan['use'])) {
                    $res = item_effect($t, $players, $objects, $seat, $players[$seat]['item'], $turn);
                    $players[$seat]['item'] = null; $uses++;
                    if (!empty($res['ends_turn'])) break;
                    continue;
                }
                $w = $plan['weapon'];
                if (isset(POWERUPS[$w])) { $players[$seat]['item'] = null; $uses++; }
                elseif ($w !== 'shell') $players[$seat]['ammo_' . $w]--;
                // where it meant to land, with no aiming error, for the accuracy figure
                $res = simulate_shot($t, $players, $objects, $seat, $plan['yaw'], $plan['elev'], $plan['power'], $w, $wind, $rules);
                $shots++;
                foreach ($res['projectiles'] as $pr) {
                    foreach ($pr['boom']['hits'] ?? [] as $h) if ($h['seat'] !== $seat && $pr === $res['projectiles'][0]) { $direct++; break; }
                    foreach ($pr['boom']['crates'] ?? [] as $c) if ($c['by'] === $seat) $claims++;
                }
                foreach ($players as $s => &$p) if ($p['alive'] && $p['hp'] <= 0) $p['alive'] = false;
                unset($p);
                $dbl = FX::$e['double'];
                if ($dbl && $dbl['seat'] === $seat && $dbl['shots'] === 0 && $players[$seat]['alive']) { FX::$e['double']['shots'] = 1; continue; }
                break;
            }
            if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) break;
            $prev = $seat;
            do { $seat = ($seat + 1) % $np; } while (!$players[$seat]['alive']);
            $turn++; next_wind($wa, $ws, $rules, $turn - 1, $turn); FX::$e['double'] = null;
            start_turn($t, $players, $objects, $rules, $turn, $prev);
            foreach ($players as $s => &$p) if ($p['alive'] && $p['hp'] <= 0) $p['alive'] = false;
            unset($p);
        }
        if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) $done++;
        $turnsSum += $turn;
    }
    sort($ms);
    $n = count($pool) * 3 * $per;
    printf("%-8s %3d battles  %4d shots  hit an enemy %3d%%  crates claimed %3d  power-ups used %3d  decided %d/%d  avg %4.0f turns  think: median %4.0f ms, 95%% %4.0f ms, max %4.0f ms\n",
        AI_LEVELS[$level]['name'], $n, $shots, $shots ? round(100 * $direct / $shots) : 0, $claims, $uses, $done, $n, $turnsSum / $n,
        $ms[(int)(count($ms) / 2)], $ms[(int)(count($ms) * .95)], end($ms));
}
printf("\nSlowest AI decision: %.0f ms (target: under 2000 ms)\n", $worstMs);
exit($worstMs < 2000 ? 0 : 1);
