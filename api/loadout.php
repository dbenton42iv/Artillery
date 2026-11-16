<?php
declare(strict_types=1);
/*
 * Your loadout, level and XP (phase 4).
 *   GET                       -> {level, xp, into, need, saved: {loadout, ranked_preset}, unlocked, catalog, ranked_uses}
 *   POST {"loadout": {"preset": id} | {"doctrine": id, "perks": [id, id]}, "ranked_preset": id}
 * A custom build may only use skills unlocked at your level. Presets are open to everyone. Below level 14, ranked
 * matches use your ranked preset whatever you pick here; the loadout is fixed when a match starts.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
$st = db()->prepare('SELECT xp, loadout FROM ' . T('users') . ' WHERE id = ?');
$st->execute([$u['id']]);
$row = $st->fetch() ?: ['xp' => 0, 'loadout' => null];
$xp = (int)$row['xp']; [$level, $into, $need] = level_info($xp);

if ($write) {
    $b = json_body();
    $lo = parse_loadout($b['loadout'] ?? null);
    if (!$lo) json_out(['error' => 'bad_loadout'], 400);
    if (($e = loadout_error($lo, $level, false)) !== null) json_out(['error' => $e], 400);
    $rp = (string)($b['ranked_preset'] ?? (json_decode((string)$row['loadout'], true)['ranked_preset'] ?? DEFAULT_LOADOUT['preset']));
    if (!isset(PRESETS[$rp])) json_out(['error' => 'bad_preset'], 400);
    $saved = $lo + ['ranked_preset' => $rp];
    db()->prepare('UPDATE ' . T('users') . ' SET loadout = ? WHERE id = ?')->execute([json_encode($saved), $u['id']]);
    $row['loadout'] = json_encode($saved);
}

$raw = json_decode((string)$row['loadout'], true) ?: [];
$saved = (parse_loadout($raw) ?? DEFAULT_LOADOUT) + ['ranked_preset' => isset(PRESETS[$raw['ranked_preset'] ?? '']) ? $raw['ranked_preset'] : DEFAULT_LOADOUT['preset']];
json_out([
    'ok' => true, 'level' => $level, 'xp' => $xp, 'into' => $into, 'need' => $need, 'max_level' => LEVEL_MAX,
    'saved' => $saved, 'unlocked' => unlocked_skills($level), 'catalog' => skills_catalog(),
    // what each kind of match would actually use right now
    'uses' => ['ranked' => loadout_public(match_loadout($row['loadout'], $level, true)), 'other' => loadout_public(match_loadout($row['loadout'], $level, false))],
]);
