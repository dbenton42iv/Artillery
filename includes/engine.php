<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/skills.php';
require_once __DIR__ . '/missions.php';
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
    // phase 5: a flare shell lights up the dark (night missions): no blast, no crater
    'flare'      => ['radius' => 0.0, 'dmg' => 0,  'split' => 0, 'flare' => FLARE_R],
];
const BASE_WEAPONS = ['shell', 'heavy', 'cluster'];
const LAVA_BOMB = ['radius' => 5.5, 'dmg' => 30, 'split' => 0];
const START_AMMO = ['heavy' => 3, 'cluster' => 2];
const BUILDING_HP = 120;
const MAX_HP = 100;

/** Hit points a living tank repairs at the start of its own turn (rules 'regen'; older matches use the site setting). */
function regen_rate(array $rules): int { return max(0, (int)($rules['regen'] ?? cfg('regen_per_turn', 3))); }

/** A tank's skill number (phase 4, from its loadout: see skill_mods() in skills.php), or the neutral default. */
function sk(array $pl, string $k, $def) { return $pl['sk'][$k] ?? $def; }
/** A tank's maximum hp: 100, or more or less with its loadout (Fortified, Reinforced Tracks, Low Profile). */
function max_hp(array $pl): int { return (int)($pl['max_hp'] ?? MAX_HP); }
/** Highest power a tank can fire (Fortified: 85). */
function max_power(array $pl): float { return (float)sk($pl, 'max_power', 100.0); }

/**
 * A weapon as fired by a tank with skills: blast damage (Meteorologist −2, then Demolitions ×0.9; Bomblet Pack's
 * bomblets −5 each; Heavy Gunner's Heavy −5), Cluster bomblet count, and the shooter's crater width, crate claim radius, fire duration and
 * fall-damage multiplier, which explode_at() and settle() read.
 */
function shooter_weapon(string $wk, array $shooter): array
{
    $w = WEAPONS[$wk];
    if ($wk === 'cluster') { $w['split'] = (int)sk($shooter, 'split', $w['split']); $w['dmg'] += (int)sk($shooter, 'bomblet_dmg', 0); }
    if ($wk === 'heavy') $w['dmg'] += (int)sk($shooter, 'heavy_dmg', 0);           // Heavy Gunner: 65
    if ($w['dmg'] > 0) $w['dmg'] = max(1, (int)round(($w['dmg'] + (int)sk($shooter, 'dmg_flat', 0)) * (float)sk($shooter, 'dmg_mult', 1.0)));
    $w['crater'] = (float)sk($shooter, 'crater', 1.0);
    $w['claim'] = (float)sk($shooter, 'claim', CRATE_CLAIM);
    $w['fire_turns'] = max(1, FIRE_TURNS + (int)sk($shooter, 'item_turns', 0));
    $w['fall'] = (float)sk($shooter, 'fall_dealt', 1.0);
    return $w;
}

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

/**
 * Centre of the circle best fitting the points (least squares on x² + z² = 2ax + 2bz + c), or null if they are
 * (nearly) in a line. Points on one circle give exactly its centre; tanks that have driven off it give the best fit.
 */
function circle_centre(array $pts): ?array
{
    $n = count($pts); $sx = $sz = $sxx = $szz = $sxz = $sw = $sxw = $szw = 0.0;
    foreach ($pts as [$x, $z]) {
        $w = $x * $x + $z * $z;
        $sx += $x; $sz += $z; $sxx += $x * $x; $szz += $z * $z; $sxz += $x * $z; $sw += $w; $sxw += $x * $w; $szw += $z * $w;
    }
    // normal equations: [[sxx, sxz, sx], [sxz, szz, sz], [sx, sz, n]] · [2a, 2b, c] = [sxw, szw, sw]
    $det = $sxx * ($szz * $n - $sz * $sz) - $sxz * ($sxz * $n - $sz * $sx) + $sx * ($sxz * $sz - $szz * $sx);
    if (abs($det) < 1e-9 * max(1.0, $sxx * $szz * $n)) return null;
    $A = $sxw * ($szz * $n - $sz * $sz) - $sxz * ($szw * $n - $sz * $sw) + $sx * ($szw * $sz - $szz * $sw);
    $B = $sxx * ($szw * $n - $sz * $sw) - $sxw * ($sxz * $n - $sz * $sx) + $sx * ($sxz * $sw - $szw * $sx);
    return [$A / $det / 2, $B / $det / 2];
}

/*
 * Elevation tiers (David's request, 16 Nov): tanks never start level with each other. Each map gives its tanks
 * different heights (a ridge shoulder and a valley, a tall island and a low one, canyon floors at different depths),
 * evenly spread between a low and a high level and dealt out at random, so no seat is favoured over many matches.
 */

/** $n heights spread evenly from $lo to $hi (a little jitter), in random order. */
function tier_levels(int $n, float $lo, float $hi): array
{
    $gap = $n > 1 ? ($hi - $lo) / ($n - 1) : 0.0; $out = [];
    for ($k = 0; $k < $n; $k++) $out[] = $n > 1 ? $lo + $k * $gap + (rnd() - .5) * $gap * .3 : ($lo + $hi) / 2;
    for ($k = $n - 1; $k > 0; $k--) { $j = (int)floor(rnd() * ($k + 1)); [$out[$k], $out[$j]] = [$out[$j], $out[$k]]; }
    return $out;
}

/**
 * Raise or lower the ground around each tank to its tier: flat out to $r0 metres (ragged edge), blending back to the
 * natural terrain by $r1. A high tier becomes a shoulder or mesa, a low one a hollow or shelf.
 */
function lift_spawns(callable $f, Noise $nz, array $spawns, array $levels, float $r0, float $r1): callable
{
    $h0 = array_map(fn($sp) => $f($sp[0], $sp[1]), $spawns);
    return function (float $x, float $z) use ($f, $nz, $spawns, $levels, $h0, $r0, $r1) {
        $h = $f($x, $z);
        foreach ($spawns as $k => $sp) {
            $d = hypot($x - $sp[0], $z - $sp[1]);
            if ($d >= $r1 + 6) continue;
            $d += ($nz->fbm($x * .07 + $k * 13.1, $z * .07 + 5) - .5) * 9;
            if ($d < $r1) $h += ($levels[$k] - $h0[$k]) * (1 - smoothstep($r0, $r1, $d));
        }
        return $h;
    };
}

