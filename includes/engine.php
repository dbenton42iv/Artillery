<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/maps.php';
/*
 * Server-side game engine. The server is the referee: clients send only their aim (turret, angle,
 * power, weapon) and the server flies the shell, digs craters and applies damage. The browser
 * replays the recorded flight path, so every player sees exactly the same result.
 *
 * Terrain changes are recorded as an ordered "ops" list in each event. assets/terrain.js applies the
 * same ops with the same arithmetic, and both sides store heights as float32, so the browser's copy of
 * the heightmap stays identical to the server's (tests/replay_parity checks this for every map).
 * Constants must match assets/game.js and assets/terrain.js.
 */
const ENG_CELL = 1.5, ENG_VMAX = 75.0, ENG_STEP = 1 / 120, ENG_WIND_K = 0.4;
const ENG_TURRET_H = 2.05, ENG_BARREL = 3.2, ENG_TANK_HIT = 2.4, ENG_PATH_EVERY = 4;
const WEAPONS = [
    'shell'   => ['radius' => 6.0,  'dmg' => 45, 'split' => 0],
    'heavy'   => ['radius' => 11.0, 'dmg' => 70, 'split' => 0],
    'cluster' => ['radius' => 4.5,  'dmg' => 24, 'split' => 5],
];
const LAVA_BOMB = ['radius' => 5.5, 'dmg' => 30, 'split' => 0];
const START_AMMO = ['heavy' => 3, 'cluster' => 2];
const BUILDING_HP = 120;

function rnd(): float { return mt_rand() / mt_getrandmax(); }
function f32(float $v): float { return unpack('g', pack('g', $v))[1]; }
function smoothstep(float $a, float $b, float $x): float
{
    $t = min(1.0, max(0.0, ($x - $a) / ($b - $a)));
    return $t * $t * (3 - 2 * $t);
}

/** Value noise with a shuffled permutation table (seeded through mt_srand). */
final class Noise
{
    private array $P = [];
    public function __construct()
    {
        $p = range(0, 255); shuffle($p);
        for ($i = 0; $i < 512; $i++) $this->P[$i] = $p[$i & 255];
    }
    public function v(float $x, float $y): float
    {
        $xi = (int)floor($x); $yi = (int)floor($y); $xf = $x - $xi; $yf = $y - $yi; $P = $this->P;
        $hv = fn($i, $j) => $P[($P[($xi + $i) & 255] + (($yi + $j) & 255)) & 511] / 255;
        $u = $xf * $xf * (3 - 2 * $xf); $v = $yf * $yf * (3 - 2 * $yf);
        $a = $hv(0, 0); $b = $hv(1, 0); $c = $hv(0, 1); $d = $hv(1, 1);
        return $a + ($b - $a) * $u + ($c - $a) * $v + ($a - $b - $c + $d) * $u * $v;
    }
    public function fbm(float $x, float $y, int $oct = 5): float
    {
        $s = 0; $a = .5; $f = 1; $n = 0;
        for ($o = 0; $o < $oct; $o++) { $s += $a * $this->v($x * $f, $y * $f); $n += $a; $a *= .5; $f *= 2; }
        return $s / $n;
    }
}

final class Terrain
{
    /** @var float[] heights, row-major, (n+1)*(n+1), row j = z, column i = x, every value float32-exact */
    public array $H;
    public int $n;
    public int $w;
    public float $half;
    public float $water;

    public function __construct(array $H, int $n, ?float $water)
    {
        $this->H = $H; $this->n = $n; $this->w = $n + 1; $this->half = $n * ENG_CELL / 2;
        $this->water = $water ?? -100.0;
    }

    public function pack(): string { return pack('g*', ...$this->H); }   // little-endian float32
    public static function unpack(string $blob, ?float $water): self
    {
        $H = array_values(unpack('g*', $blob));
        return new self($H, (int)round(sqrt(count($H))) - 1, $water);
    }
    /** Round every height to float32 (after generation; ops round as they write). */
    public function roundAll(): void { $this->H = array_values(unpack('g*', pack('g*', ...$this->H))); }

    public function heightAt(float $x, float $z): float
    {
        $fx = ($x + $this->half) / ENG_CELL; $fz = ($z + $this->half) / ENG_CELL; $n = $this->n;
        if ($fx < 0 || $fz < 0 || $fx > $n || $fz > $n) return -50.0;
        $i = min($n - 1, (int)floor($fx)); $j = min($n - 1, (int)floor($fz));
        $u = $fx - $i; $v = $fz - $j; $w = $this->w; $H = $this->H;
        return $H[$j * $w + $i] * (1 - $u) * (1 - $v) + $H[$j * $w + $i + 1] * $u * (1 - $v)
             + $H[($j + 1) * $w + $i] * (1 - $u) * $v + $H[($j + 1) * $w + $i + 1] * $u * $v;
    }

