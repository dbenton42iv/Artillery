<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Private matches. The host opens a room and gets a 6-letter invite code; friends join with the code.
 * The host picks the map, power-ups and shot clock, and starts the battle once 2–4 players are in.
 * Private matches are unrated. Rooms live only while their members' lobby pages keep polling:
 * a member who stops for ROOM_STALE seconds is dropped, and the room closes if the host goes.
 * That is 3 minutes, so the host can switch to Messages or WhatsApp to send the invite link (phones pause
 * a page in the background) without the room closing behind them.
 *
 * Invite links: join.php?code=ABC123. A friend who isn't logged in logs in or signs up first, then lands in the room.
 */
const ROOM_STALE = 180, ROOM_MAX = 4, ROOM_CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Upper-case, letters and digits only: "abc-123" → "ABC123". */
function room_clean_code(string $code): string { return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)), 0, 6); }

/** Who's inviting, for the login page: the host's shown name and how many are in, or null if the room isn't open. */
function room_invite_info(string $code): ?array
{
    $st = db()->prepare('SELECT u.id, u.username, u.alias, (SELECT COUNT(*) FROM ' . T('room_members') . ' rm WHERE rm.room_id = r.id) n
        FROM ' . T('rooms') . ' r JOIN ' . T('users') . " u ON u.id = r.host_id WHERE r.code = ? AND r.status = 'open'");
    $st->execute([room_clean_code($code)]);
    $r = $st->fetch();
    return $r ? ['host' => display_name($r), 'players' => (int)$r['n']] : null;
}
const ROOM_CLOCKS = [20, 30, 40, 60];

function room_settings(array $in): array
{
    $map = (string)($in['map'] ?? 'random');
    $items = (string)($in['items'] ?? 'all');
    $clock = (int)($in['turn_seconds'] ?? 40);
    return [
        'map' => $map === 'random' || isset(MAPS[$map]) ? $map : 'random',
        'items' => in_array($items, ['all', 'safe', 'off'], true) ? $items : 'all',
        'turn_seconds' => in_array($clock, ROOM_CLOCKS, true) ? $clock : 40,
    ];
}

function room_new_code(): string
{
    $st = db()->prepare('SELECT 1 FROM ' . T('rooms') . ' WHERE code = ?');
    for ($k = 0; $k < 50; $k++) {
        $c = ''; for ($i = 0; $i < 6; $i++) $c .= ROOM_CODE_CHARS[random_int(0, strlen(ROOM_CODE_CHARS) - 1)];
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    throw new RuntimeException('no free room code');
}

/** The open room this user is in, or null. */
function room_of(int $uid): ?array
{
    $st = db()->prepare('SELECT r.* FROM ' . T('room_members') . ' rm JOIN ' . T('rooms') . ' r ON r.id = rm.room_id WHERE rm.user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['settings'] = json_decode($r['settings'], true) ?: room_settings([]);
    return $r;
}

function room_leave(int $uid): void
{
    $r = room_of($uid);
    if (!$r) return;
    if ((int)$r['host_id'] === $uid && $r['status'] === 'open') room_close((int)$r['id']);
    db()->prepare('DELETE FROM ' . T('room_members') . ' WHERE user_id = ?')->execute([$uid]);
}

/** Close a room. Members stay listed until their next poll, which tells them it closed and lets them go. */
function room_close(int $rid): void
{
    db()->prepare('UPDATE ' . T('rooms') . " SET status = 'closed' WHERE id = ? AND status = 'open'")->execute([$rid]);
}

function room_create(int $uid, array $in): array
{
    room_leave($uid);
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$uid]);
    $code = room_new_code(); $now = time();
    db()->prepare('INSERT INTO ' . T('rooms') . ' (code, host_id, settings) VALUES (?,?,?)')->execute([$code, $uid, json_encode(room_settings($in))]);
    $rid = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO ' . T('room_members') . ' (room_id, user_id, joined_at, last_poll) VALUES (?,?,?,?)')->execute([$rid, $uid, $now, $now]);
    return ['ok' => true, 'code' => $code];
}

function room_join(int $uid, string $code): array
{
    $code = room_clean_code($code);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM ' . T('rooms') . ' WHERE code = ? FOR UPDATE');
        $st->execute([$code]);
        $r = $st->fetch();
        if (!$r || $r['status'] !== 'open') { $pdo->commit(); return ['error' => 'no_room']; }
        room_prune((int)$r['id']);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM ' . T('room_members') . ' WHERE room_id = ?');
        $cnt->execute([$r['id']]);
        $in = $pdo->prepare('SELECT 1 FROM ' . T('room_members') . ' WHERE room_id = ? AND user_id = ?');
        $in->execute([$r['id'], $uid]);
        if (!$in->fetchColumn()) {
            if ((int)$cnt->fetchColumn() >= ROOM_MAX) { $pdo->commit(); return ['error' => 'room_full']; }
            $pdo->prepare('DELETE FROM ' . T('room_members') . ' WHERE user_id = ?')->execute([$uid]);   // leave any other room
            $pdo->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$uid]);
            $now = time();
            $pdo->prepare('INSERT INTO ' . T('room_members') . ' (room_id, user_id, joined_at, last_poll) VALUES (?,?,?,?)')->execute([$r['id'], $uid, $now, $now]);
        }
        $pdo->commit();
        return ['ok' => true, 'code' => $code];
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Drop members whose lobby stopped polling; close the room if the host is one of them. */
function room_prune(int $rid): void
{
    $st = db()->prepare('SELECT r.host_id, rm.user_id, rm.last_poll FROM ' . T('rooms') . ' r JOIN ' . T('room_members') . ' rm ON rm.room_id = r.id WHERE r.id = ?');
    $st->execute([$rid]);
    $cut = time() - ROOM_STALE;
    foreach ($st->fetchAll() as $m) {
        if ((int)$m['last_poll'] >= $cut) continue;
        if ((int)$m['user_id'] === (int)$m['host_id']) { room_close($rid); return; }
        db()->prepare('DELETE FROM ' . T('room_members') . ' WHERE room_id = ? AND user_id = ?')->execute([$rid, $m['user_id']]);
    }
}