function gen_highlands(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $ang = rnd() * M_PI; $rx = cos($ang); $rz = sin($ang);
    $mH = 22 + rnd() * 16; $mLen = (35 + rnd() * 25) * $s; $ox = rnd() * 100; $oz = rnd() * 100;
    // a second, lower ridge and a few knolls break up the slopes
    $a2 = $ang + M_PI / 2 + (rnd() - .5); $r2x = cos($a2); $r2z = sin($a2); $off2 = (rnd() - .5) * 60 * $s;
    $knolls = []; for ($k = 0; $k < (int)round(5 * $s * $s); $k++) $knolls[] = [(rnd() * 2 - 1) * 90 * $s, (rnd() * 2 - 1) * 90 * $s, 8 + rnd() * 9, (9 + rnd() * 7) * $s];
    $f = function (float $x, float $z) use ($nz, $rx, $rz, $r2x, $r2z, $off2, $mH, $mLen, $ox, $oz, $half, $knolls, $s) {
        $h = $nz->fbm($x * .011 + $ox, $z * .011 + $oz) * 34 - 8;
        $along = $x * $rx + $z * $rz; $across = -$x * $rz + $z * $rx;
        $h += $mH * exp(-($across * $across) / (2 * 15 * 15)) * exp(-($along * $along) / (2 * $mLen * $mLen));
        $a2 = $x * $r2x + $z * $r2z; $c2 = -$x * $r2z + $z * $r2x - $off2;
        $h += 12 * exp(-($c2 * $c2) / (2 * 9 * 9)) * exp(-($a2 * $a2) / (2 * (45 * $s) ** 2));
        foreach ($knolls as [$kx, $kz, $kh, $kr]) { $d2 = ($x - $kx) ** 2 + ($z - $kz) ** 2; if ($d2 < 9 * $kr * $kr) $h += $kh * exp(-$d2 / (2 * $kr * $kr)); }
        return max(.3, .3 + ($h + 6) * edge_falloff($nz, $x, $z, $half));
    };
    $spawns = ring_spawns($players, 70 * $s, 80 * $s);
    // tanks on shelves and shoulders of the hills, from a low valley pad to a high one
    return [lift_spawns($f, $nz, $spawns, tier_levels($players, 4.0, 22.0), 9.0, 28.0), $spawns, []];
}

/*
 * Archipelago (remade 16 Nov, David's request): one big ring-shaped island, a torus, round a lagoon. The ring rises
 * and falls in hills and low saddles; every tank sits on the ring at its own height, and the lagoon in the middle
 * (with a few rocky islets) and the sea outside both splash shells.
 */
function gen_archipelago(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $Rr = (72 + rnd() * 6) * $s; $tube = 17 * $s;     // ring radius and half its width
    $ox = rnd() * 100; $oz = rnd() * 100;
    $spawns = ring_spawns($players, $Rr, $Rr);
    $isl = [];                                              // islets in the lagoon
    for ($k = 0, $tries = 0; $k < 3 && $tries < 30; $tries++) {
        $a = rnd() * M_PI * 2; $r = rnd() * ($Rr - $tube - 14 * $s);
        $c = [sin($a) * $r, -cos($a) * $r, (6 + rnd() * 5) * $s, 5 + rnd() * 7];
        foreach ($isl as $o) if (hypot($o[0] - $c[0], $o[1] - $c[1]) < $o[2] + $c[2] + 6) continue 2;
        $isl[] = $c; $k++;
    }
    $f = function (float $x, float $z) use ($nz, $Rr, $tube, $ox, $oz, $isl) {
        $h = -3 + $nz->fbm($x * .02, $z * .02) * 2;                       // sea and lagoon floor
        $r = hypot($x, $z); $ang = atan2($x, -$z);
        $w = $tube * (.8 + .45 * $nz->fbm(cos($ang) * 2 + $ox, sin($ang) * 2 + $oz, 3));   // the ring's width varies
        $d = abs($r - $Rr) + ($nz->fbm($x * .05 + 11, $z * .05) - .5) * 5;
        if ($d < $w) {
            // hills and saddles along the ring: crest 4–18 m, round in cross-section like a torus
            $crest = 4 + 14 * $nz->fbm(cos($ang) * 1.6 + $oz, sin($ang) * 1.6 + $ox, 4);
            $q = $d / $w;
            $h = max($h, -3 + ($crest + 3) * sqrt(max(0.0, 1 - $q * $q)) + ($nz->fbm($x * .06 + 3, $z * .06) - .5) * 2.5);
        }
        foreach ($isl as $k => [$cx, $cz, $R, $ih]) {
            $dd = hypot($x - $cx, $z - $cz); if ($dd > $R * 1.3) continue;
            $dd += ($nz->fbm($x * .07 + $k * 5, $z * .07) - .5) * $R * .4;
            if ($dd < $R) $h = max($h, -3 + ($ih + 3) * (1 - smoothstep($R * .3, $R, $dd)));
        }
        return max(.3, $h);
    };
    // each tank on the ring at its own height, from a low beach to a high bluff
    return [lift_spawns($f, $nz, $spawns, tier_levels($players, 4.5, 17.0), 8.0, 20.0), $spawns, []];
}

/*
 * Canyon (remade 16 Nov, then made shallower and wider the same day): a plateau cut by wide winding canyons. Every tank starts down on the floor of its own
 * canyon, at its own depth, and has to lob up over the rims; a canyon floor is a road it can drive along. One or two
 * more canyons cross the plateau between them. Wind is strong and blows from any direction over the rims.
 */