    /** Index range covering a circle, one cell of margin. Same as box() in terrain.js. */
    private function box(float $cx, float $cz, float $R): array
    {
        $n = $this->n; $hf = $this->half;
        return [max(0, (int)floor(($cx - $R + $hf) / ENG_CELL) - 1), min($n, (int)ceil(($cx + $R + $hf) / ENG_CELL) + 1),
                max(0, (int)floor(($cz - $R + $hf) / ENG_CELL) - 1), min($n, (int)ceil(($cz + $R + $hf) / ENG_CELL) + 1)];
    }

    /* ---- ops: each mirrors terrain.js exactly and returns its op record ---- */

    public function crater(float $cx, float $cz, float $R, float $depth = .55): array
    {
        $w = $this->w; $R2 = $R * $R; [$i0, $i1, $j0, $j1] = $this->box($cx, $cz, $R);
        for ($j = $j0; $j <= $j1; $j++) for ($i = $i0; $i <= $i1; $i++) {
            $dx = -$this->half + $i * ENG_CELL - $cx; $dz = -$this->half + $j * ENG_CELL - $cz; $d2 = $dx * $dx + $dz * $dz;
            if ($d2 < $R2) { $k = $j * $w + $i; $this->H[$k] = f32(max(.3, $this->H[$k] - sqrt($R2 - $d2) * $depth)); }
        }
        return ['c', $cx, $cz, $R];
    }

    /** p = 2: rounded mound (rubble, Earthmover). p = 3: pointed cone (volcano). */
    public function raise(float $cx, float $cz, float $R, float $h, int $p = 2): array
    {
        $w = $this->w; $R2 = $R * $R; [$i0, $i1, $j0, $j1] = $this->box($cx, $cz, $R);
        for ($j = $j0; $j <= $j1; $j++) for ($i = $i0; $i <= $i1; $i++) {
            $dx = -$this->half + $i * ENG_CELL - $cx; $dz = -$this->half + $j * ENG_CELL - $cz; $d2 = $dx * $dx + $dz * $dz;
            if ($d2 < $R2) {
                $t = 1 - $d2 / $R2; $a = $h * $t * $t; if ($p === 3) $a *= $t;
                $k = $j * $w + $i; $this->H[$k] = f32($this->H[$k] + $a);
            }
        }
        return ['r', $cx, $cz, $R, $h, $p];
    }

    /** Snowfall: every hollow above $minH rises toward its neighbours' average, at most $cap per turn. */
    public function fill(float $rate, float $cap, float $minH, array $skips): array
    {
        $O = $this->H; $w = $this->w; $n = $this->n; $H = &$this->H;
        for ($j = 1; $j < $n; $j++) for ($i = 1; $i < $n; $i++) {
            $k = $j * $w + $i; $h = $O[$k];
            if ($h <= $minH) continue;
            $x = -$this->half + $i * ENG_CELL; $z = -$this->half + $j * ENG_CELL;
            foreach ($skips as $s) { $dx = $x - $s[0]; $dz = $z - $s[1]; if ($dx * $dx + $dz * $dz < $s[2] * $s[2]) continue 2; }
            $avg = ($O[$k - 1] + $O[$k + 1] + $O[$k - $w] + $O[$k + $w]) * .25;
            if ($avg > $h) { $d = ($avg - $h) * $rate; if ($d > $cap) $d = $cap; $H[$k] = f32($h + $d); }
        }
        return ['f', $rate, $cap, $minH, $skips];
    }

    /** Flatten a round pad (tank or building footprint) to its average height, blending out to 1.8×r. */
    public function flattenPad(float $cx, float $cz, float $r): void
    {
        $w = $this->w; $s = 0; $cnt = 0; $R = $r * 1.8;
        [$i0, $i1, $j0, $j1] = $this->box($cx, $cz, $R);
        for ($j = $j0; $j <= $j1; $j++) for ($i = $i0; $i <= $i1; $i++) {
            $x = -$this->half + $i * ENG_CELL; $z = -$this->half + $j * ENG_CELL;
            if (($x - $cx) ** 2 + ($z - $cz) ** 2 < $r * $r) { $s += $this->H[$j * $w + $i]; $cnt++; }
        }
        $ph = max($cnt ? $s / $cnt : 3, $this->water + 1.4);
        for ($j = $j0; $j <= $j1; $j++) for ($i = $i0; $i <= $i1; $i++) {
            $x = -$this->half + $i * ENG_CELL; $z = -$this->half + $j * ENG_CELL; $d = hypot($x - $cx, $z - $cz);
            if ($d < $R) { $t = 1 - smoothstep($r, $R, $d); $this->H[$j * $w + $i] += ($ph - $this->H[$j * $w + $i]) * $t; }
        }
    }
}

