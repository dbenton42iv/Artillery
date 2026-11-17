<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/campaign.php';
/*
 * Scenarios (phase 5), database side: the missions table, starting a mission (alone, or co-op from a private room),
 * finishing one (stars, progress, XP per new star), and the campaign's unlock order.
 * The rules of play are in includes/missions.php.
 *
 *   missions            id, slug, title, sort, campaign (1 = one of the built-in campaign missions), status
 *                       (draft | published), def (JSON: see mission_clean()), author, updated_at
 *   scenario_progress   user, mission, best stars, fewest shots in a win, least damage taken in a win, plays, wins
 *
 * Campaign missions unlock in order: each needs at least one star on the one before. Other published missions
 * (made in the editor) are open to everyone. Admins can play anything, drafts included (test play).
 */
const STAR_XP = 30;                                     // XP for each star a player earns on a mission for the first time

/** Mission rows. $all: drafts too (the editor). */
function missions_all(bool $all = false): array
{
    $st = db()->query('SELECT id, slug, title, sort, campaign, status, def, author, updated_at FROM ' . T('missions')
        . ($all ? '' : " WHERE status = 'published'") . ' ORDER BY campaign DESC, sort, id');
    return array_map(function ($r) {
        $r['id'] = (int)$r['id']; $r['sort'] = (int)$r['sort']; $r['campaign'] = (bool)$r['campaign'];
        $r['def'] = json_decode((string)$r['def'], true) ?: [];
        return $r;
    }, $st->fetchAll());
}
function mission_row(int $id): ?array
{
    $st = db()->prepare('SELECT id, slug, title, sort, campaign, status, def, author FROM ' . T('missions') . ' WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['id'] = (int)$r['id']; $r['campaign'] = (bool)$r['campaign']; $r['def'] = json_decode((string)$r['def'], true) ?: [];
    return $r;
}

/** A user's best result on every mission: mission id => row. */
function scenario_progress_of(int $uid): array
{
    $st = db()->prepare('SELECT mission_id, stars, best_shots, best_damage, plays, wins FROM ' . T('scenario_progress') . ' WHERE user_id = ?');
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['mission_id']] = array_map(fn($v) => $v === null ? null : (int)$v, $r);
    return $out;
}

/**
 * The missions list for the lobby: published missions in order, each with what it asks, the player's stars and best,
 * and whether it is open yet. Campaign missions open one after another.
 */
function missions_for(array $u): array
{
    static $seeded = false;
    if (!$seeded) {                                       // a fresh install (or a new campaign mission in an upgrade): add them
        $seeded = true;
        if ((int)db()->query('SELECT COUNT(*) FROM ' . T('missions') . ' WHERE campaign = 1')->fetchColumn() < count(CAMPAIGN)) seed_campaign();
    }
    $prog = scenario_progress_of((int)$u['id']); $admin = is_admin($u);
    $out = []; $prevStars = null;
    foreach (missions_all(false) as $r) {
        $d = $r['def']; $p = $prog[$r['id']] ?? null;
        $open = $admin || !$r['campaign'] || $prevStars === null || $prevStars > 0;
        if ($r['campaign']) $prevStars = (int)($p['stars'] ?? 0);
        $out[] = ['id' => $r['id'], 'title' => $r['title'], 'blurb' => $d['blurb'] ?? '', 'campaign' => $r['campaign'], 'map' => $d['map'] ?? 'highlands',
                  'map_name' => MAPS[$d['map'] ?? 'highlands']['name'] ?? '', 'goal' => mission_goal_text($d), 'night' => !empty($d['opts']['night']),
                  'players' => $d['players'] ?? [1, 4], 'enemies' => count(array_filter($d['enemies'] ?? [], fn($e) => ($e['min'] ?? 1) <= ($d['players'][0] ?? 1))),
                  'enemies_max' => count($d['enemies'] ?? []),
                  'stars_rule' => ['shots' => (int)($d['stars']['shots'] ?? 0), 'damage' => (int)($d['stars']['damage'] ?? 0)],
                  'stars' => (int)($p['stars'] ?? 0), 'best_shots' => $p['best_shots'] ?? null, 'best_damage' => $p['best_damage'] ?? null,
                  'plays' => (int)($p['plays'] ?? 0), 'wins' => (int)($p['wins'] ?? 0), 'open' => $open];
    }
    return $out;
}

