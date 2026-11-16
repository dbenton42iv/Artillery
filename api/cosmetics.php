<?php
declare(strict_types=1);
/*
 * Your tank's looks (cosmetics.php): paint or camo, barrel, shell trail, blast and title.
 *   GET                         -> {level, wins: {map: n}, saved: {slot: id}, catalog, unlocked: {slot: [ids]}}
 *   POST {"paint": id, ...}     -> saves the slots given; each must be unlocked. Takes effect from your next match.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = api_user($write);
$st = db()->prepare('SELECT xp, cosmetics FROM ' . T('users') . ' WHERE id = ?');
$st->execute([$u['id']]);
$row = $st->fetch() ?: ['xp' => 0, 'cosmetics' => null];
$level = level_for((int)$row['xp']); $wins = map_wins((int)$u['id']);
$saved = clean_cosmetics($row['cosmetics'], $level, $wins);

if ($write) {
    $b = json_body();
    foreach (COSMETIC_SLOTS as $slot) {
        if (!array_key_exists($slot, $b)) continue;
        $id = (string)$b[$slot];
        if (!isset(COSMETICS[$slot][$id])) json_out(['error' => 'unknown', 'slot' => $slot], 400);
        if (!cosmetic_unlocked(COSMETICS[$slot][$id], $level, $wins)) json_out(['error' => 'locked', 'slot' => $slot], 400);
        $saved[$slot] = $id;
    }
    db()->prepare('UPDATE ' . T('users') . ' SET cosmetics = ? WHERE id = ?')->execute([json_encode($saved), $u['id']]);
}

$unlocked = [];
foreach (COSMETIC_SLOTS as $slot) $unlocked[$slot] = array_keys(array_filter(COSMETICS[$slot], fn($it) => cosmetic_unlocked($it, $level, $wins)));
json_out(['ok' => true, 'level' => $level, 'wins' => $wins, 'saved' => $saved, 'catalog' => COSMETICS, 'unlocked' => $unlocked,
          'maps' => array_map(fn($m) => $m['name'], MAPS)]);