/* =====================================================================
 * Map generation. Each gen_<id>() returns [height function or grid, spawns, extra rules].
 * 1 v 1 and 3-tank matches use a 240 m map (160 cells); 4-tank matches use 360 m (240 cells).
 * ===================================================================== */

function map_cells(int $players): int { return $players >= 4 ? 240 : 160; }

/** Island edge: 1 inside, falling to 0 at the map's rim. */
function edge_falloff(Noise $nz, float $x, float $z, float $half): float
{
    $e = (abs($x) ** 4 + abs($z) ** 4) ** .25 / $half;
    return 1 - smoothstep(.74, .97, $e + $nz->fbm($x * .03 + 9, $z * .03) * .12 - .06);
}

/** n spawn points spread evenly around a ring. */
function ring_spawns(int $n, float $rMin, float $rMax): array
{
    $base = rnd() * M_PI * 2; $out = [];
    for ($k = 0; $k < $n; $k++) {
        $a = $base + $k * M_PI * 2 / $n + (rnd() - .5) * .35;
        $r = $rMin + rnd() * ($rMax - $rMin);
        $out[] = [sin($a) * $r, -cos($a) * $r];
    }
    return $out;
}

function gen_highlands(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $ang = rnd() * M_PI; $rx = cos($ang); $rz = sin($ang);
    $mH = 18 + rnd() * 20; $mLen = (35 + rnd() * 25) * $s; $ox = rnd() * 100; $oz = rnd() * 100;
    $f = function (float $x, float $z) use ($nz, $rx, $rz, $mH, $mLen, $ox, $oz, $half) {
        $h = $nz->fbm($x * .011 + $ox, $z * .011 + $oz) * 34 - 8;
        $along = $x * $rx + $z * $rz; $across = -$x * $rz + $z * $rx;
        $h += $mH * exp(-($across * $across) / (2 * 15 * 15)) * exp(-($along * $along) / (2 * $mLen * $mLen));
        return max(.3, .3 + ($h + 6) * edge_falloff($nz, $x, $z, $half));
    };
    return [$f, ring_spawns($players, 74 * $s, 86 * $s), []];
}

function gen_archipelago(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $base = rnd() * M_PI * 2;
    $k = min(5, max(3, $players + (rnd() < .5 ? 1 : 0)));
    if ($players === 2) $k = 3 + (rnd() < .5 ? 1 : 0);
    $isl = []; $spawns = [];
    for ($i = 0; $i < $players; $i++) {                      // one island per tank, evenly around the ring
        $a = $base + $i * M_PI * 2 / $players + (rnd() - .5) * .2; $r = (66 + rnd() * 10) * $s;
        $isl[] = [sin($a) * $r, -cos($a) * $r, (19 + rnd() * 5) * $s, 7 + rnd() * 5];
        $spawns[] = [sin($a) * ($r - 2), -cos($a) * ($r - 2)];
    }
    for ($j = 0; $j < $k - $players; $j++) {                 // extra islands in the gaps (or the middle)
        if ($j < $players && $players < 4) {
            $a = $base + ($j + .5) * M_PI * 2 / $players; $r = (30 + rnd() * 18) * $s;
            $isl[] = [sin($a) * $r, -cos($a) * $r, (14 + rnd() * 6) * $s, 6 + rnd() * 8];
        } else $isl[] = [0.0, 0.0, (16 + rnd() * 6) * $s, 8 + rnd() * 8];
    }
    $f = function (float $x, float $z) use ($nz, $isl) {
        $h = -3 + $nz->fbm($x * .02, $z * .02) * 2;
        foreach ($isl as $q => [$cx, $cz, $R, $ih]) {
            $d = hypot($x - $cx, $z - $cz) + ($nz->fbm($x * .05 + $q * 7, $z * .05) - .5) * $R * .5;
            if ($d >= $R) continue;
            $prof = 1 - smoothstep($R * .35, $R, $d);
            $h = max($h, -3 + $prof * ($ih + 3 + ($nz->fbm($x * .045 + 31, $z * .045 + $q) - .5) * 8));
        }
        return max(.3, $h);
    };
    return [$f, $spawns, []];
}

