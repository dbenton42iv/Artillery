<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Scenarios (phase 5): co-op missions against computer tanks. This file is the engine side (no database), so the
 * command-line simulators can play missions too. includes/scenario.php stores missions, starts and finishes them.
 *
 * A mission is a JSON definition (the missions table): a map recipe and terrain seed, where the players start, the
 * computer tanks (with the round each one arrives), map objects (targets, walls, a depot to protect, crates), an
 * optional convoy route and rivers, a goal, a round limit and the thresholds for the 2nd and 3rd star.
 *
 *   Teams      players are team 0, computer tanks team 1. A teammate's shell or fire never hurts you.
 *   Rounds     a round ends when the turn comes back round to the players (FX 'round'). Waves, the convoy, flares
 *              and round limits all count rounds, so a mission plays the same with 1 player or 4.
 *   Waves      a computer tank with round > 1 is held back (FX 'reserve') and parachutes in at the start of that
 *              round, or earlier if the field is clear of enemies.
 *   Night      enemy tanks can't be seen until a flare shell bursts within 40 m of them; they then stay lit for 2
 *              rounds. Players carry flare shells (FX 'flare_ammo').
 *   Behemoth   one giant tank with three weak points. A blast centred within 3 m of a weak point does double damage
 *              to the Behemoth and wears the weak point down; each weak point lost changes how it fights.
 *   Stars      1 for completing the mission, 1 for using no more shots per player than the par, 1 for taking no more
 *              damage per player than the limit.
 * No sudden death in Scenarios: missions have round limits instead.
 */
const MISSION_GOALS = [
    'destroy_all'     => 'Destroy every enemy tank',
    'destroy_targets' => 'Destroy the targets',
    'survive'         => 'Survive',
    'protect'         => 'Protect the depot',
    'crates'          => 'Claim the supply crates',
];
const MISSION_KINDS = ['tank' => 'Tank', 'gun' => 'Gun emplacement', 'behemoth' => 'Behemoth'];
const MISSION_OBJECTS = ['target' => 'Target', 'wall' => 'Wall', 'depot' => 'Depot (protect)', 'crate' => 'Supply crate'];
const MISSION_MAX_ENEMIES = 12, MISSION_MAX_OBJECTS = 40;
const ENEMY_TEAM = 1;
const FLARE_R = 40.0, FLARE_ROUNDS = 3;               // lit for the rest of this round and the next 2 rounds
const BOSS_SHIELD_EVERY = 3;                           // the shield generator raises a 40 hp shield every 3rd round
const MISSION_CRATE_ROUNDS = 4;                        // paired supply drops for the players: every 4th round
const GUN_HP = 150, BOSS_HP = 600, WP_HP = 90, WP_R = 3.0;
/* Computer tanks' hp scales with the number of players (more guns on your side, tougher enemies). Missions can also
 * bring extra enemies for bigger groups (an enemy's 'min': it only comes with at least that many players). */
const ENEMY_HP_SCALE = [1 => 0.8, 2 => 1.0, 3 => 1.25, 4 => 1.5];
/* The Behemoth's weak points, in the hull's own frame: x right, y up, z back (it faces −z, like every tank). */
const BOSS_WP = [
    'gun'    => ['name' => 'Main gun',         'off' => [0.0, 3.4, -4.6], 'lost' => 'It switches to cluster barrages'],
    'radar'  => ['name' => 'Radar mast',       'off' => [-2.0, 5.8, 2.8], 'lost' => 'Its aim gets much worse'],
    'shield' => ['name' => 'Shield generator', 'off' => [2.3, 3.1, 3.6],  'lost' => 'Its shield stops coming back (every 3rd round while it stands)'],
];
/* Behemoth body: hit radius, centre height, muzzle, and the size it is drawn at. */
const BOSS_MODS = ['hit_r' => 4.6, 'hit_dy' => 1.3, 'turret_h' => 4.4, 'barrel' => 6.6, 'scale' => 2.1];

/* =====================================================================
 * Mission definitions: cleaning and checking what the editor (or a seed file) sends
 * ===================================================================== */

/** Half the map's width in metres for a mission size (160 or 240 cells). */
function mission_half(int $size): float { return $size * ENG_CELL / 2; }

/**
 * Clean a mission definition. Returns [definition, errors]. Errors are short sentences for the editor; a definition
 * with errors can be saved as a draft but not published or played.
 */