function gen_canyon(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $ox = rnd() * 100; $oz = rnd() * 100;
    $spawns = ring_spawns($players, 68 * $s, 76 * $s);
    $floors = tier_levels($players, 4.5, 11.0);   // floors well above the river level, even near the coast
    $W = 19.0 * $s;                                          // canyon half-width (floor about 19 m wide, about 55 m rim to rim)
    $cs = [];                                                // [x0, z0, dirx, dirz, floor, wiggle table, half-width]
    $mk = function (float $x0, float $z0, float $a, float $floor, int $seed, float $w) use ($nz, $half, $s) {
        $dx = sin($a); $dz = -cos($a); $L = (int)ceil($half * 1.6); $wig = [];
        $w0 = $nz->fbm($seed * 7.3, 2.5, 3);
        for ($t = -$L; $t <= $L; $t++) $wig[$t + $L] = ($nz->fbm($t * .018 + $seed * 7.3, 2.5, 3) - $w0) * 46 * $s;
        return [$x0, $z0, $dx, $dz, $floor, $wig, $L, $w];
    };
    /** Closest the canyon's centre line comes to any tank other than $skip (inside the map). */
    $clear = function (array $c, int $skip) use ($spawns, $half) {
        [$x0, $z0, $dx, $dz, , $wig, $L] = $c; $best = INF;
        for ($t = -$L; $t < $L; $t += 2) {
            $x = $x0 + $dx * $t - $dz * $wig[$t + $L]; $z = $z0 + $dz * $t + $dx * $wig[$t + $L];
            if (abs($x) > $half || abs($z) > $half) continue;
            foreach ($spawns as $k => $sp) if ($k !== $skip) $best = min($best, hypot($x - $sp[0], $z - $sp[1]));
        }
        return $best;
    };
    $seedN = 1;
    foreach ($spawns as $k => $sp) {                        // each tank's own canyon runs across the line to the centre,
        $best = null;                                        // and doesn't lead to another tank
        for ($try = 0; $try < 12; $try++) {
            $a = atan2($sp[0], -$sp[1]) + M_PI / 2 + (rnd() - .5) * .9;
            $c = $mk($sp[0], $sp[1], $a, $floors[$k], $seedN++, $W); $d = $clear($c, $k);
            if (!$best || $d > $best[1]) $best = [$c, $d];
            if ($d > 42 * $s) break;
        }
        $cs[] = $best[0];
    }
    for ($j = 0, $m = 1, $tries = 0; $j < $m && $tries < 40; $tries++) {   // a canyon between the tanks,
        $a = rnd() * M_PI; $o = (rnd() - .5) * 40 * $s;                                   // well away from all of them
        $c = $mk(cos($a) * $o, sin($a) * $o, $a, 2.0 + rnd() * 3, 20 + $tries, $W * (.8 + rnd() * .3));
        if ($clear($c, -1) < 42 * $s) continue;
        $cs[] = $c; $j++;
    }
    $f = function (float $x, float $z) use ($nz, $cs, $ox, $oz, $half, $s) {
        $plat = 15 + $nz->fbm($x * .012 + $ox, $z * .012 + $oz) * 6;            // plateau, 15–21 m (canyons about 8–18 m deep)
        $h = $plat;
        foreach ($cs as [$x0, $z0, $dx, $dz, $floor, $wig, $L, $w]) {
            $px = $x - $x0; $pz = $z - $z0;
            $t = $px * $dx + $pz * $dz; $q = -$px * $dz + $pz * $dx;
            $ti = (int)round($t); if ($ti < -$L || $ti >= $L) continue;
            $off = $wig[$ti + $L]; $slope = $wig[$ti + $L + 1] - $wig[$ti + $L];
            $d = abs($q - $off) / sqrt(1 + $slope * $slope) + ($nz->fbm($x * .09 + 3, $z * .09) - .5) * 3;
            if ($d > $w * 1.5) continue;
            $fl = $floor + max(0.0, abs($t) - 20) * .03;                          // floors rise gently away from the tank
            // steep walls with a ledge part way up
            $c = $fl + ($plat - $fl) * (.4 * smoothstep($w * .5, $w * .75, $d) + .6 * smoothstep($w * .95, $w * 1.4, $d));
            $h = min($h, $c);
        }
        $e = edge_falloff($nz, $x, $z, $half);
        return max(.3, $h * $e - 3 * (1 - $e));
    };
    return [$f, $spawns, []];
}

function gen_volcano(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $rR = 48 * $s; $wR = 9 * $s; $rc = 24 * $s;
    $f = function (float $x, float $z) use ($nz, $s, $rR, $wR, $rc, $half) {
        $r = hypot($x, $z);
        $h = 4 + $nz->fbm($x * .02, $z * .02) * 8;
        $h += (8 + $nz->fbm($x * .03 + 17, $z * .03) * 16) * exp(-(($r - $rR) ** 2) / (2 * $wR * $wR));   // ring of hills
        $h += 22 * max(0, 1 - $r / $rc) ** 1.6;                                                            // cone
        $h -= 6 * max(0, 1 - $r / (5 * $s)) ** 2;                                                          // vent
        $h += 7 * max(0, $nz->fbm($x * .025 + 50, $z * .025) - .55) * 4;                                  // old lava domes
        return max(.3, .3 + ($h + 2) * edge_falloff($nz, $x, $z, $half));
    };
    $spawns = ring_spawns($players, 72 * $s, 80 * $s);
    return [lift_spawns($f, $nz, $spawns, tier_levels($players, 4.5, 18.0), 9.0, 26.0), $spawns, ['vent' => [0.0, 0.0]]];
}

function gen_arctic(Noise $nz, int $n, float $half, int $players): array
{
    $a = rnd() * M_PI; $rx = cos($a); $rz = sin($a); $ox = rnd() * 100; $oz = rnd() * 100;
    $f = function (float $x, float $z) use ($nz, $rx, $rz, $ox, $oz, $half) {
        // stepped ice shelves: terraces about 3.5 m apart with short ice cliffs between them
        $q = $nz->fbm($x * .009 + $ox, $z * .009 + 3) * 4.2; $fl = floor($q); $fr = $q - $fl;
        $h = 3.2 + 3.5 * ($fl + smoothstep(.82, 1.0, $fr)) - 3.5;
        $h = max(3.2, $h) + $nz->fbm($x * .03, $z * .03) * .8;
        $along = $x * $rx + $z * $rz; $across = -$x * $rz + $z * $rx;
        $h += 7.5 * max(0, $nz->fbm($along * .012 + 7, $across * .045 + $oz, 4) - .5);                  // drifts
        $c = abs($nz->fbm($x * .012 + $oz, $z * .012 + $ox, 4) - .5);                                    // cracks between sheets
        if ($c < .035) $h = .4 + ($h - .4) * smoothstep(.012, .035, $c);
        $e = edge_falloff($nz, $x, $z, $half);
        return max(.3, $h * $e - 3 * (1 - $e));
    };
    $spawns = ring_spawns($players, 70 * $half / 120, 80 * $half / 120);
    // each tank on its own ice shelf: flat tops, cliff edges
    return [lift_spawns($f, $nz, $spawns, tier_levels($players, 3.5, 15.0), 12.0, 19.0), $spawns, []];
}

function gen_city(Noise $nz, int $n, float $half, int $players): array
{
    $ox = rnd() * 100;
    $f = function (float $x, float $z) use ($nz, $half, $ox) {
        $h = 3.0 + max(0.0, $nz->fbm($x * .008 + $ox, $z * .008) - .3) * 34 + $nz->fbm($x * .02, $z * .02) * .6;   // a city on hills
        $e = edge_falloff($nz, $x, $z, $half);
        return max(.3, $h * $e - 3 * (1 - $e));
    };
    $spawns = ring_spawns($players, 70 * $half / 120, 80 * $half / 120);
    return [lift_spawns($f, $nz, $spawns, tier_levels($players, 3.5, 15.0), 10.0, 26.0), $spawns, []];
}