function gen_canyon(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $a = rnd() * M_PI; $rx = cos($a); $rz = sin($a); $gw = 24 * $s;
    $ox = rnd() * 100; $oz = rnd() * 100;
    $wig = fn(float $along) => ($nz->fbm($along * .02 + 3, 5.5, 3) - .5) * 18 * $s;
    $f = function (float $x, float $z) use ($nz, $rx, $rz, $gw, $ox, $oz, $half, $wig) {
        $along = $x * $rx + $z * $rz; $across = -$x * $rz + $z * $rx;
        $ac = abs($across - $wig($along));
        $plateau = 20 + $nz->fbm($x * .015 + $ox, $z * .015 + $oz) * 10;
        $g = smoothstep($gw * .35, $gw, $ac + ($nz->fbm($x * .08, $z * .08) - .5) * 6);
        $h = .6 + ($plateau - .6) * $g;
        $e = edge_falloff($nz, $x, $z, $half);
        return max(.3, $h * $e - 3 * (1 - $e));
    };
    // tanks alternate sides of the gorge: seat 0 north, seat 1 south, ...
    $bySide = [1 => [], -1 => []];
    for ($k = 0; $k < $players; $k++) $bySide[$k % 2 === 0 ? 1 : -1][] = $k;
    $spawns = [];
    foreach ($bySide as $side => $seats) {
        $m = count($seats);
        foreach ($seats as $q => $seat) {
            $along = $m === 1 ? (rnd() - .5) * 30 * $s : ($q === 0 ? -1 : 1) * (32 + rnd() * 10) * $s;
            $across = $wig($along) + $side * (55 + rnd() * 8) * $s;
            $spawns[$seat] = [$along * $rx - $across * $rz, $along * $rz + $across * $rx];
        }
    }
    ksort($spawns);
    return [$f, array_values($spawns), ['wind_axis' => atan2($rx, -$rz)]];
}

function gen_volcano(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $rR = 48 * $s; $wR = 9 * $s; $rc = 24 * $s;
    $f = function (float $x, float $z) use ($nz, $s, $rR, $wR, $rc, $half) {
        $r = hypot($x, $z);
        $h = 4 + $nz->fbm($x * .02, $z * .02) * 6;
        $h += (8 + $nz->fbm($x * .03 + 17, $z * .03) * 14) * exp(-(($r - $rR) ** 2) / (2 * $wR * $wR));   // ring of hills
        $h += 22 * max(0, 1 - $r / $rc) ** 1.6;                                                            // cone
        $h -= 6 * max(0, 1 - $r / (5 * $s)) ** 2;                                                          // vent
        return max(.3, .3 + ($h + 2) * edge_falloff($nz, $x, $z, $half));
    };
    return [$f, ring_spawns($players, 76 * $s, 84 * $s), ['vent' => [0.0, 0.0]]];
}

function gen_arctic(Noise $nz, int $n, float $half, int $players): array
{
    $a = rnd() * M_PI; $rx = cos($a); $rz = sin($a); $ox = rnd() * 100; $oz = rnd() * 100;
    $f = function (float $x, float $z) use ($nz, $rx, $rz, $ox, $oz, $half) {
        $h = 3.2 + $nz->fbm($x * .008 + $ox, $z * .008) * 1.6;
        $along = $x * $rx + $z * $rz; $across = -$x * $rz + $z * $rx;
        $h += 7.5 * max(0, $nz->fbm($along * .012 + 7, $across * .045 + $oz, 4) - .5);                  // drifts
        $c = abs($nz->fbm($x * .012 + $oz, $z * .012 + $ox, 4) - .5);                                    // cracks between sheets
        if ($c < .035) $h = .4 + ($h - .4) * smoothstep(.012, .035, $c);
        $e = edge_falloff($nz, $x, $z, $half);
        return max(.3, $h * $e - 3 * (1 - $e));
    };
    return [$f, ring_spawns($players, 74 * $half / 120, 86 * $half / 120), []];
}

function gen_city(Noise $nz, int $n, float $half, int $players): array
{
    $f = function (float $x, float $z) use ($nz, $half) {
        $h = 3.0 + $nz->fbm($x * .02, $z * .02) * .6;
        $e = edge_falloff($nz, $x, $z, $half);
        return max(.3, $h * $e - 3 * (1 - $e));
    };
    return [$f, ring_spawns($players, 74 * $half / 120, 86 * $half / 120), []];
}

function gen_lunar(Noise $nz, int $n, float $half, int $players): array
{
    $f = fn(float $x, float $z) => 5 + $nz->fbm($x * .01, $z * .01) * 3 + $nz->fbm($x * .05 + 40, $z * .05) * .6;
    return [$f, ring_spawns($players, 74 * $half / 120, 86 * $half / 120), []];
}