function mission_clean($in): array
{
    $in = is_array($in) ? $in : [];
    $err = [];
    $num = fn($v, float $lo, float $hi, float $def) => is_numeric($v) ? max($lo, min($hi, (float)$v)) : $def;
    $int = fn($v, int $lo, int $hi, int $def) => is_numeric($v) ? max($lo, min($hi, (int)$v)) : $def;
    $str = fn($v, int $max, string $def = '') => trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($v ?? $def)), 0, $max));
    $d = [];
    $d['title'] = $str($in['title'] ?? '', 40, 'New mission') ?: 'New mission';
    $d['blurb'] = $str($in['blurb'] ?? '', 240);
    $d['map'] = isset(MAPS[$in['map'] ?? '']) ? $in['map'] : 'highlands';
    $d['seed'] = $int($in['seed'] ?? null, 1, 2147483647, 1);
    $d['size'] = (int)($in['size'] ?? 160) === 240 ? 240 : 160;
    $half = mission_half($d['size']); $lim = $half - 8;
    $pt = function ($p, string $what) use ($lim, &$err) {
        $x = is_array($p) ? ($p['x'] ?? $p[0] ?? null) : null; $z = is_array($p) ? ($p['z'] ?? $p[1] ?? null) : null;
        if (!is_numeric($x) || !is_numeric($z)) { $err[] = "$what has no position."; return [0.0, 0.0]; }
        $x = (float)$x; $z = (float)$z;
        if (abs($x) > $lim || abs($z) > $lim) { $err[] = "$what is too close to the edge of the map."; $x = max(-$lim, min($lim, $x)); $z = max(-$lim, min($lim, $z)); }
        return [round($x, 2), round($z, 2)];
    };
    $pl = is_array($in['players'] ?? null) ? $in['players'] : [1, 4];
    $d['players'] = [$int($pl[0] ?? 1, 1, 4, 1), $int($pl[1] ?? 4, 1, 4, 4)];
    if ($d['players'][1] < $d['players'][0]) $d['players'][1] = $d['players'][0];
    $d['starts'] = [];
    foreach (array_slice(is_array($in['starts'] ?? null) ? $in['starts'] : [], 0, 4) as $k => $p) $d['starts'][] = $pt($p, 'Player start ' . ($k + 1));
    if (count($d['starts']) < $d['players'][1]) $err[] = 'Place a start for each player: ' . $d['players'][1] . ' needed, ' . count($d['starts']) . ' placed.';

    $d['enemies'] = []; $boss = 0;
    foreach (array_slice(is_array($in['enemies'] ?? null) ? $in['enemies'] : [], 0, MISSION_MAX_ENEMIES) as $k => $e) {
        if (!is_array($e)) continue;
        $kind = isset(MISSION_KINDS[$e['kind'] ?? '']) ? $e['kind'] : 'tank';
        if ($kind === 'behemoth' && $boss++) { $err[] = 'Only one Behemoth per mission.'; $kind = 'tank'; }
        [$x, $z] = $pt($e, 'Enemy ' . ($k + 1));
        $defHp = $kind === 'behemoth' ? BOSS_HP : ($kind === 'gun' ? GUN_HP : MAX_HP);
        $d['enemies'][] = ['x' => $x, 'z' => $z, 'kind' => $kind,
            'level' => isset(AI_LEVEL_NAMES[$e['level'] ?? '']) ? $e['level'] : 'veteran',
            'hp' => $int($e['hp'] ?? null, 10, 2000, $defHp), 'round' => $int($e['round'] ?? 1, 1, 60, 1), 'min' => $int($e['min'] ?? 1, 1, 4, 1),
            'name' => $str($e['name'] ?? '', 20), 'goal' => !empty($e['goal'])];
    }
    if (!$d['enemies']) $err[] = 'Place at least one enemy.';
    $d['objects'] = [];
    foreach (array_slice(is_array($in['objects'] ?? null) ? $in['objects'] : [], 0, MISSION_MAX_OBJECTS) as $k => $o) {
        if (!is_array($o) || !isset(MISSION_OBJECTS[$o['type'] ?? ''])) continue;
        [$x, $z] = $pt($o, MISSION_OBJECTS[$o['type']] . ' ' . ($k + 1));
        $c = ['type' => $o['type'], 'x' => $x, 'z' => $z];
        if ($o['type'] !== 'crate') {
            $c['w'] = round($num($o['w'] ?? null, 1, 40, $o['type'] === 'wall' ? 12 : 6), 2);
            $c['d'] = round($num($o['d'] ?? null, 1, 40, $o['type'] === 'wall' ? 2.5 : 6), 2);
            $c['h'] = round($num($o['h'] ?? null, 1, 30, $o['type'] === 'wall' ? 6 : 5), 2);
            $c['hp'] = $int($o['hp'] ?? null, 10, 2000, $o['type'] === 'wall' ? 150 : 120);
            $c['name'] = $str($o['name'] ?? '', 24);
            if ($o['type'] === 'target') $c['style'] = in_array($o['style'] ?? '', ['bunker', 'span', 'radar', 'fuel'], true) ? $o['style'] : 'bunker';
        }
        $d['objects'][] = $c;
    }
    $d['rivers'] = [];
    foreach (array_slice(is_array($in['rivers'] ?? null) ? $in['rivers'] : [], 0, 4) as $k => $r) {
        if (!is_array($r)) continue;
        $d['rivers'][] = ['a' => $pt($r['a'] ?? null, 'River ' . ($k + 1)), 'b' => $pt($r['b'] ?? null, 'River ' . ($k + 1)),
                          'w' => round($num($r['w'] ?? null, 4, 40, 12), 2)];
    }
    $d['convoy'] = null;
    if (is_array($in['convoy'] ?? null) && count($in['convoy']['path'] ?? []) >= 2) {
        $path = [];
        foreach (array_slice($in['convoy']['path'], 0, 12) as $k => $p) $path[] = $pt($p, 'Convoy point ' . ($k + 1));
        $d['convoy'] = ['path' => $path, 'speed' => round($num($in['convoy']['speed'] ?? null, 2, 80, 12), 2)];
    }
    $g = is_array($in['goal'] ?? null) ? $in['goal'] : [];
    $d['goal'] = ['type' => isset(MISSION_GOALS[$g['type'] ?? '']) ? $g['type'] : 'destroy_all'];
    if ($d['goal']['type'] === 'survive' || $d['goal']['type'] === 'protect') $d['goal']['rounds'] = $int($g['rounds'] ?? 10, 1, 60, 10);
    if ($d['goal']['type'] === 'crates') $d['goal']['count'] = $int($g['count'] ?? 3, 1, 20, 3);
    $d['limit'] = $int($in['limit'] ?? 0, 0, 80, 0);
    $s = is_array($in['stars'] ?? null) ? $in['stars'] : [];
    $d['stars'] = ['shots' => $int($s['shots'] ?? 10, 1, 200, 10), 'damage' => $int($s['damage'] ?? 50, 0, 2000, 50)];
    $o = is_array($in['opts'] ?? null) ? $in['opts'] : [];
    $d['opts'] = [
        'night' => !empty($o['night']), 'flares' => $int($o['flares'] ?? 0, 0, 20, 0),
        'items' => in_array($o['items'] ?? '', ['off', 'safe', 'all'], true) ? $o['items'] : 'safe',
        'drops' => !array_key_exists('drops', $o) || !empty($o['drops']),
        'erupt' => $int($o['erupt'] ?? 0, 0, 10, 0),
        'move' => $num($o['move'] ?? null, 0, 20, 10), 'regen' => $int($o['regen'] ?? 3, 0, 10, 3),
        'wind' => is_numeric($o['wind'] ?? null) ? $num($o['wind'], 0, 15, 8) : null,
        'turn_seconds' => in_array((int)($o['turn_seconds'] ?? 0), [20, 30, 40, 60], true) ? (int)$o['turn_seconds'] : 40,
    ];
    // goals that need something on the map
    $goalObjs = array_filter($d['objects'], fn($x) => $x['type'] === 'target');
    if ($d['goal']['type'] === 'destroy_targets' && !$goalObjs && !array_filter($d['enemies'], fn($e) => $e['goal']))
        $err[] = '"Destroy the targets" needs a target, or an enemy marked as a target.';
    if ($d['goal']['type'] === 'protect' && !array_filter($d['objects'], fn($x) => $x['type'] === 'depot')) $err[] = '"Protect the depot" needs a depot.';
    if ($d['goal']['type'] === 'crates') {
        $n = count(array_filter($d['objects'], fn($x) => $x['type'] === 'crate'));
        if ($n < $d['goal']['count']) $err[] = "The goal needs {$d['goal']['count']} crates but only $n are placed.";
    }
    if ($d['opts']['night'] && $d['opts']['flares'] < 1) $err[] = 'A night mission needs flares for the players.';
    if ($d['opts']['erupt'] && $d['map'] !== 'volcano') $d['opts']['erupt'] = 0;
    // tanks too close together can't drive or would overlap
    $spots = array_merge(array_map(fn($p) => [$p[0], $p[1], 'A player start'], $d['starts']), array_map(fn($e) => [$e['x'], $e['z'], 'An enemy'], $d['enemies']));
    for ($i = 0; $i < count($spots); $i++) for ($j = $i + 1; $j < count($spots); $j++) {
        if (hypot($spots[$i][0] - $spots[$j][0], $spots[$i][1] - $spots[$j][1]) < 6) { $err[] = "{$spots[$i][2]} and " . lcfirst($spots[$j][2]) . ' are less than 6 m apart.'; break 2; }
    }
    return [$d, array_values(array_unique($err))];
}
const AI_LEVEL_NAMES = ['rookie' => 'Rookie', 'veteran' => 'Veteran', 'ace' => 'Ace'];

