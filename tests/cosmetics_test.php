<?php
/*
 * Cosmetics unit checks: the catalog, unlock rules, and cleaning a saved choice.
 *   php tests/cosmetics_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
function cfg(string $k, $d = null) { return $d; }
require dirname(__DIR__) . '/includes/engine.php';
require dirname(__DIR__) . '/includes/cosmetics.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $c, $info = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name " . json_encode($info) . "\n"; }
}

foreach (COSMETIC_SLOTS as $slot) {
    ok("$slot: has items", count(COSMETICS[$slot]) >= 4);
    ok("$slot: default exists and is open at level 1", isset(COSMETICS[$slot][DEFAULT_COSMETICS[$slot]]) && COSMETICS[$slot][DEFAULT_COSMETICS[$slot]]['level'] === 1);
    foreach (COSMETICS[$slot] as $id => $it) {
        if (isset($it['map']) && !isset(MAPS[$it['map']])) ok("$slot $id: map exists", false, $it['map']);
        if (isset($it['map']) && $it['level'] > 50) continue;
        if ($it['level'] > 50) ok("$slot $id: reachable", false, $it['level']);
    }
}
ok('every map has a camo', count(array_filter(COSMETICS['paint'], fn($i) => isset($i['camo']))) === count(MAPS), array_keys(MAPS));
ok('every map has a title', count(array_filter(COSMETICS['title'], fn($i) => isset($i['map']))) === count(MAPS));
ok('stored choice fits its column (255)', strlen(json_encode(array_map(fn($l) => str_repeat('x', max(array_map('strlen', array_keys($l)))), COSMETICS))) < 255);

$w = COSMETICS['paint']['woodland'];
ok('camo locked below its level with no wins', !cosmetic_unlocked($w, 7, []));
ok('camo open at its level', cosmetic_unlocked($w, 8, []));
ok('camo open by 3 wins on its map at level 1', cosmetic_unlocked($w, 1, ['highlands' => 3]) && !cosmetic_unlocked($w, 1, ['highlands' => 2]));
ok('wins on another map do not count', !cosmetic_unlocked($w, 1, ['canyon' => 9]));
ok('map title only by wins', !cosmetic_unlocked(COSMETICS['title']['moonwalker'], 50, ['lunar' => 9]) && cosmetic_unlocked(COSMETICS['title']['moonwalker'], 1, ['lunar' => 10]));

ok('clean: nothing saved -> defaults', clean_cosmetics(null, 1, []) === DEFAULT_COSMETICS);
ok('clean: JSON string accepted', clean_cosmetics('{"paint":"olive"}', 1, [])['paint'] === 'olive');
$c = clean_cosmetics(['paint' => 'gold', 'barrel' => 'twin', 'trail' => 'azure', 'blast' => 'nope', 'title' => 'gunner'], 8, []);
ok('clean: locked and unknown fall back, open kept', $c === ['paint' => 'team', 'barrel' => 'standard', 'trail' => 'azure', 'blast' => 'standard', 'title' => 'gunner'], $c);
ok('clean: junk input', clean_cosmetics('not json', 50, []) === DEFAULT_COSMETICS && clean_cosmetics([1, 2], 50, []) === DEFAULT_COSMETICS);

$p = cosmetics_public(['paint' => 'arctic', 'barrel' => 'long', 'trail' => 'rings', 'blast' => 'smoke', 'title' => 'legend']);
ok('public: camo has pattern and colours', $p['paint']['camo'] === 'shards' && count($p['paint']['colors']) === 4);
ok('public: no unlock rules sent', !isset($p['paint']['level']) && !isset($p['paint']['map']) && !isset($p['paint']['wins']));
ok('public: barrel, trail, blast, title', $p['barrel']['model'] === 'long' && $p['trail']['style'] === 'rings' && $p['blast']['style'] === 'smoke' && $p['title']['text'] === 'Legend');
ok('public: null -> defaults', cosmetics_public(null)['paint']['color'] === null && cosmetics_public(null)['title']['text'] === null);
ok('public: unknown id -> default', cosmetics_public(['paint' => 'zzz'] + DEFAULT_COSMETICS)['paint']['id'] === 'team');
ok('title text', title_text('{"title":"bombardier"}') === 'Bombardier' && title_text(null) === null && title_text('{"title":"none"}') === null);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
