<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/items.php';
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
    // power-up shells (phase 2): fired from the item slot, one shot each
    'bunker'     => ['radius' => 7.0, 'dmg' => 60, 'split' => 0, 'tunnel' => 8.0],
    'napalm'     => ['radius' => 4.0, 'dmg' => 25, 'split' => 0, 'fire' => true],
    'earthmover' => ['radius' => 7.0, 'dmg' => 0,  'split' => 0, 'mound' => 5.0],
];
const BASE_WEAPONS = ['shell', 'heavy', 'cluster'];
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

/**
 * n spawn points spread around a ring. All tanks share one radius, so the centre is the same distance from
 * every tank (and from any group of survivors). Crates fall around that centre: see crate_centre().
 */
function ring_spawns(int $n, float $rMin, float $rMax): array
{
    $base = rnd() * M_PI * 2; $out = []; $r = $rMin + rnd() * ($rMax - $rMin);
    for ($k = 0; $k < $n; $k++) {
        $a = $base + $k * M_PI * 2 / $n + (rnd() - .5) * .35;
        $out[] = [sin($a) * $r, -cos($a) * $r];
    }
    return $out;
}

/** Centre of the circle best fitting the points (least squares), or null if they are (nearly) in a line. */
function circle_centre(array $pts): ?array
{
    [$x0, $z0] = $pts[0]; $a = $b = $c = $d = $e = 0.0;
    for ($i = 1; $i < count($pts); $i++) {
        $u = 2 * ($pts[$i][0] - $x0); $v = 2 * ($pts[$i][1] - $z0);
        $w = $pts[$i][0] ** 2 - $x0 ** 2 + $pts[$i][1] ** 2 - $z0 ** 2;
        $a += $u * $u; $b += $u * $v; $c += $v * $v; $d += $u * $w; $e += $v * $w;
    }
    $det = $a * $c - $b * $b;
    if (abs($det) < 1e-6 * max(1.0, $a * $c)) return null;
    return [($c * $d - $b * $e) / $det, ($a * $e - $b * $d) / $det];
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
    $isl = []; $spawns = []; $r = (66 + rnd() * 10) * $s;
    for ($i = 0; $i < $players; $i++) {                      // one island per tank, evenly around the ring
        $a = $base + $i * M_PI * 2 / $players + (rnd() - .5) * .2;
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

/* =====================================================================
 * Cover. No map may be flat with a straight line of sight between tanks: after a map is built, every
 * pair of tanks is checked turret to turret, and any pair that can see each other gets a cover feature
 * raised between them (in the map's own style) until the ground blocks the view. Buildings don't count,
 * because they can be shot down; the cover is ground.
 * ===================================================================== */

/** The style of cover each map raises: [shape (2 rounded, 3 pointed), bump radius, ridge half-length, extra height]. */
const COVER_STYLE = [
    'highlands'   => [2, 9.0, 12.0, 2.5],      // grassy hill
    'archipelago' => [3, 7.0, 6.0, 3.0],       // sea stack: a rocky islet
    'canyon'      => [3, 5.0, 8.0, 2.5],       // rock pillars on the rim
    'volcano'     => [2, 8.0, 10.0, 2.5],      // ash mound
    'arctic'      => [3, 5.5, 16.0, 2.5],      // pressure ridge: a long jagged ice wall
    'city'        => [2, 6.0, 12.0, 2.5],      // rubble berm
    'lunar'       => [2, 11.0, 8.0, 3.0],      // mesa
];

/**
 * Can tank a see tank b in a straight line (turret to turret)? Only the ground counts, and it has to rise at least
 * COVER_MIN above the line: a crest a few centimetres high would be gone after the first crater.
 */
const COVER_MIN = 2.0;
function line_of_sight(Terrain $t, array $a, array $b): bool
{
    $A = [$a[0], $a[1] + ENG_TURRET_H, $a[2]]; $B = [$b[0], $b[1] + ENG_TURRET_H, $b[2]];
    $L = hypot($B[0] - $A[0], $B[2] - $A[2]);
    for ($s = 5.0; $s < $L - 5; $s += 1.0) {
        $k = $s / $L;
        if ($t->heightAt($A[0] + ($B[0] - $A[0]) * $k, $A[2] + ($B[2] - $A[2]) * $k) > $A[1] + ($B[1] - $A[1]) * $k + COVER_MIN) return false;
    }
    return true;
}

/** A ridge of bumps across the line from a to b, at fraction $k along it, tall enough to block the view. */
function raise_cover(Terrain $t, string $mapId, array $a, array $b, float $k): void
{
    [$p, $R, $half, $extra] = COVER_STYLE[$mapId] ?? COVER_STYLE['highlands'];
    $dx = $b[0] - $a[0]; $dz = $b[2] - $a[2]; $L = hypot($dx, $dz) ?: 1.0;
    $ux = -$dz / $L; $uz = $dx / $L;                                // across the line of sight
    $cx = $a[0] + $dx * $k; $cz = $a[2] + $dz * $k;
    $sight = $a[1] + ($b[1] - $a[1]) * $k + ENG_TURRET_H;
    $need = $sight - $t->heightAt($cx, $cz) + COVER_MIN + $extra + rnd() * 2;
    if ($need <= 0) return;
    for ($o = -$half; $o <= $half + 1e-9; $o += max(3.0, $R * .6)) {
        $j = (rnd() - .5) * 2;
        $t->raise($cx + $ux * $o + $dx / $L * $j, $cz + $uz * $o + $dz / $L * $j, $R * (.85 + rnd() * .3), $need * (1 - .35 * ($o / max(1.0, $half)) ** 2), $p);
    }
}

/** Themed features that break up the open maps (on top of the guarantee below). Kept clear of tank pads. */
function scatter_cover(Terrain $t, string $mapId, array $spawns): void
{
    $count = ['archipelago' => 3, 'canyon' => 4, 'arctic' => 5, 'city' => 3, 'lunar' => 4][$mapId] ?? 0;
    $s = $t->half / 120; $count = (int)round($count * $s * $s);
    [$p, $R] = COVER_STYLE[$mapId] ?? COVER_STYLE['highlands'];
    for ($k = 0, $tries = 0; $k < $count && $tries < 200; $tries++) {
        $x = (rnd() * 2 - 1) * $t->half * .7; $z = (rnd() * 2 - 1) * $t->half * .7;
        foreach ($spawns as $sp) if (hypot($sp[0] - $x, $sp[1] - $z) < 24) continue 2;
        $a = rnd() * M_PI; $len = $mapId === 'arctic' ? 3 : 1;          // arctic: long ridges; elsewhere single features
        $base = max($t->heightAt($x, $z), $t->water);
        $h = ($mapId === 'archipelago' ? $t->water + 7 - $t->heightAt($x, $z) : 5 + rnd() * 4) + ($base - $t->heightAt($x, $z));
        for ($i = -$len + 1; $i < $len; $i++) $t->raise($x + cos($a) * $i * $R, $z + sin($a) * $i * $R, $R * (1 + rnd() * .4), $h * (1 - .2 * abs($i)), $p);
        $k++;
    }
}

/** Make sure no two tanks can see each other: raise cover until every pair's view is blocked. */
function ensure_cover(Terrain $t, string $mapId, array $spawns): void
{
    $pos = fn($sp) => [$sp[0], $t->heightAt($sp[0], $sp[1]), $sp[1]];
    for ($round = 0; $round < 6; $round++) {
        $open = 0;
        for ($i = 0; $i < count($spawns); $i++) for ($j = $i + 1; $j < count($spawns); $j++) {
            $a = $pos($spawns[$i]); $b = $pos($spawns[$j]);
            if (!line_of_sight($t, $a, $b)) continue;
            $open++;
            raise_cover($t, $mapId, $a, $b, .38 + rnd() * .24);
        }
        if (!$open) return;
    }
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
    if (count($spawns) === 4 && ($c = circle_centre(array_slice($spawns, 0, 3)))) {
        // put the 4th tank on the circle through the other three, so one point is equally far from all four
        $cr = hypot($spawns[0][0] - $c[0], $spawns[0][1] - $c[1]); $cd = hypot($spawns[3][0] - $c[0], $spawns[3][1] - $c[1]);
        if ($cd > 1) $spawns[3] = [$c[0] + ($spawns[3][0] - $c[0]) * $cr / $cd, $c[1] + ($spawns[3][1] - $c[1]) * $cr / $cd];
    }
    $H = [];
    for ($j = 0; $j <= $n; $j++) for ($i = 0; $i <= $n; $i++) $H[$j * $w + $i] = $f(-$half + $i * ENG_CELL, -$half + $j * ENG_CELL);
    $t = new Terrain($H, $n, $R['water']);
    if ($mapId === 'lunar') lunar_craters($t);
    scatter_cover($t, $mapId, $spawns);
    foreach ($spawns as $sp) $t->flattenPad($sp[0], $sp[1], 4.5);
    $objects = !empty($R['buildings']) ? place_buildings($t, $spawns) : [];
    ensure_cover($t, $mapId, $spawns);                     // after the pads and buildings, so nothing flattens it again
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
        'mode' => 'ranked', 'items' => 'safe', 'turn_seconds' => (int)cfg('turn_seconds', 40),
        'crate_every' => max(1, (int)cfg('crate_every', CRATE_EVERY)), 'crate_max' => max(1, (int)cfg('crate_max', CRATE_MAX)),
        'crate_radius' => max(0.0, (float)cfg('crate_radius', CRATE_RADIUS)),
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

/** 'tank' if point p is touching a living tank (a shell can't hit its own tank in the first 0.25 s). */
function hit_tank(array $players, array $p, ?int $owner, float $age): ?string
{
    foreach ($players as $s => $pl) {
        if (!$pl['alive'] || ($owner !== null && $s === $owner && $age < .25)) continue;
        if (sqrt(($p[0] - $pl['x']) ** 2 + ($p[1] - ($pl['y'] + 1.1)) ** 2 + ($p[2] - $pl['z']) ** 2) < ENG_TANK_HIT) return 'tank';
    }
    return null;
}

/** Is point p touching a crate still on the field? Returns its index or null. */
function crate_at(array $objects, array $p): ?int
{
    foreach ($objects as $k => $o) {
        if ($o['type'] !== 'crate' || $o['state'] !== 'live') continue;
        if (sqrt(($p[0] - $o['x']) ** 2 + ($p[1] - ($o['y'] + .8)) ** 2 + ($p[2] - $o['z']) ** 2) < CRATE_HIT) return $k;
    }
    return null;
}

/**
 * Bunker Buster: from where the shell met the ground, bore on along its flight line for up to $len metres.
 * It explodes early if it reaches a tank or a building, or breaks out of the far side of a hill into the air.
 * Returns [explosion point, end reason].
 */
function tunnel(Terrain $t, array $players, array $objects, array $p, array $vel, float $len, ?int $owner): array
{
    $m = sqrt($vel[0] ** 2 + $vel[1] ** 2 + $vel[2] ** 2) ?: 1.0;
    $d = [$vel[0] / $m, $vel[1] / $m, $vel[2] / $m]; $q = $p;
    for ($s = .25; $s <= $len + 1e-9; $s += .25) {
        $q = [$p[0] + $d[0] * $s, $p[1] + $d[1] * $s, $p[2] + $d[2] * $s];
        if (abs($q[0]) > $t->half || abs($q[2]) > $t->half) return [$q, 'tunnel'];
        if (hit_tank($players, $q, $owner, 1.0)) return [$q, 'tank'];
        if ($objects && building_at($objects, $q) !== null) return [$q, 'object'];
        if ($s >= 1 && $q[1] > $t->heightAt($q[0], $q[2]) + .05) return [$q, 'tunnel'];   // broke out of the far side
    }
    return [$q, 'tunnel'];
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
            $end = hit_tank($players, $p, $owner, $pr['n'] * ENG_STEP);
            if (!$end && $objects && building_at($objects, $p) !== null) $end = 'object';
            if (!$end && $objects && crate_at($objects, $p) !== null) $end = 'crate';
            $gh = $t->heightAt($p[0], $p[2]);
            if (!$end && !empty($rules['splash']) && $p[1] <= $t->water && $gh < $t->water) { $end = 'water'; $p[1] = $t->water; }
            if (!$end && $p[1] <= $gh) { $end = 'ground'; $p[1] = $gh; }
            if ($end === 'ground' && !empty($w['tunnel'])) {          // Bunker Buster: bore on before exploding
                [$p, $end] = tunnel($t, $players, $objects, $p, $pr['vel'], (float)$w['tunnel'], $owner);
                $pr['path'][] = r3($pr['pos']);
            }
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
        $abs = 0;
        if ($drop > 3 && $pl['alive']) {
            [$dmg, $abs] = apply_damage($players, $pl, $s, (int)round(($drop - 3) * 4 * $mult), $pl['last_hit_by']);
        }
        $f = ['seat' => $s, 'y' => round($g, 3), 'dmg' => $dmg];
        if ($abs) $f['abs'] = $abs;
        $falls[] = $f;
    }
    unset($pl);
    return $falls;
}

/** Crates rest on the ground, or float where it is under water. The browser works out the same height. */
function settle_crates(Terrain $t, array &$objects): void
{
    foreach ($objects as &$o) if ($o['type'] === 'crate' && $o['state'] === 'live') $o['y'] = max($t->heightAt($o['x'], $o['z']), $t->water);
    unset($o);
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
    $falls = settle($t, $players, $rules);
    settle_crates($t, $objects);
    return ['projectiles' => $projs, 'ops' => $ops, 'falls' => $falls];
}

/**
 * Take hp off a tank. A Shield Bubble soaks up damage first (not sudden-death drain: $shieldable = false).
 * Returns [hp actually lost, damage the shield absorbed].
 */
function apply_damage(array &$players, array &$victim, int $vseat, int $dmg, ?int $by, bool $shieldable = true): array
{
    $abs = 0;
    if ($shieldable && $dmg > 0 && isset(FX::$e['shields'][$vseat])) {
        $sh = &FX::$e['shields'][$vseat];
        $abs = min($dmg, (int)$sh['hp']); $sh['hp'] -= $abs; $dmg -= $abs;
        if ($sh['hp'] <= 0) unset(FX::$e['shields'][$vseat]);
        unset($sh);
    }
    $lost = min($dmg, $victim['hp']);
    $victim['hp'] -= $lost;
    if ($by !== null && ($lost > 0 || $abs > 0)) {
        $victim['last_hit_by'] = $by;
        if ($by !== $vseat) $players[$by]['damage_dealt'] += $lost;
    }
    return [$lost, $abs];
}

function explode_at(Terrain $t, array &$players, array &$objects, array $p, array $w, ?int $owner, bool $splash, array &$ops): array
{
    $R = $w['radius'] * ($splash ? .6 : 1);
    $Rc = 0.0; $mound = !empty($w['mound']) && !$splash; $out = [];
    if ($mound) {                                                    // Earthmover: build up instead of digging
        $ops[] = $t->raise($p[0], $p[2], $w['radius'], (float)$w['mound'], 2);
        $out['mound'] = true;
    } elseif (!$splash) {
        $Rc = $w['radius'] * ($p[1] < $t->water ? .8 : 1);
        $ops[] = $t->crater($p[0], $p[2], $Rc);
    }
    $reach = $R * 1.45; $hits = [];
    if ($w['dmg'] > 0) foreach ($players as $s => &$pl) {
        if (!$pl['alive']) continue;
        $d = sqrt(($p[0] - $pl['x']) ** 2 + ($p[1] - ($pl['y'] + 1)) ** 2 + ($p[2] - $pl['z']) ** 2);
        if ($d < $reach) {
            $dmg = (int)round($w['dmg'] * ($d < ENG_TANK_HIT ? 1 : (1 - ($d - ENG_TANK_HIT) / ($reach - ENG_TANK_HIT)) ** .9));
            if ($dmg > 0) {
                [$lost, $abs] = apply_damage($players, $pl, $s, $dmg, $owner);
                $h = ['seat' => $s, 'dmg' => $lost]; if ($abs) $h['abs'] = $abs;
                $hits[] = $h;
            }
        }
    }
    unset($pl);
    if (!empty($w['fire']) && !$splash) {                           // Napalm: a fire zone that burns for 3 turns
        $id = FX::$e['fire_seq']++;
        $fire = ['id' => $id, 'x' => round($p[0], 3), 'z' => round($p[2], 3), 'r' => FIRE_R, 'left' => FIRE_TURNS, 'owner' => $owner];
        FX::$e['fires'][] = $fire;
        $out['fire'] = $fire;
    }
    $crates = [];
    foreach ($objects as $k => &$o) {                                // a blast within 4 m claims a crate; further out it wrecks it
        if ($o['type'] !== 'crate' || $o['state'] !== 'live') continue;
        $d = sqrt(($p[0] - $o['x']) ** 2 + ($p[1] - ($o['y'] + .8)) ** 2 + ($p[2] - $o['z']) ** 2);
        if ($d <= CRATE_CLAIM && $owner !== null) {
            // what's inside is decided only now, so nobody (not even a player reading the network traffic) knows before
            $o['item'] ??= roll_item(item_pool((string)($o['pool'] ?? 'safe'))) ?? 'repair';
            $prev = $players[$owner]['item'] ?? null;
            $players[$owner]['item'] = $o['item'];
            $o['state'] = 'claimed'; $o['by'] = $owner;
            $crates[] = ['id' => $o['id'], 'by' => $owner, 'item' => $o['item'], 'lost' => $prev];
        } elseif ($d < $R && !$mound) {
            $o['state'] = 'destroyed';                               // wrecked unopened: its contents never existed
            $crates[] = ['id' => $o['id'], 'by' => null];
        }
    }
    unset($o);
    if ($crates) $out['crates'] = $crates;
    $obj = [];
    if ($w['dmg'] > 0) foreach ($objects as $k => &$o) {
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
    return ['x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'r' => $Rc, 'vr' => $R, 'hits' => $hits, 'obj' => $obj, 'splash' => $splash] + $out;
}

/* =====================================================================
 * Start of a turn: shields wearing off, eruptions, fire zones, snowfall, sudden death and supply crates.
 * $prevSeat is the tank whose turn just ended (shields count down on enemy turns).
 * Returns the replay record for a 'turn' event, or null if nothing happened.
 * ===================================================================== */
function start_turn(Terrain $t, array &$players, array &$objects, array $rules, int $turn, ?int $prevSeat = null): ?array
{
    $ops = []; $out = ['turn' => $turn]; $did = false;

    if ($prevSeat !== null && FX::$e['shields']) {
        $gone = [];
        foreach (FX::$e['shields'] as $s => &$sh) {
            if ((int)$s === $prevSeat) continue;
            if (--$sh['left'] <= 0) $gone[] = (int)$s;
        }
        unset($sh);
        foreach ($gone as $s) unset(FX::$e['shields'][$s]);
        if ($gone) { $out['shield_end'] = $gone; $did = true; }
    }
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
    if (FX::$e['fires']) {                                            // napalm burns whoever sits in it
        $burn = []; $left = [];
        foreach (FX::$e['fires'] as $f) {
            foreach ($players as $s => &$pl) {
                if (!$pl['alive'] || hypot($pl['x'] - $f['x'], $pl['z'] - $f['z']) >= $f['r']) continue;
                [$lost, $abs] = apply_damage($players, $pl, $s, FIRE_DMG, $f['owner']);
                $b = ['seat' => $s, 'dmg' => $lost, 'fire' => $f['id']]; if ($abs) $b['abs'] = $abs;
                $burn[] = $b;
            }
            unset($pl);
            if (--$f['left'] > 0) $left[] = $f; else $out['fire_end'][] = $f['id'];
        }
        FX::$e['fires'] = $left;
        if ($burn) $out['burn'] = $burn;
        $did = true;
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
            [$lost] = apply_damage($players, $pl, $s, SD_DRAIN, null, false);
            $drain[] = ['seat' => $s, 'dmg' => $lost];
        }
        unset($pl);
        $out['sudden'] = ['first' => $turn === SD_TURN, 'drain' => $drain, 'wind_bonus' => sd_wind_bonus($rules, $turn)];
        $did = true;
    }
    $out['ops'] = $ops;
    $out['falls'] = $did ? settle($t, $players, $rules) : [];
    settle_crates($t, $objects);
    $every = max(1, (int)($rules['crate_every'] ?? CRATE_EVERY));
    if ($turn % $every === 0 && ($c = drop_crate($t, $players, $objects, $rules, $turn))) {
        $out['crates'] = [$c]; $did = true;
    }
    return $did ? $out : null;
}

/* =====================================================================
 * Supply crates
 * ===================================================================== */

/** Can a crate land at (x, z)? Clear of tanks, buildings and other crates, and on the map. */
function crate_spot_ok(Terrain $t, array $players, array $objects, float $x, float $z): bool
{
    $lim = $t->half * .88;
    if (abs($x) > $lim || abs($z) > $lim || $t->heightAt($x, $z) < -20) return false;
    foreach ($players as $pl) if ($pl['alive'] && hypot($pl['x'] - $x, $pl['z'] - $z) < 14) return false;
    foreach ($objects as $o) {
        if ($o['type'] === 'building' && $o['hp'] > 0 && abs($x - $o['x']) < $o['hw'] + 2 && abs($z - $o['z']) < $o['hd'] + 2) return false;
        if ($o['type'] === 'crate' && $o['state'] === 'live' && hypot($o['x'] - $x, $o['z'] - $z) < 6) return false;
    }
    return true;
}

/** max ÷ min of the ground distances from (x, z) to each living tank: 1.0 is perfectly fair (reported by the tests). */
function crate_fairness(array $players, float $x, float $z): float
{
    $lo = INF; $hi = 0.0;
    foreach ($players as $pl) {
        if (!$pl['alive']) continue;
        $d = hypot($pl['x'] - $x, $pl['z'] - $z); $lo = min($lo, $d); $hi = max($hi, $d);
    }
    return $lo > 0 ? $hi / $lo : INF;
}

/**
 * The point between the living tanks that crates fall around: halfway between two tanks; with three or four,
 * the centre of the circle they stand on (every match spawns its tanks on one shared circle, and survivors of a
 * circle stay on it), which is the same distance from each of them. Falls back to their average position.
 */
function crate_centre(array $players): ?array
{
    $alive = array_values(array_map(fn($p) => [$p['x'], $p['z']], array_filter($players, fn($p) => $p['alive'])));
    if (count($alive) < 2) return null;
    $avg = [array_sum(array_column($alive, 0)) / count($alive), array_sum(array_column($alive, 1)) / count($alive)];
    if (count($alive) === 2) return $avg;
    $c = circle_centre($alive);
    return $c && hypot($c[0] - $avg[0], $c[1] - $avg[1]) < 60 ? $c : $avg;
}

/**
 * Every mode: a random spot inside a circle of crate_radius metres (15 by default) around the point between the
 * living tanks, chosen evenly over the circle's area, and clear of tanks, buildings and other crates.
 * Returns [x, z, centre x, centre z] or null if nothing in the circle is clear.
 */
function crate_spot(Terrain $t, array $players, array $objects, float $radius): ?array
{
    if (!($c = crate_centre($players))) return null;
    for ($k = 0; $k < 80; $k++) {
        $a = rnd() * M_PI * 2; $r = sqrt(rnd()) * $radius;
        $x = $c[0] + cos($a) * $r; $z = $c[1] + sin($a) * $r;
        if (crate_spot_ok($t, $players, $objects, $x, $z)) return [$x, $z, $c[0], $c[1]];
    }
    return null;
}

/** Drop a crate if the match uses power-ups and fewer than the match's crate limit are out. Returns the new crate or null. */
function drop_crate(Terrain $t, array $players, array &$objects, array $rules, int $turn): ?array
{
    $pool = item_pool((string)($rules['items'] ?? 'off'));
    if (!$pool) return null;
    $live = count(array_filter($objects, fn($o) => $o['type'] === 'crate' && $o['state'] === 'live'));
    if ($live >= max(1, (int)($rules['crate_max'] ?? CRATE_MAX))) return null;
    $spot = crate_spot($t, $players, $objects, (float)($rules['crate_radius'] ?? CRATE_RADIUS));
    if (!$spot) return null;
    $x = round($spot[0], 3); $z = round($spot[1], 3);
    // contents are rolled when the crate is claimed (explode_at), from the pool named here
    $c = ['id' => count($objects), 'type' => 'crate', 'x' => $x, 'z' => $z, 'y' => max($t->heightAt($x, $z), $t->water),
          'pool' => (string)$rules['items'], 'state' => 'live', 'turn' => $turn];
    $objects[] = $c;
    return $c;
}

/** Relocate: a random clear, fairly flat spot on land at least 40 m from where the tank is now. Null if none. */
function relocate_spot(Terrain $t, array $players, array $objects, int $seat): ?array
{
    $me = $players[$seat]; $lim = $t->half * .85;
    for ($k = 0; $k < 600; $k++) {
        $x = (rnd() * 2 - 1) * $lim; $z = (rnd() * 2 - 1) * $lim;
        if (hypot($x - $me['x'], $z - $me['z']) < RELOCATE_MIN) continue;
        $y = $t->heightAt($x, $z);
        if ($y < $t->water + .6) continue;
        if (abs($t->heightAt($x + 1.5, $z) - $t->heightAt($x - 1.5, $z)) > 1.5 || abs($t->heightAt($x, $z + 1.5) - $t->heightAt($x, $z - 1.5)) > 1.5) continue;
        foreach ($players as $s => $pl) if ($s !== $seat && $pl['alive'] && hypot($pl['x'] - $x, $pl['z'] - $z) < 12) continue 2;
        foreach ($objects as $o) {
            if ($o['type'] === 'building' && $o['hp'] > 0 && abs($x - $o['x']) < $o['hw'] + 3 && abs($z - $o['z']) < $o['hd'] + 3) continue 2;
            if ($o['type'] === 'crate' && $o['state'] === 'live' && hypot($o['x'] - $x, $o['z'] - $z) < 4) continue 2;
        }
        return [round($x, 3), round($y, 3), round($z, 3)];
    }
    return null;
}

/**
 * Use a non-shell power-up held by $seat. Changes $players and FX in place.
 * Returns ['event' => replay record, 'ends_turn' => bool] or ['error' => code] (the item is then kept).
 * Weather Report's forecast needs the match's wind state, so game.php adds it (forecast_winds()).
 */
function item_effect(?Terrain $t, array &$players, array $objects, int $seat, string $item, int $turn): array
{
    $me = &$players[$seat]; $ev = ['item' => $item]; $ends = false;
    $dbl = FX::$e['double'];
    if ($dbl && $dbl['seat'] === $seat && $dbl['shots'] > 0) return ['error' => 'mid_double'];
    switch ($item) {
        case 'repair':
            if ($me['hp'] >= 100) return ['error' => 'full_hp'];
            $heal = min(REPAIR_HP, 100 - $me['hp']); $me['hp'] += $heal; $ev['heal'] = $heal;
            break;
        case 'shield':
            FX::$e['shields'][$seat] = ['hp' => SHIELD_HP, 'left' => SHIELD_TURNS];
            break;
        case 'weather':
            break;
        case 'double':
            if ($dbl && $dbl['seat'] === $seat && $dbl['turn'] === $turn) return ['error' => 'already_on'];
            FX::$e['double'] = ['seat' => $seat, 'turn' => $turn, 'shots' => 0];
            break;
        case 'rangefinder':
            if (!in_array($seat, FX::$e['range'], true)) FX::$e['range'][] = $seat;
            break;
        case 'relocate':
            $to = relocate_spot($t, $players, $objects, $seat);
            if (!$to) return ['error' => 'no_spot'];
            $ev['from'] = [round($me['x'], 3), round($me['y'], 3), round($me['z'], 3)]; $ev['to'] = $to;
            [$me['x'], $me['y'], $me['z']] = $to;
            $ends = true;
            break;
        default:
            return ['error' => 'not_here'];                     // Flare: night maps only (phase 5)
    }
    return ['event' => $ev, 'ends_turn' => $ends];
}