/** One line saying what the mission asks, e.g. "Destroy every enemy tank within 20 rounds". */
function mission_goal_text(array $d): string
{
    $g = $d['goal']; $t = MISSION_GOALS[$g['type']] ?? '';
    if ($g['type'] === 'survive') $t = "Hold out for {$g['rounds']} rounds (or destroy every enemy)";
    if ($g['type'] === 'protect') $t = "Keep the depot standing for {$g['rounds']} rounds (or destroy every enemy)";
    if ($g['type'] === 'crates') $t = "Claim {$g['count']} supply crates before the enemy does";
    if (!empty($d['convoy'])) $t .= ' before the convoy gets through';
    if ($d['limit'] && !in_array($g['type'], ['survive', 'protect'], true)) $t .= " within {$d['limit']} rounds";
    return $t;
}

/* =====================================================================
 * The battlefield for a mission: the map recipe grown from the mission's seed, rivers carved, pads flattened under
 * every tank and object. The same seed always gives the same ground (the editor shows exactly what will be played).
 * ===================================================================== */
function generate_mission_map(array $d, int $nh = 1): array
{
    $hpk = ENEMY_HP_SCALE[$nh] ?? 1.0;                   // targets, walls and depots get tougher with more players too
    mt_srand($d['seed']);
    $n = $d['size']; $half = mission_half($n); $w = $n + 1; $mapId = $d['map'];
    $nz = new Noise();
    [$f, , $extra] = ('gen_' . $mapId)($nz, $n, $half, 2);
    $water = MAPS[$mapId]['water'];
    foreach ($d['rivers'] as $r) {                       // a river: a channel down to below sea level, banks sloping up over 10 m
        $f = function (float $x, float $z) use ($f, $r, $water) {
            $h = $f($x, $z); $bed = ($water ?? 0.0) - 1.2; $hw = $r['w'] / 2;
            $dist = seg_dist($x, $z, $r['a'][0], $r['a'][1], $r['b'][0], $r['b'][1]);
            if ($dist >= $hw + 10) return $h;
            $k = 1 - smoothstep($hw, $hw + 10, $dist);
            return $h + (min($h, $bed) - $h) * $k;
        };
    }
    $H = [];
    for ($j = 0; $j <= $n; $j++) for ($i = 0; $i <= $n; $i++) $H[$j * $w + $i] = $f(-$half + $i * ENG_CELL, -$half + $j * ENG_CELL);
    $t = new Terrain($H, $n, $water);
    if ($mapId === 'lunar') lunar_craters($t);
    $tankSpots = array_merge($d['starts'], array_map(fn($e) => [$e['x'], $e['z']], $d['enemies']));
    $keep = array_merge($tankSpots, array_map(fn($o) => [$o['x'], $o['z']], $d['objects']), $d['convoy']['path'] ?? []);
    scatter_cover($t, $mapId, $keep);
    foreach ($d['starts'] as $p) $t->flattenPad($p[0], $p[1], 4.5);
    foreach ($d['enemies'] as $e) $t->flattenPad($e['x'], $e['z'], $e['kind'] === 'behemoth' ? 9.0 : 4.5);
    $objects = [];
    foreach ($d['objects'] as $o) {
        if ($o['type'] === 'crate') continue;
        if (($o['style'] ?? '') !== 'span') $t->flattenPad($o['x'], $o['z'], max($o['w'], $o['d']) / 2 + 1);
    }
    if (!empty(MAPS[$mapId]['buildings'])) {                // Ruined City keeps its buildings, clear of everything the mission placed
        foreach (place_buildings($t, $keep) as $b) $objects[] = $b + ['kind' => 'ruin'];
    }
    $t->roundAll();
    // mission objects first, then the city's own buildings; an object's id is its place in the list
    $list = [];
    foreach ($d['objects'] as $k => $o) {
        $y = round($t->heightAt($o['x'], $o['z']), 3);
        if ($o['type'] === 'crate') {
            $list[] = ['type' => 'crate', 'x' => $o['x'], 'z' => $o['z'], 'y' => max($y, $t->water), 'pool' => $d['opts']['items'] === 'off' ? 'safe' : $d['opts']['items'],
                       'state' => 'live', 'turn' => 0, 'mission' => true];
            continue;
        }
        // a bridge span stands on the river bed; its deck is h metres above the water
        $h = ($o['style'] ?? '') === 'span' ? round(max(1.0, ($t->water > -50 ? $t->water : $y) + $o['h'] - $y), 2) : $o['h'];
        $b = ['type' => 'building', 'kind' => $o['type'], 'x' => $o['x'], 'z' => $o['z'], 'y' => $y, 'hw' => round($o['w'] / 2, 2), 'hd' => round($o['d'] / 2, 2),
              'h' => $h, 'hp' => (int)round($o['hp'] * $hpk), 'max' => (int)round($o['hp'] * $hpk)];
        if ($o['name'] !== '') $b['name'] = $o['name'];
        if ($o['type'] === 'target') { $b['goal'] = true; $b['style'] = $o['style']; }
        if ($o['type'] === 'depot') $b['protect'] = true;
        $list[] = $b;
    }
    foreach ($objects as $b) $list[] = $b;
    foreach ($list as $k => &$o) $o = ['id' => $k] + $o;
    unset($o);
    mt_srand(random_int(1, PHP_INT_MAX));              // back to unpredictable numbers for the battle itself
    $pos = fn($p) => [$p[0], round($t->heightAt($p[0], $p[1]), 3), $p[1]];
    return ['terrain' => $t, 'starts' => array_map($pos, $d['starts']), 'enemies' => array_map(fn($e) => $pos([$e['x'], $e['z']]), $d['enemies']),
            'objects' => $list, 'extra' => $extra];
}

