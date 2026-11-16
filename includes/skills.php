<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Skills, loadouts, XP and levels (phase 4).
 *
 * Before a match each player brings a loadout: 1 doctrine (a play style) and up to 2 perks. Every skill has a cost,
 * so a loadout changes how you play without making the tank stronger overall. Skills unlock by level; levels come
 * from XP, which every finished match earns (rating stays ranked-only and is never touched by any of this).
 *
 * A loadout is stored as JSON: {"preset": id} for one of the three presets, or {"doctrine": id, "perks": [id, id]}
 * for a custom build of unlocked skills. Below level RANKED_FREE ranked matches use a preset (the player's
 * "ranked_preset"), so new players aren't matched against builds they can't have yet.
 *
 * skill_mods() turns a loadout into the numbers the engine reads from each tank ($players[$seat]['sk']). Every key
 * has a neutral default (sk() in engine.php), so a tank with no loadout plays exactly as before phase 4.
 */

const RANKED_FREE = 14;          // from this level every skill is unlocked and ranked allows custom loadouts
const LEVEL_MAX = 50;

/*
 * mods keys:
 *   hp          + or − starting (and maximum) hp           heavy / cluster  starting ammo (replaces the default)
 *   crater      crater radius × (your shells)              fall_dealt  × fall damage your shells cause
 *   dmg_mult    × blast damage of your shells              dmg_flat    + blast damage of every shell (before dmg_mult)
 *   max_power   highest power you can fire                 arc         × length of the aim arc (browser only)
 *   forecast    see the wind of your own next turn         fall_taken  × fall damage you take
 *   clock       + seconds on your shot clock               claim       crate claim radius for your shells (m)
 *   pick        crate pick-up radius when driving (m)
 *   item_turns  + turns your timed power-ups last          spotter     after each shot: how far it landed from the nearest enemy
 *   hit_r       your tank's hit radius (m)                 split / bomblet_dmg  Cluster bomblets: count, + damage each
 *   heavy_dmg   + damage of your Heavy shells (Heavy Gunner: −5)
 *   dug_in      blasts within 3 m of you do this much (0.9: 10% less), and you can't use Relocate
 *   ai_aim      × the AI's aiming error when it plays this build (the AI's stand-in for information skills)
 */
const DOCTRINES = [
    'standard'      => ['name' => 'Standard Issue', 'level' => 1,  'icon' => '★',
                        'gain' => 'Heavy ×3, Cluster ×2', 'cost' => 'None', 'mods' => []],
    'heavy'         => ['name' => 'Heavy Gunner',   'level' => 3,  'icon' => '⬣',
                        'gain' => 'Heavy ×4', 'cost' => 'No Cluster rounds; your Heavy shells do 65 damage, not 70',
                        'mods' => ['heavy' => 4, 'cluster' => 0, 'heavy_dmg' => -5]],
    'demolitions'   => ['name' => 'Demolitions',    'level' => 5,  'icon' => '✸',
                        'gain' => 'Craters 30% wider; fall damage you cause counts double', 'cost' => 'Blast damage −10%',
                        'mods' => ['crater' => 1.3, 'fall_dealt' => 2.0, 'dmg_mult' => 0.9]],
    'sharpshooter'  => ['name' => 'Sharpshooter',   'level' => 7,  'icon' => '◎',
                        'gain' => 'Aim arc twice as long', 'cost' => 'Heavy ×2, Cluster ×1',
                        'mods' => ['arc' => 2.0, 'heavy' => 2, 'cluster' => 1, 'ai_aim' => 0.88]],
    'fortified'     => ['name' => 'Fortified',      'level' => 9,  'icon' => '⛨',
                        'gain' => 'Start at 105 hp', 'cost' => 'Max power 85 (shorter range)', 'mods' => ['hp' => 5, 'max_power' => 85.0]],
    'meteorologist' => ['name' => 'Meteorologist',  'level' => 12, 'icon' => '☁',
                        'gain' => 'See the wind of your next turn before you shoot', 'cost' => 'Every shell −2 damage',
                        'mods' => ['forecast' => 1, 'dmg_flat' => -2, 'ai_aim' => 0.95]],
];
const PERKS = [
    'tracks'     => ['name' => 'Reinforced Tracks', 'level' => 2,  'icon' => '▤',
                     'gain' => 'Fall damage on you halved', 'cost' => '−5 starting hp', 'mods' => ['fall_taken' => 0.5, 'hp' => -5]],
    'loader'     => ['name' => 'Quick Loader',      'level' => 2,  'icon' => '⏱',
                     'gain' => '+10 s on your shot clock', 'cost' => 'None (clock only)', 'mods' => ['clock' => 10, 'ai_aim' => 0.975]],
    'scavenger'  => ['name' => 'Scavenger',         'level' => 4,  'icon' => '⌂',
                     'gain' => 'Claim crates from 6 m instead of 4 (3.5 m instead of 2.5 when driving over them)', 'cost' => 'Power-ups you claim last 1 turn less',
                     'mods' => ['claim' => 6.0, 'pick' => 3.5, 'item_turns' => -1]],
    'spotter'    => ['name' => 'Spotter',           'level' => 6,  'icon' => '⌕',
                     'gain' => 'After each shot, see how far it landed from the nearest enemy', 'cost' => 'Enemies see you are spotting',
                     'mods' => ['spotter' => true, 'ai_aim' => 0.97]],
    'lowprofile' => ['name' => 'Low Profile',       'level' => 8,  'icon' => '▁',
                     'gain' => 'Smaller target: hit radius 2.1 m instead of 2.4', 'cost' => '−4 starting hp', 'mods' => ['hit_r' => 2.1, 'hp' => -4]],
    'bomblets'   => ['name' => 'Bomblet Pack',      'level' => 10, 'icon' => '⁘',
                     'gain' => 'Cluster splits into 7 bomblets instead of 5', 'cost' => 'Each bomblet −5 damage', 'mods' => ['split' => 7, 'bomblet_dmg' => -5]],
    'dugin'      => ['name' => 'Dug In',            'level' => 14, 'icon' => '◠',
                     'gain' => 'Blasts within 3 m of you do 10% less', 'cost' => "Can't use Relocate", 'mods' => ['dug_in' => 0.9]],
];
/** Balanced loadouts open to everyone in every mode; below level 14 ranked always uses one of these. */
const PRESETS = [
    'rifleman' => ['name' => 'Rifleman', 'doctrine' => 'standard',    'perks' => ['loader', 'tracks'],    'blurb' => 'All-rounder: the standard kit, more time to aim, softer falls'],
    'gunner'   => ['name' => 'Gunner',   'doctrine' => 'heavy',       'perks' => ['loader', 'scavenger'], 'blurb' => 'Four Heavy shells (65 damage), no Cluster; grabs crates from further away'],
    'sapper'   => ['name' => 'Sapper',   'doctrine' => 'demolitions', 'perks' => ['tracks', 'scavenger'], 'blurb' => 'Digs wide craters to drop enemies; takes falls well'],
];
const DEFAULT_LOADOUT = ['preset' => 'rifleman'];

