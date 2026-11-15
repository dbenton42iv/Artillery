<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Map recipes. A map is a terrain generator (gen_<id>() in engine.php) plus these physics settings.
 * The settings are copied into the match row (matches.rules) when the match starts, so changing a
 * value here never alters a match already in progress. assets/game.js picks the look by map id.
 *
 *   gravity      m/s² (20 everywhere except the Moon)
 *   wind         [min, max] m/s
 *   wind_axis    wind only blows along one line (set by the generator)
 *   water        sea level, or null for no sea
 *   splash       shells landing in water burst at the surface: radius ×0.6, no crater
 *   erupt_every  every Nth turn the vent throws lava bombs and the cone grows
 *   snow         snowfall partly refills craters at the start of every turn
 *   fall_mult    multiplier on fall damage
 *   buildings    the generator places buildings (120 hp, block shells, collapse to rubble)
 */
const MAPS = [
    'highlands'   => ['name' => 'Highlands',    'gravity' => 20.0, 'wind' => [0.0, 8.0],  'water' => 1.6,
                      'blurb' => 'An island with a central ridge.'],
    'archipelago' => ['name' => 'Archipelago',  'gravity' => 20.0, 'wind' => [0.0, 10.0], 'water' => 1.6, 'splash' => true,
                      'blurb' => 'Shells that land in the sea splash: smaller blast, no crater.'],
    'canyon'      => ['name' => 'Canyon',       'gravity' => 20.0, 'wind' => [6.0, 14.0], 'water' => 1.6, 'wind_axis' => true,
                      'crate_high' => true,   // crates land up on the plateaus, never down in the gorge
                      'blurb' => 'Strong wind always blows along the canyon. Lob high.'],
    'volcano'     => ['name' => 'Volcano',      'gravity' => 20.0, 'wind' => [0.0, 6.0],  'water' => 1.6, 'erupt_every' => 4,
                      'blurb' => 'Every 4th turn the vent erupts: 3 lava bombs, and the cone grows.'],
    'arctic'      => ['name' => 'Arctic Shelf', 'gravity' => 20.0, 'wind' => [4.0, 12.0], 'water' => 1.6, 'snow' => true, 'fall_mult' => 1.5,
                      'blurb' => 'Snow refills craters every turn. Falls on ice hurt 50% more.'],
    'city'        => ['name' => 'Ruined City',  'gravity' => 20.0, 'wind' => [0.0, 6.0],  'water' => 1.6, 'buildings' => true,
                      'blurb' => 'Buildings (120 hp) stop shells until they are shot down to rubble.'],
    'lunar'       => ['name' => 'Lunar Base',   'gravity' => 7.0,  'wind' => [0.0, 0.0],  'water' => null,
                      'blurb' => 'Low gravity, no wind: long, lazy arcs. Go easy on the power.'],
];

/** Ranked pool used until Seasons (phase 6) bring a per-season pool. Override with 'ranked_maps' in config.php. */
const RANKED_POOL_DEFAULT = ['highlands', 'archipelago', 'canyon', 'volcano', 'arctic'];

const RANKED_SESSION_GAP = 1800;          // a ranked session ends after 30 minutes without a ranked match

/* Sudden death: from this turn on, wind rises by SD_WIND m/s per turn (never above SD_WIND_CAP) and every tank
 * loses SD_DRAIN hp per turn. */
const SD_TURN = 30, SD_WIND = 0.5, SD_DRAIN = 5, SD_WIND_CAP = 15.0;

function ranked_pool(): array
{
    $pool = cfg('ranked_maps', RANKED_POOL_DEFAULT);
    $pool = array_values(array_unique(array_filter(is_array($pool) ? $pool : [], fn($m) => isset(MAPS[$m]))));
    return $pool ?: RANKED_POOL_DEFAULT;
}
