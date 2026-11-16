<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Power-ups and supply crates (phase 2).
 *
 * A crate parachutes in at the start of every 5th turn (at most 1 on the map), at a random spot inside a 15 m circle
 * around the point between the living tanks (all three set in config.php). A shell exploding within
 * CRATE_CLAIM metres of a crate claims it for the shooter; a crate further away but still inside the blast
 * is destroyed. Each tank holds one power-up; a new claim replaces the old one.
 * Crates are sealed: what's inside is rolled only when one is claimed, so nobody can know it before.
 *
 *   kind  instant  used from the weapon bar without spending the turn (api/item.php)
 *         turn     used from the weapon bar and ends the turn (Relocate)
 *         shell    selected like a weapon and fired (api/shot.php with weapon = the item id)
 *   safe  may drop in ranked matches (and in private matches set to "ranked-safe")
 *   weight  relative drop chance. Flare has weight 0 until night maps arrive with Scenarios (phase 5).
 *
 * The numbers are starting points: every drop, claim and use is written to the item_log table so the
 * weights can be tuned against real win rates later.
 */
/* Defaults: a crate every 5th turn, at most 1 on the field. Change with 'crate_every' / 'crate_max' in config.php;
 * each match keeps the values it started with (stored in its rules). */
const CRATE_EVERY = 5, CRATE_MAX = 1, CRATE_CLAIM = 4.0, CRATE_HIT = 1.3;
/* Paired drops (phase 4): each tank's crate lands this far from it (crate_near in config.php); driving within
 * CRATE_PICK metres of a crate picks it up (Scavenger: 3.5 m). */
const CRATE_NEAR = 18.0, CRATE_PICK = 2.5;
/* Where crates fall: anywhere inside a circle of this radius (metres) around the point between the living tanks
 * (crate_centre() in engine.php). Change with 'crate_radius' in config.php. */
const CRATE_RADIUS = 15.0;

const POWERUPS = [
    'repair'     => ['name' => 'Repair Kit',     'kind' => 'instant', 'safe' => true,  'weight' => 20, 'short' => '+30 hp'],
    'shield'     => ['name' => 'Shield Bubble',  'kind' => 'instant', 'safe' => true,  'weight' => 15, 'short' => 'Absorbs 40 damage for 2 enemy turns'],
    'weather'    => ['name' => 'Weather Report', 'kind' => 'instant', 'safe' => true,  'weight' => 15, 'short' => 'Shows the wind for your next 2 turns'],
    'bunker'     => ['name' => 'Bunker Buster',  'kind' => 'shell',   'safe' => true,  'weight' => 12, 'short' => 'Tunnels 8 m, then explodes (60 dmg, 7 m)'],
    'napalm'     => ['name' => 'Napalm',         'kind' => 'shell',   'safe' => true,  'weight' => 10, 'short' => 'Leaves a 6 m fire: 8 dmg a turn for 3 turns'],
    'earthmover' => ['name' => 'Earthmover',     'kind' => 'shell',   'safe' => true,  'weight' => 10, 'short' => 'Builds a mound instead of a crater'],
    'double'     => ['name' => 'Double Shot',    'kind' => 'instant', 'safe' => false, 'weight' => 6,  'short' => 'Fire twice this turn'],
    'rangefinder'=> ['name' => 'Rangefinder',    'kind' => 'instant', 'safe' => false, 'weight' => 6,  'short' => 'Aim arc shows the whole flight, wind included'],
    'flare'      => ['name' => 'Flare',          'kind' => 'instant', 'safe' => false, 'weight' => 0,  'short' => 'Night maps only (Scenarios)'],
    'relocate'   => ['name' => 'Relocate',       'kind' => 'turn',    'safe' => false, 'weight' => 2,  'short' => 'Moves your tank 40 m+ at random. Uses your turn'],
];

const REPAIR_HP = 30, SHIELD_HP = 40, SHIELD_TURNS = 2, FIRE_DMG = 8, FIRE_TURNS = 3, FIRE_R = 6.0, RELOCATE_MIN = 40.0;

/** Which power-ups can drop for a match's item setting: 'all', 'safe' or 'off'. */
function item_pool(string $setting): array
{
    if ($setting === 'off') return [];
    $out = [];
    foreach (POWERUPS as $k => $p) if ($p['weight'] > 0 && ($setting === 'all' || $p['safe'])) $out[$k] = $p['weight'];
    return $out;
}

/** Weighted random pick from item_pool(). */
function roll_item(array $pool): ?string
{
    $sum = array_sum($pool);
    if ($sum <= 0) return null;
    $r = rnd() * $sum;
    foreach ($pool as $k => $w) { if ($r < $w) return $k; $r -= $w; }
    return array_key_last($pool);
}

/**
 * Ongoing effects for the match being simulated: shields, fire zones, Double Shot, Rangefinder and
 * pre-rolled wind (Weather Report). game.php loads it from matches.effects before each action and saves
 * it after. The engine reads and changes it as shells explode and turns start.
 */
final class FX
{
    public static array $e = [];
    public static function reset(?array $e = null): void
    {
        self::$e = ($e ?? []) + ['shields' => [], 'fires' => [], 'double' => null, 'range' => [], 'winds' => [], 'forecast' => [], 'fire_seq' => 0, 'moved' => null];
    }
    /** Public view sent to every player (no wind forecasts). */
    public static function pub(?int $me): array
    {
        $e = self::$e;
        $out = ['shields' => (object)$e['shields'], 'fires' => array_values($e['fires']), 'double' => $e['double'], 'range' => array_values($e['range']),
                'moved' => $e['moved']];
        if ($me !== null && !empty($e['forecast'][$me])) $out['forecast'] = $e['forecast'][$me];
        return $out;
    }
}
FX::reset();