/** Host only, while the room is open: change the settings. */
function room_update(int $uid, array $in): array
{
    $r = room_of($uid);
    if (!$r || $r['status'] !== 'open') return ['error' => 'no_room'];
    if ((int)$r['host_id'] !== $uid) return ['error' => 'not_host'];
    db()->prepare('UPDATE ' . T('rooms') . ' SET settings = ? WHERE id = ?')->execute([json_encode(room_settings($in)), $r['id']]);
    return ['ok' => true];
}

/** Host only: start the battle with everyone in the room (2–4 players). */
function room_start(int $uid): array
{
    $pdo = db();
    $r = room_of($uid);
    if (!$r || $r['status'] !== 'open') return ['error' => 'no_room'];
    if ((int)$r['host_id'] !== $uid) return ['error' => 'not_host'];
    room_prune((int)$r['id']);
    $st = $pdo->prepare('SELECT rm.user_id, u.rating FROM ' . T('room_members') . ' rm JOIN ' . T('users') . ' u ON u.id = rm.user_id WHERE rm.room_id = ? ORDER BY rm.joined_at');
    $st->execute([$r['id']]);
    $group = $st->fetchAll();
    if (count($group) < 2) return ['error' => 'need_players'];
    foreach ($group as $g) if (active_match_for((int)$g['user_id'])) return ['error' => 'someone_in_match'];
    $lock = $pdo->quote('art3d_room_' . cfg('db')['prefix'] . $r['id']);
    if ((int)$pdo->query("SELECT GET_LOCK($lock, 3)")->fetchColumn() !== 1) return ['error' => 'busy'];
    try {
        $chk = $pdo->prepare('SELECT status FROM ' . T('rooms') . ' WHERE id = ?');
        $chk->execute([$r['id']]);
        if ($chk->fetchColumn() !== 'open') return ['error' => 'no_room'];
        $s = $r['settings'];
        $map = $s['map'] === 'random' ? array_keys(MAPS)[mt_rand(0, count(MAPS) - 1)] : $s['map'];
        $mid = create_match($group, count($group), $map, ['game_mode' => 'private', 'items' => $s['items'], 'turn_seconds' => $s['turn_seconds']]);
        $pdo->prepare('UPDATE ' . T('rooms') . " SET status = 'started', match_id = ? WHERE id = ?")->execute([$mid, $r['id']]);
        return ['ok' => true, 'match_id' => $mid];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK($lock)");
    }
}

/** Poll: keeps the member's place, and reports the room (or the match it started). */
function room_status(int $uid): array
{
    $r = room_of($uid);
    if (!$r) return ['room' => null];
    if ($r['status'] === 'started') {
        db()->prepare('DELETE FROM ' . T('room_members') . ' WHERE user_id = ?')->execute([$uid]);
        return ['room' => null, 'match_id' => (int)$r['match_id']];
    }
    if ($r['status'] !== 'open') { db()->prepare('DELETE FROM ' . T('room_members') . ' WHERE user_id = ?')->execute([$uid]); return ['room' => null, 'closed' => true]; }
    db()->prepare('UPDATE ' . T('room_members') . ' SET last_poll = ? WHERE user_id = ?')->execute([time(), $uid]);
    room_prune((int)$r['id']);
    $r = room_of($uid);
    if (!$r || $r['status'] !== 'open') {                 // this poll found the host gone and closed the room
        db()->prepare('DELETE FROM ' . T('room_members') . ' WHERE user_id = ?')->execute([$uid]);
        return ['room' => null, 'closed' => true];
    }
    $st = db()->prepare('SELECT u.id, u.username, u.alias, u.rating FROM ' . T('room_members') . ' rm JOIN ' . T('users') . ' u ON u.id = rm.user_id WHERE rm.room_id = ? ORDER BY rm.joined_at');
    $st->execute([$r['id']]);
    $members = array_map(fn($m) => ['name' => display_name($m), 'host' => (int)$m['id'] === (int)$r['host_id'], 'me' => (int)$m['id'] === $uid,
                                    'rank' => rank_for((int)$m['rating'])], $st->fetchAll());
    return ['room' => ['code' => $r['code'], 'host' => (int)$r['host_id'] === $uid, 'settings' => $r['settings'], 'members' => $members, 'max' => ROOM_MAX]];
}