/** Can this user start (or host) this mission? Returns null or an error code. */
function mission_playable(array $u, ?array $row, int $players, bool $test = false): ?string
{
    if (!$row) return 'no_mission';
    if ($row['status'] !== 'published' && !($test && is_admin($u))) return 'no_mission';
    [, $err] = mission_clean($row['def']);
    if ($err) return 'mission_invalid';
    $d = $row['def'];
    if ($players < $d['players'][0]) return 'need_players';
    if ($players > $d['players'][1]) return 'too_many_players';
    if (!$test && !is_admin($u)) {
        foreach (missions_for($u) as $m) if ($m['id'] === $row['id'] && !$m['open']) return 'locked';
    }
    return null;
}

/** Start a mission for one player. $in: {"mission": id, "test": bool (admins: drafts too, no progress or XP)}. */
function scenario_start_solo(array $u, array $in): array
{
    $uid = (int)$u['id'];
    if ($mid = active_match_for($uid)) return ['error' => 'in_match', 'match_id' => $mid];
    $row = mission_row((int)($in['mission'] ?? 0));
    $test = !empty($in['test']) && is_admin($u);
    if ($e = mission_playable($u, $row, 1, $test)) return ['error' => $e];
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$uid]);
    if (function_exists('room_leave')) room_leave($uid);
    $mid = scenario_create([['user_id' => $uid, 'rating' => (int)$u['rating']]], $row, ['test' => $test]);
    return ['ok' => true, 'match_id' => $mid];
}

/**
 * Create a mission's match. $group: the players (user_id, rating) in seat order; they take the mission's starts in
 * order. The computer tanks follow, in the order the mission lists them. Returns the match id.
 */
