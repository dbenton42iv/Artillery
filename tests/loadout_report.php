<?php
/*
 * Sums up tests/loadout_bench.php output: win rate for every doctrine, perk, preset and full build (a draw counts
 * as half a win), with a 95% margin. A build "passes" when its win rate is at most 55%.
 *   php tests/loadout_report.php /tmp/bench/*.jsonl [--top 12] [--json]
 * Builds whose rate is above 55% but with too few games to be sure are listed as "check": run them through
 * `loadout_bench.php vs KEY 400` to confirm.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$top = 12; $json = false; $files = [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--top') $top = (int)$argv[++$i];
    elseif ($argv[$i] === '--json') $json = true;
    else $files[] = $argv[$i];
}
$S = ['build' => [], 'doctrine' => [], 'perk' => [], 'preset' => []];
// [games, score, expected score]: in 1 v 1 an average build scores 0.5 a game; with n tanks, 1/n
$add = function (string $kind, string $k, float $score, float $exp = .5) use (&$S) { $S[$kind][$k] ??= [0, 0.0, 0.0]; $S[$kind][$k][0]++; $S[$kind][$k][1] += $score; $S[$kind][$k][2] += $exp; };
$ffa = false;
$games = 0; $draws = 0; $turns = 0;
foreach ($files as $f) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true); if (!$r) continue;
    $games++; $turns += $r['turns']; if ($r['w'] === null) $draws++;
    if (isset($r['los'])) {                                         // free-for-all: 3 or 4 tanks
        $ffa = true; $n = count($r['los']);
        foreach ($r['los'] as $s => $k) {
            $score = $r['w'] === null ? 1 / $n : ($r['w'] === $s ? 1.0 : 0.0);
            $add('build', $k, $score, 1 / $n); $parts = explode('+', $k);
            $add('doctrine', $parts[0], $score, 1 / $n);
            foreach (array_slice($parts, 1) as $p) $add('perk', $p, $score, 1 / $n);
            if ($r['presets'][$s]) $add('preset', $r['presets'][$s], $score, 1 / $n);
        }
        continue;
    }
    foreach (['a' => 'b', 'b' => 'a'] as $me => $them) {
        if ($r[$me] === $r[$them]) continue;                         // a mirror match says nothing about the build
        $score = $r['w'] === null ? .5 : ($r['w'] === $me ? 1.0 : 0.0);
        $add('build', $r[$me], $score);
        $parts = explode('+', $r[$me]);
        $add('doctrine', $parts[0], $score);
        foreach (array_slice($parts, 1) as $p) $add('perk', $p, $score);
        if ($r['p' . $me]) $add('preset', $r['p' . $me], $score);
    }
}
$rows = function (string $kind) use ($S) {
    $out = [];
    foreach ($S[$kind] as $k => [$n, $w, $e]) {
        // as a 1 v 1-equivalent rate: wins relative to the fair share, scaled so that fair = 50% (exact in 1 v 1)
        $p = $w / $n; $m = 1.96 * sqrt(max($p * (1 - $p), .0625 / max(1, $n)) / $n);
        $f = .5 * $n / $e;                                          // 1 in 1 v 1; 1.5 with 3 tanks; 2 with 4
        $out[] = ['key' => $k, 'n' => $n, 'rate' => $p * $f, 'margin' => $m * $f, 'share' => $w / $e];
    }
    usort($out, fn($a, $b) => $b['rate'] <=> $a['rate']);
    return $out;
};
$report = ['games' => $games, 'draws' => $draws, 'avg_turns' => $games ? $turns / $games : 0];
foreach (['doctrine', 'perk', 'preset', 'build'] as $k) $report[$k] = $rows($k);
if ($json) { echo json_encode($report, JSON_PRETTY_PRINT), "\n"; exit; }

printf("%d matches (%d draws), %.1f turns on average%s\n", $games, $draws, $report['avg_turns'],
    $ffa ? "\nFree-for-all: rates are wins vs the fair share, scaled so that 50% is fair (55% = 1.1× the fair share)" : '');
$show = function (string $title, array $rs, int $limit = 99) {
    echo "\n$title\n";
    foreach (array_slice($rs, 0, $limit) as $r) {
        $flag = $r['rate'] <= .55 ? '' : ($r['rate'] - $r['margin'] > .55 ? '  OVER 55%' : '  check');
        printf("  %-36s %5d games  %5.1f%% ± %4.1f%s\n", $r['key'], $r['n'], 100 * $r['rate'], 100 * $r['margin'], $flag);
    }
};
$show('Doctrines', $report['doctrine']);
$show('Perks', $report['perk']);
$show('Presets', $report['preset']);
$show("Best $top builds", $report['build'], $top);
$over = array_filter($report['build'], fn($r) => $r['rate'] - $r['margin'] > .55);
$check = array_filter($report['build'], fn($r) => $r['rate'] > .55 && $r['rate'] - $r['margin'] <= .55);
printf("\nBuilds over 55%% for sure: %d. Above 55%% but not sure yet (run `vs` on them): %d.\n", count($over), count($check));
