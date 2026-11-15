<?php
/*
 * Crate placement check: "crates fall at a random spot inside a 15 m circle around the point between the players".
 *   php tests/crate_gate.php [/tmp/sims.json] [seeds]
 *
 * The centre is worked out here independently of the engine: halfway between two tanks; for three or more,
 * the point the same distance from the first three (where their perpendicular bisectors cross), which is the
 * centre of the circle the tanks spawn on. Every crate must land within crate_radius of it, and the spots must
 * spread over the whole circle (for even spreading, half of them land beyond 71% of the radius).
 * Also checks sealed crates: nothing reveals what's inside before a claim, and ranked claims are ranked-safe.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/engine.php';

/** Independent centre: midpoint, or the circumcentre of the first three tanks. */
function centre_between(array $tanks): array
{
    if (count($tanks) === 2) return [($tanks[0][0] + $tanks[1][0]) / 2, ($tanks[0][1] + $tanks[1][1]) / 2];
    [[$ax, $az], [$bx, $bz], [$cx, $cz]] = $tanks;
    $d = 2 * ($ax * ($bz - $cz) + $bx * ($cz - $az) + $cx * ($az - $bz));
    $a2 = $ax * $ax + $az * $az; $b2 = $bx * $bx + $bz * $bz; $c2 = $cx * $cx + $cz * $cz;
    return [($a2 * ($bz - $cz) + $b2 * ($cz - $az) + $c2 * ($az - $bz)) / $d, ($a2 * ($cx - $bx) + $b2 * ($ax - $cx) + $c2 * ($bx - $ax)) / $d];
}
$R = CRATE_RADIUS; $fail = 0; $dists = [];
$check = function (array $tanks, float $x, float $z, string $where) use (&$fail, &$dists, $R) {
    $c = centre_between($tanks); $d = hypot($x - $c[0], $z - $c[1]); $dists[] = $d / $R;
    if ($d > $R + 1e-6) { $fail++; echo "FAIL  $where: crate " . round($d, 2) . " m from the centre (limit $R m)\n"; }
};

// ---- 1. drops recorded in simulated matches (every mode) ----
$file = $argv[1] ?? '/tmp/sims.json';
if (is_file($file)) {
    $sims = json_decode(file_get_contents($file), true); $n = 0;
    foreach ($sims as $m) foreach ($m['crate_drops'] as $c) { $n++; $check($c['tanks'], $c['x'], $c['z'], "{$m['map']} {$m['players_n']}p {$m['game_mode']} turn {$c['turn']}"); }
    printf("Simulated matches: %d crates dropped\n", $n);
    $leaks = 0; $claims = ['ranked' => [], 'casual' => []];
    foreach ($sims as $m) {
        foreach ($m['events'] as $e) {
            foreach ($e['data']['crates'] ?? [] as $c) if (isset($c['item'])) $leaks++;
            foreach (array_merge($e['data']['projectiles'] ?? [], $e['data']['bombs'] ?? []) as $pr)
                foreach ($pr['boom']['crates'] ?? [] as $c) {
                    if ($c['by'] === null && isset($c['item'])) $leaks++;
                    if ($c['by'] !== null) $claims[$m['game_mode']][] = $c['item'];
                }
        }
        foreach ($m['objects_final'] as $o) if ($o['type'] === 'crate' && $o['state'] !== 'claimed' && isset($o['item'])) $leaks++;
    }
    $unsafe = array_filter($claims['ranked'], fn($i) => !POWERUPS[$i]['safe']);
    $fail += $leaks + count($unsafe);
    printf("Sealed crates: %d leaks of contents before a claim; %d ranked claims, %d not ranked-safe\n", $leaks, count($claims['ranked']), count($unsafe));
}

// ---- 2. stress run: every map, 2–4 tanks, random survivors, cratered ground ----
$seeds = (int)($argv[2] ?? 20); $rows = [];
foreach (array_keys(MAPS) as $mi => $map) foreach ([2, 3, 4] as $np) {
    $drops = 0; $tries = 0;
    for ($s = 0; $s < $seeds; $s++) {
        mt_srand(9000 + $mi * 500 + $np * 50 + $s);
        FX::reset();
        $gen = generate_map($map, $np); $t = $gen['terrain']; $objects = $gen['objects']; $rules = $gen['rules']; $rules['items'] = 'all';
        $players = [];
        foreach ($gen['spawns'] as $k => $p) $players[$k] = ['seat' => $k, 'x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'hp' => 100, 'alive' => true, 'last_hit_by' => null, 'damage_dealt' => 0, 'item' => null];
        for ($k = 0; $k < 12; $k++) $t->crater((rnd() * 2 - 1) * $t->half * .7, (rnd() * 2 - 1) * $t->half * .7, 4 + rnd() * 7);
        settle($t, $players, $rules);
        if ($np > 2 && rnd() < .6) foreach ((array)array_rand($players, mt_rand(1, $np - 2)) as $d) $players[$d]['alive'] = false;
        for ($k = 0; $k < 4; $k++) {
            $tries++;
            $c = drop_crate($t, $players, $objects, $rules, 5 * ($k + 1));
            if (!$c) continue;
            $drops++;
            $tanks = array_values(array_map(fn($p) => [$p['x'], $p['z']], array_filter($players, fn($p) => $p['alive'])));
            $check($tanks, $c['x'], $c['z'], "$map {$np}p seed $s");
            $objects[count($objects) - 1]['state'] = 'claimed';         // taken, so the next turn can drop again
        }
    }
    $rows[] = sprintf('%-12s %dp  %3d of %3d drop turns produced a crate', $map, $np, $drops, $tries);
}
sort($dists); $n = count($dists);
echo "\nStress run (random survivors, cratered ground):\n" . implode("\n", $rows) . "\n";
printf("\nAll %d crates: distance from the centre as a share of the %g m radius: median %.2f (even spread: 0.71), farthest %.2f\n",
    $n, $R, $dists[(int)($n / 2)], end($dists));
$spread = abs($dists[(int)($n / 2)] - .71) < .06;
if (!$spread) { $fail++; echo "FAIL  crates don't spread evenly over the circle\n"; }
echo $fail ? "\nCHECK FAILED ($fail)\n" : "\nPASS: every crate fell inside the {$R} m circle between the tanks, spread evenly over it.\n";
exit($fail ? 1 : 0);