function gen_lunar(Noise $nz, int $n, float $half, int $players): array
{
    $s = $half / 120; $spawns = ring_spawns($players, 70 * $s, 80 * $s);
    $mesas = [];                                             // flat-topped mesas, clear of the tanks
    for ($k = 0, $tries = 0; $k < (int)round(5 * $s * $s) && $tries < 60; $tries++) {
        $mx = (rnd() * 2 - 1) * 85 * $s; $mz = (rnd() * 2 - 1) * 85 * $s; $mr = (9 + rnd() * 9) * $s;
        foreach ($spawns as $sp) if (hypot($mx - $sp[0], $mz - $sp[1]) < $mr + 28) continue 2;
        $mesas[] = [$mx, $mz, $mr, 7 + rnd() * 7]; $k++;
    }
    $f = function (float $x, float $z) use ($nz, $mesas) {
        $h = 5 + $nz->fbm($x * .01, $z * .01) * 6 + $nz->fbm($x * .05 + 40, $z * .05) * .6;
        foreach ($mesas as $q => [$mx, $mz, $mr, $mh]) {
            $d = hypot($x - $mx, $z - $mz); if ($d > $mr + 9) continue;
            $d += ($nz->fbm($x * .08 + $q * 9, $z * .08) - .5) * 6;
            if ($d < $mr + 6) $h += $mh * (1 - smoothstep($mr, $mr + 6, $d));
        }
        return $h;
    };
    // one tank up on a mesa, another down in a basin
    return [lift_spawns($f, $nz, $spawns, tier_levels($players, 2.5, 15.0), 10.0, 22.0), $spawns, []];
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
    'archipelago' => [3, 7.0, 6.0, 3.0],       // sea stack: a rocky islet in the lagoon
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

    $rules = map_rules($mapId, $n, $extra);
    return ['terrain' => $t, 'spawns' => $pos, 'objects' => $objects, 'rules' => $rules];
}

