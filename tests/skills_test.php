<?php
/*
 * Phase 4 unit checks: XP and levels, loadout rules, and what each skill does in the engine (on a flat test map,
 * so every number is exact).
 *   php tests/skills_test.php
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

/* ---------- XP and levels ---------- */
ok('level 1 at 0 XP, 75 to level 2', level_info(0) === [1, 0, 75]);
ok('level 2 at 75 XP', level_for(75) === 2 && level_for(74) === 1);
ok('level 14 at 2925 XP (every skill)', level_for(2925) === 14 && level_for(2924) === 13 && xp_for_level(14) === 2925);
ok('levels stop at 50', level_for(10 ** 7) === 50 && level_info(10 ** 7)[2] === 0);
$x = match_xp(['damage_dealt' => 87, 'kills' => 1, 'placement' => 1], false, true, true);
ok('XP: finish 50 + 8×2 damage + kill 25 + win 50 + first win 100', $x['total'] === 241, $x);
$x = match_xp(['damage_dealt' => 87, 'kills' => 1, 'placement' => 1], true, true, true);
ok('XP: quitting keeps only damage and kills', $x['total'] === 41, $x);
ok('XP: a typical match is about 130', match_xp(['damage_dealt' => 120, 'kills' => 0, 'placement' => 2], false, false, false)['total'] === 74
   && match_xp(['damage_dealt' => 150, 'kills' => 1, 'placement' => 1], false, true, false)['total'] === 155);

/* ---------- loadouts ---------- */
ok('parse: preset', parse_loadout('{"preset":"gunner"}') === ['preset' => 'gunner']);
ok('parse: custom, perks sorted', parse_loadout(['doctrine' => 'heavy', 'perks' => ['spotter', 'loader']]) === ['doctrine' => 'heavy', 'perks' => ['loader', 'spotter']]);
ok('parse: 3 perks refused', parse_loadout(['doctrine' => 'heavy', 'perks' => ['spotter', 'loader', 'tracks']]) === null);
ok('parse: unknown skill refused', parse_loadout(['doctrine' => 'heavy', 'perks' => ['laser']]) === null && parse_loadout(['doctrine' => 'x']) === null);
ok('parse: duplicate perk counts once', parse_loadout(['doctrine' => 'heavy', 'perks' => ['loader', 'loader']]) === ['doctrine' => 'heavy', 'perks' => ['loader']]);
ok('locked below the level', loadout_error(['doctrine' => 'fortified', 'perks' => []], 8, false) === 'locked' && loadout_error(['doctrine' => 'fortified', 'perks' => []], 9, false) === null);
ok('ranked below 14 needs a preset', loadout_error(['doctrine' => 'standard', 'perks' => []], 13, true) === 'ranked_preset' && loadout_error(['preset' => 'sapper'], 1, true) === null);
ok('match_loadout: ranked below 14 uses the ranked preset', match_loadout('{"doctrine":"heavy","perks":["loader"],"ranked_preset":"sapper"}', 5, true) === ['preset' => 'sapper']);
ok('match_loadout: casual keeps the custom build', match_loadout('{"doctrine":"heavy","perks":["loader"],"ranked_preset":"sapper"}', 5, false) === ['doctrine' => 'heavy', 'perks' => ['loader']]);
ok('match_loadout: a build the level no longer allows falls back to the default', match_loadout('{"doctrine":"meteorologist","perks":[]}', 3, false) === DEFAULT_LOADOUT);
ok('match_loadout: nothing saved = default', match_loadout(null, 1, true) === DEFAULT_LOADOUT);
ok('every skill unlocked by level 14', count(unlocked_skills(14)['doctrines']) === 6 && count(unlocked_skills(14)['perks']) === 7);
ok('126 two-perk builds', count(all_loadouts()) === 126 && count(array_unique(array_map('loadout_key', all_loadouts()))) === 126);
ok('presets are valid at level 1 in ranked', !array_filter(array_keys(PRESETS), fn($p) => loadout_error(['preset' => $p], 1, true) !== null));
$m = skill_mods(['doctrine' => 'fortified', 'perks' => ['lowprofile', 'tracks']]);
ok('mods add up: hp 100 + 5 − 4 − 5 = 96', loadout_hp(['doctrine' => 'fortified', 'perks' => ['lowprofile', 'tracks']]) === 96 && $m['max_power'] === 85.0 && $m['hit_r'] === 2.1, $m);
ok('no loadout = no mods, 100 hp', skill_mods(null) === [] && loadout_hp(null) === 100);