/* ---------------- XP and levels ---------------- */

/** XP earned for each thing a player does in a match (scenario stars and daily challenges come with phase 5). */
const XP_TABLE = ['finish' => 50, 'per10dmg' => 2, 'kill' => 25, 'win' => 50, 'first_win' => 100, 'star' => 30, 'daily' => 75];

/** XP needed to go from level $n to $n + 1. */
function xp_need(int $n): int { return 50 + 25 * $n; }

/** [level, XP into this level, XP needed for the next one (0 at the top)] for a total. */
function level_info(int $xp): array
{
    $L = 1; $xp = max(0, $xp);
    while ($L < LEVEL_MAX && $xp >= xp_need($L)) { $xp -= xp_need($L); $L++; }
    return [$L, $L >= LEVEL_MAX ? 0 : $xp, $L >= LEVEL_MAX ? 0 : xp_need($L)];
}
function level_for(int $xp): int { return level_info($xp)[0]; }
/** Total XP at which a level is reached. */
function xp_for_level(int $L): int { $s = 0; for ($n = 1; $n < min($L, LEVEL_MAX); $n++) $s += xp_need($n); return $s; }

/**
 * XP for one player's match. $p: placement, damage_dealt, kills; $quit: left or was removed; $soleWinner: placed 1st alone;
 * $firstWinToday: no win yet today. Returns ['total' => n, 'lines' => [[key, label, xp], ...]].
 */
function match_xp(array $p, bool $quit, bool $soleWinner, bool $firstWinToday): array
{
    $lines = [];
    if (!$quit) $lines[] = ['finish', 'Finished the match', XP_TABLE['finish']];
    $d = intdiv(max(0, (int)$p['damage_dealt']), 10) * XP_TABLE['per10dmg'];
    if ($d) $lines[] = ['damage', (int)$p['damage_dealt'] . ' damage dealt', $d];
    if ((int)$p['kills'] > 0) $lines[] = ['kills', (int)$p['kills'] . ' kill' . ((int)$p['kills'] > 1 ? 's' : ''), (int)$p['kills'] * XP_TABLE['kill']];
    if ($soleWinner && !$quit) {
        $lines[] = ['win', 'Victory', XP_TABLE['win']];
        if ($firstWinToday) $lines[] = ['first_win', 'First win of the day', XP_TABLE['first_win']];
    }
    return ['total' => array_sum(array_column($lines, 2)), 'lines' => $lines];
}

/* ---------------- loadouts ---------------- */

/** Skills unlocked at a level: ['doctrines' => [ids], 'perks' => [ids]]. */
function unlocked_skills(int $level): array
{
    return ['doctrines' => array_keys(array_filter(DOCTRINES, fn($d) => $d['level'] <= $level)),
            'perks' => array_keys(array_filter(PERKS, fn($p) => $p['level'] <= $level))];
}

