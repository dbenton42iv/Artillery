<?php
/* Sums up tests/mission_bench.php output (stdin): per mission and player count, wins, rounds, shots, damage taken, stars. */
if (PHP_SAPI !== 'cli') exit;
$g = [];
while (($l = fgets(STDIN)) !== false) { $r = json_decode($l, true); if (!$r) continue; $g[$r['m'] . ' ×' . $r['n']][] = $r; }
printf("%-22s %4s %6s %8s %7s %7s %6s %6s\n", 'mission', 'runs', 'win%', 'rounds', 'shots', 'taken', 'stars', 'secs');
foreach ($g as $k => $rs) {
    $w = array_filter($rs, fn($r) => $r['out'] === 'win'); $n = count($rs);
    $avg = fn($a, $f) => count($a) ? array_sum(array_column($a, $f)) / count($a) : 0;
    $med = function ($a, $f) { $v = array_column($a, $f); sort($v); return $v ? $v[intdiv(count($v), 2)] : 0; };
    printf("%-22s %4d %5.0f%% %8.1f %7s %7s %6.2f %6.1f\n", $k, $n, 100 * count($w) / $n, $avg($rs, 'rounds'),
        $w ? sprintf('%.0f', $med($w, 'shots')) : '-', $w ? sprintf('%.0f', $med($w, 'taken')) : '-', $avg($rs, 'stars'), $avg($rs, 'secs'));
    $outs = array_count_values(array_column($rs, 'out')); if (isset($outs['stuck'])) echo "   stuck: {$outs['stuck']}\n";
}