/** The rules stored on a mission's match. Seats, teams and computer players are added by scenario.php. */
function mission_rules(array $d, array $extra, int $missionId = 0): array
{
    $o = $d['opts'];
    $r = map_rules($d['map'], $d['size'], $extra);
    if ($o['wind'] !== null) { $r['wind_max'] = (float)$o['wind']; $r['wind_min'] = min($r['wind_min'], $r['wind_max']); }
    if ($o['erupt']) $r['erupt_every'] = $o['erupt'];
    $len = 0.0; $path = $d['convoy']['path'] ?? [];
    for ($k = 1; $k < count($path); $k++) $len += hypot($path[$k][0] - $path[$k - 1][0], $path[$k][1] - $path[$k - 1][1]);
    return array_merge($r, [
        'mode' => 'scenario', 'ranked' => false, 'items' => $o['items'], 'crates' => $o['drops'] && $o['items'] !== 'off' ? 'paired' : 'none',
        'turn_seconds' => $o['turn_seconds'], 'move' => (float)$o['move'], 'regen' => $o['regen'], 'sd_turn' => 100000, 'night' => $o['night'],
        'mission' => ['id' => $missionId, 'title' => $d['title'], 'goal' => $d['goal'], 'limit' => $d['limit'], 'stars' => $d['stars'],
                      'text' => mission_goal_text($d), 'convoy' => $path ? ['path' => $path, 'speed' => $d['convoy']['speed'], 'len' => round($len, 2)] : null],
    ]);
}