/* ---------- the engine ---------- */
$flat = fn(float $h = 5.0) => new Terrain(array_fill(0, 161 * 161, $h), 160, -100.0);
$rules = ['gravity' => 20.0, 'water' => -100.0, 'splash' => false, 'fall_mult' => 1.0, 'items' => 'safe'];
$tank = fn(float $x, float $z, ?array $lo = null) => ['seat' => 0, 'x' => $x, 'y' => 5.0, 'z' => $z, 'hp' => loadout_hp($lo), 'alive' => true, 'last_hit_by' => null,
    'damage_dealt' => 0, 'item' => null, 'loadout' => $lo, 'sk' => skill_mods($lo), 'max_hp' => loadout_hp($lo)];
$C = fn(string $d, array $p = []) => ['doctrine' => $d, 'perks' => $p];
/** Explode $wk fired by seat 0 (loadout $lo) at point $p; seat 1 stands at $victim with loadout $vlo. */
$blast = function (string $wk, ?array $lo, array $p, array $victim, ?array $vlo = null, array $objects = []) use ($flat, $rules, $tank) {
    FX::reset(); $t = $flat();
    $players = [0 => $tank(-60, 0, $lo), 1 => ['seat' => 1] + $tank($victim[0], $victim[1], $vlo)];
    $ops = [];
    $b = explode_at($t, $players, $objects, $p, shooter_weapon($wk, $players[0]), 0, false, $ops);
    return [$b, $players, $objects, $t];
};
[$b] = $blast('shell', null, [0.0, 6.0, 0.0], [0.0, 0.0]);
ok('baseline: a direct shell does 45', $b['hits'][0]['dmg'] === 45 && $b['r'] === 6.0, $b);
[$b] = $blast('shell', $C('demolitions'), [0.0, 6.0, 0.0], [0.0, 0.0]);
ok('Demolitions: 41 damage, crater 7.8 m', $b['hits'][0]['dmg'] === 41 && abs($b['r'] - 7.8) < 1e-9, $b);
[$b] = $blast('heavy', $C('meteorologist'), [0.0, 6.0, 0.0], [0.0, 0.0]);
ok('Meteorologist: Heavy 70 − 2 = 68', $b['hits'][0]['dmg'] === 68, $b);
ok('Bomblet Pack: 7 bomblets of 19', shooter_weapon('cluster', $tank(0, 0, $C('standard', ['bomblets'])))['split'] === 7
   && shooter_weapon('cluster', $tank(0, 0, $C('standard', ['bomblets'])))['dmg'] === 19);
