<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/migrate.php';
ensure_schema();

/* =====================================================================
 * Matchmaking: players wait in a queue per mode (2, 3 or 4 tanks).
 * Each player's search window starts at ±100 rating and widens by 15 per
 * second of waiting (capped at ±800), so close ranks meet first and nobody
 * waits forever.
 * ===================================================================== */
const MM_BASE_WINDOW = 100, MM_GROWTH = 15, MM_MAX_WINDOW = 800, MM_STALE = 20, LOAD_GRACE = 10;

function search_window(int $waited): int { return min(MM_MAX_WINDOW, MM_BASE_WINDOW + MM_GROWTH * $waited); }

function active_match_for(int $uid): ?int
{
    $st = db()->prepare('SELECT m.id FROM ' . T('matches') . ' m JOIN ' . T('match_players') . ' p ON p.match_id = m.id
                         WHERE p.user_id = ? AND m.status = \'active\' AND p.alive = 1 ORDER BY m.id DESC LIMIT 1');
    $st->execute([$uid]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function run_matchmaker(int $mode): void
{
    $pdo = db();
    $lock = 'art3d_mm_' . cfg('db')['prefix'] . $mode;
    if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 3)')->fetchColumn() !== 1) return;
    try {
        $now = time();
        $pdo->prepare('DELETE FROM ' . T('queue') . ' WHERE match_id IS NULL AND last_poll < ?')->execute([$now - MM_STALE]);
        $st = $pdo->prepare('SELECT user_id, rating, joined_at FROM ' . T('queue') . ' WHERE mode = ? AND match_id IS NULL ORDER BY joined_at, user_id');
        $st->execute([$mode]);
        $pool = $st->fetchAll();
        while (count($pool) >= $mode) {
            $made = false;
            foreach ($pool as $anchor) {
                $win = search_window($now - (int)$anchor['joined_at']);
                $cands = array_filter($pool, fn($q) => $q['user_id'] !== $anchor['user_id']
                    && abs($q['rating'] - $anchor['rating']) <= $win);
                if (count($cands) < $mode - 1) continue;
                usort($cands, fn($a, $b) => abs($a['rating'] - $anchor['rating']) <=> abs($b['rating'] - $anchor['rating']));
                $group = array_merge([$anchor], array_slice($cands, 0, $mode - 1));
                $mid = create_match($group, $mode);
                $ids = array_map(fn($g) => (int)$g['user_id'], $group);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare('UPDATE ' . T('queue') . " SET match_id = ? WHERE user_id IN ($in)")->execute([$mid, ...$ids]);
                $pool = array_values(array_filter($pool, fn($q) => !in_array((int)$q['user_id'], $ids, true)));
                $made = true;
                break;
            }
            if (!$made) break;
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}

/* =====================================================================
 * Ranked map rotation: no map repeats within a player's ranked session.
 * A session is a run of ranked matches with less than 30 minutes between one match ending and the next
 * starting. Within it, a player cycles through the whole pool before any map comes back.
 * ===================================================================== */

/** Maps each player has already seen in the current cycle, and when each map was last played by anyone. */
function map_history(array $uids, array $pool): array
{
    $seen = []; $lastAgo = []; $latest = [];
    $st = db()->prepare('SELECT m.map_id, m.rules, TIMESTAMPDIFF(SECOND, m.created_at, NOW()) s_ago,
            TIMESTAMPDIFF(SECOND, COALESCE(m.finished_at, NOW()), NOW()) e_ago
        FROM ' . T('match_players') . ' p JOIN ' . T('matches') . ' m ON m.id = p.match_id
        WHERE p.user_id = ? AND m.map_id IS NOT NULL ORDER BY m.id DESC LIMIT 40');
    foreach ($uids as $uid) {
        $st->execute([$uid]);
        $plays = array_values(array_filter($st->fetchAll(), function ($r) {
            $rules = json_decode((string)$r['rules'], true);
            return !is_array($rules) || !empty($rules['ranked']);
        }));
        foreach ($plays as $r) {
            $ago = max(0, (int)$r['s_ago']);
            $lastAgo[$r['map_id']] = min($lastAgo[$r['map_id']] ?? PHP_INT_MAX, $ago);
        }
        foreach ($plays as &$r) {                  // rows finished before this fix may carry a PHP-clock time: fall back to the start
            $r['s_ago'] = max(0, (int)$r['s_ago']); $r['e_ago'] = (int)$r['e_ago'];
            if ($r['e_ago'] < 0 || $r['e_ago'] > $r['s_ago']) $r['e_ago'] = $r['s_ago'];
        }
        unset($r);
        $session = [];
        if ($plays && (int)$plays[0]['e_ago'] <= RANKED_SESSION_GAP) {
            $session[] = $plays[0]['map_id'];
            for ($i = 1; $i < count($plays); $i++) {
                if ((int)$plays[$i]['e_ago'] - (int)$plays[$i - 1]['s_ago'] > RANKED_SESSION_GAP) break;
                $session[] = $plays[$i]['map_id'];
            }
        }
        // walk the session oldest first; once the whole pool has been seen, a fresh cycle starts
        $cycle = [];
        foreach (array_reverse($session) as $map) {
            if (!in_array($map, $pool, true)) continue;
            $cycle[$map] = true;
            if (count($cycle) >= count($pool)) $cycle = [];
        }
        $seen[$uid] = array_keys($cycle);
        if ($session) $latest[$uid] = $session[0];
    }
    return ['seen' => $seen, 'last_ago' => $lastAgo, 'latest' => $latest];
}

/** Pure choice, kept apart from the database so it can be tested. */
function choose_map(array $pool, array $hist): string
{
    $seen = array_unique(array_merge([], ...array_values($hist['seen'] ?: [[]])));
    $open = array_values(array_diff($pool, $seen));
    // right after a cycle resets, still avoid the map someone has just played
    $fresh = array_values(array_diff($open, array_values($hist['latest'])));
    if ($fresh) $open = $fresh;
    if ($open) return $open[mt_rand(0, count($open) - 1)];
    // every map is taken by someone's cycle: the one played least recently by anyone
    $best = []; $bestAgo = -1;
    foreach ($pool as $m) {
        $ago = $hist['last_ago'][$m] ?? PHP_INT_MAX;
        if ($ago > $bestAgo) { $best = [$m]; $bestAgo = $ago; } elseif ($ago === $bestAgo) $best[] = $m;
    }
    return $best[mt_rand(0, count($best) - 1)];
}

function pick_ranked_map(array $uids): string
{
    $pool = ranked_pool();
    return choose_map($pool, map_history($uids, $pool));
}

/** $group: rows with user_id + rating. Returns new match id. */
function create_match(array $group, int $mode, ?string $mapId = null): int
{
    shuffle($group);                                   // random seat order = random first shooter
    $mapId ??= pick_ranked_map(array_map(fn($g) => (int)$g['user_id'], $group));
    $gen = generate_map($mapId, count($group));
    $t = $gen['terrain']; $pos = $gen['spawns']; $rules = $gen['rules'];
    [$wa, $ws] = initial_wind($rules);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO ' . T('matches') . ' (mode, map_id, rules, objects, terrain, wind_ang, wind_spd, current_seat, turn_deadline) VALUES (?,?,?,?,?,?,?,0,?)')
        ->execute([$mode, $mapId, json_encode($rules, JSON_PRESERVE_ZERO_FRACTION), json_encode($gen['objects'], JSON_PRESERVE_ZERO_FRACTION),
                   $t->pack(), $wa, $ws, time() + (int)cfg('turn_seconds', 40) + LOAD_GRACE]);
    $mid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO ' . T('match_players') . ' (match_id, seat, user_id, x, y, z, ammo_heavy, ammo_cluster, rating_before, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($group as $seat => $g) {
        $ins->execute([$mid, $seat, $g['user_id'], $pos[$seat][0], $pos[$seat][1], $pos[$seat][2], START_AMMO['heavy'], START_AMMO['cluster'], $g['rating'], time()]);
    }
    $pdo->commit();
    return $mid;
}

/* =====================================================================
 * Turns
 * ===================================================================== */
function lock_match(int $mid): ?array
{
    $st = db()->prepare('SELECT * FROM ' . T('matches') . ' WHERE id = ? FOR UPDATE');
    $st->execute([$mid]);
    $m = $st->fetch();
    if (!$m) return null;
    $m['rules'] = match_rules($m);
    $m['objects'] = json_decode((string)($m['objects'] ?? ''), true) ?: [];
    $m['objects_dirty'] = false;
    return $m;
}
/** Rules for a match row. Matches created before map support play as Highlands. */
function match_rules(array $m): array
{
    $r = json_decode((string)($m['rules'] ?? ''), true);
    if (is_array($r)) return $r;
    return ['map' => 'highlands', 'n' => 160, 'gravity' => 20.0, 'water' => 1.6, 'wind_min' => 0.0, 'wind_max' => (float)cfg('wind_max', 8),
            'wind_axis' => null, 'splash' => false, 'erupt_every' => 0, 'vent' => null, 'snow' => false, 'fall_mult' => 1.0, 'ranked' => true];
}
function load_players(int $mid): array
{
    $st = db()->prepare('SELECT * FROM ' . T('match_players') . ' WHERE match_id = ? ORDER BY seat');
    $st->execute([$mid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['seat', 'user_id', 'hp', 'alive', 'ammo_heavy', 'ammo_cluster', 'strikes', 'damage_dealt', 'kills', 'rating_before'] as $k) $r[$k] = (int)$r[$k];
        foreach (['x', 'y', 'z'] as $k) $r[$k] = (float)$r[$k];
        $r['last_hit_by'] = $r['last_hit_by'] === null ? null : (int)$r['last_hit_by'];
        $r['death_turn'] = $r['death_turn'] === null ? null : (int)$r['death_turn'];
        $r['alive'] = (bool)$r['alive'];
        $out[$r['seat']] = $r;
    }
    return $out;
}
function save_players(int $mid, array $players): void
{
    $st = db()->prepare('UPDATE ' . T('match_players') . ' SET hp=?, alive=?, y=?, ammo_heavy=?, ammo_cluster=?, strikes=?, death_turn=?,
                         last_hit_by=?, damage_dealt=?, kills=? WHERE match_id=? AND seat=?');
    foreach ($players as $p) {
        $st->execute([$p['hp'], $p['alive'] ? 1 : 0, $p['y'], $p['ammo_heavy'], $p['ammo_cluster'], $p['strikes'], $p['death_turn'],
                      $p['last_hit_by'], $p['damage_dealt'], $p['kills'], $mid, $p['seat']]);
    }
}
function add_event(array $m, int $seat, string $type, array $payload): int
{
    db()->prepare('INSERT INTO ' . T('match_events') . ' (match_id, turn_no, seat, type, payload) VALUES (?,?,?,?,?)')
        ->execute([$m['id'], $m['turn_no'], $seat, $type, json_encode($payload, JSON_PRESERVE_ZERO_FRACTION)]);
    return (int)db()->lastInsertId();
}

/** Mark players at 0 hp as destroyed. Returns seats that died. */
function resolve_deaths(array &$players, int $turn): array
{
    $dead = [];
    foreach ($players as $s => &$p) {
        if ($p['alive'] && $p['hp'] <= 0) {
            $p['alive'] = false; $p['death_turn'] = $turn; $dead[] = $s;
            if ($p['last_hit_by'] !== null && $p['last_hit_by'] !== $s) $players[$p['last_hit_by']]['kills']++;
        }
    }
    return $dead;
}

/**
 * After a shot/skip/forfeit: either finish the match or hand the turn to the next living tank.
 * Start-of-turn effects (eruption, snowfall, sudden death) run here and are recorded as a 'turn' event.
 * Returns that event's id, or null. $t is the match terrain (changed in place).
 */
function advance_or_finish(array &$m, array &$players, Terrain $t): ?int
{
    $alive = array_filter($players, fn($p) => $p['alive']);
    if (count($alive) <= 1) { finish_match($m, $players); return null; }
    $n = count($players); $s = (int)$m['current_seat'];
    do { $s = ($s + 1) % $n; } while (!$players[$s]['alive']);
    $old = (int)$m['turn_no']; $new = $old + 1;
    $wa = (float)$m['wind_ang']; $ws = (float)$m['wind_spd'];
    next_wind($wa, $ws, $m['rules'], $old, $new);
    $m['current_seat'] = $s; $m['turn_no'] = $new; $m['wind_ang'] = $wa; $m['wind_spd'] = $ws;
    $m['turn_deadline'] = time() + (int)cfg('turn_seconds', 40);

    $before = array_map(fn($o) => $o['hp'], $m['objects']);
    $fx = start_turn($t, $players, $m['objects'], $m['rules'], $new);
    if ($fx === null) return null;
    if (array_map(fn($o) => $o['hp'], $m['objects']) !== $before) $m['objects_dirty'] = true;
    $m['terrain_dirty'] = true;
    $fx['deaths'] = resolve_deaths($players, $new);
    $fx['wind'] = wind_vec($wa, $ws);
    $eid = add_event($m, $s, 'turn', $fx);
    if (count(array_filter($players, fn($p) => $p['alive'])) <= 1) { finish_match($m, $players); return $eid; }
    if (!$players[$s]['alive']) {                       // the tank whose turn it is just died: pass to the next one
        do { $s = ($s + 1) % $n; } while (!$players[$s]['alive']);
        $m['current_seat'] = $s;
    }
    return $eid;
}

function save_match(array $m, ?Terrain $t = null, ?int $lastEvent = null): void
{
    $sql = 'UPDATE ' . T('matches') . ' SET status=?, wind_ang=?, wind_spd=?, turn_no=?, current_seat=?, turn_deadline=?, winner_user_id=?, finished_at=?';
    $args = [$m['status'], $m['wind_ang'], $m['wind_spd'], $m['turn_no'], $m['current_seat'], $m['turn_deadline'], $m['winner_user_id'], $m['finished_at']];
    if ($t) { $sql .= ', terrain=?'; $args[] = $t->pack(); }
    if (!empty($m['objects_dirty'])) { $sql .= ', objects=?'; $args[] = json_encode($m['objects'], JSON_PRESERVE_ZERO_FRACTION); }
    if ($lastEvent) { $sql .= ', last_event_id=?'; $args[] = $lastEvent; }
    $sql .= ' WHERE id=?'; $args[] = $m['id'];
    db()->prepare($sql)->execute($args);
}

/**
 * Placement: survivors 1st; the rest by how late they were destroyed (same turn = tie).
 * Ratings: multiplayer Elo, each pair of players counted as a head-to-head result.
 */
function finish_match(array &$m, array &$players): void
{
    // the database clock, so finished_at compares cleanly with created_at (PHP and MySQL time zones can differ)
    $m['status'] = 'finished'; $m['finished_at'] = (string)db()->query('SELECT NOW()')->fetchColumn();
    $order = fn($p) => $p['alive'] ? PHP_INT_MAX : (int)$p['death_turn'];
    foreach ($players as &$p) {
        $p['placement'] = 1 + count(array_filter($players, fn($q) => $order($q) > $order($p)));
    }
    unset($p);
    $n = count($players); $K = 32;
    foreach ($players as &$p) {
        $delta = 0.0;
        foreach ($players as $q) {
            if ($q['seat'] === $p['seat']) continue;
            $S = $p['placement'] < $q['placement'] ? 1.0 : ($p['placement'] === $q['placement'] ? .5 : 0.0);
            $E = 1 / (1 + 10 ** (($q['rating_before'] - $p['rating_before']) / 400));
            $delta += $K * ($S - $E) / max(1, $n - 1) * ($n > 2 ? 1.5 : 1);
        }
        $p['rating_after'] = max(100, $p['rating_before'] + (int)round($delta));
    }
    unset($p);
    $winners = array_filter($players, fn($p) => $p['placement'] === 1);
    $m['winner_user_id'] = count($winners) === 1 ? reset($winners)['user_id'] : null;
    $up = db()->prepare('UPDATE ' . T('match_players') . ' SET placement=?, rating_after=? WHERE match_id=? AND seat=?');
    $uu = db()->prepare('UPDATE ' . T('users') . ' SET rating=?, games_played=games_played+1, wins=wins+? WHERE id=?');
    foreach ($players as $p) {
        $up->execute([$p['placement'], $p['rating_after'], $m['id'], $p['seat']]);
        $uu->execute([$p['rating_after'], ($p['placement'] === 1 && $m['winner_user_id']) ? 1 : 0, $p['user_id']]);
    }
}

/** Called on every poll: if the shooter's clock ran out, skip them (and remove them after too many strikes). */
function check_timeout(int $mid): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT status, turn_deadline FROM ' . T('matches') . ' WHERE id=?');
    $st->execute([$mid]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'active' || time() <= (int)$row['turn_deadline']) return;
    $pdo->beginTransaction();
    try {
        $m = lock_match($mid);
        if (!$m || $m['status'] !== 'active' || time() <= (int)$m['turn_deadline']) { $pdo->commit(); return; }
        $players = load_players($mid);
        $seat = (int)$m['current_seat'];
        $players[$seat]['strikes']++;
        $type = 'skip';
        if ($players[$seat]['strikes'] >= (int)cfg('max_strikes', 3)) {
            $type = 'forfeit'; $players[$seat]['hp'] = 0; $players[$seat]['alive'] = false; $players[$seat]['death_turn'] = (int)$m['turn_no'];
        }
        $eid = add_event($m, $seat, $type, ['reason' => 'timeout', 'strikes' => $players[$seat]['strikes']]);
        $t = Terrain::unpack($m['terrain'], $m['rules']['water']);
        $eid = advance_or_finish($m, $players, $t) ?? $eid;
        save_players($mid, $players);
        save_match($m, !empty($m['terrain_dirty']) ? $t : null, $eid);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function forfeit(int $mid, int $uid): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = lock_match($mid);
        if (!$m || $m['status'] !== 'active') { $pdo->commit(); return false; }
        $players = load_players($mid);
        $seat = null;
        foreach ($players as $p) if ($p['user_id'] === $uid && $p['alive']) $seat = $p['seat'];
        if ($seat === null) { $pdo->commit(); return false; }
        $players[$seat]['hp'] = 0; $players[$seat]['alive'] = false; $players[$seat]['death_turn'] = (int)$m['turn_no'];
        $eid = add_event($m, $seat, 'forfeit', ['reason' => 'quit']);
        $t = null;
        if ((int)$m['current_seat'] === $seat) {
            $t = Terrain::unpack($m['terrain'], $m['rules']['water']);
            $eid = advance_or_finish($m, $players, $t) ?? $eid;
        } elseif (count(array_filter($players, fn($p) => $p['alive'])) <= 1) finish_match($m, $players);
        save_players($mid, $players);
        save_match($m, $t && !empty($m['terrain_dirty']) ? $t : null, $eid);
        $pdo->commit();
        return true;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Validate and resolve a shot. Returns ['ok'=>true] or ['error'=>...]. */
function take_shot(int $mid, int $uid, array $in): array
{
    $wk = (string)($in['weapon'] ?? 'shell');
    if (!isset(WEAPONS[$wk])) return ['error' => 'bad_weapon'];
    foreach (['yaw', 'elev', 'power', 'turn_no'] as $k) if (!isset($in[$k]) || !is_numeric($in[$k])) return ['error' => 'bad_input'];
    $yaw = fmod(fmod((float)$in['yaw'], 360) + 360, 360);
    $elev = min(85.0, max(5.0, (float)$in['elev']));
    $power = min(100.0, max(10.0, (float)$in['power']));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = lock_match($mid);
        if (!$m || $m['status'] !== 'active') { $pdo->commit(); return ['error' => 'not_active']; }
        if ((int)$in['turn_no'] !== (int)$m['turn_no']) { $pdo->commit(); return ['error' => 'stale_turn']; }
        if (time() > (int)$m['turn_deadline'] + 2) { $pdo->commit(); return ['error' => 'too_late']; }
        $players = load_players($mid);
        $seat = (int)$m['current_seat'];
        if ($players[$seat]['user_id'] !== $uid) { $pdo->commit(); return ['error' => 'not_your_turn']; }
        if ($wk !== 'shell') {
            $col = 'ammo_' . $wk;
            if ($players[$seat][$col] <= 0) { $pdo->commit(); return ['error' => 'no_ammo']; }
            $players[$seat][$col]--;
        }
        $players[$seat]['strikes'] = 0;

        $t = Terrain::unpack($m['terrain'], $m['rules']['water']);
        $wind = wind_vec((float)$m['wind_ang'], (float)$m['wind_spd']);
        $before = array_map(fn($o) => $o['hp'], $m['objects']);
        $res = simulate_shot($t, $players, $m['objects'], $seat, $yaw, $elev, $power, $wk, $wind, $m['rules']);
        if (array_map(fn($o) => $o['hp'], $m['objects']) !== $before) $m['objects_dirty'] = true;
        $res['deaths'] = resolve_deaths($players, (int)$m['turn_no']);
        $res['aim'] = ['yaw' => $yaw, 'elev' => $elev, 'power' => $power, 'weapon' => $wk];
        $res['wind'] = $wind;
        $eid = add_event($m, $seat, 'shot', $res);
        $eid = advance_or_finish($m, $players, $t) ?? $eid;
        save_players($mid, $players);
        save_match($m, $t, $eid);
        $pdo->commit();
        return ['ok' => true, 'event_id' => $eid];
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Public snapshot of a match for the client. */
function match_snapshot(int $mid, int $since, bool $withTerrain): ?array
{
    $st = db()->prepare('SELECT id, mode, map_id, rules, status, wind_ang, wind_spd, turn_no, current_seat, turn_deadline, last_event_id, winner_user_id'
        . ($withTerrain ? ', terrain, objects' : '') . ' FROM ' . T('matches') . ' WHERE id=?');
    $st->execute([$mid]);
    $m = $st->fetch();
    if (!$m) return null;
    $st = db()->prepare('SELECT p.seat, p.user_id, u.username, u.alias, p.hp, p.alive, p.x, p.y, p.z, p.ammo_heavy, p.ammo_cluster, p.strikes,
            p.placement, p.damage_dealt, p.kills, p.rating_before, p.rating_after, p.last_seen
        FROM ' . T('match_players') . ' p JOIN ' . T('users') . ' u ON u.id = p.user_id WHERE p.match_id=? ORDER BY p.seat');
    $st->execute([$mid]);
    $now = time();
    $players = array_map(function ($p) use ($now) {
        $r = ['seat' => (int)$p['seat'], 'user_id' => (int)$p['user_id'], 'name' => display_name($p), 'hp' => (int)$p['hp'],
              'alive' => (bool)$p['alive'], 'x' => (float)$p['x'], 'y' => (float)$p['y'], 'z' => (float)$p['z'],
              'ammo' => ['shell' => null, 'heavy' => (int)$p['ammo_heavy'], 'cluster' => (int)$p['ammo_cluster']],
              'strikes' => (int)$p['strikes'], 'placement' => $p['placement'] === null ? null : (int)$p['placement'],
              'damage' => (int)$p['damage_dealt'], 'kills' => (int)$p['kills'], 'rating' => (int)$p['rating_before'],
              'rating_after' => $p['rating_after'] === null ? null : (int)$p['rating_after'],
              'online' => $now - (int)$p['last_seen'] < 15];
        $r['rank'] = rank_for($r['rating']);
        return $r;
    }, $st->fetchAll());

    $ev = db()->prepare('SELECT id, turn_no, seat, type, payload FROM ' . T('match_events') . ' WHERE match_id=? AND id>? ORDER BY id LIMIT 40');
    $ev->execute([$mid, $since]);
    $events = array_map(fn($e) => ['id' => (int)$e['id'], 'turn' => (int)$e['turn_no'], 'seat' => (int)$e['seat'], 'type' => $e['type'],
                                   'data' => json_decode($e['payload'], true)], $ev->fetchAll());
    $out = [
        'match' => ['id' => (int)$m['id'], 'status' => $m['status'], 'turn' => (int)$m['turn_no'], 'seat' => (int)$m['current_seat'],
                    'time_left' => max(0, (int)$m['turn_deadline'] - $now), 'turn_seconds' => (int)cfg('turn_seconds', 40),
                    'wind_ang' => (float)$m['wind_ang'], 'wind_spd' => (float)$m['wind_spd'],
                    'last_event_id' => (int)$m['last_event_id'], 'winner_user_id' => $m['winner_user_id'] === null ? null : (int)$m['winner_user_id']],
        'players' => $players,
        'events' => $events,
    ];
    $rules = match_rules($m);
    $out['map'] = ['id' => $rules['map'], 'name' => MAPS[$rules['map']]['name'] ?? $rules['map'], 'blurb' => MAPS[$rules['map']]['blurb'] ?? '',
                   'rules' => $rules, 'sudden_turn' => SD_TURN];
    if ($withTerrain) {
        $out['terrain'] = base64_encode($m['terrain']);
        $out['objects'] = json_decode((string)($m['objects'] ?? ''), true) ?: [];
    }
    return $out;
}