/* =====================================================================
 * During a mission
 * ===================================================================== */

/**
 * Seats for a mission with $nh players: the players take the mission's starts in order (team 0), then the computer
 * tanks in the order the mission lists them (team 1). Adds each computer tank's level, name, kind, hp and limits to
 * $rules. Returns [seats (seat => team, kind, round), tanks (seat => position, team, alive)].
 */
function mission_setup(array $d, array $gen, array &$rules, int $nh): array
{
    $seats = []; $tanks = []; $counts = ['tank' => 0, 'gun' => 0];
    for ($k = 0; $k < $nh; $k++) {
        $seats[$k] = ['team' => 0, 'kind' => 'player', 'round' => 1];
        $tanks[$k] = ['seat' => $k, 'x' => $gen['starts'][$k][0], 'y' => $gen['starts'][$k][1], 'z' => $gen['starts'][$k][2], 'team' => 0, 'alive' => true];
        $rules['teams'][$k] = 0;
    }
    $i = 0;
    foreach ($d['enemies'] as $j => $e) {
        if ($e['min'] > $nh) continue;                    // this one only comes with more players
        $s = $nh + $i;
        $e['hp'] = (int)round($e['hp'] * (ENEMY_HP_SCALE[$nh] ?? 1.0));
        $seats[$s] = ['team' => ENEMY_TEAM, 'kind' => $e['kind'], 'round' => $e['round']];
        $tanks[$s] = ['seat' => $s, 'x' => $gen['enemies'][$j][0], 'y' => $gen['enemies'][$j][1], 'z' => $gen['enemies'][$j][2], 'team' => ENEMY_TEAM, 'alive' => $e['round'] <= 1,
                      'hp' => $e['hp'], 'level' => $e['level']];
        $i++;
        $rules['teams'][$s] = ENEMY_TEAM; $rules['bots'][$s] = $e['level']; $rules['kinds'][$s] = $e['kind']; $rules['hp'][$s] = $e['hp'];
        $label = $e['kind'] === 'behemoth' ? 'Behemoth' : ($e['kind'] === 'gun' ? 'Gun ' : 'Enemy ') . ($e['kind'] === 'behemoth' ? '' : ++$counts[$e['kind']]);
        $rules['names'][$s] = $e['name'] !== '' ? $e['name'] : $label;
        if ($e['kind'] !== 'tank') $rules['nodrive'][$s] = true;
        if ($e['kind'] === 'behemoth') $rules['mods'][$s] = BOSS_MODS;
        if ($e['goal']) $rules['goal_seats'][$s] = true;
    }
    return [$seats, $tanks];
}

/** Is $a an enemy of $b? (Team from the player record; without teams everyone is on their own.) */
function foes(array $a, array $b): bool { return ($a['team'] ?? $a['seat']) !== ($b['team'] ?? $b['seat']); }

/** Where a Behemoth's weak point is in the world (the hull faces FX boss 'face' degrees, like an aim yaw). */
function boss_wp_pos(array $pl, string $id, float $face): array
{
    [$lx, $ly, $lz] = BOSS_WP[$id]['off']; $a = deg2rad($face);
    return [$pl['x'] + $lx * cos($a) - $lz * sin($a), $pl['y'] + $ly, $pl['z'] + $lx * sin($a) + $lz * cos($a)];
}

/**
 * A blast centred within WP_R of one of the Behemoth's standing weak points: that weak point loses $dmg hp, and the
 * caller doubles the damage to the Behemoth. Returns ['id', 'hp', 'down'] or null.
 */