/** Clean up a loadout from JSON or a request. Returns ['preset' => id] or ['doctrine' => id, 'perks' => [..]] or null. */
function parse_loadout($in): ?array
{
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return null;
    if (isset($in['preset'])) return isset(PRESETS[$in['preset']]) ? ['preset' => (string)$in['preset']] : null;
    $d = (string)($in['doctrine'] ?? '');
    if (!isset(DOCTRINES[$d])) return null;
    $perks = array_values(array_unique(array_map('strval', is_array($in['perks'] ?? null) ? $in['perks'] : [])));
    if (count($perks) > 2) return null;
    foreach ($perks as $k) if (!isset(PERKS[$k])) return null;
    sort($perks);
    return ['doctrine' => $d, 'perks' => $perks];
}

/** The doctrine and perks a loadout stands for: [doctrine id, [perk ids], preset id or null]. */
function loadout_parts(array $lo): array
{
    if (isset($lo['preset'])) { $p = PRESETS[$lo['preset']]; return [$p['doctrine'], $p['perks'], $lo['preset']]; }
    return [$lo['doctrine'], $lo['perks'], null];
}

/** Can a player of this level take this loadout into a match of this kind? Returns null if so, or the reason. */
function loadout_error(array $lo, int $level, bool $ranked): ?string
{
    if (isset($lo['preset'])) return null;                                  // presets: everyone, every mode
    if ($ranked && $level < RANKED_FREE) return 'ranked_preset';
    $u = unlocked_skills($level);
    if (!in_array($lo['doctrine'], $u['doctrines'], true)) return 'locked';
    foreach ($lo['perks'] as $k) if (!in_array($k, $u['perks'], true)) return 'locked';
    return null;
}

/**
 * The loadout a user brings into a match. $saved is users.loadout (JSON): the loadout plus "ranked_preset".
 * Below level 14 a custom build is swapped for the ranked preset in ranked matches; anything invalid falls back
 * to the default. Returns a clean loadout (never null).
 */
function match_loadout(?string $saved, int $level, bool $ranked): array
{
    $raw = json_decode((string)$saved, true);
    $lo = parse_loadout($raw) ?? DEFAULT_LOADOUT;
    if (loadout_error($lo, $level, $ranked) === 'ranked_preset') {
        $rp = (string)($raw['ranked_preset'] ?? '');
        return ['preset' => isset(PRESETS[$rp]) ? $rp : DEFAULT_LOADOUT['preset']];
    }
    return loadout_error($lo, $level, $ranked) === null ? $lo : DEFAULT_LOADOUT;
}

/** Engine numbers for a loadout (see the mods keys above). */
function skill_mods(?array $lo): array
{
    if (!$lo) return [];
    [$d, $perks] = loadout_parts($lo);
    $m = [];
    foreach (array_merge([DOCTRINES[$d]['mods'] ?? []], array_map(fn($k) => PERKS[$k]['mods'] ?? [], $perks)) as $add) {
        foreach ($add as $k => $v) {
            if ($k === 'hp' || $k === 'clock' || $k === 'item_turns' || $k === 'dmg_flat') $m[$k] = ($m[$k] ?? 0) + $v;
            elseif ($k === 'ai_aim') $m[$k] = ($m[$k] ?? 1.0) * $v;
            else $m[$k] = $v;
        }
    }
    return $m;
}

/** Starting and maximum hp for a loadout. */
function loadout_hp(?array $lo): int { return MAX_HP + (int)(skill_mods($lo)['hp'] ?? 0); }

/** Short public description of a loadout, for the battle page and the lobby. */
function loadout_public(?array $lo): ?array
{
    if (!$lo) return null;
    [$d, $perks, $preset] = loadout_parts($lo);
    return ['preset' => $preset, 'name' => $preset ? PRESETS[$preset]['name'] : DOCTRINES[$d]['name'], 'doctrine' => $d, 'perks' => $perks];
}

/** A stable key for a loadout (doctrine + sorted perks), used to compare win rates of the same build. */
function loadout_key(?array $lo): string
{
    if (!$lo) return 'none';
    [$d, $perks] = loadout_parts($lo); sort($perks);
    return $d . ($perks ? '+' . implode('+', $perks) : '');
}

/** Every custom loadout with exactly two perks (126 of them), plus fewer-perk builds if $all. */
function all_loadouts(bool $all = false): array
{
    $out = []; $pk = array_keys(PERKS);
    foreach (array_keys(DOCTRINES) as $d) {
        if ($all) { $out[] = ['doctrine' => $d, 'perks' => []]; foreach ($pk as $a) $out[] = ['doctrine' => $d, 'perks' => [$a]]; }
        for ($i = 0; $i < count($pk); $i++) for ($j = $i + 1; $j < count($pk); $j++) $out[] = ['doctrine' => $d, 'perks' => [$pk[$i], $pk[$j]]];
    }
    return $out;
}

/** Everything the browser needs to show skills (lobby picker and battle page). */
function skills_catalog(): array
{
    $strip = fn($a) => array_map(fn($x) => array_diff_key($x, ['mods' => 1]), $a);
    return ['doctrines' => $strip(DOCTRINES), 'perks' => $strip(PERKS), 'presets' => PRESETS, 'ranked_free' => RANKED_FREE];
}