/** Lunar craters: bowls with raised rims, carved after the base plain. */
function lunar_craters(Terrain $t): void
{
    $s = $t->half / 120; $count = (int)round(22 * $s * $s); $w = $t->w;
    for ($c = 0; $c < $count; $c++) {
        $cx = (rnd() * 2 - 1) * $t->half * .95; $cz = (rnd() * 2 - 1) * $t->half * .95;
        $R = 4 + rnd() ** 2 * 16; $Rr = $R * 1.35;
        $i0 = max(0, (int)floor(($cx - $Rr + $t->half) / ENG_CELL)); $i1 = min($t->n, (int)ceil(($cx + $Rr + $t->half) / ENG_CELL));
        $j0 = max(0, (int)floor(($cz - $Rr + $t->half) / ENG_CELL)); $j1 = min($t->n, (int)ceil(($cz + $Rr + $t->half) / ENG_CELL));
        for ($j = $j0; $j <= $j1; $j++) for ($i = $i0; $i <= $i1; $i++) {
            $d = hypot(-$t->half + $i * ENG_CELL - $cx, -$t->half + $j * ENG_CELL - $cz) / $R;
            if ($d < 1) $t->H[$j * $w + $i] -= $R * .32 * (1 - $d * $d);
            if ($d > .75 && $d < 1.35) $t->H[$j * $w + $i] += $R * .09 * sin(($d - .75) / .6 * M_PI);
        }
    }
    foreach ($t->H as &$v) $v = max(.3, $v);
}

/** Ruined City: blocky buildings on a jittered grid, clear of the tank pads. */
function place_buildings(Terrain $t, array $spawns): array
{
    $objs = []; $step = 15.0; $lim = $t->half * .78; $max = $t->n >= 240 ? 40 : 26;
    for ($gz = -$lim; $gz <= $lim; $gz += $step) for ($gx = -$lim; $gx <= $lim; $gx += $step) {
        if (count($objs) >= $max || rnd() > .5) continue;
        $x = $gx + (rnd() - .5) * 4; $z = $gz + (rnd() - .5) * 4;
        if (hypot($x, $z) > $lim) continue;
        foreach ($spawns as $sp) if (hypot($x - $sp[0], $z - $sp[1]) < 19) continue 2;
        $hw = 3 + rnd() * 3; $hd = 3 + rnd() * 3; $bh = 7 + rnd() * 9;
        if ($t->heightAt($x, $z) < $t->water + .8) continue;
        $t->flattenPad($x, $z, max($hw, $hd) + 1);
        $objs[] = ['id' => count($objs), 'type' => 'building', 'x' => round($x, 2), 'z' => round($z, 2), 'y' => 0.0,
                   'hw' => round($hw, 2), 'hd' => round($hd, 2), 'h' => round($bh, 2), 'hp' => BUILDING_HP, 'max' => BUILDING_HP];
    }
    return $objs;
}

/**
 * Build a whole battlefield: terrain, tank positions, map objects and the rules stored on the match.
 * Returns ['terrain' => Terrain, 'spawns' => [[x,y,z]...], 'objects' => [...], 'rules' => [...]].
 */
function generate_map(string $mapId, int $players): array
{
    $R = MAPS[$mapId] ?? MAPS['highlands'];
    $n = map_cells($players); $half = $n * ENG_CELL / 2; $w = $n + 1;
    $nz = new Noise();
    [$f, $spawns, $extra] = ('gen_' . $mapId)($nz, $n, $half, $players);
    $H = [];
    for ($j = 0; $j <= $n; $j++) for ($i = 0; $i <= $n; $i++) $H[$j * $w + $i] = $f(-$half + $i * ENG_CELL, -$half + $j * ENG_CELL);
    $t = new Terrain($H, $n, $R['water']);
    if ($mapId === 'lunar') lunar_craters($t);
    foreach ($spawns as $sp) $t->flattenPad($sp[0], $sp[1], 4.5);
    $objects = !empty($R['buildings']) ? place_buildings($t, $spawns) : [];
    $t->roundAll();
    foreach ($objects as &$o) $o['y'] = round($t->heightAt($o['x'], $o['z']), 3);
    unset($o);
    $pos = array_map(fn($sp) => [$sp[0], $t->heightAt($sp[0], $sp[1]), $sp[1]], $spawns);

    $windMax = (float)cfg('wind_max', 8) <= 0 ? 0.0 : $R['wind'][1];
    $rules = [
        'map' => $mapId, 'n' => $n, 'gravity' => $R['gravity'], 'water' => $R['water'],
        'wind_min' => min($R['wind'][0], $windMax), 'wind_max' => $windMax,
        'wind_axis' => isset($extra['wind_axis']) && !empty($R['wind_axis']) ? round($extra['wind_axis'], 6) : null,
        'splash' => !empty($R['splash']), 'erupt_every' => (int)($R['erupt_every'] ?? 0), 'vent' => $extra['vent'] ?? null,
        'snow' => !empty($R['snow']), 'fall_mult' => (float)($R['fall_mult'] ?? 1.0), 'ranked' => true,
    ];
    return ['terrain' => $t, 'spawns' => $pos, 'objects' => $objects, 'rules' => $rules];
}