function weak_point_hit(array $pl, int $seat, array $p, int $dmg): ?array
{
    $b = FX::$e['boss'] ?? null;
    if (!$b || (int)$b['seat'] !== $seat) return null;
    $best = null;
    foreach ($b['wp'] as $id => $wp) {
        if ($wp['hp'] <= 0) continue;
        $q = boss_wp_pos($pl, $id, (float)$b['face']);
        $d = sqrt(($p[0] - $q[0]) ** 2 + ($p[1] - $q[1]) ** 2 + ($p[2] - $q[2]) ** 2);
        if ($d < WP_R && (!$best || $d < $best[1])) $best = [$id, $d];
    }
    if (!$best) return null;
    $id = $best[0];
    $hp = FX::$e['boss']['wp'][$id]['hp'] = max(0, (int)$b['wp'][$id]['hp'] - $dmg);
    $out = ['id' => $id, 'hp' => $hp];
    if ($hp <= 0) $out['down'] = true;
    return $out;
}
/** How many of the Behemoth's weak points are gone, and is a given one still standing. */
function boss_lost(): int { return count(array_filter(FX::$e['boss']['wp'] ?? [], fn($w) => $w['hp'] <= 0)); }
function boss_has(string $id): bool { return isset(FX::$e['boss']['wp'][$id]) && FX::$e['boss']['wp'][$id]['hp'] > 0; }

/** A flare shell bursts: every enemy of the shooter within $r metres is lit for FLARE_ROUNDS rounds. */
function flare_burst(array $players, array $p, float $r, ?int $owner): array
{
    $lit = [];
    foreach ($players as $s => $pl) {
        if (!$pl['alive'] || $owner === null || !foes($pl, $players[$owner])) continue;
        if (hypot($pl['x'] - $p[0], $pl['z'] - $p[2]) < $r) {
            FX::$e['lit'][$s] = FLARE_ROUNDS; $lit[] = $s;
            FX::$e['seen'][$s] = [round($pl['x'], 3), round($pl['y'], 3), round($pl['z'], 3)];   // where the players last saw it
        }
    }
    $id = (int)(FX::$e['glow_seq'] ?? 0); FX::$e['glow_seq'] = $id + 1;
    $g = ['id' => $id, 'x' => round($p[0], 3), 'y' => round($p[1], 3), 'z' => round($p[2], 3), 'r' => $r, 'left' => FLARE_ROUNDS];
    FX::$e['glow'][] = $g;
    return ['x' => $p[0], 'y' => $p[1], 'z' => $p[2], 'r' => 0.0, 'vr' => 0.0, 'hits' => [], 'obj' => [], 'splash' => false, 'flare' => $g + ['lit' => $lit]];
}

/**
 * Start of a turn in a mission (called from start_turn() before anything else). When the turn comes back round to
 * the players a new round starts: flares burn down, the convoy moves on, and held-back enemies arrive.
 * Adds to the turn event $out; returns whether anything happened.
 */
function mission_turn_start(Terrain $t, array &$players, array &$objects, array $rules, int $turn, ?int $prev, ?int $seat, array &$out): bool
{
    if ($prev === null || $seat === null || $seat > $prev) return false;   // still the same round
    $round = (int)(FX::$e['round'] ?? 1) + 1;
    FX::$e['round'] = $round; $out['round'] = $round;
    foreach (FX::$e['lit'] ?? [] as $s => $k) {
        if ($players[$s]['alive']) FX::$e['seen'][$s] = [round($players[$s]['x'], 3), round($players[$s]['y'], 3), round($players[$s]['z'], 3)];
        if ($k - 1 > 0 && $players[$s]['alive']) { FX::$e['lit'][$s] = $k - 1; continue; }
        unset(FX::$e['lit'][$s]); $out['lit_end'][] = (int)$s;
    }
    $glow = [];
    foreach (FX::$e['glow'] ?? [] as $g) { if (--$g['left'] > 0) $glow[] = $g; else $out['glow_end'][] = $g['id']; }
    if (isset(FX::$e['glow'])) FX::$e['glow'] = $glow;
    $cv = $rules['mission']['convoy'] ?? null;
    if ($cv && !(FX::$e['convoy']['done'] ?? false)) {
        $dd = min((float)$cv['len'], (float)(FX::$e['convoy']['d'] ?? 0) + (float)$cv['speed']);
        FX::$e['convoy'] = ['d' => round($dd, 2), 'done' => $dd >= (float)$cv['len'] - 1e-6];
        $out['convoy'] = FX::$e['convoy'];
    }
    // waves: everyone due this round; if the field is already clear of enemies, the next wave comes early
    $res = FX::$e['reserve'] ?? [];
    if ($res) {
        $due = array_keys(array_filter($res, fn($r) => $r <= $round));
        $foes = array_filter($players, fn($p) => $p['alive'] && ($p['team'] ?? 0) === ENEMY_TEAM);
        if (!$due && !$foes) { $next = min($res); $due = array_keys(array_filter($res, fn($r) => $r === $next)); }
        foreach ($due as $s) {
            $s = (int)$s; $pl = &$players[$s];
            [$x, $z] = arrival_spot($t, $players, $s, (float)$pl['x'], (float)$pl['z']);
            $pl['x'] = $x; $pl['z'] = $z; $pl['y'] = round($t->heightAt($x, $z), 3);
            $pl['alive'] = true; $pl['death_turn'] = null; $pl['hp'] = max_hp($pl);
            unset($pl, FX::$e['reserve'][$s]);
            $out['arrive'][] = ['seat' => $s, 'at' => [$players[$s]['x'], $players[$s]['y'], $players[$s]['z']], 'hp' => $players[$s]['hp']];
        }
    }
    return true;
}

