<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * The built-in campaign (phase 5): seven co-op missions, put into the missions table the first time the lobby lists
 * missions (seed_campaign() in scenario.php). They can be changed in the mission editor like any other mission;
 * a slug already in the table is never overwritten from here. Positions are metres from the map's centre (x east, z
 * south). tests/mission_map.php draws any of them from above; tests/mission_bench.php plays them with stand-ins.
 */
const CAMPAIGN = [
    ['slug' => 'hold-the-ridge', 'title' => 'Hold the Ridge', 'map' => 'highlands', 'seed' => 23, 'size' => 160,
     'blurb' => 'Dig in on the ridge. Six waves of enemy tanks parachute onto the far hills. Hold out for 14 rounds, or destroy every one of them.',
     'players' => [1, 4], 'starts' => [[-22, -16], [-8, -9], [6, -2], [20, 4]],
     'enemies' => [
        ['x' => -62, 'z' => -84, 'level' => 'rookie', 'round' => 1],
        ['x' => 62, 'z' => -82, 'level' => 'rookie', 'round' => 3],
        ['x' => -72, 'z' => 74, 'level' => 'rookie', 'round' => 5], ['x' => 70, 'z' => 80, 'level' => 'rookie', 'round' => 5, 'min' => 3],
        ['x' => 0, 'z' => -96, 'level' => 'rookie', 'round' => 7], ['x' => -96, 'z' => 22, 'level' => 'rookie', 'round' => 7, 'min' => 2],
        ['x' => 92, 'z' => -30, 'level' => 'rookie', 'round' => 9], ['x' => 10, 'z' => 92, 'level' => 'rookie', 'round' => 9, 'min' => 4],
        ['x' => -50, 'z' => 92, 'level' => 'rookie', 'round' => 11], ['x' => 84, 'z' => 52, 'level' => 'veteran', 'round' => 11, 'min' => 2],
        ['x' => -92, 'z' => -58, 'level' => 'rookie', 'round' => 11, 'min' => 3],
     ],
     'goal' => ['type' => 'survive', 'rounds' => 14], 'limit' => 0, 'stars' => ['shots' => 12, 'damage' => 60],
     'opts' => ['items' => 'safe', 'drops' => true, 'regen' => 5]],

    ['slug' => 'bridge-out', 'title' => 'Bridge Out', 'map' => 'highlands', 'seed' => 11, 'size' => 160,
     'blurb' => 'An enemy convoy is heading for the river. Bring down all three spans of the bridge before it gets there, while tanks on the far bank shoot back.',
     'players' => [1, 4], 'starts' => [[-90, 92], [-68, 96], [-46, 92], [-24, 96]],
     'enemies' => [
        ['x' => -104, 'z' => -30, 'level' => 'rookie', 'round' => 1],
        ['x' => -44, 'z' => -24, 'level' => 'rookie', 'round' => 1, 'min' => 2],
        ['x' => -76, 'z' => -52, 'kind' => 'gun', 'level' => 'rookie', 'round' => 1, 'min' => 3],
     ],
     'objects' => [
        ['type' => 'target', 'style' => 'span', 'name' => 'North span',  'x' => -75, 'z' => 8.5,  'w' => 8, 'd' => 9, 'h' => 9, 'hp' => 140],
        ['type' => 'target', 'style' => 'span', 'name' => 'Middle span', 'x' => -75, 'z' => 17.5, 'w' => 8, 'd' => 9, 'h' => 9, 'hp' => 140],
        ['type' => 'target', 'style' => 'span', 'name' => 'South span',  'x' => -75, 'z' => 26.5, 'w' => 8, 'd' => 9, 'h' => 9, 'hp' => 140],
     ],
     'rivers' => [['a' => [-112, 16], 'b' => [112, 28], 'w' => 16]],
     'convoy' => ['path' => [[-26, -102], [-58, -66], [-76, -30], [-75, 3]], 'speed' => 16],
     'goal' => ['type' => 'destroy_targets'], 'limit' => 0, 'stars' => ['shots' => 6, 'damage' => 20],
     'opts' => ['items' => 'safe', 'drops' => true]],

    ['slug' => 'supply-run', 'title' => 'Supply Run', 'map' => 'archipelago', 'seed' => 7, 'size' => 160,
     'blurb' => 'Seven supply crates are scattered round the ring and the islets. Claim four before the enemy across the lagoon does: drive over them, or shoot them open.',
     'players' => [1, 4], 'starts' => [[-24, 82], [-6, 86], [12, 85], [30, 80]],
     'enemies' => [
        ['x' => -26, 'z' => -76, 'level' => 'rookie', 'round' => 1],
        ['x' => 26, 'z' => -74, 'level' => 'rookie', 'round' => 1, 'min' => 2],
        ['x' => 60, 'z' => -46, 'level' => 'rookie', 'round' => 4, 'min' => 4],
     ],
     'objects' => [
        ['type' => 'crate', 'x' => -82, 'z' => 4], ['type' => 'crate', 'x' => 84, 'z' => 2],
        ['type' => 'crate', 'x' => -62, 'z' => 56], ['type' => 'crate', 'x' => 62, 'z' => 56],
        ['type' => 'crate', 'x' => -60, 'z' => -52], ['type' => 'crate', 'x' => 62, 'z' => -50],
        ['type' => 'crate', 'x' => -40, 'z' => 4],
     ],
     'goal' => ['type' => 'crates', 'count' => 4], 'limit' => 24, 'stars' => ['shots' => 8, 'damage' => 30],
     'opts' => ['items' => 'safe', 'drops' => false]],

    ['slug' => 'flare-line', 'title' => 'Flare Line', 'map' => 'highlands', 'seed' => 31, 'size' => 160,
     'blurb' => 'Night. Enemy guns are dug in across the valley and you can\'t see them. Fire flares to light them up: a lit tank stays visible for 2 rounds.',
     'players' => [1, 4], 'starts' => [[-30, 60], [-10, 66], [10, 66], [30, 60]],
     'enemies' => [
        ['x' => -50, 'z' => -36, 'level' => 'rookie', 'hp' => 70, 'round' => 1],
        ['x' => 4, 'z' => -54, 'kind' => 'gun', 'level' => 'rookie', 'hp' => 70, 'round' => 1],
        ['x' => 56, 'z' => -32, 'level' => 'rookie', 'hp' => 70, 'round' => 1, 'min' => 2],
        ['x' => -20, 'z' => -74, 'kind' => 'gun', 'level' => 'rookie', 'hp' => 70, 'round' => 1, 'min' => 3],
     ],
     'goal' => ['type' => 'destroy_all'], 'limit' => 26, 'stars' => ['shots' => 14, 'damage' => 50],
     'opts' => ['night' => true, 'flares' => 8, 'items' => 'safe', 'drops' => true]],

    ['slug' => 'fortress-siege', 'title' => 'Fortress Siege', 'map' => 'city', 'seed' => 5, 'size' => 160,
     'blurb' => 'Two heavy guns sit inside a walled fort at the south of the city. Break through the walls or lob over them, and silence both guns within 25 rounds.',
     'players' => [1, 4], 'starts' => [[-30, -10], [-8, -14], [14, -10], [36, -14]],
     'enemies' => [
        ['x' => -46, 'z' => 57, 'kind' => 'gun', 'level' => 'rookie', 'hp' => 90, 'goal' => true, 'name' => 'West gun'],
        ['x' => -34, 'z' => 64, 'kind' => 'gun', 'level' => 'rookie', 'hp' => 90, 'goal' => true, 'name' => 'East gun'],
        ['x' => 30, 'z' => 30, 'level' => 'rookie', 'round' => 1, 'min' => 2],
        ['x' => -90, 'z' => 18, 'level' => 'rookie', 'round' => 6, 'min' => 3],
     ],
     'objects' => [
        ['type' => 'wall', 'name' => 'North wall', 'x' => -40, 'z' => 46, 'w' => 26, 'd' => 2.5, 'h' => 6, 'hp' => 120],
        ['type' => 'wall', 'name' => 'South wall', 'x' => -40, 'z' => 75, 'w' => 26, 'd' => 2.5, 'h' => 6, 'hp' => 120],
        ['type' => 'wall', 'name' => 'West wall',  'x' => -54, 'z' => 53.5, 'w' => 2.5, 'd' => 12, 'h' => 6, 'hp' => 120],
        ['type' => 'wall', 'name' => 'West wall',  'x' => -54, 'z' => 67.5, 'w' => 2.5, 'd' => 12, 'h' => 6, 'hp' => 120],
        ['type' => 'wall', 'name' => 'East wall',  'x' => -26, 'z' => 53.5, 'w' => 2.5, 'd' => 12, 'h' => 6, 'hp' => 120],
        ['type' => 'wall', 'name' => 'East wall',  'x' => -26, 'z' => 67.5, 'w' => 2.5, 'd' => 12, 'h' => 6, 'hp' => 120],
     ],
     'goal' => ['type' => 'destroy_targets'], 'limit' => 25, 'stars' => ['shots' => 12, 'damage' => 45],
     'opts' => ['items' => 'safe', 'drops' => true]],

    ['slug' => 'eruption', 'title' => 'Eruption', 'map' => 'volcano', 'seed' => 3, 'size' => 160,
     'blurb' => 'The volcano is waking: it erupts every 2nd round. Hold out for 15 rounds against the tanks on the far slopes, or destroy them all.',
     'players' => [1, 4], 'starts' => [[-74, 56], [-58, 72], [-84, 36], [-40, 84]],
     'enemies' => [
        ['x' => 70, 'z' => -58, 'level' => 'rookie', 'round' => 1],
        ['x' => 86, 'z' => -26, 'level' => 'rookie', 'round' => 1],
        ['x' => 36, 'z' => -84, 'level' => 'veteran', 'round' => 1, 'min' => 2],
        ['x' => 60, 'z' => 60, 'level' => 'rookie', 'round' => 5, 'min' => 4],
     ],
     'goal' => ['type' => 'survive', 'rounds' => 15], 'limit' => 0, 'stars' => ['shots' => 10, 'damage' => 60],
     'opts' => ['items' => 'safe', 'drops' => true, 'erupt' => 2]],

    ['slug' => 'the-behemoth', 'title' => 'The Behemoth', 'map' => 'highlands', 'seed' => 23, 'size' => 160,
     'blurb' => 'A giant tank with three weak points that take double damage: the main gun, the radar mast and the shield generator. Each one you destroy changes how it fights.',
     'players' => [1, 4], 'starts' => [[-36, 56], [-12, 64], [12, 64], [36, 56]],
     'enemies' => [
        ['x' => 0, 'z' => -56, 'kind' => 'behemoth', 'level' => 'rookie', 'hp' => 300, 'goal' => true],
        ['x' => -56, 'z' => -40, 'level' => 'rookie', 'round' => 1, 'min' => 2],
        ['x' => 56, 'z' => -44, 'level' => 'rookie', 'round' => 1, 'min' => 3],
        ['x' => -86, 'z' => -12, 'level' => 'rookie', 'round' => 8],
        ['x' => 86, 'z' => -16, 'level' => 'rookie', 'round' => 10, 'min' => 4],
     ],
     'goal' => ['type' => 'destroy_targets'], 'limit' => 36, 'stars' => ['shots' => 20, 'damage' => 70],
     'opts' => ['items' => 'safe', 'drops' => true]],
];
