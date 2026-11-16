<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Cosmetics (the original phase 3's rewards, added in phase 4): paint and camo, barrel models, shell trails, blast
 * effects and titles. They change only how a tank looks, never how it plays: the server's shots, hits and replays are
 * the same whatever a player wears. They unlock by level, and the camos and map titles also by winning on their map
 * against other players (vs AI wins don't count). Chosen in the garage (garage.php), saved in users.cosmetics, and
 * fixed for each match in match_players.cosmetics, so everyone in a battle sees the same thing.
 *
 * In battle the turret keeps the tank's team colour, so a painted tank is still easy to tell apart.
 */

const COSMETIC_SLOTS = ['paint', 'barrel', 'trail', 'blast', 'title'];

/*
 * Each item: name, level (unlocks at), optional map + wins (or that many wins on that map), and how it looks:
 *   paint:  color (hex; null = the team colour) or camo (pattern id, with colours)
 *   barrel: model    trail: color (null = team colour) and style (line, rings, sparks, rainbow)
 *   blast:  style    title: the text shown over the tank and on the leaderboard
 */
const COSMETICS = [
    'paint' => [
        'team'     => ['name' => 'Team colour',   'level' => 1,  'color' => null],
        'olive'    => ['name' => 'Olive drab',    'level' => 1,  'color' => '#5b6236'],
        'sand'     => ['name' => 'Desert sand',   'level' => 1,  'color' => '#b89a64'],
        'slate'    => ['name' => 'Slate',         'level' => 2,  'color' => '#59636b'],
        'gunmetal' => ['name' => 'Gunmetal',      'level' => 4,  'color' => '#3b3f45'],
        'forest'   => ['name' => 'Forest green',  'level' => 6,  'color' => '#2f5133'],
        'ochre'    => ['name' => 'Ochre',         'level' => 9,  'color' => '#b07a2a'],
        'midnight' => ['name' => 'Midnight blue', 'level' => 12, 'color' => '#23304f'],
        'ivory'    => ['name' => 'Ivory',         'level' => 16, 'color' => '#e6dfc8'],
        'crimson'  => ['name' => 'Crimson',       'level' => 20, 'color' => '#7d2328'],
        'gold'     => ['name' => 'Gold',          'level' => 30, 'color' => '#c9a227'],
        'woodland' => ['name' => 'Woodland camo', 'level' => 8,  'map' => 'highlands',   'wins' => 3, 'camo' => 'blobs',   'colors' => ['#4b5a2e', '#2e3a1c', '#6b5a36', '#1c1f14']],
        'reef'     => ['name' => 'Reef camo',     'level' => 10, 'map' => 'archipelago', 'wins' => 3, 'camo' => 'waves',   'colors' => ['#2f6f7a', '#1d4752', '#d8c890', '#3f8f8a']],
        'desert'   => ['name' => 'Desert camo',   'level' => 11, 'map' => 'canyon',      'wins' => 3, 'camo' => 'blobs',   'colors' => ['#c2a36b', '#8f6b3e', '#e0c99a', '#6b4e2c']],
        'arctic'   => ['name' => 'Arctic camo',   'level' => 14, 'map' => 'arctic',      'wins' => 3, 'camo' => 'shards',  'colors' => ['#e8edf0', '#a9b6bf', '#6f7d87', '#ffffff']],
        'urban'    => ['name' => 'Urban camo',    'level' => 17, 'map' => 'city',        'wins' => 3, 'camo' => 'blocks',  'colors' => ['#7a7d80', '#4a4d50', '#a7a9ab', '#2c2e30']],
        'lava'     => ['name' => 'Lava camo',     'level' => 22, 'map' => 'volcano',     'wins' => 3, 'camo' => 'cracks',  'colors' => ['#2a1f1c', '#1a1312', '#e2541b', '#ffb02e']],
        'lunar'    => ['name' => 'Lunar camo',    'level' => 26, 'map' => 'lunar',       'wins' => 3, 'camo' => 'craters', 'colors' => ['#9a9a9e', '#6c6c70', '#c4c4c8', '#4a4a4e']],
    ],
    'barrel' => [
        'standard' => ['name' => 'Standard', 'level' => 1,  'model' => 'standard'],
        'short'    => ['name' => 'Stubby',   'level' => 3,  'model' => 'short'],
        'long'     => ['name' => 'Long gun', 'level' => 7,  'model' => 'long'],
        'twin'     => ['name' => 'Twin',     'level' => 15, 'model' => 'twin'],
    ],
    'trail' => [
        'team'    => ['name' => 'Team colour',   'level' => 1,  'color' => null,      'style' => 'line'],
        'white'   => ['name' => 'White smoke',   'level' => 1,  'color' => '#f2f2ee', 'style' => 'line'],
        'amber'   => ['name' => 'Amber',         'level' => 3,  'color' => '#f0b429', 'style' => 'line'],
        'emerald' => ['name' => 'Emerald',       'level' => 5,  'color' => '#3ddc84', 'style' => 'line'],
        'azure'   => ['name' => 'Azure',         'level' => 8,  'color' => '#4aa8ff', 'style' => 'line'],
        'rings'   => ['name' => 'Smoke rings',   'level' => 10, 'color' => null,      'style' => 'rings'],
        'violet'  => ['name' => 'Violet',        'level' => 13, 'color' => '#b07cff', 'style' => 'line'],
        'sparks'  => ['name' => 'Sparks',        'level' => 18, 'color' => '#ffd27a', 'style' => 'sparks'],
        'rainbow' => ['name' => 'Rainbow',       'level' => 28, 'color' => null,      'style' => 'rainbow'],
    ],
    'blast' => [
        'standard' => ['name' => 'Standard',     'level' => 1,  'style' => 'standard'],
        'sparks'   => ['name' => 'Spark shower', 'level' => 11, 'style' => 'sparks'],
        'smoke'    => ['name' => 'Smoke ring',   'level' => 19, 'style' => 'smoke'],
        'confetti' => ['name' => 'Confetti',     'level' => 35, 'style' => 'confetti'],
    ],
    'title' => [
        'none'        => ['name' => 'No title',       'level' => 1,  'text' => null],
        'gunner'      => ['name' => 'Gunner',         'level' => 5,  'text' => 'Gunner'],
        'bombardier'  => ['name' => 'Bombardier',     'level' => 10, 'text' => 'Bombardier'],
        'artillerist' => ['name' => 'Artillerist',    'level' => 15, 'text' => 'Artillerist'],
        'engineer'    => ['name' => 'Siege Engineer', 'level' => 20, 'text' => 'Siege Engineer'],
        'thunderer'   => ['name' => 'Thunderer',      'level' => 25, 'text' => 'Thunderer'],
        'master'      => ['name' => 'Master Gunner',  'level' => 30, 'text' => 'Master Gunner'],
        'ironrain'    => ['name' => 'Iron Rain',      'level' => 40, 'text' => 'Iron Rain'],
        'legend'      => ['name' => 'Legend',         'level' => 50, 'text' => 'Legend'],
        'highlander'  => ['name' => 'Highlander',     'level' => 99, 'map' => 'highlands',   'wins' => 10, 'text' => 'Highlander'],
        'islander'    => ['name' => 'Island Hopper',  'level' => 99, 'map' => 'archipelago', 'wins' => 10, 'text' => 'Island Hopper'],
        'canyon'      => ['name' => 'Canyon Runner',  'level' => 99, 'map' => 'canyon',      'wins' => 10, 'text' => 'Canyon Runner'],
        'volcano'     => ['name' => 'Volcano Tamer',  'level' => 99, 'map' => 'volcano',     'wins' => 10, 'text' => 'Volcano Tamer'],
        'icebreaker'  => ['name' => 'Ice Breaker',    'level' => 99, 'map' => 'arctic',      'wins' => 10, 'text' => 'Ice Breaker'],
        'streets'     => ['name' => 'Street Fighter', 'level' => 99, 'map' => 'city',        'wins' => 10, 'text' => 'Street Fighter'],
        'moonwalker'  => ['name' => 'Moonwalker',     'level' => 99, 'map' => 'lunar',       'wins' => 10, 'text' => 'Moonwalker'],
    ],
];
const DEFAULT_COSMETICS = ['paint' => 'team', 'barrel' => 'standard', 'trail' => 'team', 'blast' => 'standard', 'title' => 'none'];

/** A player's wins on each map against other players (vs AI matches don't count): [map id => wins]. */
function map_wins(int $uid): array
{
    $st = db()->prepare('SELECT m.map_id, COUNT(*) FROM ' . T('match_players') . ' p JOIN ' . T('matches') . " m ON m.id = p.match_id
        WHERE p.user_id = ? AND m.status = 'finished' AND m.winner_user_id = p.user_id AND m.game_mode <> 'ai' GROUP BY m.map_id");
    $st->execute([$uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Is a cosmetic open to a player of this level with these map wins? (Level, or enough wins on its map.) */
function cosmetic_unlocked(array $item, int $level, array $wins): bool
{
    if ($level >= $item['level']) return true;
    return isset($item['map']) && ($wins[$item['map']] ?? 0) >= (int)$item['wins'];
}

/** A saved choice cleaned up: known ids and unlocked ones only; anything else falls back to the default. */
function clean_cosmetics($saved, int $level, array $wins): array
{
    if (is_string($saved)) $saved = json_decode($saved, true);
    $out = DEFAULT_COSMETICS;
    foreach (COSMETIC_SLOTS as $slot) {
        $id = is_array($saved) ? (string)($saved[$slot] ?? '') : '';
        if (isset(COSMETICS[$slot][$id]) && cosmetic_unlocked(COSMETICS[$slot][$id], $level, $wins)) $out[$slot] = $id;
    }
    return $out;
}

/** How a set of cosmetics looks, for the battle page (only what it needs to draw them). */
function cosmetics_public(?array $c): array
{
    $c = $c ?: DEFAULT_COSMETICS; $out = [];
    foreach (COSMETIC_SLOTS as $slot) {
        $id = isset(COSMETICS[$slot][$c[$slot] ?? '']) ? $c[$slot] : DEFAULT_COSMETICS[$slot];
        $out[$slot] = ['id' => $id] + array_diff_key(COSMETICS[$slot][$id], ['level' => 1, 'map' => 1, 'wins' => 1]);
    }
    return $out;
}

/** The title text a player shows (leaderboard, battle), or null. */
function title_text(?string $saved): ?string
{
    $c = is_string($saved) ? json_decode($saved, true) : null;
    $id = is_array($c) ? (string)($c['title'] ?? '') : '';
    return COSMETICS['title'][$id]['text'] ?? null;
}

/** The cosmetics each human brings into a match (checked against level and map wins now); computer players: default. */
function group_cosmetics(array $group): array
{
    $out = [];
    foreach ($group as $seat => $g) {
        $st = db()->prepare('SELECT xp, cosmetics, is_bot FROM ' . T('users') . ' WHERE id = ?');
        $st->execute([(int)$g['user_id']]); $u = $st->fetch();
        $out[$seat] = (!$u || (int)$u['is_bot']) ? DEFAULT_COSMETICS
            : clean_cosmetics($u['cosmetics'], level_for((int)$u['xp']), $u['cosmetics'] ? map_wins((int)$g['user_id']) : []);
    }
    return $out;
}