function scenario_create(array $group, array $row, array $opt = []): int
{
    [$d] = mission_clean($row['def']);
    $gen = generate_mission_map($d, count($group));
    $t = $gen['terrain'];
    $rules = mission_rules($d, $gen['extra'], (int)$row['id']);
    if (!empty($opt['test'])) $rules['test'] = true;     // an admin's test play: no progress, no XP
    $nh = count($group);
    $bot = ai_bot_ids()[0];
    [$seats, $tanks] = mission_setup($d, $gen, $rules, $nh);
    [$wa, $ws] = initial_wind($rules);
    $los = group_loadouts($group, ['ranked' => false], []);
    $cos = group_cosmetics($group);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        FX::reset();
        mission_fx_init($d, $seats, $tanks);
        $first = ['sk' => skill_mods($los[0])];
        $pdo->prepare('INSERT INTO ' . T('matches') . ' (mode, game_mode, map_id, rules, objects, effects, terrain, wind_ang, wind_spd, current_seat, turn_deadline) VALUES (?,?,?,?,?,?,?,?,?,0,?)')
            ->execute([count($seats), 'scenario', $d['map'], json_encode($rules, JSON_PRESERVE_ZERO_FRACTION), json_encode($gen['objects'], JSON_PRESERVE_ZERO_FRACTION),
                       json_encode(FX::$e, JSON_PRESERVE_ZERO_FRACTION), $t->pack(), $wa, $ws, time() + turn_secs_for($rules, $first) + LOAD_GRACE]);
        $mid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO ' . T('match_players') . ' (match_id, seat, user_id, hp, alive, x, y, z, ammo_heavy, ammo_cluster, loadout, cosmetics, rating_before, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($group as $k => $g) {
            $mods = skill_mods($los[$k]);
            $ins->execute([$mid, $k, $g['user_id'], loadout_hp($los[$k]), 1, $tanks[$k]['x'], $tanks[$k]['y'], $tanks[$k]['z'],
                           (int)($mods['heavy'] ?? START_AMMO['heavy']), (int)($mods['cluster'] ?? START_AMMO['cluster']), json_encode($los[$k]), json_encode($cos[$k]), (int)$g['rating'], time()]);
        }
        foreach ($tanks as $s => $tk) {
            if ($s < $nh) continue;
            $boss = $rules['kinds'][$s] === 'behemoth';
            $ins->execute([$mid, $s, $bot, $tk['hp'], $tk['alive'] ? 1 : 0, $tk['x'], $tk['y'], $tk['z'],
                           $boss ? 99 : START_AMMO['heavy'], $boss ? 99 : START_AMMO['cluster'], null, null, 1000, time()]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $mid;
}

/**
 * A mission ends (called from finish_match()): the result, stars and per-player numbers go into the match's rules
 * (rules.result, read by the battle page and award_xp()), the players' best results are saved, and XP is paid.
 * Placement: the players all 1st on a win, all 2nd on a loss; the computer tanks the other way round. Unrated.
 */
function scenario_finish(array &$m, array &$players): void
{
    $rules = &$m['rules'];
    $outcome = !empty($rules['ended']) ? 'ended' : (mission_outcome($rules, $players, $m['objects'] ?? []) ?? 'lose');
    $humans = array_filter($players, fn($p) => !isset($rules['bots'][$p['seat']]));
    $st = db()->prepare('SELECT seat, COUNT(*) n FROM ' . T('match_events') . " WHERE match_id = ? AND type = 'shot' GROUP BY seat");
    $st->execute([$m['id']]);
    $shots = array_map('intval', array_column($st->fetchAll(), 'n', 'seat'));
    $nh = max(1, count($humans));
    $shotsEach = array_sum(array_map(fn($p) => $shots[$p['seat']] ?? 0, $humans)) / $nh;
    $takenEach = array_sum(array_map(fn($p) => (int)(FX::$e['taken'][$p['seat']] ?? 0), $humans)) / $nh;
    $stars = $outcome === 'ended' ? [false, false, false] : mission_stars($rules, $outcome, $shotsEach, $takenEach);
    $n = count(array_filter($stars));
    $res = ['outcome' => $outcome, 'stars' => $stars, 'count' => $n, 'shots' => round($shotsEach, 1), 'taken' => round($takenEach, 1),
            'round' => (int)(FX::$e['round'] ?? 1), 'new_stars' => []];
    $win = $outcome === 'win';
    foreach ($players as &$p) {
        $human = !isset($rules['bots'][$p['seat']]);
        $p['placement'] = $human === $win ? 1 : 2;
        $p['rating_after'] = $p['rating_before'];
    }
    unset($p);
    $m['winner_user_id'] = null;
    $mid = (int)($rules['mission']['id'] ?? 0);
    $up = db()->prepare('UPDATE ' . T('match_players') . ' SET placement=?, rating_after=? WHERE match_id=? AND seat=?');
    foreach ($players as $p) $up->execute([$p['placement'], $p['rating_after'], $m['id'], $p['seat']]);
    if ($outcome !== 'ended' && empty($rules['test']) && $mid) {
        $q = db()->prepare('SELECT DISTINCT seat FROM ' . T('match_events') . " WHERE match_id = ? AND type = 'forfeit'");
        $q->execute([$m['id']]);
        $quit = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $sel = db()->prepare('SELECT stars FROM ' . T('scenario_progress') . ' WHERE user_id = ? AND mission_id = ? FOR UPDATE');
        $put = db()->prepare('INSERT INTO ' . T('scenario_progress') . ' (user_id, mission_id, stars, best_shots, best_damage, plays, wins) VALUES (?,?,?,?,?,1,?)
            ON DUPLICATE KEY UPDATE stars = GREATEST(stars, VALUES(stars)),
              best_shots = IF(VALUES(best_shots) IS NULL, best_shots, LEAST(COALESCE(best_shots, VALUES(best_shots)), VALUES(best_shots))),
              best_damage = IF(VALUES(best_damage) IS NULL, best_damage, LEAST(COALESCE(best_damage, VALUES(best_damage)), VALUES(best_damage))),
              plays = plays + 1, wins = wins + VALUES(wins)');
        foreach ($humans as $p) {
            if (in_array($p['seat'], $quit, true)) continue;  // left the battle: no stars, no progress
            $sel->execute([$p['user_id'], $mid]); $before = (int)($sel->fetchColumn() ?: 0);
            $res['new_stars'][$p['seat']] = max(0, $n - $before);
            $put->execute([$p['user_id'], $mid, $n, $win ? (int)ceil($shotsEach) : null, $win ? (int)round($takenEach) : null, $win ? 1 : 0]);
        }
    }
    $rules['result'] = $res; $m['rules_dirty'] = true;
    unset($rules);
    if ($outcome !== 'ended' && empty($m['rules']['test'])) award_xp($m, $players);
}

/** XP lines for stars earned on a mission for the first time. */
function star_xp(array $gain, int $newStars): array
{
    if ($newStars <= 0) return $gain;
    $gain['lines'][] = ['stars', $newStars . ' new star' . ($newStars > 1 ? 's' : ''), $newStars * STAR_XP];
    $gain['total'] += $newStars * STAR_XP;
    return $gain;
}

/** What the battle page shows about the mission: title, goal, progress, the star rules, and the result once it's over. */
function scenario_snapshot(int $mid, array $rules, array $players, string $status): array
{
    $st = db()->prepare('SELECT objects FROM ' . T('matches') . ' WHERE id = ?');
    $st->execute([$mid]);
    $objects = json_decode((string)$st->fetchColumn(), true) ?: [];
    $ms = $rules['mission'];
    $out = ['id' => (int)$ms['id'], 'title' => $ms['title'], 'stars_rule' => $ms['stars'], 'night' => !empty($rules['night']),
            'test' => !empty($rules['test'])] + mission_progress($rules, $players, $objects);
    if (!empty($ms['convoy'])) $out['convoy_path'] = $ms['convoy']['path'];
    if ($status === 'finished' && isset($rules['result'])) $out['result'] = array_diff_key($rules['result'], ['new_stars' => 1]);
    return $out;
}

/* =====================================================================
 * The missions table: created on upgrade, filled with the campaign
 * ===================================================================== */

/** Put the built-in campaign missions into the table (those not there yet, by slug). */
function seed_campaign(): void
{
    $ins = db()->prepare('INSERT IGNORE INTO ' . T('missions') . " (slug, title, sort, campaign, status, def, author) VALUES (?,?,?,1,'published',?,'campaign')");
    foreach (CAMPAIGN as $k => $def) {
        [$d] = mission_clean($def);
        $ins->execute([$def['slug'], $d['title'], ($k + 1) * 10, json_encode($d, JSON_PRESERVE_ZERO_FRACTION)]);
    }
}

/* =====================================================================
 * Editor (admins)
 * ===================================================================== */

/** Save a mission from the editor. Returns ['ok', 'id', 'errors'] or ['error']. Publishing needs a clean mission. */
function mission_save(array $admin, array $in): array
{
    [$d, $err] = mission_clean($in['def'] ?? []);
    $status = ($in['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    if ($status === 'published' && $err) return ['error' => 'mission_invalid', 'errors' => $err];
    $id = (int)($in['id'] ?? 0);
    $pdo = db();
    if ($id) {
        $row = mission_row($id);
        if (!$row) return ['error' => 'no_mission'];
        $pdo->prepare('UPDATE ' . T('missions') . ' SET title = ?, status = ?, def = ?, author = ? WHERE id = ?')
            ->execute([$d['title'], $status, json_encode($d, JSON_PRESERVE_ZERO_FRACTION), $admin['username'], $id]);
    } else {
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM ' . T('missions'))->fetchColumn();
        $pdo->prepare('INSERT INTO ' . T('missions') . ' (slug, title, sort, campaign, status, def, author) VALUES (?,?,?,0,?,?,?)')
            ->execute(['m' . bin2hex(random_bytes(6)), $d['title'], $sort, $status, json_encode($d, JSON_PRESERVE_ZERO_FRACTION), $admin['username']]);
        $id = (int)$pdo->lastInsertId();
    }
    return ['ok' => true, 'id' => $id, 'status' => $status, 'errors' => $err, 'def' => $d];
}

/** Delete a mission (campaign missions can't be deleted, only unpublished). Its matches keep their own copy of the rules. */
function mission_delete(int $id): array
{
    $row = mission_row($id);
    if (!$row) return ['error' => 'no_mission'];
    if ($row['campaign']) return ['error' => 'campaign'];
    db()->prepare('DELETE FROM ' . T('scenario_progress') . ' WHERE mission_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM ' . T('missions') . ' WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

/** The terrain a mission would be played on, for the editor's map: heights (float32, base64) and the objects. */
function mission_preview(array $def): array
{
    [$d, $err] = mission_clean($def);
    $gen = generate_mission_map($d);
    return ['n' => $d['size'], 'half' => mission_half($d['size']), 'water' => MAPS[$d['map']]['water'], 'terrain' => base64_encode($gen['terrain']->pack()),
            'objects' => $gen['objects'], 'vent' => $gen['extra']['vent'] ?? null, 'errors' => $err];
}
