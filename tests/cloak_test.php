<?php
/*
 * Invisibility power-up: unit checks on the engine (flat test map).
 *   php tests/cloak_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8, 'move_per_turn' => 10, 'regen_per_turn' => 3];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/engine.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $c, $info = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name " . json_encode($info) . "\n"; }
}
$t = new Terrain(array_fill(0, 161 * 161, 5.0), 160, -100.0);
$rules = ['gravity' => 20.0, 'water' => -100.0, 'splash' => false, 'fall_mult' => 1.0, 'items' => 'all', 'erupt_every' => 0, 'vent' => null,
          'crates' => 'paired', 'crate_every' => 5, 'crate_near' => 18.0, 'move' => 10, 'regen' => 0];
$tank = fn(int $s, float $x, float $z) => ['seat' => $s, 'x' => $x, 'y' => 5.0, 'z' => $z, 'hp' => 100, 'alive' => true, 'last_hit_by' => null,
    'damage_dealt' => 0, 'item' => null, 'loadout' => null, 'sk' => [], 'max_hp' => 100];

ok('in the catalog: instant, casual only', POWERUPS['cloak']['kind'] === 'instant' && !POWERUPS['cloak']['safe'] && POWERUPS['cloak']['weight'] > 0);
ok('drops with items "all", not in ranked-safe', isset(item_pool('all')['cloak']) && !isset(item_pool('safe')['cloak']));

FX::reset(); $pl = [0 => $tank(0, -40, 0), 1 => $tank(1, 40, 0)];
$r = item_effect($t, $pl, [], 0, 'cloak', 3);
ok('use: free action, hidden 2 rounds, remembers where it vanished', $r['ends_turn'] === false && FX::$e['cloak'][0] === ['left' => 2, 'turn' => 3, 'seen' => [-40.0, 5.0, 0.0]], FX::$e['cloak']);
ok('use again while hidden: already on', (item_effect($t, $pl, [], 0, 'cloak', 3)['error'] ?? '') === 'already_on');
ok('public view: who and how long, never where', json_encode(FX::pub(1)['cloak']) === '{"0":{"left":2}}');

$objs = [];
$e = start_turn($t, $pl, $objs, $rules, 4, 0, 1);
ok("enemy's turn: still hidden, 2 left", FX::$e['cloak'][0]['left'] === 2 && empty($e['cloak_end']));
$e = start_turn($t, $pl, $objs, $rules, 5, 1, 0);
ok('its next turn (round 2): still hidden, 1 left', FX::$e['cloak'][0]['left'] === 1 && empty($e['cloak_end']));
ok('a supply drop skips the hidden tank (its crate would give it away)', count($objs) === 1 && $objs[0]['for'] === 1, $objs);
$pl[0]['x'] = -30.0;                                        // drove unseen
start_turn($t, $pl, $objs, $rules, 6, 0, 1);
$e = start_turn($t, $pl, $objs, $rules, 7, 1, 0);
ok('its turn after that: visible again, at its real spot', !isset(FX::$e['cloak'][0]) && $e['cloak_end'] === [['seat' => 0, 'at' => [-30.0, 5.0, 0.0]]], $e['cloak_end'] ?? null);

FX::reset(); item_effect($t, $pl, [], 0, 'cloak', 3);
ok('cloaked()', cloaked(0) && !cloaked(1));
ok('firing reveals: real position, cloak gone', cloak_reveal($pl, 0) === [-30.0, 5.0, 0.0] && !cloaked(0));
ok('reveal when not hidden: nothing', cloak_reveal($pl, 1) === null);

FX::reset(); item_effect($t, $pl, [], 0, 'cloak', 3);
$ops = []; $b = explode_at($t, $pl, $objs, [-30.0, 6.0, 0.0], shooter_weapon('shell', $pl[1]), 1, false, $ops);
ok('still there: a shell on it hits as usual', ($b['hits'][0]['seat'] ?? -1) === 0 && $b['hits'][0]['dmg'] > 0, $b['hits']);

FX::reset(); item_effect($t, $pl, [], 0, 'cloak', 3); $pl[0]['alive'] = false;
start_turn($t, $pl, $objs, $rules, 4, 0, 1);
ok('destroyed while hidden: cloak cleared', !cloaked(0));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