/** Starting wind for a match: [angle, speed]. */
function initial_wind(array $rules): array
{
    $min = (float)$rules['wind_min']; $max = (float)$rules['wind_max'];
    if ($max <= 0) return [0.0, 0.0];
    $ang = $rules['wind_axis'] !== null ? (float)$rules['wind_axis'] + (rnd() < .5 ? 0 : M_PI) : rnd() * M_PI * 2;
    return [$ang, $min + rnd() * ($max - $min) * .7];
}

/** Sudden-death wind bonus for a given turn. */
function sd_wind_bonus(array $rules, int $turn): float
{
    if ((float)$rules['wind_max'] <= 0 || $turn < SD_TURN) return 0.0;
    return SD_WIND * ($turn - SD_TURN + 1);
}

/** Shift the wind for the next turn: a random walk inside the map's range, plus any sudden-death bonus. */
function next_wind(float &$ang, float &$spd, array $rules, int $oldTurn, int $newTurn): void
{
    $min = (float)$rules['wind_min']; $max = (float)$rules['wind_max'];
    if ($max <= 0) { $spd = 0.0; return; }
    $base = $spd - sd_wind_bonus($rules, $oldTurn);
    if ($rules['wind_axis'] !== null) { if (rnd() < .2) $ang = fmod($ang + M_PI, M_PI * 2); }
    else $ang += (rnd() - .5) * .9;
    $base = min($max, max($min, $base + (rnd() - .5) * 4));
    $spd = min(SD_WIND_CAP, $base + sd_wind_bonus($rules, $newTurn));
}

function wind_vec(float $ang, float $spd): array { return [sin($ang) * $spd, -cos($ang) * $spd]; }

/* =====================================================================
 * Flight and explosions
 * ===================================================================== */
function r3(array $v): array { return [round($v[0], 3), round($v[1], 3), round($v[2], 3)]; }

function new_proj(array $pos, array $vel, float $t0, bool $bomblet): array
{
    return ['pos' => $pos, 'vel' => $vel, 't0' => $t0, 'n' => 0, 'bomblet' => $bomblet, 'alive' => true,
            'path' => [r3($pos)], 'end' => null, 'boom' => null];
}

/** Does point p sit inside a standing building? Returns its index or null. */
function building_at(array $objects, array $p): ?int
{
    foreach ($objects as $k => $o) {
        if ($o['type'] !== 'building' || $o['hp'] <= 0) continue;
        if (abs($p[0] - $o['x']) <= $o['hw'] && abs($p[2] - $o['z']) <= $o['hd'] && $p[1] <= $o['y'] + $o['h'] && $p[1] >= $o['y'] - 2) return $k;
    }
    return null;
}

/**
 * Fly a set of projectiles until all have landed. $owner is the firing seat, or null (lava bombs).
 * Updates $players, $objects and the terrain; appends terrain ops to $ops in the order they happen.
 */
function fly(Terrain $t, array &$players, array &$objects, array $projs, array $w, ?int $owner, array $wind, array $rules, array &$ops): array
{
    $g = (float)$rules['gravity']; $clock = 0; $guard = 0; $half = $t->half;
    while ($guard++ < 8000) {
        $any = false; $spawn = [];
        foreach ($projs as &$pr) {
            if (!$pr['alive']) continue;
            $any = true;
            if ($pr['t0'] > $clock * ENG_STEP + 1e-9) continue;          // staggered launch (lava bombs)
            $vy0 = $pr['vel'][1];
            $pr['vel'][0] += $wind[0] * ENG_WIND_K * ENG_STEP;
            $pr['vel'][2] += $wind[1] * ENG_WIND_K * ENG_STEP;
            $pr['vel'][1] -= $g * ENG_STEP;
            for ($k = 0; $k < 3; $k++) $pr['pos'][$k] += $pr['vel'][$k] * ENG_STEP;
            $pr['n']++;
            if ($pr['n'] % ENG_PATH_EVERY === 0) $pr['path'][] = r3($pr['pos']);
            $p = $pr['pos'];

            if ($w['split'] && !$pr['bomblet'] && $vy0 > 0 && $pr['vel'][1] <= 0) {
                $pr['alive'] = false; $pr['end'] = 'split'; $pr['path'][] = r3($p);
                for ($k = 0; $k < $w['split']; $k++) {
                    $a = $k / $w['split'] * M_PI * 2 + rnd() * .5; $s = 4 + rnd() * 3;
                    $spawn[] = new_proj($p, [$pr['vel'][0] + cos($a) * $s, $pr['vel'][1] + rnd() * 3, $pr['vel'][2] + sin($a) * $s],
                                        ($clock + 1) * ENG_STEP, true);
                }
                continue;
            }
            if (abs($p[0]) > $half + 4 || abs($p[2]) > $half + 4 || $p[1] < -30) {
                $pr['alive'] = false; $pr['end'] = 'oob'; $pr['path'][] = r3($p); continue;
            }
            $end = null;
            foreach ($players as $s => $pl) {
                if (!$pl['alive'] || ($owner !== null && $s === $owner && $pr['n'] * ENG_STEP < .25)) continue;
                if (sqrt(($p[0] - $pl['x']) ** 2 + ($p[1] - ($pl['y'] + 1.1)) ** 2 + ($p[2] - $pl['z']) ** 2) < ENG_TANK_HIT) { $end = 'tank'; break; }
            }
            if (!$end && $objects && building_at($objects, $p) !== null) $end = 'object';
            $gh = $t->heightAt($p[0], $p[2]);
            if (!$end && !empty($rules['splash']) && $p[1] <= $t->water && $gh < $t->water) { $end = 'water'; $p[1] = $t->water; }
            if (!$end && $p[1] <= $gh) { $end = 'ground'; $p[1] = $gh; }
            if ($end) {
                $p = r3($p);
                $pr['pos'] = $p; $pr['path'][] = $p; $pr['alive'] = false; $pr['end'] = $end;
                $pr['boom'] = explode_at($t, $players, $objects, $p, $w, $owner, $end === 'water', $ops);
            }
        }
        unset($pr);
        foreach ($spawn as $s) $projs[] = $s;
        $clock++;
        if (!$any) break;
    }
    return array_map(fn($pr) => [
        'path' => $pr['path'], 't0' => round($pr['t0'], 4), 'dur' => round($pr['n'] * ENG_STEP, 4),
        'bomblet' => $pr['bomblet'], 'end' => $pr['end'], 'boom' => $pr['boom'],
    ], $projs);
}