[$b] = $blast('shell', null, [2.2, 6.0, 0.0], [0.0, 0.0]);
[$b2] = $blast('shell', null, [2.2, 6.0, 0.0], [0.0, 0.0], $C('standard', ['lowprofile']));
ok('Low Profile: 2.2 m off is a direct hit on a normal tank, not on a low one', $b['hits'][0]['dmg'] === 45 && $b2['hits'][0]['dmg'] < 45, [$b['hits'], $b2['hits']]);
[$b] = $blast('shell', null, [0.0, 6.0, 0.0], [0.0, 0.0], $C('standard', ['dugin']));
[$b2] = $blast('shell', null, [0.0, 6.0, 4.0], [0.0, 0.0], $C('standard', ['dugin']));
[$b3] = $blast('shell', null, [0.0, 6.0, 4.0], [0.0, 0.0]);
ok('Dug In: 10% less within 3 m (45 → 41), unchanged further out', $b['hits'][0]['dmg'] === 41 && $b2['hits'][0]['dmg'] === $b3['hits'][0]['dmg'], [$b['hits'], $b2['hits'], $b3['hits']]);
$crate = [['id' => 0, 'type' => 'crate', 'x' => 5.0, 'z' => 0.0, 'y' => 5.0, 'pool' => 'safe', 'state' => 'live']];
[$b] = $blast('shell', null, [0.0, 5.8, 0.0], [40.0, 40.0], null, $crate);
[$b2] = $blast('shell', $C('standard', ['scavenger']), [0.0, 5.8, 0.0], [40.0, 40.0], null, $crate);
ok('Scavenger: a crate 5 m away is wrecked normally, claimed with Scavenger', isset($b['crates'][0]) && $b['crates'][0]['by'] === null && ($b2['crates'][0]['by'] ?? null) === 0, [$b['crates'] ?? null, $b2['crates'] ?? null]);
[$b] = $blast('napalm', $C('standard', ['scavenger']), [0.0, 5.8, 0.0], [40.0, 40.0]);
ok('Scavenger: Napalm burns 2 turns instead of 3', $b['fire']['left'] === 2, $b['fire']);
// Dug In never finds Relocate
FX::reset(); $found = 0;
for ($i = 0; $i < 400; $i++) {
    mt_srand($i); $objs = [['id' => 0, 'type' => 'crate', 'x' => 0.0, 'z' => 0.0, 'y' => 5.0, 'pool' => 'all', 'state' => 'live']];
    $pl = [0 => $tank(-60, 0, $C('standard', ['dugin'])), 1 => ['seat' => 1] + $tank(60, 60)]; $ops = [];
    explode_at($flat(), $pl, $objs, [0.0, 5.8, 0.0], shooter_weapon('shell', $pl[0]), 0, false, $ops);
    if ($objs[0]['item'] === 'relocate') $found++;
}
ok('Dug In: 400 claims from the full pool, never Relocate', $found === 0);
// falls
$fall = function (?array $shooterLo, ?array $victimLo) use ($flat, $rules, $tank) {
    FX::reset(); $t = $flat(); $t->crater(0, 0, 9.0, 1.0);
    $pl = [0 => $tank(-60, 0, $shooterLo), 1 => ['seat' => 1] + $tank(0, 0, $victimLo)];
    $w = shooter_weapon('shell', $pl[0]);
    return settle($t, $pl, $rules, 0, (float)$w['fall'])[0]['dmg'] ?? null;
};
$base = $fall(null, null);
ok('falls: Demolitions doubles the fall it causes', $fall($C('demolitions'), null) === (int)round($base * 2) || abs($fall($C('demolitions'), null) - 2 * $base) <= 1, [$base, $fall($C('demolitions'), null)]);
ok('falls: Reinforced Tracks halves the fall it takes', abs($fall(null, $C('standard', ['tracks'])) - $base / 2) <= 1, [$base, $fall(null, $C('standard', ['tracks']))]);
// regen and repair stop at the tank's own maximum
FX::reset(); $t = $flat();
$pl = [0 => array_replace($tank(0, 0, $C('fortified')), ['hp' => 103]), 1 => ['seat' => 1] + $tank(50, 50)];
$o = []; $ev = start_turn($t, $pl, $o, $rules + ['erupt_every' => 0, 'vent' => null, 'snow' => false, 'wind_max' => 0, 'regen' => 3, 'crate_every' => 99], 2, 1, 0);
ok('regen: a Fortified tank heals up to 105', $pl[0]['hp'] === 105 && $ev['regen']['heal'] === 2, [$pl[0]['hp'], $ev['regen'] ?? null]);
$pl[0]['hp'] = 70; $r = item_effect($t, $pl, [], 0, 'repair', 3);
ok('repair: +30 up to the tank maximum', $pl[0]['hp'] === 100);
$pl[0]['hp'] = 95; item_effect($t, $pl, [], 0, 'repair', 3);
ok('repair: Fortified caps at 105, not 100', $pl[0]['hp'] === 105);
$pl[0] = $tank(0, 0, $C('standard', ['scavenger'])); item_effect($t, $pl, [], 0, 'shield', 3);
ok('Scavenger: Shield lasts 1 enemy turn', FX::$e['shields'][0]['left'] === 1);
$pl[0] = $tank(0, 0, $C('standard', ['dugin']));
ok('Dug In: Relocate refused', (item_effect($t, $pl, [], 0, 'relocate', 3)['error'] ?? '') === 'dug_in');
// spotter
$pl = [0 => $tank(0, 0), 1 => ['seat' => 1] + $tank(0, -100)];            // the enemy straight ahead (−z)
$res = ['projectiles' => [['boom' => ['x' => 3.0, 'z' => -88.0]]]];
$sp = spot_report($pl, 0, $res, [1]);
ok('Spotter: 12 m short, 3 m right', $sp['long'] === -12.0 && $sp['right'] === 3.0 && $sp['seat'] === 1, $sp);
ok('Heavy Gunner: Heavy does 65', shooter_weapon('heavy', $tank(0, 0, $C('heavy')))['dmg'] === 65 && shooter_weapon('heavy', $tank(0, 0))['dmg'] === 70);
ok('max power: Fortified 85, others 100', max_power($tank(0, 0, $C('fortified'))) === 85.0 && max_power($tank(0, 0)) === 100.0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