/** A clear spot for an arriving tank: where the mission put it, or the nearest spot 5 m clear of every other tank. */
function arrival_spot(Terrain $t, array $players, int $seat, float $x, float $z): array
{
    $lim = $t->half - 8;
    for ($r = 0.0; $r <= 30; $r += 3) for ($k = 0; $k < ($r ? 12 : 1); $k++) {
        $a = $k / 12 * M_PI * 2; $px = max(-$lim, min($lim, $x + sin($a) * $r)); $pz = max(-$lim, min($lim, $z - cos($a) * $r));
        if ($t->heightAt($px, $pz) < $t->water + .6) continue;
        foreach ($players as $s => $p) if ($s !== $seat && ($p['alive'] || $p['death_turn'] !== null) && hypot($p['x'] - $px, $p['z'] - $pz) < 5.5) continue 2;
        return [round($px, 3), round($pz, 3)];
    }
    return [round($x, 3), round($z, 3)];
}

/**
 * The tank whose turn it is: the Behemoth raises its shield while the generator stands, and once it has lost two
 * weak points it fires twice a turn. Returns whether anything happened.
 */
function mission_seat_start(array &$players, array $rules, int $turn, int $seat, array &$out): bool
{
    $b = FX::$e['boss'] ?? null;
    if (!$b || (int)$b['seat'] !== $seat || !$players[$seat]['alive']) return false;
    $did = false;
    if (boss_has('shield') && (int)(FX::$e['round'] ?? 1) % BOSS_SHIELD_EVERY === 1 && (int)(FX::$e['shields'][$seat]['hp'] ?? 0) < SHIELD_HP) {
        FX::$e['shields'][$seat] = ['hp' => SHIELD_HP, 'left' => 2]; $out['boss_shield'] = $seat; $did = true;
    }
    if (boss_lost() >= 2) { FX::$e['double'] = ['seat' => $seat, 'turn' => $turn, 'shots' => 0]; $out['enrage'] = $seat; $did = true; }
    return $did;
}

/**
 * Has the mission been won or lost? 'win', 'lose' or null (still on). Checked after every action and every new turn.
 * $players carry 'team'; $objects are the match's objects; rounds, waves and the convoy come from FX.
 */
function mission_outcome(array $rules, array $players, array $objects): ?string
{
    $ms = $rules['mission']; $g = $ms['goal']; $round = (int)(FX::$e['round'] ?? 1);
    $mine = array_filter($players, fn($p) => ($p['team'] ?? 0) !== ENEMY_TEAM);
    if (!array_filter($mine, fn($p) => $p['alive'])) return 'lose';
    foreach ($objects as $o) if (!empty($o['protect']) && $o['hp'] <= 0) return 'lose';
    if (FX::$e['convoy']['done'] ?? false) return 'lose';
    $foes = array_filter($players, fn($p) => ($p['team'] ?? 0) === ENEMY_TEAM);
    $pending = FX::$e['reserve'] ?? [];
    switch ($g['type']) {
        case 'destroy_all':
            if (!array_filter($foes, fn($p) => $p['alive']) && !$pending) return 'win';
            break;
        case 'destroy_targets':
            $objLeft = array_filter($objects, fn($o) => !empty($o['goal']) && $o['hp'] > 0);
            $tankLeft = array_filter($foes, fn($p) => !empty($rules['goal_seats'][$p['seat']]) && ($p['alive'] || isset($pending[$p['seat']])));
            if (!$objLeft && !$tankLeft) return 'win';
            break;
        case 'survive': case 'protect':                    // hold out for the rounds, or wipe out every enemy sooner
            if ($round > (int)$g['rounds'] || (!array_filter($foes, fn($p) => $p['alive']) && !$pending)) return 'win';
            break;
        case 'crates':
            $ours = $live = 0;
            foreach ($objects as $o) if ($o['type'] === 'crate' && !empty($o['mission'])) {
                if ($o['state'] === 'live') $live++;
                elseif ($o['state'] === 'claimed' && isset($mine[(int)$o['by']])) $ours++;
            }
            if ($ours >= (int)$g['count']) return 'win';
            if ($ours + $live < (int)$g['count']) return 'lose';
            break;
    }
    if ((int)$ms['limit'] > 0 && $round > (int)$ms['limit']) return 'lose';
    return null;
}