/** Tanks drop into (or are lifted out of) the changed ground; long falls hurt. */
function settle(Terrain $t, array &$players, array $rules): array
{
    $falls = []; $mult = (float)($rules['fall_mult'] ?? 1.0);
    foreach ($players as $s => &$pl) {
        $g = $t->heightAt($pl['x'], $pl['z']);
        if (abs($pl['y'] - $g) <= .03) continue;
        $drop = $pl['y'] - $g; $pl['y'] = $g; $dmg = 0;
        if ($drop > 3 && $pl['alive']) {
            $dmg = (int)round(($drop - 3) * 4 * $mult);
            apply_damage($players, $pl, $s, $dmg, $pl['last_hit_by']);
        }
        $falls[] = ['seat' => $s, 'y' => round($g, 3), 'dmg' => $dmg];
    }
    unset($pl);
    return $falls;
}

/**
 * Fly one shot. $players is keyed by seat: ['x','y','z','hp','alive','last_hit_by','damage_dealt'] and is updated in place.
 * Returns the replay record: projectiles (sampled paths + explosions), terrain ops and post-shot settling.
 */
function simulate_shot(Terrain $t, array &$players, array &$objects, int $seat, float $yaw, float $elev, float $power, string $wk, array $wind, array $rules): array
{
    $w = WEAPONS[$wk];
    $y = deg2rad($yaw); $e = deg2rad($elev);
    $d = [cos($e) * sin($y), sin($e), -cos($e) * cos($y)];
    $me = $players[$seat];
    $o = [$me['x'] + $d[0] * ENG_BARREL, $me['y'] + ENG_TURRET_H + $d[1] * ENG_BARREL, $me['z'] + $d[2] * ENG_BARREL];
    $sp = $power / 100 * ENG_VMAX;
    $ops = [];
    $projs = fly($t, $players, $objects, [new_proj($o, [$d[0] * $sp, $d[1] * $sp, $d[2] * $sp], 0.0, false)], $w, $seat, $wind, $rules, $ops);
    return ['projectiles' => $projs, 'ops' => $ops, 'falls' => settle($t, $players, $rules)];
}

function apply_damage(array &$players, array &$victim, int $vseat, int $dmg, ?int $by): void
{
    $victim['hp'] = max(0, $victim['hp'] - $dmg);
    if ($by !== null) {
        $victim['last_hit_by'] = $by;
        if ($by !== $vseat) $players[$by]['damage_dealt'] += $dmg;
    }
}

