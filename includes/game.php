<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/cosmetics.php';
ensure_schema();

/* =====================================================================
 * Game modes (matches.game_mode):
 *   ranked   rated, random map from the ranked pool, ranked-safe power-ups in fair crates
 *   casual   unrated, any map (players may name one), the full set of power-ups
 *   private  unrated, by invite code; the host picks the map, power-ups and shot clock (includes/rooms.php)
 *
 * Matchmaking: players wait in a queue per kind (ranked/casual) and size (2, 3 or 4 tanks).
 * Ranked: each player's search window starts at ±100 rating and widens by 15 per second of waiting
 * (capped at ±800), so close ranks meet first and nobody waits forever.
 * Casual: first come, first served; a player who named a map only meets players who chose that map or "any".
 * ===================================================================== */
const GAME_MODES = ['ranked' => 'Ranked', 'casual' => 'Casual', 'private' => 'Private', 'ai' => 'vs AI'];
/*   ai       unrated, against computer players (includes/ai.php); ranked rules by default, fair crates */
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

function run_matchmaker(int $mode, string $kind = 'ranked'): void
{
    $pdo = db();
    $lock = 'art3d_mm_' . cfg('db')['prefix'] . $kind . $mode;
    if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 3)')->fetchColumn() !== 1) return;
    try {
        $now = time();
        $pdo->prepare('DELETE FROM ' . T('queue') . ' WHERE match_id IS NULL AND last_poll < ?')->execute([$now - MM_STALE]);
        $st = $pdo->prepare('SELECT user_id, rating, joined_at, map_pref FROM ' . T('queue') . ' WHERE mode = ? AND kind = ? AND match_id IS NULL ORDER BY joined_at, user_id');
        $st->execute([$mode, $kind]);
        $pool = $st->fetchAll();
        while (count($pool) >= $mode) {
            $made = false;
            foreach ($pool as $anchor) {
                if ($kind === 'casual') {
                    if (!($pick = casual_group($pool, $anchor, $mode))) continue;
                    [$group, $map] = $pick;
                    $mid = create_match($group, $mode, $map ?? array_keys(MAPS)[mt_rand(0, count(MAPS) - 1)], ['game_mode' => 'casual', 'items' => 'all']);
                } else {
                    $win = search_window($now - (int)$anchor['joined_at']);
                    $cands = array_filter($pool, fn($q) => $q['user_id'] !== $anchor['user_id']
                        && abs($q['rating'] - $anchor['rating']) <= $win);
                    if (count($cands) < $mode - 1) continue;
                    usort($cands, fn($a, $b) => abs($a['rating'] - $anchor['rating']) <=> abs($b['rating'] - $anchor['rating']));
                    $group = array_merge([$anchor], array_slice($cands, 0, $mode - 1));
                    $mid = create_match($group, $mode);
                }
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

/**
 * Casual: the anchor plus the longest-waiting players whose map choice fits. Returns [group, map or null] or null.
 * A player who chose "any" (map_pref null) fits every group; one who named a map only fits that map.
 */
function casual_group(array $pool, array $anchor, int $mode): ?array
{
    $targets = $anchor['map_pref'] !== null ? [$anchor['map_pref']]
        : array_merge([null], array_values(array_unique(array_filter(array_column($pool, 'map_pref')))));
    foreach ($targets as $map) {
        $fit = array_values(array_filter($pool, fn($q) => $q['user_id'] !== $anchor['user_id'] && ($q['map_pref'] === null || $q['map_pref'] === $map)));
        if (count($fit) >= $mode - 1) return [array_merge([$anchor], array_slice($fit, 0, $mode - 1)), $map];
    }
    return null;
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
            return !is_array($rules) || !empty($rules['ranked']);   // casual and private matches don't count
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

/**
 * $group: rows with user_id + rating. Returns new match id.
 * $opt: game_mode ('ranked' | 'casual' | 'private' | 'ai'), items ('all' | 'safe' | 'off'), turn_seconds,
 *       bots (AI matches: [user id => skill level]).
 * Only ranked matches are rated.
 */
function create_match(array $group, int $mode, ?string $mapId = null, array $opt = []): int
{
    shuffle($group);                                   // random seat order = random first shooter
    $gm = $opt['game_mode'] ?? 'ranked';
    $mapId ??= pick_ranked_map(array_map(fn($g) => (int)$g['user_id'], $group));
    $gen = generate_map($mapId, count($group));
    $t = $gen['terrain']; $pos = $gen['spawns']; $rules = $gen['rules'];
    $rules['mode'] = $gm; $rules['ranked'] = $gm === 'ranked';
    $rules['items'] = $gm === 'ranked' ? 'safe' : (in_array($opt['items'] ?? 'all', ['all', 'safe', 'off'], true) ? ($opt['items'] ?? 'all') : 'all');
    $rules['turn_seconds'] = (int)($opt['turn_seconds'] ?? cfg('turn_seconds', 40));
    $rules['move'] = (float)($opt['move'] ?? cfg('move_per_turn', 10));   // metres a tank may drive each turn (0 = no driving)
    $rules['regen'] = (int)($opt['regen'] ?? cfg('regen_per_turn', 3));   // hp a tank repairs at the start of its turn (0 = off)
    if (!empty($opt['bots'])) {                         // which seats the AI drives, and how well it aims
        $rules['bots'] = [];
        foreach ($group as $seat => $g) if (isset($opt['bots'][(int)$g['user_id']])) $rules['bots'][$seat] = $opt['bots'][(int)$g['user_id']];
    }
    [$wa, $ws] = initial_wind($rules);
    $los = group_loadouts($group, $rules, $opt);           // phase 4: each tank's doctrine and perks, checked against its level
    $cos = group_cosmetics($group);                         // and how it looks (paint, barrel, trail, blast, title)
    $pdo = db();
    $pdo->beginTransaction();
    FX::reset();
    $tanks = [];
    foreach ($group as $seat => $g) {
        $tanks[$seat] = ['seat' => $seat, 'alive' => true, 'loadout' => $los[$seat], 'sk' => skill_mods($los[$seat]), 'max_hp' => loadout_hp($los[$seat])];
    }
    $m = ['turn_no' => 1, 'wind_ang' => $wa, 'wind_spd' => $ws, 'rules' => $rules];
    if (sk($tanks[0], 'forecast', 0)) add_forecast($m, $tanks, 0, [1 + count($tanks)]);   // Meteorologist moves first
    $pdo->prepare('INSERT INTO ' . T('matches') . ' (mode, game_mode, map_id, rules, objects, effects, terrain, wind_ang, wind_spd, current_seat, turn_deadline) VALUES (?,?,?,?,?,?,?,?,?,0,?)')
        ->execute([$mode, $gm, $mapId, json_encode($rules, JSON_PRESERVE_ZERO_FRACTION), json_encode($gen['objects'], JSON_PRESERVE_ZERO_FRACTION),
                   json_encode(FX::$e, JSON_PRESERVE_ZERO_FRACTION), $t->pack(), $wa, $ws, time() + turn_secs_for($rules, $tanks[0]) + LOAD_GRACE]);
    $mid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO ' . T('match_players') . ' (match_id, seat, user_id, hp, x, y, z, ammo_heavy, ammo_cluster, loadout, cosmetics, rating_before, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($group as $seat => $g) {
        $mods = $tanks[$seat]['sk'];
        $ins->execute([$mid, $seat, $g['user_id'], $tanks[$seat]['max_hp'], $pos[$seat][0], $pos[$seat][1], $pos[$seat][2],
                       (int)($mods['heavy'] ?? START_AMMO['heavy']), (int)($mods['cluster'] ?? START_AMMO['cluster']),
                       json_encode($los[$seat]), json_encode($cos[$seat]), $g['rating'], time()]);
    }
    $pdo->commit();
    return $mid;
}

/**
 * The loadout each seat brings (phase 4). Humans: their saved loadout, if their level allows it in this kind of match
 * (below level 14, ranked uses their ranked preset). Computer players: $opt['bot_loadouts'][user id] if given (any build),
 * otherwise a random preset. $opt['loadouts'][user id] overrides a human's saved choice (tests); it is still checked.
 */
function group_loadouts(array $group, array $rules, array $opt): array
{
    $ids = array_map(fn($g) => (int)$g['user_id'], $group);
    $users = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare('SELECT id, xp, loadout, is_bot FROM ' . T('users') . " WHERE id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $users[(int)$r['id']] = $r;
    }
    $out = []; $presets = array_keys(PRESETS);
    foreach ($group as $seat => $g) {
        $uid = (int)$g['user_id']; $u = $users[$uid] ?? ['xp' => 0, 'loadout' => null, 'is_bot' => 0];
        if (isset($opt['bots'][$uid]) || !empty($u['is_bot'])) {
            $out[$seat] = parse_loadout($opt['bot_loadouts'][$uid] ?? null) ?? ['preset' => $presets[mt_rand(0, count($presets) - 1)]];
            continue;
        }
        $saved = isset($opt['loadouts'][$uid]) ? json_encode($opt['loadouts'][$uid]) : $u['loadout'];
        $out[$seat] = match_loadout($saved, level_for((int)$u['xp']), !empty($rules['ranked']));
    }
    return $out;
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
    FX::reset(json_decode((string)($m['effects'] ?? ''), true) ?: []);
    return $m;
}
/** Shot clock for a match (private matches may set their own). */
function turn_secs(array $rules): int { return (int)($rules['turn_seconds'] ?? cfg('turn_seconds', 40)); }
/** Shot clock for one tank: the match's, plus Quick Loader's 10 s. */
function turn_secs_for(array $rules, array $pl): int { return turn_secs($rules) + (int)sk($pl, 'clock', 0); }
/** Rules for a match row. Matches created before map support play as Highlands. */
function match_rules(array $m): array
{
    $r = json_decode((string)($m['rules'] ?? ''), true);
    if (is_array($r)) return $r;
    return ['map' => 'highlands', 'n' => 160, 'gravity' => 20.0, 'water' => 1.6, 'wind_min' => 0.0, 'wind_max' => (float)cfg('wind_max', 8),
            'wind_axis' => null, 'splash' => false, 'erupt_every' => 0, 'vent' => null, 'snow' => false, 'fall_mult' => 1.0, 'ranked' => true,
            'mode' => 'ranked', 'items' => 'off'];
}
function load_players(int $mid): array
{
    $st = db()->prepare('SELECT * FROM ' . T('match_players') . ' WHERE match_id = ? ORDER BY seat');
    $st->execute([$mid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['seat', 'user_id', 'hp', 'alive', 'ammo_heavy', 'ammo_cluster', 'strikes', 'damage_dealt', 'kills', 'rating_before'] as $k) $r[$k] = (int)$r[$k];
        foreach (['x', 'y', 'z'] as $k) $r[$k] = (float)$r[$k];
        $r['moved'] = (float)($r['moved'] ?? 0);
        $r['last_hit_by'] = $r['last_hit_by'] === null ? null : (int)$r['last_hit_by'];
        $r['death_turn'] = $r['death_turn'] === null ? null : (int)$r['death_turn'];
        $r['alive'] = (bool)$r['alive'];
        $r['item'] = $r['item'] ?? null;
        $r['loadout'] = parse_loadout($r['loadout'] ?? null);           // phase 4; null for older matches (no skills)
        $r['sk'] = skill_mods($r['loadout']); $r['max_hp'] = loadout_hp($r['loadout']);
        $out[$r['seat']] = $r;
    }
    return $out;
}
function save_players(int $mid, array $players): void
{
    $st = db()->prepare('UPDATE ' . T('match_players') . ' SET hp=?, alive=?, x=?, y=?, z=?, ammo_heavy=?, ammo_cluster=?, item=?, strikes=?, death_turn=?,
                         last_hit_by=?, damage_dealt=?, kills=?, moved=? WHERE match_id=? AND seat=?');
    foreach ($players as $p) {
        $st->execute([$p['hp'], $p['alive'] ? 1 : 0, $p['x'], $p['y'], $p['z'], $p['ammo_heavy'], $p['ammo_cluster'], $p['item'], $p['strikes'], $p['death_turn'],
                      $p['last_hit_by'], $p['damage_dealt'], $p['kills'], round((float)($p['moved'] ?? 0), 2), $mid, $p['seat']]);
    }
}
function add_event(array $m, int $seat, string $type, array $payload): int
{
    db()->prepare('INSERT INTO ' . T('match_events') . ' (match_id, turn_no, seat, type, payload) VALUES (?,?,?,?,?)')
        ->execute([$m['id'], $m['turn_no'], $seat, $type, json_encode($payload, JSON_PRESERVE_ZERO_FRACTION)]);
    $id = (int)db()->lastInsertId();
    log_crates($m, $payload);
    return $id;
}

/* =====================================================================
 * Power-up log: every drop, claim, loss and use, for tuning drop weights against win rates later.
 * ===================================================================== */
function log_item(array $m, ?int $seat, string $item, string $action): void
{
    static $users = [];
    if ($seat !== null && !isset($users[$m['id']][$seat])) {
        $st = db()->prepare('SELECT seat, user_id FROM ' . T('match_players') . ' WHERE match_id = ?');
        $st->execute([$m['id']]);
        foreach ($st->fetchAll() as $r) $users[$m['id']][(int)$r['seat']] = (int)$r['user_id'];
    }
    db()->prepare('INSERT INTO ' . T('item_log') . ' (match_id, user_id, game_mode, turn_no, item, action) VALUES (?,?,?,?,?,?)')
        ->execute([$m['id'], $seat === null ? null : ($users[$m['id']][$seat] ?? null), $m['rules']['mode'] ?? 'ranked', $m['turn_no'], $item, $action]);
}
function log_crates(array $m, array $payload): void
{
    foreach ($payload['crates'] ?? [] as $c) log_item($m, null, 'crate', 'drop');
    foreach (array_merge($payload['projectiles'] ?? [], $payload['bombs'] ?? []) as $pr) {
        foreach ($pr['boom']['crates'] ?? [] as $c) {
            if ($c['by'] === null) { log_item($m, null, 'crate', 'destroyed'); continue; }
            log_item($m, $c['by'], $c['item'], 'claim');
            if ($c['lost']) log_item($m, $c['by'], $c['lost'], 'replaced');
        }
    }
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
/** One tank left, or (against the AI) no human tank left: nobody would be watching the computer play itself. */
function battle_over(array $m, array $players): bool
{
    $alive = array_filter($players, fn($p) => $p['alive']);
    if (count($alive) <= 1) return true;
    $bots = $m['rules']['bots'] ?? [];
    return $bots && !array_filter($alive, fn($p) => !isset($bots[$p['seat']]));
}

function advance_or_finish(array &$m, array &$players, Terrain $t): ?int
{
    if (battle_over($m, $players)) { finish_match($m, $players); return null; }
    $n = count($players); $prev = (int)$m['current_seat']; $s = $prev;
    do { $s = ($s + 1) % $n; } while (!$players[$s]['alive']);
    $old = (int)$m['turn_no']; $new = $old + 1;
    $wa = (float)$m['wind_ang']; $ws = (float)$m['wind_spd'];
    if (isset(FX::$e['winds'][$new])) {                // pre-rolled by a Weather Report
        [$wa, $ws] = FX::$e['winds'][$new]; unset(FX::$e['winds'][$new]);
    } else next_wind($wa, $ws, $m['rules'], $old, $new);
    foreach (FX::$e['forecast'] as $k => $f) {
        $f = array_values(array_filter($f, fn($x) => $x[0] >= $new));
        if ($f) FX::$e['forecast'][$k] = $f; else unset(FX::$e['forecast'][$k]);
    }
    FX::$e['double'] = null;
    FX::$e['moved'] = null;                             // a fresh driving allowance for the next tank
    $m['current_seat'] = $s; $m['turn_no'] = $new; $m['wind_ang'] = $wa; $m['wind_spd'] = $ws;
    $m['turn_deadline'] = time() + turn_secs_for($m['rules'], $players[$s]);

    $before = json_encode($m['objects']);
    $fx = start_turn($t, $players, $m['objects'], $m['rules'], $new, $prev, $s);
    if ($fx === null) { meteo_forecast($m, $players); return null; }
    if (json_encode($m['objects']) !== $before) $m['objects_dirty'] = true;
    $m['terrain_dirty'] = true;
    $fx['deaths'] = resolve_deaths($players, $new);
    $fx['wind'] = wind_vec($wa, $ws);
    $eid = add_event($m, $s, 'turn', $fx);
    if (battle_over($m, $players)) { finish_match($m, $players); return $eid; }
    if (!$players[$s]['alive']) {                       // the tank whose turn it is just died: pass to the next one
        do { $s = ($s + 1) % $n; } while (!$players[$s]['alive']);
        $m['current_seat'] = $s;
        $m['turn_deadline'] = time() + turn_secs_for($m['rules'], $players[$s]);
    }
    meteo_forecast($m, $players);
    return $eid;
}

/** Meteorologist: when its turn starts, the tank sees the wind of its own next turn (rolled now and kept, so it's exact). */
function meteo_forecast(array $m, array $players): void
{
    $s = (int)$m['current_seat'];
    if (!sk($players[$s], 'forecast', 0)) return;
    $alive = count(array_filter($players, fn($p) => $p['alive']));
    add_forecast($m, $players, $s, [(int)$m['turn_no'] + $alive]);
}

function save_match(array $m, ?Terrain $t = null, ?int $lastEvent = null): void
{
    $sql = 'UPDATE ' . T('matches') . ' SET status=?, wind_ang=?, wind_spd=?, turn_no=?, current_seat=?, turn_deadline=?, winner_user_id=?, finished_at=?';
    $args = [$m['status'], $m['wind_ang'], $m['wind_spd'], $m['turn_no'], $m['current_seat'], $m['turn_deadline'], $m['winner_user_id'], $m['finished_at']];
    if ($t) { $sql .= ', terrain=?'; $args[] = $t->pack(); }
    $sql .= ', effects=?'; $args[] = json_encode(FX::$e, JSON_PRESERVE_ZERO_FRACTION);
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
    $n = count($players); $K = empty($m['rules']['ranked']) ? 0 : 32;   // casual and private matches are unrated
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
        if ($K) $uu->execute([$p['rating_after'], ($p['placement'] === 1 && $m['winner_user_id']) ? 1 : 0, $p['user_id']]);
    }
    award_xp($m, $players);
}

/**
 * XP for every human in a finished match, in every mode (phase 4). Saved on the match (match_players.xp and xp_detail,
 * for the end screen) and added to the user's total and level. A player who left or was removed for missed turns
 * doesn't get the "finished" or win XP, but keeps XP for damage and kills. Computer players earn nothing.
 */
function award_xp(array $m, array $players): void
{
    $pdo = db();
    $q = $pdo->prepare('SELECT DISTINCT seat FROM ' . T('match_events') . " WHERE match_id = ? AND type = 'forfeit'");
    $q->execute([$m['id']]);
    $quit = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $bots = $m['rules']['bots'] ?? [];
    $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
    $sel = $pdo->prepare('SELECT xp, first_win_on, is_bot FROM ' . T('users') . ' WHERE id = ? FOR UPDATE');
    $mp = $pdo->prepare('UPDATE ' . T('match_players') . ' SET xp = ?, xp_detail = ? WHERE match_id = ? AND seat = ?');
    $uu = $pdo->prepare('UPDATE ' . T('users') . ' SET xp = ?, level = ?, first_win_on = ? WHERE id = ?');
    foreach ($players as $p) {
        if (isset($bots[$p['seat']])) continue;
        $sel->execute([$p['user_id']]); $u = $sel->fetch();
        if (!$u || (int)$u['is_bot']) continue;
        $sole = $p['placement'] === 1 && (int)$m['winner_user_id'] === (int)$p['user_id'];
        $first = $sole && (string)$u['first_win_on'] !== $today;
        $gain = match_xp($p, in_array($p['seat'], $quit, true), $sole, $first);
        $before = (int)$u['xp']; $after = $before + $gain['total'];
        $detail = $gain + ['before' => $before, 'after' => $after, 'level_before' => level_for($before), 'level_after' => level_for($after)];
        $mp->execute([$gain['total'], json_encode($detail), $m['id'], $p['seat']]);
        $uu->execute([$after, level_for($after), $first ? $today : $u['first_win_on'], $p['user_id']]);
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

function forfeit(int $mid, int $uid, string $reason = 'quit'): bool
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
        $eid = add_event($m, $seat, 'forfeit', ['reason' => $reason]);   // quit, or 'suspended' by an admin
        $t = null;
        if ((int)$m['current_seat'] === $seat) {
            $t = Terrain::unpack($m['terrain'], $m['rules']['water']);
            $eid = advance_or_finish($m, $players, $t) ?? $eid;
        } elseif (battle_over($m, $players)) finish_match($m, $players);
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
    $power = (float)$in['power'];                       // clamped below, once the tank's loadout is known (Fortified: 85)

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
        $power = min(max_power($players[$seat]), max(10.0, $power));
        if (isset(POWERUPS[$wk])) {                      // a power-up shell: fired from the item slot
            if ($players[$seat]['item'] !== $wk) { $pdo->commit(); return ['error' => 'no_item']; }
            $players[$seat]['item'] = null;
            log_item($m, $seat, $wk, 'use');
        } elseif ($wk !== 'shell') {
            $col = 'ammo_' . $wk;
            if ($players[$seat][$col] <= 0) { $pdo->commit(); return ['error' => 'no_ammo']; }
            $players[$seat][$col]--;
        }
        $players[$seat]['strikes'] = 0;
        FX::$e['range'] = array_values(array_diff(FX::$e['range'], [$seat]));   // a Rangefinder lasts one shot
        $dbl = FX::$e['double'];
        $first = $dbl && $dbl['seat'] === $seat && $dbl['turn'] === (int)$m['turn_no'] && $dbl['shots'] === 0;

        $t = Terrain::unpack($m['terrain'], $m['rules']['water']);
        $wind = wind_vec((float)$m['wind_ang'], (float)$m['wind_spd']);
        $before = json_encode($m['objects']);          // buildings take damage; crates are claimed, wrecked or settle
        $enemies = array_keys(array_filter($players, fn($p) => $p['alive'] && $p['seat'] !== $seat));
        $res = simulate_shot($t, $players, $m['objects'], $seat, $yaw, $elev, $power, $wk, $wind, $m['rules']);
        if (json_encode($m['objects']) !== $before) $m['objects_dirty'] = true;
        if (sk($players[$seat], 'spotter', false) && ($sp = spot_report($players, $seat, $res, $enemies))) $res['spot'] = $sp;
        $res['deaths'] = resolve_deaths($players, (int)$m['turn_no']);
        $res['aim'] = ['yaw' => $yaw, 'elev' => $elev, 'power' => $power, 'weapon' => $wk];
        $res['wind'] = $wind;
        if ($first) $res['double'] = 1;
        $eid = add_event($m, $seat, 'shot', $res);
        if ($first && $players[$seat]['alive'] && !battle_over($m, $players)) {
            // Double Shot: the same tank fires again before the turn passes
            FX::$e['double']['shots'] = 1;
            $m['turn_deadline'] = max((int)$m['turn_deadline'], time() + 20);
        } else $eid = advance_or_finish($m, $players, $t) ?? $eid;
        save_players($mid, $players);
        save_match($m, $t, $eid);
        $pdo->commit();
        return ['ok' => true, 'event_id' => $eid];
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/**
 * Use the held power-up (instant items, and Relocate, which ends the turn). Body: {"match_id", "turn_no"}.
 * Shell power-ups (Bunker Buster, Napalm, Earthmover) are fired through take_shot() instead.
 */
function use_item(int $mid, int $uid, array $in): array
{
    if (!isset($in['turn_no']) || !is_numeric($in['turn_no'])) return ['error' => 'bad_input'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = lock_match($mid);
        if (!$m || $m['status'] !== 'active') { $pdo->commit(); return ['error' => 'not_active']; }
        if ((int)$in['turn_no'] !== (int)$m['turn_no']) { $pdo->commit(); return ['error' => 'stale_turn']; }
        if (time() > (int)$m['turn_deadline'] + 2) { $pdo->commit(); return ['error' => 'too_late']; }
        $players = load_players($mid);
        $seat = (int)$m['current_seat']; $me = $players[$seat];
        if ($me['user_id'] !== $uid) { $pdo->commit(); return ['error' => 'not_your_turn']; }
        $item = $me['item'];
        if (!$item || !isset(POWERUPS[$item])) { $pdo->commit(); return ['error' => 'no_item']; }
        if (POWERUPS[$item]['kind'] === 'shell') { $pdo->commit(); return ['error' => 'fire_it']; }
        $dbl = FX::$e['double'];
        if ($dbl && $dbl['seat'] === $seat && $dbl['shots'] > 0) { $pdo->commit(); return ['error' => 'mid_double']; }
        $t = $item === 'relocate' ? Terrain::unpack($m['terrain'], $m['rules']['water']) : null;
        $r = item_effect($t, $players, $m['objects'], $seat, $item, (int)$m['turn_no']);
        if (isset($r['error'])) { $pdo->commit(); return $r; }
        if ($item === 'weather') forecast_winds($m, $players, $seat);
        $ev = $r['event']; $endsTurn = $r['ends_turn'];
        $me = &$players[$seat];
        $me['item'] = null; $me['strikes'] = 0;
        unset($me);
        $eid = add_event($m, $seat, 'item', $ev);
        log_item($m, $seat, $item, 'use');
        if ($endsTurn) $eid = advance_or_finish($m, $players, $t) ?? $eid;
        save_players($mid, $players);
        save_match($m, $endsTurn && !empty($m['terrain_dirty']) ? $t : null, $eid);
        $pdo->commit();
        return ['ok' => true, 'event_id' => $eid];
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Metres $seat may still drive this turn. */
function move_left(array $m, int $seat): float
{
    $mv = FX::$e['moved'] ?? null; $used = 0.0;
    if ($mv && (int)$mv['seat'] === $seat && (int)$mv['turn'] === (int)$m['turn_no']) $used = (float)$mv['used'];
    return max(0.0, move_budget($m['rules']) - $used);
}

/**
 * Drive the tank whose turn it is toward a point. Body: {"match_id", "turn_no", "x", "z"}.
 * Doesn't end the turn; the tank can drive again while it has allowance left, then fires.
 */
function drive(int $mid, int $uid, array $in): array
{
    foreach (['x', 'z', 'turn_no'] as $k) if (!isset($in[$k]) || !is_numeric($in[$k]) || !is_finite((float)$in[$k])) return ['error' => 'bad_input'];
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
        if (move_budget($m['rules']) <= 0) { $pdo->commit(); return ['error' => 'no_driving']; }
        $dbl = FX::$e['double'];
        if ($dbl && $dbl['seat'] === $seat && $dbl['shots'] > 0) { $pdo->commit(); return ['error' => 'mid_double']; }
        $left = move_left($m, $seat);
        $t = Terrain::unpack($m['terrain'], $m['rules']['water']);
        $ev = drive_tank($t, $players, $m['objects'], $seat, (float)$in['x'], (float)$in['z'], $left);
        if (isset($ev['error'])) { $pdo->commit(); return $ev; }
        if (!empty($ev['pickup'])) $m['objects_dirty'] = true;           // drove over a crate
        $used = move_budget($m['rules']) - $left + $ev['used'];
        FX::$e['moved'] = ['seat' => $seat, 'turn' => (int)$m['turn_no'], 'used' => round($used, 2)];
        $ev['left'] = round(max(0.0, move_budget($m['rules']) - $used), 2);
        $players[$seat]['strikes'] = 0;                  // driving counts as being at the controls
        $players[$seat]['moved'] = ($players[$seat]['moved'] ?? 0) + $ev['used'];
        $eid = add_event($m, $seat, 'move', $ev);
        foreach ($ev['pickup'] ?? [] as $c) { log_item($m, $seat, $c['item'], 'claim'); if ($c['lost']) log_item($m, $seat, $c['lost'], 'replaced'); }
        save_players($mid, $players);
        save_match($m, null, $eid);
        $pdo->commit();
        return ['ok' => true, 'event_id' => $eid, 'left' => $ev['left']];
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/**
 * Weather Report: roll the wind now for every turn up to the holder's 2nd turn from now (1st with Scavenger), store it
 * so advance_or_finish() uses exactly these values, and return the holder's forecast: [[turn, angle, speed], ...].
 * "Your next turns" assumes nobody is destroyed in between; each value is exact for its turn number.
 */
function forecast_winds(array $m, array $players, int $seat): array
{
    $alive = count(array_filter($players, fn($p) => $p['alive']));
    $turn = (int)$m['turn_no']; $k = max(1, 2 + (int)sk($players[$seat], 'item_turns', 0));
    return add_forecast($m, $players, $seat, array_map(fn($i) => $turn + $i * $alive, range(1, $k)));
}

/**
 * Pre-roll the wind up to the last of $turns and add those turns to $seat's forecast (kept with any it already has,
 * e.g. a Weather Report and Meteorologist together). Returns the seat's whole forecast.
 */
function add_forecast(array $m, array $players, int $seat, array $turns): array
{
    $turn = (int)$m['turn_no'];
    $wa = (float)$m['wind_ang']; $ws = (float)$m['wind_spd'];
    for ($k = $turn + 1; $k <= max($turns); $k++) {
        if (isset(FX::$e['winds'][$k])) { [$wa, $ws] = FX::$e['winds'][$k]; continue; }
        next_wind($wa, $ws, $m['rules'], $k - 1, $k);
        FX::$e['winds'][$k] = [$wa, $ws];
    }
    $f = [];
    foreach (FX::$e['forecast'][$seat] ?? [] as $x) if ($x[0] >= $turn) $f[$x[0]] = $x;
    foreach ($turns as $k) $f[$k] = [$k, round(FX::$e['winds'][$k][0], 4), round(FX::$e['winds'][$k][1], 2)];
    ksort($f);
    return FX::$e['forecast'][$seat] = array_values($f);
}

/**
 * What a crate held is private to whoever opened it (David's rule): in shot and drive events sent to anyone else,
 * the claimed crates lose 'item' (what was inside) and 'lost' (the power-up it replaced). Everyone still sees who
 * opened which crate. A power-up shows itself only when it's used or fired.
 */
function hide_crate_contents(?array $d, ?int $viewer): ?array
{
    if (!$d) return $d;
    $hide = function (array &$list) use ($viewer) {
        foreach ($list as &$c) if (($c['by'] ?? null) !== $viewer) { unset($c['item'], $c['lost']); $c['sealed'] = true; }
    };
    foreach ($d['projectiles'] ?? [] as $k => $pr) if (!empty($pr['boom']['crates'])) $hide($d['projectiles'][$k]['boom']['crates']);
    foreach ($d['bombs'] ?? [] as $k => $b) if (!empty($b['crates'])) $hide($d['bombs'][$k]['crates']);
    if (!empty($d['pickup'])) $hide($d['pickup']);
    return $d;
}

/** Snapshot of a match for one player's browser (only that player sees their own wind forecast). */
function match_snapshot(int $mid, int $since, bool $withTerrain, ?int $mySeat = null): ?array
{
    $st = db()->prepare('SELECT id, mode, game_mode, map_id, rules, effects, status, wind_ang, wind_spd, turn_no, current_seat, turn_deadline, last_event_id, winner_user_id'
        . ($withTerrain ? ', terrain, objects' : '') . ' FROM ' . T('matches') . ' WHERE id=?');
    $st->execute([$mid]);
    $m = $st->fetch();
    if (!$m) return null;
    $st = db()->prepare('SELECT p.seat, p.user_id, u.username, u.alias, u.xp AS user_xp, p.hp, p.alive, p.x, p.y, p.z, p.ammo_heavy, p.ammo_cluster, p.item, p.strikes,
            p.placement, p.damage_dealt, p.kills, p.rating_before, p.rating_after, p.last_seen, p.loadout, p.xp, p.xp_detail, p.cosmetics
        FROM ' . T('match_players') . ' p JOIN ' . T('users') . ' u ON u.id = p.user_id WHERE p.match_id=? ORDER BY p.seat');
    $st->execute([$mid]);
    $now = time();
    $bots = match_rules($m)['bots'] ?? [];
    $players = array_map(function ($p) use ($now, $bots, $mySeat) {
        $r = ['seat' => (int)$p['seat'], 'user_id' => (int)$p['user_id'], 'name' => display_name($p), 'hp' => (int)$p['hp'],
              'alive' => (bool)$p['alive'], 'x' => (float)$p['x'], 'y' => (float)$p['y'], 'z' => (float)$p['z'],
              'ammo' => ['shell' => null, 'heavy' => (int)$p['ammo_heavy'], 'cluster' => (int)$p['ammo_cluster']],
              // a held power-up is private: others only see that the tank holds one ('sealed'), never which
              'item' => $p['item'] === null ? null : ((int)$p['seat'] === $mySeat ? $p['item'] : 'sealed'),
              'strikes' => (int)$p['strikes'], 'placement' => $p['placement'] === null ? null : (int)$p['placement'],
              'damage' => (int)$p['damage_dealt'], 'kills' => (int)$p['kills'], 'rating' => (int)$p['rating_before'],
              'rating_after' => $p['rating_after'] === null ? null : (int)$p['rating_after'],
              'online' => $now - (int)$p['last_seen'] < 15];
        $r['rank'] = rank_for($r['rating']);
        if (isset($bots[$r['seat']])) { $r['bot'] = $bots[$r['seat']]; $r['online'] = true; }
        else $r['level'] = level_for((int)$p['user_xp']);
        // phase 4: everyone sees everyone's loadout (it's on the intro screen); older matches have none
        $lo = parse_loadout($p['loadout'] ?? null); $mods = skill_mods($lo);
        $r['loadout'] = loadout_public($lo);
        $r['max_hp'] = loadout_hp($lo); $r['max_power'] = (float)($mods['max_power'] ?? 100); $r['arc'] = (float)($mods['arc'] ?? 1);
        $r['spotter'] = !empty($mods['spotter']); $r['clock'] = (int)($mods['clock'] ?? 0);
        $r['cos'] = cosmetics_public(json_decode((string)($p['cosmetics'] ?? ''), true) ?: null);   // looks only (older matches: default)
        $r['title'] = $r['cos']['title']['text'];
        if ($r['seat'] === $mySeat && $p['xp'] !== null) $r['xp'] = json_decode((string)$p['xp_detail'], true) ?: ['total' => (int)$p['xp']];
        return $r;
    }, $st->fetchAll());

    $ev = db()->prepare('SELECT id, turn_no, seat, type, payload FROM ' . T('match_events') . ' WHERE match_id=? AND id>? ORDER BY id LIMIT 40');
    $ev->execute([$mid, $since]);
    $events = array_map(fn($e) => ['id' => (int)$e['id'], 'turn' => (int)$e['turn_no'], 'seat' => (int)$e['seat'], 'type' => $e['type'],
                                   'data' => hide_crate_contents(json_decode($e['payload'], true), $mySeat)], $ev->fetchAll());
    $out = [
        'match' => ['id' => (int)$m['id'], 'status' => $m['status'], 'turn' => (int)$m['turn_no'], 'seat' => (int)$m['current_seat'],
                    'time_left' => max(0, (int)$m['turn_deadline'] - $now), 'turn_seconds' => turn_secs(match_rules($m)),
                    'game_mode' => $m['game_mode'] ?? 'ranked', 'ended' => match_rules($m)['ended'] ?? null,
                    'wind_ang' => (float)$m['wind_ang'], 'wind_spd' => (float)$m['wind_spd'],
                    'last_event_id' => (int)$m['last_event_id'], 'winner_user_id' => $m['winner_user_id'] === null ? null : (int)$m['winner_user_id']],
        'players' => $players,
        'events' => $events,
    ];
    FX::reset(json_decode((string)($m['effects'] ?? ''), true) ?: []);
    $out['effects'] = FX::pub($mySeat);
    $rules = match_rules($m);
    $out['map'] = ['id' => $rules['map'], 'name' => MAPS[$rules['map']]['name'] ?? $rules['map'], 'blurb' => MAPS[$rules['map']]['blurb'] ?? '',
                   'rules' => $rules, 'move' => move_budget($rules), 'regen' => regen_rate($rules), 'sudden_turn' => SD_TURN, 'sudden_wind' => SD_WIND, 'sudden_drain' => SD_DRAIN];
    if ($withTerrain) {
        $out['skills'] = skills_catalog();
        $out['terrain'] = base64_encode($m['terrain']);
        $out['objects'] = array_map(function ($o) use ($mySeat) {   // sealed crates: never send what's inside
            if ($o['type'] === 'crate' && $o['state'] === 'live') unset($o['item'], $o['pool']);
            if ($o['type'] === 'crate' && ($o['by'] ?? null) !== $mySeat) unset($o['item'], $o['pool']);   // opened by someone else: still private
            return $o;
        }, json_decode((string)($m['objects'] ?? ''), true) ?: []);
    }
    return $out;
}