/** The rules stored on a match for a map (physics, wind, special rules, crates). */
function map_rules(string $mapId, int $n, array $extra): array
{
    $R = MAPS[$mapId] ?? MAPS['highlands'];
    $windMax = (float)cfg('wind_max', 8) <= 0 ? 0.0 : $R['wind'][1];
    return [
        'map' => $mapId, 'n' => $n, 'gravity' => $R['gravity'], 'water' => $R['water'],
        'wind_min' => min($R['wind'][0], $windMax), 'wind_max' => $windMax,
        'wind_axis' => isset($extra['wind_axis']) && !empty($R['wind_axis']) ? round($extra['wind_axis'], 6) : null,
        'splash' => !empty($R['splash']), 'erupt_every' => (int)($R['erupt_every'] ?? 0), 'vent' => $extra['vent'] ?? null,
        'snow' => !empty($R['snow']), 'fall_mult' => (float)($R['fall_mult'] ?? 1.0), 'ranked' => true,
        'mode' => 'ranked', 'items' => 'safe', 'turn_seconds' => (int)cfg('turn_seconds', 40),
        'crate_every' => max(1, (int)cfg('crate_every', CRATE_EVERY)), 'crate_max' => max(1, (int)cfg('crate_max', CRATE_MAX)),
        'crate_radius' => max(0.0, (float)cfg('crate_radius', CRATE_RADIUS)),
        'crate_high' => !empty($R['crate_high']),
        // phase 4: one crate per tank, near it ('centre' = the old single crate between the tanks; older matches)
        'crates' => cfg('crate_drops', 'paired') === 'centre' ? 'centre' : 'paired', 'crate_near' => max(8.0, (float)cfg('crate_near', CRATE_NEAR)),
    ];
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
    if ((float)$rules['wind_max'] <= 0 || $turn < sd_turn($rules)) return 0.0;
    return SD_WIND * ($turn - sd_turn($rules) + 1);
}
/** The turn sudden death starts: 30, or never in Scenarios (missions have their own round limits). */
function sd_turn(array $rules): int { return (int)($rules['sd_turn'] ?? SD_TURN); }

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
        if (sqrt(($p[0] - $pl['x']) ** 2 + ($p[1] - ($pl['y'] + 1.1 + (float)sk($pl, 'hit_dy', 0.0))) ** 2 + ($p[2] - $pl['z']) ** 2) < (float)sk($pl, 'hit_r', ENG_TANK_HIT)) return 'tank';
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
function settle(Terrain $t, array &$players, array $rules, ?int $causer = null, float $causerMult = 1.0): array
{
    $falls = []; $mult = (float)($rules['fall_mult'] ?? 1.0);
    foreach ($players as $s => &$pl) {
        $g = $t->heightAt($pl['x'], $pl['z']);
        if (abs($pl['y'] - $g) <= .03) continue;
        $drop = $pl['y'] - $g; $pl['y'] = $g; $dmg = 0;
        $abs = 0;
        if ($drop > 3 && $pl['alive']) {
            // Demolitions: falls the shooter's shell causes count double; Reinforced Tracks: falls on you are halved
            $k = $mult * ($causer !== null && $causer !== $s ? $causerMult : 1.0) * (float)sk($pl, 'fall_taken', 1.0);
            [$dmg, $abs] = apply_damage($players, $pl, $s, (int)round(($drop - 3) * 4 * $k), $pl['last_hit_by']);
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

/** Where a shell leaves the barrel: turret height and barrel length (the Behemoth's gun sits higher and is longer). */
function muzzle(array $me, array $d): array
{
    $th = (float)sk($me, 'turret_h', ENG_TURRET_H); $bl = (float)sk($me, 'barrel', ENG_BARREL);
    return [$me['x'] + $d[0] * $bl, $me['y'] + $th + $d[1] * $bl, $me['z'] + $d[2] * $bl];
}

/**
 * Fly one shot. $players is keyed by seat: ['x','y','z','hp','alive','last_hit_by','damage_dealt'] and is updated in place.
 * Returns the replay record: projectiles (sampled paths + explosions), terrain ops and post-shot settling.
 */
function simulate_shot(Terrain $t, array &$players, array &$objects, int $seat, float $yaw, float $elev, float $power, string $wk, array $wind, array $rules): array
{
    $me = $players[$seat];
    $w = shooter_weapon($wk, $me);
    $y = deg2rad($yaw); $e = deg2rad($elev);
    $d = [cos($e) * sin($y), sin($e), -cos($e) * cos($y)];
    $o = muzzle($me, $d);
    $sp = $power / 100 * ENG_VMAX;
    $ops = [];
    $projs = fly($t, $players, $objects, [new_proj($o, [$d[0] * $sp, $d[1] * $sp, $d[2] * $sp], 0.0, false)], $w, $seat, $wind, $rules, $ops);
    $falls = settle($t, $players, $rules, $seat, (float)$w['fall']);
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
    if ($lost > 0 && isset(FX::$e['taken'])) FX::$e['taken'][$vseat] = (int)(FX::$e['taken'][$vseat] ?? 0) + $lost;   // Scenarios: for the stars
    if ($by !== null && ($lost > 0 || $abs > 0)) {
        $victim['last_hit_by'] = $by;
        if ($by !== $vseat) $players[$by]['damage_dealt'] += $lost;
    }
    return [$lost, $abs];
}

function explode_at(Terrain $t, array &$players, array &$objects, array $p, array $w, ?int $owner, bool $splash, array &$ops): array
{
    if (!empty($w['flare'])) return flare_burst($players, $p, (float)$w['flare'], $owner);   // phase 5: lights, nothing else
    $R = $w['radius'] * ($splash ? .6 : 1);
    $Rc = 0.0; $mound = !empty($w['mound']) && !$splash; $out = [];
    if ($mound) {                                                    // Earthmover: build up instead of digging
        $ops[] = $t->raise($p[0], $p[2], $w['radius'], (float)$w['mound'], 2);
        $out['mound'] = true;
    } elseif (!$splash) {
        $Rc = $w['radius'] * ($p[1] < $t->water ? .8 : 1) * (float)($w['crater'] ?? 1.0);   // Demolitions: ×1.3
        $ops[] = $t->crater($p[0], $p[2], $Rc);
    }
    $reach = $R * 1.45; $hits = [];
    if ($w['dmg'] > 0) foreach ($players as $s => &$pl) {
        if (!$pl['alive']) continue;
        // co-op (Scenarios): a teammate's shell doesn't hurt you (your own still does)
        if ($owner !== null && $owner !== $s && isset($pl['team'], $players[$owner]['team']) && $pl['team'] === $players[$owner]['team']) continue;
        $d = sqrt(($p[0] - $pl['x']) ** 2 + ($p[1] - ($pl['y'] + 1 + (float)sk($pl, 'hit_dy', 0.0))) ** 2 + ($p[2] - $pl['z']) ** 2);
        $hr = (float)sk($pl, 'hit_r', ENG_TANK_HIT);                    // Low Profile: 2.1 m; the Behemoth: 4.6 m
        $reachT = max($reach, $hr + 1.0);
        if ($d < $reachT) {
            $dmg = (int)round($w['dmg'] * ($d < $hr ? 1 : (1 - ($d - $hr) / ($reachT - $hr)) ** .9));
            if ($dmg > 0 && $d < 3 && sk($pl, 'dug_in', false)) $dmg = (int)round($dmg * (float)sk($pl, 'dug_in', 1.0));   // Dug In: 10% less
            $wp = $dmg > 0 ? weak_point_hit($pl, $s, $p, $dmg) : null;   // the Behemoth's weak points take double
            if ($wp) $dmg *= 2;
            if ($dmg > 0) {
                [$lost, $abs] = apply_damage($players, $pl, $s, $dmg, $owner);
                $h = ['seat' => $s, 'dmg' => $lost]; if ($abs) $h['abs'] = $abs;
                if ($wp) $h['wp'] = $wp;
                $hits[] = $h;
            }
        }
    }
    unset($pl);
    if (!empty($w['fire']) && !$splash) {                           // Napalm: a fire zone that burns for 3 turns
        $id = FX::$e['fire_seq']++;
        $fire = ['id' => $id, 'x' => round($p[0], 3), 'z' => round($p[2], 3), 'r' => FIRE_R, 'left' => (int)($w['fire_turns'] ?? FIRE_TURNS), 'owner' => $owner];
        FX::$e['fires'][] = $fire;
        $out['fire'] = $fire;
    }
    $crates = [];
    foreach ($objects as $k => &$o) {                                // a blast within 4 m claims a crate; further out it wrecks it
        if ($o['type'] !== 'crate' || $o['state'] !== 'live') continue;
        $d = sqrt(($p[0] - $o['x']) ** 2 + ($p[1] - ($o['y'] + .8)) ** 2 + ($p[2] - $o['z']) ** 2);
        if ($d <= (float)($w['claim'] ?? CRATE_CLAIM) && $owner !== null) {   // Scavenger: 6 m
            $crates[] = open_crate($players, $o, $owner);
        } elseif ($d < $R && !$mound && empty($o['mission'])) {        // a mission's crates are sturdy: only claiming opens them
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
function start_turn(Terrain $t, array &$players, array &$objects, array $rules, int $turn, ?int $prevSeat = null, ?int $seat = null): ?array
{
    $ops = []; $out = ['turn' => $turn]; $did = false;
    if (!empty($rules['mission'])) $did = mission_turn_start($t, $players, $objects, $rules, $turn, $prevSeat, $seat, $out) || $did;

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
    if (!empty(FX::$e['cloak'])) {                                    // invisibility wears off at the start of its tank's 3rd turn
        foreach (FX::$e['cloak'] as $s => $c) {
            $s = (int)$s;
            if (!$players[$s]['alive']) { unset(FX::$e['cloak'][$s]); continue; }
            if ($s !== $seat) continue;
            if (--FX::$e['cloak'][$s]['left'] > 0) continue;
            unset(FX::$e['cloak'][$s]);
            $out['cloak_end'][] = ['seat' => $s, 'at' => [round($players[$s]['x'], 3), round($players[$s]['y'], 3), round($players[$s]['z'], 3)]];
            $did = true;
        }
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
                if ($f['owner'] !== null && $f['owner'] !== $s && isset($pl['team'], $players[$f['owner']]['team']) && $pl['team'] === $players[$f['owner']]['team']) continue;
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
    if ($turn >= sd_turn($rules)) {
        $drain = [];
        foreach ($players as $s => &$pl) {
            if (!$pl['alive']) continue;
            [$lost] = apply_damage($players, $pl, $s, SD_DRAIN, null, false);
            $drain[] = ['seat' => $s, 'dmg' => $lost];
        }
        unset($pl);
        $out['sudden'] = ['first' => $turn === sd_turn($rules), 'drain' => $drain, 'wind_bonus' => sd_wind_bonus($rules, $turn)];
        $did = true;
    }
    $out['ops'] = $ops;
    $out['falls'] = $did ? settle($t, $players, $rules) : [];
    settle_crates($t, $objects);
    // heal over time: the tank whose turn it is repairs a little, after this turn's damage, until sudden death
    // (in Scenarios only the players repair: computer tanks don't)
    if ($seat !== null && $turn < sd_turn($rules) && ($r = regen_rate($rules)) > 0 && isset($players[$seat]) && !(!empty($rules['mission']) && isset($rules['bots'][$seat]))) {
        $me = &$players[$seat];
        if ($me['alive'] && $me['hp'] > 0 && $me['hp'] < max_hp($me)) {
            $heal = min($r, max_hp($me) - $me['hp']); $me['hp'] += $heal;
            $out['regen'] = ['seat' => $seat, 'heal' => $heal]; $did = true;
        }
        unset($me);
    }
    if (!empty($rules['mission']) && $seat !== null) $did = mission_seat_start($players, $rules, $turn, $seat, $out) || $did;
    $every = max(1, (int)($rules['crate_every'] ?? CRATE_EVERY));
    // Scenarios count rounds, not turns: the players' crates come at the start of every 4th round
    $due = !empty($rules['mission']) ? isset($out['round']) && $out['round'] % MISSION_CRATE_ROUNDS === 0 : $turn % $every === 0;
    if ($due && ($rules['crates'] ?? 'centre') !== 'none') {
        $cs = ($rules['crates'] ?? 'centre') === 'paired' ? drop_paired($t, $players, $objects, $rules, $turn)
            : (($c = drop_crate($t, $players, $objects, $rules, $turn)) ? [$c] : []);
        if ($cs) { $out['crates'] = $cs; $did = true; }
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
 * the centre of the circle they stand on (every match spawns its tanks on one shared circle, so until anyone drives
 * it is the same distance from each of them; after that, the circle that best fits where they are). Falls back to
 * their average position if they stand nearly in a line or that centre is more than 60 m from them.
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
 * Maps with crate_high (Canyon): the spot must also be up on the high ground, no more than 3 m below the lowest
 * living tank and on dry land, never down in a gorge. The search starts with the usual circle and, while it holds
 * too few such spots (on Canyon the point between the tanks is in the gorge), widens in 15 m steps up to 60 m; the
 * crate lands at a random one of the fairest tenth of the high spots found (closest to the same distance from every tank).
 * Returns [x, z, centre x, centre z] or null if nothing is clear.
 */
function crate_spot(Terrain $t, array $players, array $objects, float $radius, bool $high = false): ?array
{
    if (!($c = crate_centre($players))) return null;
    $floor = -INF;
    if ($high) {
        $floor = max($t->water + .6, min(array_map(fn($p) => (float)$p['y'], array_filter($players, fn($p) => $p['alive']))) - 3);
    }
    $ok = fn(float $x, float $z) => crate_spot_ok($t, $players, $objects, $x, $z) && (!$high || $t->heightAt($x, $z) >= $floor);
    if (!$high) {
        for ($k = 0; $k < 80; $k++) {
            $a = rnd() * M_PI * 2; $r = sqrt(rnd()) * $radius;
            $x = $c[0] + cos($a) * $r; $z = $c[1] + sin($a) * $r;
            if ($ok($x, $z)) return [$x, $z, $c[0], $c[1]];
        }
        return null;
    }
    for ($R = max(15.0, $radius); $R <= 60 + 1e-9; $R += 15) {
        $found = [];
        for ($k = 0; $k < 200; $k++) {
            $a = rnd() * M_PI * 2; $r = sqrt(rnd()) * $R;
            $x = $c[0] + cos($a) * $r; $z = $c[1] + sin($a) * $r;
            if ($ok($x, $z)) $found[] = [crate_fairness($players, $x, $z), $x, $z];
        }
        if (count($found) < 6) continue;
        sort($found);
        $pick = $found[mt_rand(0, max(3, intdiv(count($found), 10)) - 1)];
        return [$pick[1], $pick[2], $c[0], $c[1]];
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
    $high = (bool)($rules['crate_high'] ?? (($rules['map'] ?? '') === 'canyon'));   // Canyon matches from before this rule get it too
    $spot = crate_spot($t, $players, $objects, (float)($rules['crate_radius'] ?? CRATE_RADIUS), $high);
    if (!$spot) return null;
    $x = round($spot[0], 3); $z = round($spot[1], 3);
    // contents are rolled when the crate is claimed (explode_at), from the pool named here
    $c = ['id' => count($objects), 'type' => 'crate', 'x' => $x, 'z' => $z, 'y' => max($t->heightAt($x, $z), $t->water),
          'pool' => (string)$rules['items'], 'state' => 'live', 'turn' => $turn];
    $objects[] = $c;
    return $c;
}

/*
 * Paired drops (phase 4, David's choice): one crate per tank, each the same distance from its own tank, so nobody has
 * to give up a shot to get one. Drive over your crate to pick it up (a turn or two of driving), or shoot any crate as
 * before: within 4 m claims it, a blast further out wrecks it, so an enemy's crate can be stolen or denied.
 * A tank that still has a crate waiting gets no new one. Every crate in a drop is the same distance from its own tank
 * (crate_near, 18 m by default; nearer or farther only if some tank has no clear spot at that distance), at least
 * PAIR_MARGIN metres farther from every other tank, on dry ground the tank can drive to.
 */
const PAIR_MARGIN = 10.0;

/**
 * Clear, dry spots exactly $D metres from $seat's tank, nearer to it than to any other tank; with $reach, only those
 * the tank can drive to (a tank stuck in a deep crater can't reach any, so the drop then falls back to unreachable spots).
 */
function paired_spots(Terrain $t, array $players, array $objects, int $seat, float $D, bool $reach = true): array
{
    $me = $players[$seat]; $out = []; $lim = $t->half - DRIVE_EDGE - 2;
    $blk = drive_blockers($players, $objects, $seat); $a0 = rnd() * M_PI * 2;
    for ($k = 0; $k < 24; $k++) {
        $a = $a0 + $k / 24 * M_PI * 2;
        $x = $me['x'] + sin($a) * $D; $z = $me['z'] - cos($a) * $D;
        if (abs($x) > $lim || abs($z) > $lim || $t->heightAt($x, $z) < $t->water + DRIVE_SHORE + .2) continue;
        foreach ($players as $s => $pl) if ($s !== $seat && $pl['alive'] && hypot($pl['x'] - $x, $pl['z'] - $z) < $D + PAIR_MARGIN) continue 2;
        foreach ($objects as $o) {
            if ($o['type'] === 'building' && $o['hp'] > 0 && abs($x - $o['x']) < $o['hw'] + 3 && abs($z - $o['z']) < $o['hd'] + 3) continue 2;
            if ($o['type'] === 'crate' && $o['state'] === 'live' && hypot($o['x'] - $x, $o['z'] - $z) < 6) continue 2;
        }
        $out[] = [$x, $z];
        if (!$reach) continue;
        array_pop($out);
        // the tank must be able to drive there: straight at it, within three times the distance of allowance
        $r = drive_path($t, $blk, (float)$me['x'], (float)$me['z'], $x, $z, $D * 3);
        if (hypot($r["x"] - $x, $r["z"] - $z) > CRATE_PICK - .3) continue;   // with a margin, so rounding never puts it out of reach
        $out[] = [$x, $z];
    }
    return $out;
}

/** Drop one crate for each living tank that has none waiting. Returns the new crates (each with 'for' => seat). */
function drop_paired(Terrain $t, array $players, array &$objects, array $rules, int $turn): array
{
    if (!item_pool((string)($rules['items'] ?? 'off'))) return [];
    $waiting = [];
    foreach ($objects as $o) if ($o['type'] === 'crate' && $o['state'] === 'live' && isset($o['for'])) $waiting[(int)$o['for']] = true;
    // an invisible tank gets no crate this drop: where its crate lands would give it away
    $seats = array_keys(array_filter($players, fn($p) => $p['alive'] && !isset($waiting[$p['seat']]) && !isset(FX::$e['cloak'][$p['seat']])
        && !(!empty($rules['mission']) && isset($rules['bots'][$p['seat']]))));   // Scenarios: crates for the players only
    if (!$seats) return [];
    $near = (float)($rules['crate_near'] ?? CRATE_NEAR);
    // one distance for everyone in this drop; spots each tank can drive to if possible, otherwise (a tank dug into
    // a crater) any clear dry spot at that distance for that tank, which can still be shot open
    foreach ([true, false] as $strict) foreach ([0, 3, -3, 6, -6, 10] as $dd) {
        $D = max(8.0, $near + $dd); $pick = [];
        foreach ($seats as $s) {
            $sp = paired_spots($t, $players, $objects, $s, $D); $reach = true;
            if (!$sp && !$strict) { $sp = paired_spots($t, $players, $objects, $s, $D, false); $reach = false; }
            if (!$sp) continue 2;
            $pick[$s] = [...$sp[mt_rand(0, count($sp) - 1)], $reach];
        }
        $out = [];
        foreach ($pick as $s => [$x, $z, $reach]) {
            $x = round($x, 3); $z = round($z, 3);
            $c = ['id' => count($objects), 'type' => 'crate', 'x' => $x, 'z' => $z, 'y' => max($t->heightAt($x, $z), $t->water),
                  'pool' => (string)$rules['items'], 'state' => 'live', 'turn' => $turn, 'for' => $s, 'd' => $D];
            if (!$reach) $c['far'] = true;                          // its tank can't drive there right now
            $objects[] = $c; $out[] = $c;
        }
        return $out;
    }
    return [];
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

/* =====================================================================
 * Driving (phase 3): on its turn, before firing, a tank may drive up to the match's per-turn allowance
 * (rules 'move', 10 m by default) in one or more hops. Each hop goes in a straight line toward a point
 * the player picked and stops early at a slope too steep to climb or descend, water, the map edge,
 * another tank or wreck, a building, a crate, or when the allowance runs out. Distance is measured
 * along the ground, so climbing costs more than driving on the flat.
 * drive_path() has a twin, drive() in assets/terrain.js, which the battle page uses to preview a hop.
 * Both use the same steps and arithmetic (tests/drive_parity checks they agree).
 * ===================================================================== */
const DRIVE_STEP = 0.25, DRIVE_UP = 1.0, DRIVE_DOWN = 1.2, DRIVE_SAMPLE = 4, DRIVE_EDGE = 6.0, DRIVE_SHORE = 0.6;
const DRIVE_TANK_R = 4.5, DRIVE_CRATE_R = 2.6, DRIVE_BUILDING_PAD = 1.8, DRIVE_MIN = 0.5;

/** Per-turn driving allowance in metres for a match (matches from before phase 3 use the site setting). */
function move_budget(array $rules): float { return max(0.0, (float)($rules['move'] ?? cfg('move_per_turn', 10))); }

/**
 * What a tank can't drive through: other tanks (wrecks too) as circles, standing buildings as boxes.
 * Crates don't block: driving over one picks it up (crate_pickups()).
 */
function drive_blockers(array $players, array $objects, int $seat): array
{
    $b = ['c' => [], 'b' => []];
    foreach ($players as $s => $pl) if ($s !== $seat) $b['c'][] = [(float)$pl['x'], (float)$pl['z'], DRIVE_TANK_R];
    foreach ($objects as $o) {
        if ($o['type'] === 'building' && $o['hp'] > 0) $b['b'][] = [(float)$o['x'], (float)$o['z'], $o['hw'] + DRIVE_BUILDING_PAD, $o['hd'] + DRIVE_BUILDING_PAD];
    }
    return $b;
}

/** Distance from point (px, pz) to the segment from (ax, az) to (bx, bz). */
function seg_dist(float $px, float $pz, float $ax, float $az, float $bx, float $bz): float
{
    $dx = $bx - $ax; $dz = $bz - $az; $L2 = $dx * $dx + $dz * $dz;
    $k = $L2 > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($pz - $az) * $dz) / $L2)) : 0.0;
    return hypot($px - ($ax + $dx * $k), $pz - ($az + $dz * $k));
}

/**
 * Crates picked up by driving: every live crate within CRATE_PICK metres (Scavenger: more) of the straight route the tank
 * just drove. Opens each one for the driver (contents are rolled now, as with a shell claim) and marks it claimed.
 * Returns [['id', 'by', 'item', 'lost'], ...] in the order they were reached.
 */
function crate_pickups(array &$players, array &$objects, int $seat, float $x0, float $z0, float $x1, float $z1): array
{
    $r = (float)sk($players[$seat], 'pick', CRATE_PICK); $got = [];
    $order = [];
    foreach ($objects as $k => $o) {
        if ($o['type'] !== 'crate' || $o['state'] !== 'live') continue;
        if (seg_dist((float)$o['x'], (float)$o['z'], $x0, $z0, $x1, $z1) <= $r) $order[$k] = hypot($o['x'] - $x0, $o['z'] - $z0);
    }
    asort($order);
    foreach (array_keys($order) as $k) $got[] = open_crate($players, $objects[$k], $seat);
    return $got;
}

/** $seat opens a crate (by shell or by driving): its contents are rolled now and go into the tank's one slot. */
function open_crate(array &$players, array &$o, int $seat): array
{
    // what's inside is decided only now, so nobody (not even a player reading the network traffic) knows before
    $pool = item_pool((string)($o['pool'] ?? 'safe'));
    if (sk($players[$seat], 'dug_in', false)) unset($pool['relocate']);   // Dug In can't use it, so never finds it
    $o['item'] ??= roll_item($pool) ?? 'repair';
    $prev = $players[$seat]['item'] ?? null;
    $players[$seat]['item'] = $o['item'];
    $o['state'] = 'claimed'; $o['by'] = $seat;
    return ['id' => $o['id'], 'by' => $seat, 'item' => $o['item'], 'lost' => $prev];
}

/**
 * Drive from (x0, z0) toward (tx, tz) with $budget metres of allowance.
 * Returns ['path' => [[x, y, z], ...] every metre, 'x', 'z' (where it stopped), 'used' (metres along the ground),
 *          'stop' => arrived | fuel | steep | cliff | water | edge | blocked].
 */
function drive_path(Terrain $t, array $blk, float $x0, float $z0, float $tx, float $tz, float $budget): array
{
    $dx = $tx - $x0; $dz = $tz - $z0; $L = sqrt($dx * $dx + $dz * $dz);
    $x = $x0; $z = $z0; $h = $t->heightAt($x, $z); $used = 0.0; $k = 0; $stop = 'arrived';
    $path = [[$x, $h, $z]];
    if ($L < 0.01) return ['path' => $path, 'x' => $x, 'z' => $z, 'used' => 0.0, 'stop' => $stop];
    $ux = $dx / $L; $uz = $dz / $L; $lim = $t->half - DRIVE_EDGE; $shore = $t->water + DRIVE_SHORE;
    while (true) {
        $done = $k * DRIVE_STEP;
        if ($done >= $L - 1e-9) break;
        $s = min(DRIVE_STEP, $L - $done);
        $nx = $x + $ux * $s; $nz = $z + $uz * $s;
        if (abs($nx) > $lim || abs($nz) > $lim) { $stop = 'edge'; break; }
        $nh = $t->heightAt($nx, $nz);
        if ($nh < $shore) { $stop = 'water'; break; }
        $g = ($nh - $h) / $s;
        if ($g > DRIVE_UP) { $stop = 'steep'; break; }
        if ($g < -DRIVE_DOWN) { $stop = 'cliff'; break; }
        $seg = sqrt($s * $s + ($nh - $h) * ($nh - $h));
        if ($used + $seg > $budget + 1e-9) { $stop = 'fuel'; break; }
        foreach ($blk['c'] as $c) {                       // inside a circle and getting closer to its centre
            $a = $nx - $c[0]; $b = $nz - $c[1]; $d2 = $a * $a + $b * $b;
            if ($d2 < $c[2] * $c[2]) { $a0 = $x - $c[0]; $b0 = $z - $c[1]; if ($d2 < $a0 * $a0 + $b0 * $b0) { $stop = 'blocked'; break 2; } }
        }
        foreach ($blk['b'] as $c) if (abs($nx - $c[0]) < $c[2] && abs($nz - $c[1]) < $c[3]) { $stop = 'blocked'; break 2; }
        $x = $nx; $z = $nz; $h = $nh; $used += $seg; $k++;
        if ($k % DRIVE_SAMPLE === 0) $path[] = [$x, $h, $z];
    }
    $last = end($path);
    if ($last[0] !== $x || $last[2] !== $z) $path[] = [$x, $h, $z];
    return ['path' => $path, 'x' => $x, 'z' => $z, 'used' => $used, 'stop' => $stop];
}

/**
 * Move $seat toward (tx, tz) with $left metres of allowance. Changes $players in place.
 * Returns the replay record for a 'move' event, or ['error' => code].
 */
function drive_tank(Terrain $t, array &$players, array &$objects, int $seat, float $tx, float $tz, float $left): array
{
    $me = &$players[$seat];
    if ($left < DRIVE_MIN) return ['error' => 'no_fuel'];
    $r = drive_path($t, drive_blockers($players, $objects, $seat), (float)$me['x'], (float)$me['z'], $tx, $tz, $left);
    if ($r['used'] < DRIVE_MIN - 1e-9) return ['error' => 'cant_drive', 'stop' => $r['stop']];
    $x = round($r['x'], 3); $z = round($r['z'], 3); $y = round($t->heightAt($x, $z), 3);
    $ev = ['from' => [round($me['x'], 3), round($me['y'], 3), round($me['z'], 3)], 'to' => [$x, $y, $z],
           'path' => array_map('r3', $r['path']), 'used' => round($r['used'], 2), 'stop' => $r['stop']];
    $ev['path'][count($ev['path']) - 1] = [$x, $y, $z];
    $x0 = (float)$me['x']; $z0 = (float)$me['z'];
    [$me['x'], $me['y'], $me['z']] = [$x, $y, $z];
    unset($me);
    if ($got = crate_pickups($players, $objects, $seat, $x0, $z0, $x, $z)) $ev['pickup'] = $got;   // drove over a crate
    return $ev;
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
            if ($me['hp'] >= max_hp($me)) return ['error' => 'full_hp'];
            $heal = min(REPAIR_HP, max_hp($me) - $me['hp']); $me['hp'] += $heal; $ev['heal'] = $heal;
            break;
        case 'shield':
            FX::$e['shields'][$seat] = ['hp' => SHIELD_HP, 'left' => max(1, SHIELD_TURNS + (int)sk($me, 'item_turns', 0))];   // Scavenger: 1 less
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
        case 'cloak':
            if (isset(FX::$e['cloak'][$seat])) return ['error' => 'already_on'];
            FX::$e['cloak'][$seat] = ['left' => CLOAK_ROUNDS, 'turn' => $turn, 'seen' => [round($me['x'], 3), round($me['y'], 3), round($me['z'], 3)]];
            break;
        case 'relocate':
            if (sk($me, 'dug_in', false)) return ['error' => 'dug_in'];
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

/**
 * Spotter (phase 4): where a shot landed relative to the nearest enemy, along the line from the shooter to that enemy.
 * $long: metres past (+) or short of (−) the enemy; $right: metres right (+) or left (−) of the line, seen from the
 * shooter. A Cluster shell counts where its bomblets landed on average. Null if it left the map or no enemy is left.
 */
/** Is $seat invisible right now? */
function cloaked(int $seat): bool { return isset(FX::$e['cloak'][$seat]); }

/** The tank fires: invisibility ends. Returns where it is (for everyone's replay), or null if it wasn't hidden. */
function cloak_reveal(array $players, int $seat): ?array
{
    if (!cloaked($seat)) return null;
    unset(FX::$e['cloak'][$seat]);
    return [round($players[$seat]['x'], 3), round($players[$seat]['y'], 3), round($players[$seat]['z'], 3)];
}

function spot_report(array $players, int $seat, array $res, array $enemies): ?array
{
    $booms = [];
    foreach ($res['projectiles'] as $pr) if ($pr['boom']) $booms[] = $pr['boom'];
    if (!$booms || !$enemies) return null;
    $first = $res['projectiles'][0]['boom'] ?? null;
    $L = $first ? [$first['x'], $first['z']]
        : [array_sum(array_column($booms, 'x')) / count($booms), array_sum(array_column($booms, 'z')) / count($booms)];
    $me = $players[$seat]; $best = null;
    foreach ($enemies as $s) {
        $e = $players[$s]; $d = hypot($e['x'] - $L[0], $e['z'] - $L[1]);
        if (!$best || $d < $best[1]) $best = [$s, $d];
    }
    $e = $players[$best[0]];
    $dx = $e['x'] - $me['x']; $dz = $e['z'] - $me['z']; $D = hypot($dx, $dz) ?: 1.0;
    $ux = $dx / $D; $uz = $dz / $D;                                   // forward; right is (−uz, ux)
    $lx = $L[0] - $me['x']; $lz = $L[1] - $me['z'];
    return ['seat' => $best[0], 'long' => round($lx * $ux + $lz * $uz - $D, 1), 'right' => round(-$lx * $uz + $lz * $ux, 1), 'miss' => round($best[1], 1)];
}