function explode_at(Terrain $t, array &$players, array &$objects, array $p, array $w, ?int $owner, bool $splash, array &$ops): array
{
    $R = $w['radius'] * ($splash ? .6 : 1);
    $Rc = 0.0;
    if (!$splash) {
        $Rc = $w['radius'] * ($p[1] < $t->water ? .8 : 1);
        $ops[] = $t->crater($p[0], $p[2], $Rc);
    }
    $reach = $R * 1.45; $hits = [];
    foreach ($players as $s => &$pl) {
        if (!$pl['alive']) continue;
        $d = sqrt(($p[0] - $pl['x']) ** 2 + ($p[1] - ($pl['y'] + 1)) ** 2 + ($p[2] - $pl['z']) ** 2);
        if ($d < $reach) {
            $dmg = (int)round($w['dmg'] * ($d < ENG_TANK_HIT ? 1 : (1 - ($d - ENG_TANK_HIT) / ($reach - ENG_TANK_HIT)) ** .9));
            if ($dmg > 0) { apply_damage($players, $pl, $s, $dmg, $owner); $hits[] = ['seat' => $s, 'dmg' => $dmg]; }
        }
    }
    unset($pl);
    $obj = [];
    foreach ($objects as $k => &$o) {
        if ($o['type'] !== 'building' || $o['hp'] <= 0) continue;
        $cx = max($o['x'] - $o['hw'], min($o['x'] + $o['hw'], $p[0]));
        $cy = max($o['y'], min($o['y'] + $o['h'], $p[1]));
        $cz = max($o['z'] - $o['hd'], min($o['z'] + $o['hd'], $p[2]));
        $d = sqrt(($p[0] - $cx) ** 2 + ($p[1] - $cy) ** 2 + ($p[2] - $cz) ** 2);
        if ($d >= $reach) continue;
        $dmg = (int)round($w['dmg'] * ($d < 1 ? 1 : (1 - ($d - 1) / ($reach - 1)) ** .9));
        if ($dmg <= 0) continue;
        $o['hp'] = max(0, $o['hp'] - $dmg);
        $rec = ['id' => $o['id'], 'dmg' => $dmg, 'hp' => $o['hp']];
        if ($o['hp'] <= 0) {                                         // collapses into a rubble mound
            $ops[] = $t->raise((float)$o['x'], (float)$o['z'], max($o['hw'], $o['hd']) * 1.6, min(3.0, $o['h'] * .18), 2);
            $rec['destroyed'] = true;
        }
        $obj[] = $rec;
    }
    unset($o);
    return ['x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'r' => $Rc, 'vr' => $R, 'hits' => $hits, 'obj' => $obj, 'splash' => $splash];
}

/* =====================================================================
 * Start of a turn: eruptions, snowfall and sudden death.
 * Returns the replay record for a 'turn' event, or null if nothing happened.
 * ===================================================================== */
function start_turn(Terrain $t, array &$players, array &$objects, array $rules, int $turn): ?array
{
    $ops = []; $out = ['turn' => $turn]; $did = false;

    if ($rules['erupt_every'] > 0 && $turn % $rules['erupt_every'] === 0 && $rules['vent']) {
        [$vx, $vz] = $rules['vent']; $g = (float)$rules['gravity']; $s = $t->half / 120;
        $o = [(float)$vx, $t->heightAt($vx, $vz) + 2, (float)$vz]; $projs = [];
        for ($k = 0; $k < 3; $k++) {
            $a = rnd() * M_PI * 2; $r = (20 + rnd() * 70) * $s;
            $tx = $vx + sin($a) * $r; $tz = $vz - cos($a) * $r; $ty = $t->heightAt($tx, $tz);
            $T = 2.6 + rnd() * 1.2;
            $projs[] = new_proj($o, [($tx - $o[0]) / $T, ($ty - $o[1] + .5 * $g * $T * $T) / $T, ($tz - $o[2]) / $T], round($k * .4, 4), false);
        }
        $out['bombs'] = fly($t, $players, $objects, $projs, LAVA_BOMB, null, [0.0, 0.0], $rules, $ops);
        $ops[] = $t->raise((float)$vx, (float)$vz, 14 * $s, 1.2, 3);   // the cone grows
        $out['erupt'] = true; $did = true;
    }
    if (!empty($rules['snow'])) {
        $skips = [];
        foreach ($players as $pl) if ($pl['alive']) $skips[] = [round($pl['x'], 3), round($pl['z'], 3), 4.0];
        $ops[] = $t->fill(.25, .35, $t->water + .2, $skips);
        $out['snow'] = true; $did = true;
    }
    if ($turn >= SD_TURN) {
        $drain = [];
        foreach ($players as $s => &$pl) {
            if (!$pl['alive']) continue;
            apply_damage($players, $pl, $s, SD_DRAIN, null);
            $drain[] = ['seat' => $s, 'dmg' => SD_DRAIN];
        }
        unset($pl);
        $out['sudden'] = ['first' => $turn === SD_TURN, 'drain' => $drain, 'wind_bonus' => sd_wind_bonus($rules, $turn)];
        $did = true;
    }
    if (!$did) return null;
    $out['ops'] = $ops;
    $out['falls'] = settle($t, $players, $rules);
    return $out;
}
