<?php
/* Phase 5 unit checks: mission definitions, teams, waves, the convoy, flares, the Behemoth, outcomes and stars. No database.
 *   php tests/scenario_test.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
$GLOBALS['ART_CFG'] = ['wind_max' => 8, 'move_per_turn' => 10, 'regen_per_turn' => 3];
function cfg(string $k, $d = null) { return $GLOBALS['ART_CFG'][$k] ?? $d; }
require dirname(__DIR__) . '/includes/ai.php';
require dirname(__DIR__) . '/includes/campaign.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m) { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "FAIL $m\n"; } }
function flat(int $n = 160, float $h = 5.0): Terrain { return new Terrain(array_fill(0, ($n + 1) ** 2, $h), $n, 1.6); }
function tank(int $s, float $x, float $z, int $team, int $hp = 100): array { return ['seat' => $s, 'x' => $x, 'y' => 5.0, 'z' => $z, 'hp' => $hp, 'max_hp' => $hp, 'alive' => true, 'team' => $team, 'last_hit_by' => null, 'damage_dealt' => 0, 'item' => null, 'death_turn' => null, 'sk' => []]; }

// definitions
foreach (CAMPAIGN as $c) { [, $e] = mission_clean($c); ok(!$e, "campaign {$c['slug']} is valid: " . implode(' ', $e)); }
[$d, $e] = mission_clean([]); ok(count($e) >= 2, 'an empty mission lists what is missing');
[$d, $e] = mission_clean(['starts' => [[0, 0]], 'players' => [1, 1], 'enemies' => [['x' => 3, 'z' => 0]]]); ok((bool)array_filter($e, fn($x) => str_contains($x, '6 m')), 'tanks closer than 6 m are refused');
[$d, $e] = mission_clean(['starts' => [[500, 0]], 'players' => [1, 1], 'enemies' => [['x' => 0, 'z' => 50]]]); ok($d['starts'][0][0] <= 112 && $e, 'positions off the map are pulled in and flagged');
[$d, $e] = mission_clean(['starts' => [[0, 0]], 'players' => [1, 1], 'enemies' => [['x' => 0, 'z' => 50, 'kind' => 'behemoth'], ['x' => 30, 'z' => 50, 'kind' => 'behemoth']]]);
ok($d['enemies'][1]['kind'] === 'tank' && $e, 'only one Behemoth');
[$d, $e] = mission_clean(['starts' => [[0, 0]], 'players' => [1, 1], 'enemies' => [['x' => 0, 'z' => 50]], 'goal' => ['type' => 'crates', 'count' => 3], 'objects' => [['type' => 'crate', 'x' => 10, 'z' => 10]]]);
ok((bool)array_filter($e, fn($x) => str_contains($x, 'crates')), 'a crate goal needs enough crates');
[$d, $e] = mission_clean(['starts' => [[0, 0]], 'players' => [1, 1], 'enemies' => [['x' => 0, 'z' => 50]], 'opts' => ['night' => true, 'flares' => 0]]);
ok((bool)array_filter($e, fn($x) => str_contains($x, 'flares')), 'night needs flares');

// the same seed gives the same ground; another seed doesn't
[$hr] = mission_clean(CAMPAIGN[0]);
$a = generate_mission_map($hr)['terrain']->pack(); $b = generate_mission_map($hr)['terrain']->pack();
$hr2 = $hr; $hr2['seed']++; $c = generate_mission_map($hr2)['terrain']->pack();
ok($a === $b && $a !== $c, 'terrain is repeatable from the seed');

// seats: enemies only for enough players, hp scales
[$br] = mission_clean(CAMPAIGN[0]); $g = generate_mission_map($br);
foreach ([1 => 5, 2 => 7, 4 => 11] as $nh => $want) { $r = mission_rules($br, $g['extra']); [$seats, $tk] = mission_setup($br, $g, $r, $nh);
    ok(count($seats) - $nh === $want, "Hold the Ridge brings $want enemies for $nh player(s) (got " . (count($seats) - $nh) . ')'); }
$r = mission_rules($br, $g['extra']); mission_setup($br, $g, $r, 4); ok($r['hp'][4] === 150, 'enemy hp ×1.5 with 4 players');
ok($r['sd_turn'] > 1000 && $r['mode'] === 'scenario' && !$r['ranked'], 'no sudden death, unrated');

// friendly fire
$t = flat(); FX::reset(); $P = [0 => tank(0, 0, 0, 0), 1 => tank(1, 4, 0, 0), 2 => tank(2, -4, 0, 1)]; $o = []; $ops = [];
$b = explode_at($t, $P, $o, [0.0, 5.5, 0.0], WEAPONS['heavy'], 0, false, $ops);
$hit = array_column($b['hits'], 'dmg', 'seat');
ok(!isset($hit[1]) && isset($hit[2]) && isset($hit[0]), "a teammate's shell spares teammates but hits enemies and yourself");

// waves arrive at their round, early if the field is clear
$rules = ['mission' => ['goal' => ['type' => 'destroy_all'], 'limit' => 0], 'teams' => [0, 1, 1], 'bots' => [1 => 'rookie', 2 => 'rookie']];
FX::reset(); FX::$e['round'] = 1; FX::$e['reserve'] = [2 => 3];
$P = [0 => tank(0, 0, 60, 0), 1 => tank(1, 0, -60, 1), 2 => tank(2, 30, -60, 1)]; $P[2]['alive'] = false;
$out = ['turn' => 3]; mission_turn_start($t, $P, $o, $rules, 3, 1, 0, $out);
ok(FX::$e['round'] === 2 && !isset($out['arrive']) && !$P[2]['alive'], 'round 2: wave due in round 3 waits');
ok(mission_outcome($rules, $P, $o) === null, 'not won while a wave is still to come');
$P[1]['alive'] = false; $P[1]['hp'] = 0; $P[1]['death_turn'] = 3;
$out = []; mission_turn_start($t, $P, $o, $rules, 5, 1, 0, $out);
ok(FX::$e['round'] === 3 && $P[2]['alive'] && count($out['arrive']) === 1, 'the wave lands in its round');
FX::$e['reserve'] = [2 => 9]; $P[2]['alive'] = false; $out = [];
mission_turn_start($t, $P, $o, $rules, 7, 1, 0, $out);
ok(isset($out['arrive']), 'with no enemy left, the next wave comes early');
$P[2]['alive'] = false; $P[2]['hp'] = 0; FX::$e['reserve'] = [];
ok(mission_outcome($rules, $P, $o) === 'win', 'destroy_all won when every enemy and wave is gone');
$P[0]['alive'] = false; ok(mission_outcome($rules, $P, $o) === 'lose', 'lost when every player is destroyed');

// survive: rounds, or wipe them out; round limit
$rules['mission']['goal'] = ['type' => 'survive', 'rounds' => 5]; $P = [0 => tank(0, 0, 60, 0), 1 => tank(1, 0, -60, 1)];
FX::reset(); FX::$e['round'] = 5; ok(mission_outcome($rules, $P, $o) === null, 'survive: still on in the last round');
FX::$e['round'] = 6; ok(mission_outcome($rules, $P, $o) === 'win', 'survive: won after the rounds');
$rules['mission']['goal'] = ['type' => 'destroy_all']; $rules['mission']['limit'] = 5;
ok(mission_outcome($rules, $P, $o) === 'lose', 'round limit passed: lost');

// convoy
$rules['mission']['limit'] = 0; $rules['mission']['convoy'] = ['path' => [[0, 0], [30, 0]], 'speed' => 12, 'len' => 30.0];
FX::reset(); FX::$e['round'] = 1; FX::$e['convoy'] = ['d' => 0.0, 'done' => false];
foreach ([1, 2] as $k) { $out = []; mission_turn_start($t, $P, $o, $rules, 10 + $k, 1, 0, $out); }
ok(FX::$e['convoy']['d'] == 24.0 && mission_outcome($rules, $P, $o) === null, 'the convoy moves 12 m a round');
$out = []; mission_turn_start($t, $P, $o, $rules, 13, 1, 0, $out);
ok(FX::$e['convoy']['done'] && mission_outcome($rules, $P, $o) === 'lose', 'the convoy got through: lost');
unset($rules['mission']['convoy']);

// targets and crates
$rules['mission']['goal'] = ['type' => 'destroy_targets']; $rules['goal_seats'] = [1 => true];
$o = [['id' => 0, 'type' => 'building', 'goal' => true, 'hp' => 10, 'x' => 0, 'z' => 0, 'y' => 5, 'hw' => 2, 'hd' => 2, 'h' => 4, 'max' => 10]];
FX::reset(); FX::$e['round'] = 1;
ok(mission_outcome($rules, $P, $o) === null, 'targets: still standing');
$o[0]['hp'] = 0; ok(mission_outcome($rules, $P, $o) === null, 'targets: a target tank is still alive');
$P[1]['alive'] = false; ok(mission_outcome($rules, $P, $o) === 'win', 'targets: all down');
$P[1]['alive'] = true;
$rules['mission']['goal'] = ['type' => 'crates', 'count' => 2];
$o = [['id' => 0, 'type' => 'crate', 'mission' => true, 'state' => 'claimed', 'by' => 0], ['id' => 1, 'type' => 'crate', 'mission' => true, 'state' => 'claimed', 'by' => 1],
      ['id' => 2, 'type' => 'crate', 'mission' => true, 'state' => 'live', 'by' => null, 'x' => 0, 'z' => 0, 'y' => 5]];
ok(mission_outcome($rules, $P, $o) === null, 'crates: one each, one left');
$o[2]['state'] = 'claimed'; $o[2]['by'] = 1; ok(mission_outcome($rules, $P, $o) === 'lose', 'crates: the enemy took the last one');
$o[2]['by'] = 0; ok(mission_outcome($rules, $P, $o) === 'win', 'crates: claimed enough');
// a mission crate can't be wrecked
FX::reset(); $o = [['id' => 0, 'type' => 'crate', 'mission' => true, 'state' => 'live', 'x' => 5, 'z' => 0, 'y' => 5]]; $ops = [];
explode_at($t, $P, $o, [0.0, 5.0, 0.0], WEAPONS['heavy'], 1, false, $ops); ok($o[0]['state'] === 'live', 'mission crates are not wrecked by a blast');

// flares
FX::reset(); FX::$e['round'] = 1; $P = [0 => tank(0, 0, 60, 0), 1 => tank(1, 0, 0, 1), 2 => tank(2, 80, 0, 1)];
$nr = ['night' => true, 'teams' => [0, 1, 1]];
ok(in_dark($nr, $P[1]) && !in_dark($nr, $P[0]), 'at night enemies are unseen, players are not');
$b = flare_burst($P, [10.0, 5.0, 0.0], FLARE_R, 0);
ok($b['flare']['lit'] === [1] && !in_dark($nr, $P[1]) && in_dark($nr, $P[2]) && isset(FX::$e['seen'][1]), 'a flare lights enemies within 40 m and records where');
$o = [];
foreach ([1, 2] as $k) { $out = []; mission_turn_start($t, $P, $o, ['mission' => ['convoy' => null]], 10 + $k, 2, 0, $out); }
ok(!in_dark($nr, $P[1]), 'still lit two rounds on');
$out = []; mission_turn_start($t, $P, $o, ['mission' => ['convoy' => null]], 13, 2, 0, $out);
ok(in_dark($nr, $P[1]) && in_array(1, $out['lit_end'], true), 'dark again after that');
$ops = []; $b = explode_at($t, $P, $o, [0.0, 5.0, 0.0], WEAPONS['flare'], 0, false, $ops);
ok(!$ops && !$b['hits'] && isset($b['flare']), 'a flare shell has no blast or crater');

// the Behemoth
FX::reset(); FX::$e['round'] = 1; $B = tank(1, 0, 0, 1, 600) ; $B['sk'] = BOSS_MODS; $P = [0 => tank(0, 0, 60, 0), 1 => $B];
FX::$e['boss'] = ['seat' => 1, 'face' => 0.0, 'wp' => array_map(fn() => ['hp' => WP_HP, 'max' => WP_HP], BOSS_WP)];
$gun = boss_wp_pos($P[1], 'gun', 0.0); ok(abs($gun[2] + 4.6) < 1e-9 && abs($gun[1] - 8.4) < 1e-9, 'facing 0°, the main gun is at the front (−z)');
$g90 = boss_wp_pos($P[1], 'gun', 90.0); ok(abs($g90[0] - 4.6) < 1e-6, 'facing 90°, the main gun is on the +x side');
$ops = []; $o = [];
$b = explode_at($t, $P, $o, [$gun[0], $gun[1], $gun[2] - .5], WEAPONS['shell'], 0, false, $ops);
$h = array_values(array_filter($b['hits'], fn($x) => $x['seat'] === 1))[0];
ok(isset($h['wp']) && $h['wp']['id'] === 'gun' && $h['dmg'] % 2 === 0 && FX::$e['boss']['wp']['gun']['hp'] === WP_HP - $h['dmg'] / 2, "a shell on the main gun: double damage ({$h['dmg']}), the gun loses half that");
$b = explode_at($t, $P, $o, [$gun[0], $gun[1], $gun[2] - .5], WEAPONS['heavy'], 0, false, $ops);
ok(boss_lost() === 1 && !empty(array_values(array_filter($b['hits'], fn($x) => $x['seat'] === 1))[0]['wp']['down']), 'second hit: the main gun is destroyed');
$out = []; ok(mission_seat_start($P, ['mission' => []], 3, 1, $out) && isset($out['boss_shield']) && FX::$e['shields'][1]['hp'] === SHIELD_HP, 'round 1: the generator raises a shield');
FX::$e['round'] = 2; unset(FX::$e['shields'][1]); $out = []; mission_seat_start($P, ['mission' => []], 5, 1, $out);
ok(!isset($out['boss_shield']), 'not again until round 4');
FX::$e['boss']['wp']['radar']['hp'] = 0; $out = []; mission_seat_start($P, ['mission' => []], 7, 1, $out);
ok(isset($out['enrage']) && FX::$e['double']['seat'] === 1, 'two weak points down: two shots a turn');
$plan = ai_mission_decide($t, $P, [], 1, [0.0, 0.0], ['mission' => ['goal' => ['type' => 'destroy_targets']], 'gravity' => 20.0, 'splash' => false], AI_LEVELS['rookie'], FX::$e, null);
ok($plan['weapon'] === 'cluster', 'main gun gone: it fires cluster');

// stars
$sr = ['mission' => ['stars' => ['shots' => 10, 'damage' => 40]]];
ok(mission_stars($sr, 'win', 10, 40) === [true, true, true] && mission_stars($sr, 'win', 11, 10) === [true, false, true] && mission_stars($sr, 'lose', 1, 0) === [false, false, false], 'stars: complete, shots, damage');
// computer tanks don't repair; players do
FX::reset(); $P = [0 => tank(0, 0, 60, 0, 100), 1 => tank(1, 0, 0, 1, 100)]; $P[0]['hp'] = 50; $P[1]['hp'] = 50; $o = [];
$mr = ['mission' => ['convoy' => null], 'bots' => [1 => 'rookie'], 'regen' => 3, 'erupt_every' => 0, 'snow' => false, 'crate_every' => 5, 'crates' => 'none', 'sd_turn' => 99999, 'wind_max' => 0, 'fall_mult' => 1.0];
start_turn($t, $P, $o, $mr, 2, 0, 1); ok($P[1]['hp'] === 50, 'a computer tank does not repair');
start_turn($t, $P, $o, $mr, 3, 1, 0); ok($P[0]['hp'] === 53, 'a player repairs');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