/** Progress for the battle page: what's left to do. */
function mission_progress(array $rules, array $players, array $objects): array
{
    $ms = $rules['mission']; $g = $ms['goal'];
    $foes = array_filter($players, fn($p) => ($p['team'] ?? 0) === ENEMY_TEAM);
    $pending = FX::$e['reserve'] ?? [];
    $out = ['round' => (int)(FX::$e['round'] ?? 1), 'limit' => (int)$ms['limit'], 'goal' => $g['type'], 'text' => $ms['text'],
            'enemies' => count(array_filter($foes, fn($p) => $p['alive'])), 'incoming' => count($pending),
            'next_wave' => $pending ? min($pending) : null];
    if ($g['type'] === 'destroy_targets') {
        $all = array_filter($objects, fn($o) => !empty($o['goal']));
        $tanks = array_filter($foes, fn($p) => !empty($rules['goal_seats'][$p['seat']]));
        $out['targets'] = [count($all) + count($tanks) - count(array_filter($all, fn($o) => $o['hp'] > 0)) - count(array_filter($tanks, fn($p) => $p['alive'] || isset($pending[$p['seat']]))),
                           count($all) + count($tanks)];
    }
    if ($g['type'] === 'survive' || $g['type'] === 'protect') $out['rounds'] = (int)$g['rounds'];
    if ($g['type'] === 'crates') {
        $mine = array_filter($players, fn($p) => ($p['team'] ?? 0) !== ENEMY_TEAM);
        $ours = $theirs = 0;
        foreach ($objects as $o) if ($o['type'] === 'crate' && !empty($o['mission']) && $o['state'] === 'claimed') {
            if (isset($mine[(int)$o['by']])) $ours++; else $theirs++;
        }
        $out['crates'] = [$ours, (int)$g['count'], $theirs];
    }
    if (!empty($ms['convoy'])) $out['convoy'] = [(float)(FX::$e['convoy']['d'] ?? 0), (float)$ms['convoy']['len'], (float)$ms['convoy']['speed']];
    return $out;
}

/** Stars for a finished mission: [completed, within the shot par, within the damage limit] (per player, on average). */
function mission_stars(array $rules, string $outcome, float $shotsEach, float $takenEach): array
{
    $s = $rules['mission']['stars']; $won = $outcome === 'win';
    return [$won, $won && $shotsEach <= (float)$s['shots'] + 1e-9, $won && $takenEach <= (float)$s['damage'] + 1e-9];
}

/** What every player's page is told about the mission's effects (FX::pub). */
function mission_fx_pub(array $e, ?int $me): array
{
    $out = ['round' => (int)$e['round'], 'reserve' => (object)($e['reserve'] ?? []), 'lit' => (object)($e['lit'] ?? []),
            'glow' => array_values($e['glow'] ?? []), 'convoy' => $e['convoy'] ?? null, 'seen' => (object)($e['seen'] ?? [])];
    if (!empty($e['boss'])) $out['boss'] = ['seat' => (int)$e['boss']['seat'], 'face' => (float)$e['boss']['face'],
        'wp' => array_map(fn($w) => ['hp' => (int)$w['hp'], 'max' => (int)$w['max']], $e['boss']['wp'])];
    if ($me !== null && isset($e['flare_ammo'][$me])) $out['flares'] = (int)$e['flare_ammo'][$me];
    return $out;
}

/**
 * Set up the effects a mission starts with: round 1, the held-back waves, the damage tally, flares for the players,
 * the Behemoth's weak points. $seats: seat => ['team', 'kind', 'round'] for every tank.
 */
function mission_fx_init(array $d, array $seats, array $players): void
{
    FX::$e['round'] = 1; FX::$e['taken'] = []; FX::$e['lit'] = []; FX::$e['glow'] = []; FX::$e['glow_seq'] = 0;
    FX::$e['reserve'] = [];
    foreach ($seats as $s => $x) if ($x['team'] === ENEMY_TEAM && $x['round'] > 1) FX::$e['reserve'][$s] = $x['round'];
    if ($d['opts']['night']) foreach ($seats as $s => $x) if ($x['team'] !== ENEMY_TEAM) FX::$e['flare_ammo'][$s] = $d['opts']['flares'];
    if (!empty($d['convoy'])) FX::$e['convoy'] = ['d' => 0.0, 'done' => false];
    foreach ($seats as $s => $x) if ($x['kind'] === 'behemoth') {
        $hum = array_filter($players, fn($p) => ($p['team'] ?? 0) !== ENEMY_TEAM);
        $cx = array_sum(array_column($hum, 'x')) / max(1, count($hum)); $cz = array_sum(array_column($hum, 'z')) / max(1, count($hum));
        $me = $players[$s];
        $face = fmod(rad2deg(atan2($cx - $me['x'], -($cz - $me['z']))) + 360, 360);   // facing the players
        FX::$e['boss'] = ['seat' => $s, 'face' => round($face, 3), 'wp' => array_map(fn() => ['hp' => WP_HP, 'max' => WP_HP], BOSS_WP)];
    }
}

/** Night missions: an enemy tank nobody has lit with a flare is unseen (its position isn't sent, its drives are hidden). */
function in_dark(array $rules, array $pl): bool
{
    return !empty($rules['night']) && ($pl['team'] ?? 0) === ENEMY_TEAM && empty(FX::$e['lit'][$pl['seat']]);
}
