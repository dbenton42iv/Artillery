<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/game.php';
/*
 * Site admin tools (admin.php). The game has no server process to restart: every request runs PHP and reads the
 * database. "Restarting" means clearing out what can get stuck there: matches that never finish, the matchmaking
 * queue and open private rooms, plus PHP's code cache after uploading new files.
 * Admins are the usernames listed in config.php under 'admins'.
 */

function is_admin(?array $u): bool
{
    if (!$u) return false;
    $list = array_map(fn($n) => strtolower(trim((string)$n)), (array)cfg('admins', []));
    return in_array(strtolower((string)$u['username']), $list, true);
}

/**
 * End a match with no result: nobody wins or loses rating, and matches played and wins don't change.
 * Players still on the battle page see "Ended by the site admin". Returns true if it was still running.
 */
function admin_end_match(int $mid): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = lock_match($mid);
        if (!$m || $m['status'] !== 'active') { $pdo->commit(); return false; }
        $rules = $m['rules']; $rules['ended'] = 'admin';
        $pdo->prepare('UPDATE ' . T('matches') . " SET status = 'finished', finished_at = NOW(), winner_user_id = NULL, rules = ? WHERE id = ?")
            ->execute([json_encode($rules, JSON_PRESERVE_ZERO_FRACTION), $mid]);
        $pdo->prepare('UPDATE ' . T('match_players') . ' SET rating_after = rating_before, placement = NULL WHERE match_id = ?')->execute([$mid]);
        $pdo->prepare('DELETE FROM ' . T('queue') . ' WHERE match_id = ?')->execute([$mid]);
        $pdo->prepare('UPDATE ' . T('rooms') . " SET status = 'closed' WHERE match_id = ?")->execute([$mid]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** End every running match. Returns how many. */
function admin_end_all(): int
{
    $n = 0;
    foreach (db()->query('SELECT id FROM ' . T('matches') . " WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $mid) $n += admin_end_match((int)$mid) ? 1 : 0;
    return $n;
}

/** Empty the matchmaking queue (everyone searching has to press Find match again). Returns how many rows. */
function admin_clear_queue(): int { return db()->exec('DELETE FROM ' . T('queue')); }

/** Close every open private room. Returns how many. */
function admin_close_rooms(): int
{
    db()->exec('DELETE rm FROM ' . T('room_members') . ' rm JOIN ' . T('rooms') . " r ON r.id = rm.room_id WHERE r.status = 'open'");
    return db()->exec('UPDATE ' . T('rooms') . " SET status = 'closed' WHERE status = 'open'");
}

/** Make PHP load the files on disk again (after an upload). Returns null if this host has no OPcache. */
function admin_reload_code(): ?bool
{
    if (!function_exists('opcache_reset')) return null;
    return @opcache_reset();
}

/** What the admin page shows. */
function admin_status(): array
{
    $pdo = db();
    $matches = $pdo->query('SELECT m.id, m.game_mode, m.map_id, m.mode, m.turn_no, m.turn_deadline, m.created_at,
            TIMESTAMPDIFF(SECOND, m.created_at, NOW()) age,
            (SELECT TIMESTAMPDIFF(SECOND, MAX(e.created_at), NOW()) FROM ' . T('match_events') . ' e WHERE e.match_id = m.id) idle
        FROM ' . T('matches') . " m WHERE m.status = 'active' ORDER BY m.id")->fetchAll();
    $who = $pdo->prepare('SELECT u.username, u.alias, u.is_bot, p.hp, p.alive, p.last_seen FROM ' . T('match_players') . ' p JOIN ' . T('users') . ' u ON u.id = p.user_id WHERE p.match_id = ? ORDER BY p.seat');
    foreach ($matches as &$m) {
        $who->execute([$m['id']]);
        $m['players'] = $who->fetchAll();
        $m['idle'] = $m['idle'] === null ? (int)$m['age'] : (int)$m['idle'];
        $m['online'] = count(array_filter($m['players'], fn($p) => !$p['is_bot'] && time() - (int)$p['last_seen'] < 30));
    }
    unset($m);
    return [
        'matches' => $matches,
        'queue' => (int)$pdo->query('SELECT COUNT(*) FROM ' . T('queue') . ' WHERE match_id IS NULL')->fetchColumn(),
        'rooms' => (int)$pdo->query('SELECT COUNT(*) FROM ' . T('rooms') . " WHERE status = 'open'")->fetchColumn(),
        'errors' => $pdo->query('SELECT created_at, source, match_id, LEFT(message, 300) message FROM ' . T('errors') . ' ORDER BY id DESC LIMIT 12')->fetchAll(),
        'opcache' => function_exists('opcache_get_status') && (@opcache_get_status(false)['opcache_enabled'] ?? false),
    ];
}

/**
 * Phase 4 gate, watched on real games: how often each loadout wins finished ranked matches. A draw counts as half a
 * win; a match with n tanks expects 1/n wins, so "vs fair share" compares the wins with what an average build would get.
 * Returns rows sorted by win rate: [key, name, matches, wins, rate, share (wins ÷ expected wins)].
 */
function admin_loadout_stats(): array
{
    $rows = db()->query('SELECT p.loadout, p.placement, m.mode, m.winner_user_id = p.user_id AS won, m.winner_user_id IS NULL AS drawn
        FROM ' . T('match_players') . ' p JOIN ' . T('matches') . " m ON m.id = p.match_id
        WHERE m.game_mode = 'ranked' AND m.status = 'finished' AND p.loadout IS NOT NULL AND p.placement IS NOT NULL")->fetchAll();
    $agg = [];
    foreach ($rows as $r) {
        $lo = parse_loadout($r['loadout']); if (!$lo) continue;
        $k = loadout_key($lo);
        $agg[$k] ??= ['key' => $k, 'name' => loadout_public($lo)['preset'] ? PRESETS[$lo['preset']]['name'] . ' (preset)' : $k, 'n' => 0, 'w' => 0.0, 'exp' => 0.0];
        $agg[$k]['n']++; $agg[$k]['exp'] += 1 / max(2, (int)$r['mode']);
        $agg[$k]['w'] += $r['won'] ? 1.0 : (($r['drawn'] && (int)$r['placement'] === 1) ? .5 : 0.0);
    }
    foreach ($agg as &$a) { $a['rate'] = $a['w'] / $a['n']; $a['share'] = $a['exp'] > 0 ? $a['w'] / $a['exp'] : 0; }
    unset($a);
    usort($agg, fn($a, $b) => $b['share'] <=> $a['share']);
    return array_values($agg);
}
