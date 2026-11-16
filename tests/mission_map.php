<?php
/*
 * Command-line only. Draws a mission's battlefield from above as a PNG: the ground shaded by height, water, a grid
 * every 20 m (labelled), and everything the mission places (players' starts, enemies with their wave, objects,
 * rivers, the convoy route). For authoring and checking campaign missions.
 *   php tests/mission_map.php <slug | map:seed[:size]> out.png [scale]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8, 'move_per_turn' => 10, 'regen_per_turn' => 3];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/engine.php';
require dirname(__DIR__) . '/includes/campaign.php';
$arg = $argv[1] ?? 'highlands:1'; $out = $argv[2] ?? '/tmp/map.png'; $px = (float)($argv[3] ?? 3);
$def = null;
foreach (CAMPAIGN as $c) if ($c['slug'] === $arg) $def = $c;
if (!$def) { $p = explode(':', $arg); $def = ['map' => $p[0], 'seed' => (int)($p[1] ?? 1), 'size' => (int)($p[2] ?? 160), 'starts' => [], 'enemies' => []]; }
[$d, $err] = mission_clean($def);
foreach ($err as $e) fwrite(STDERR, "! $e\n");
$t0 = microtime(true);
$gen = generate_mission_map($d);
fwrite(STDERR, sprintf("generated in %.2f s\n", microtime(true) - $t0));
$t = $gen['terrain']; $n = $t->n; $half = $t->half; $W = (int)ceil($n * ENG_CELL * $px);
$im = imagecreatetruecolor($W, $W);
$lo = min($t->H); $hi = max($t->H);
for ($y = 0; $y < $W; $y++) for ($x = 0; $x < $W; $x++) {
    $wx = -$half + $x / $px; $wz = -$half + $y / $px; $h = $t->heightAt($wx, $wz);
    $dx = $t->heightAt($wx + 1, $wz) - $t->heightAt($wx - 1, $wz); $dz = $t->heightAt($wx, $wz + 1) - $t->heightAt($wx, $wz - 1);
    $shade = max(.35, min(1.25, 1 - ($dx - $dz) * .18));
    if ($h < $t->water) { $c = [40, 90, 140]; $shade = 1; }
    else { $k = ($h - $lo) / max(1, $hi - $lo); $c = [90 + 120 * $k, 130 + 90 * $k, 70 + 80 * $k]; }
    imagesetpixel($im, $x, $y, imagecolorallocate($im, (int)min(255, $c[0] * $shade), (int)min(255, $c[1] * $shade), (int)min(255, $c[2] * $shade)));
}
$P = fn($x, $z) => [(int)(($x + $half) * $px), (int)(($z + $half) * $px)];
$grid = imagecolorallocatealpha($im, 255, 255, 255, 95); $txt = imagecolorallocate($im, 255, 255, 255);
for ($g = -floor($half / 20) * 20; $g <= $half; $g += 20) {
    [$a] = $P($g, 0); imageline($im, $a, 0, $a, $W, $grid); imageline($im, 0, $a, $W, $a, $grid);
    imagestring($im, 1, $a + 2, 2, (string)$g, $txt); imagestring($im, 1, 2, $a + 2, (string)$g, $txt);
}
foreach ($d['rivers'] as $r) { [$a, $b] = $P($r['a'][0], $r['a'][1]); [$c, $e] = $P($r['b'][0], $r['b'][1]); imageline($im, $a, $b, $c, $e, imagecolorallocate($im, 120, 200, 255)); }
if ($d['convoy']) { $pts = $d['convoy']['path']; for ($k = 1; $k < count($pts); $k++) { [$a, $b] = $P(...$pts[$k - 1]); [$c, $e] = $P(...$pts[$k]); imagesetthickness($im, 2); imageline($im, $a, $b, $c, $e, imagecolorallocate($im, 255, 140, 0)); } }
foreach ($gen['objects'] as $o) {
    if ($o['type'] === 'crate') { [$a, $b] = $P($o['x'], $o['z']); imagefilledrectangle($im, $a - 3, $b - 3, $a + 3, $b + 3, imagecolorallocate($im, 240, 180, 40)); continue; }
    [$a, $b] = $P($o['x'] - $o['hw'], $o['z'] - $o['hd']); [$c, $e] = $P($o['x'] + $o['hw'], $o['z'] + $o['hd']);
    $col = ($o['kind'] ?? '') === 'ruin' ? [150, 150, 150] : (($o['kind'] ?? '') === 'target' ? [255, 60, 200] : (($o['kind'] ?? '') === 'depot' ? [60, 255, 120] : [200, 200, 220]));
    imagefilledrectangle($im, $a, $b, $c, $e, imagecolorallocate($im, ...$col));
}
foreach ($gen['starts'] as $k => $p) { [$a, $b] = $P($p[0], $p[2]); imagefilledellipse($im, $a, $b, 12, 12, imagecolorallocate($im, 60, 140, 255)); imagestring($im, 2, $a + 7, $b - 6, 'P' . ($k + 1) . sprintf(' %.0f', $p[1]), $txt); }
foreach ($gen['enemies'] as $k => $p) { $e = $d['enemies'][$k]; [$a, $b] = $P($p[0], $p[2]); imagefilledellipse($im, $a, $b, $e['kind'] === 'behemoth' ? 22 : 12, $e['kind'] === 'behemoth' ? 22 : 12, imagecolorallocate($im, 230, 50, 50));
    imagestring($im, 2, $a + 7, $b - 6, ($e['kind'] === 'gun' ? 'G' : 'E') . ($k + 1) . 'r' . $e['round'] . sprintf(' %.0f', $p[1]), $txt); }
imagepng($im, $out);
fwrite(STDERR, "water {$t->water}, heights " . round($lo, 1) . '..' . round($hi, 1) . "\n");
