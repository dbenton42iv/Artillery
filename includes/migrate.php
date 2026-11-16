<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/names.php';
/*
 * Brings an existing install's tables up to date, so an upgrade is just "upload the new files".
 * The check is two zero-row queries per request; the ALTERs run once, under a lock.
 */
function ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $need = [];
    foreach (['maps' => 'SELECT map_id, rules, objects FROM ' . T('matches') . ' LIMIT 0',
              'names' => 'SELECT alias, alias_key, username_key, alias_changed_at FROM ' . T('users') . ' LIMIT 0',
              'items' => 'SELECT m.game_mode, m.effects, p.item, q.kind, q.map_pref, r.code, rm.room_id, l.action FROM ' . T('matches') . ' m, '
                       . T('match_players') . ' p, ' . T('queue') . ' q, ' . T('rooms') . ' r, ' . T('room_members') . ' rm, ' . T('item_log') . ' l LIMIT 0',
              'ai' => 'SELECT is_bot FROM ' . T('users') . ' LIMIT 0',
              'errors' => 'SELECT id FROM ' . T('errors') . ' LIMIT 0',
              'drive' => 'SELECT moved FROM ' . T('match_players') . ' LIMIT 0',
              'skills' => 'SELECT u.xp, u.level, u.loadout, u.first_win_on, p.loadout, p.xp, p.xp_detail FROM ' . T('users') . ' u, ' . T('match_players') . ' p LIMIT 0',
              'bans' => 'SELECT banned_until, ban_reason, banned_by FROM ' . T('users') . ' LIMIT 0',
             ] as $k => $probe) {
        try { db()->query($probe); } catch (PDOException $e) { $need[] = $k; }
    }
    if (!$need) return;
    $pdo = db();
    $lock = $pdo->quote('art3d_migrate_' . cfg('db')['prefix']);
    $pdo->query("SELECT GET_LOCK($lock, 10)");
    try {
        $cols = fn(string $t) => array_column($pdo->query('SHOW COLUMNS FROM ' . T($t))->fetchAll(), 'Field');
        if (in_array('maps', $need, true)) {                     // phase 1: maps
            $have = $cols('matches'); $add = [];
            if (!in_array('map_id', $have, true))  $add[] = 'ADD COLUMN map_id VARCHAR(16) NULL AFTER mode';
            if (!in_array('rules', $have, true))   $add[] = 'ADD COLUMN rules TEXT NULL AFTER map_id';
            if (!in_array('objects', $have, true)) $add[] = 'ADD COLUMN objects MEDIUMTEXT NULL AFTER rules';
            if ($add) $pdo->exec('ALTER TABLE ' . T('matches') . ' ' . implode(', ', $add));
            $pdo->exec('ALTER TABLE ' . T('match_events') . " MODIFY type ENUM('shot','skip','forfeit','turn') NOT NULL");
        }
        if (in_array('names', $need, true)) {                    // aliases
            $have = $cols('users'); $add = [];
            if (!in_array('username_key', $have, true))     $add[] = "ADD COLUMN username_key VARCHAR(24) NOT NULL DEFAULT '' AFTER last_login, ADD KEY ix_username_key (username_key)";
            if (!in_array('alias', $have, true))            $add[] = 'ADD COLUMN alias VARCHAR(20) NULL AFTER username_key';
            if (!in_array('alias_key', $have, true))        $add[] = 'ADD COLUMN alias_key VARCHAR(24) NULL AFTER alias, ADD UNIQUE KEY uq_alias_key (alias_key)';
            if (!in_array('alias_changed_at', $have, true)) $add[] = 'ADD COLUMN alias_changed_at DATETIME NULL AFTER alias_key';
            if ($add) $pdo->exec('ALTER TABLE ' . T('users') . ' ' . implode(', ', $add));
            $upd = $pdo->prepare('UPDATE ' . T('users') . ' SET username_key = ? WHERE id = ?');
            foreach ($pdo->query('SELECT id, username FROM ' . T('users') . " WHERE username_key = ''")->fetchAll() as $r) $upd->execute([name_key($r['username']), $r['id']]);
        }
        if (in_array('items', $need, true)) {                    // phase 2: crates, power-ups, Casual and private matches
            $have = $cols('matches'); $add = [];
            if (!in_array('game_mode', $have, true)) $add[] = "ADD COLUMN game_mode VARCHAR(8) NOT NULL DEFAULT 'ranked' AFTER mode";
            if (!in_array('effects', $have, true))   $add[] = 'ADD COLUMN effects TEXT NULL AFTER objects';
            if ($add) $pdo->exec('ALTER TABLE ' . T('matches') . ' ' . implode(', ', $add));
            if (!in_array('item', $cols('match_players'), true)) $pdo->exec('ALTER TABLE ' . T('match_players') . ' ADD COLUMN item VARCHAR(12) NULL AFTER ammo_cluster');
            $have = $cols('queue'); $add = [];
            if (!in_array('kind', $have, true))     $add[] = "ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'ranked' AFTER mode";
            if (!in_array('map_pref', $have, true)) $add[] = 'ADD COLUMN map_pref VARCHAR(16) NULL AFTER kind';
            if ($add) $pdo->exec('ALTER TABLE ' . T('queue') . ' ' . implode(', ', $add));
            $pdo->exec('ALTER TABLE ' . T('match_events') . " MODIFY type ENUM('shot','skip','forfeit','turn','item') NOT NULL");
            require_once __DIR__ . '/schema.php';
            foreach (schema_sql(cfg('db')['prefix']) as $sql) {   // creates only the tables that are missing
                if (preg_match('/`[^`]*(rooms|room_members|item_log)`/', $sql)) $pdo->exec($sql);
            }
        }
        if (in_array('ai', $need, true) && !in_array('is_bot', $cols('users'), true)) {   // AI opponents
            $pdo->exec('ALTER TABLE ' . T('users') . ' ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER wins');
        }
        if (in_array('errors', $need, true)) {                   // problem log (server errors and browser crash reports)
            require_once __DIR__ . '/schema.php';
            foreach (schema_sql(cfg('db')['prefix']) as $sql) if (str_contains($sql, cfg('db')['prefix'] . 'errors`')) $pdo->exec($sql);
        }
        if (in_array('drive', $need, true)) {                    // phase 3: driving
            if (!in_array('moved', $cols('match_players'), true)) $pdo->exec('ALTER TABLE ' . T('match_players') . ' ADD COLUMN moved FLOAT NOT NULL DEFAULT 0 AFTER kills');
            $pdo->exec('ALTER TABLE ' . T('match_events') . " MODIFY type ENUM('shot','skip','forfeit','turn','item','move') NOT NULL");
        }
        if (in_array('bans', $need, true)) {                     // admins can ban or pause players
            $have = $cols('users'); $add = [];
            if (!in_array('banned_until', $have, true)) $add[] = 'ADD COLUMN banned_until DATETIME NULL';
            if (!in_array('ban_reason', $have, true))   $add[] = 'ADD COLUMN ban_reason VARCHAR(200) NULL';
            if (!in_array('banned_by', $have, true))    $add[] = 'ADD COLUMN banned_by VARCHAR(40) NULL';
            if ($add) $pdo->exec('ALTER TABLE ' . T('users') . ' ' . implode(', ', $add));
        }
        if (in_array('skills', $need, true)) {                   // phase 4: XP, levels and loadouts
            $have = $cols('users'); $add = [];
            if (!in_array('xp', $have, true))           $add[] = 'ADD COLUMN xp INT UNSIGNED NOT NULL DEFAULT 0 AFTER wins';
            if (!in_array('level', $have, true))        $add[] = 'ADD COLUMN level TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER xp';
            if (!in_array('loadout', $have, true))      $add[] = 'ADD COLUMN loadout VARCHAR(255) NULL AFTER level';
            if (!in_array('first_win_on', $have, true)) $add[] = 'ADD COLUMN first_win_on DATE NULL AFTER loadout';
            if ($add) $pdo->exec('ALTER TABLE ' . T('users') . ' ' . implode(', ', $add));
            $have = $cols('match_players'); $add = [];
            if (!in_array('loadout', $have, true))   $add[] = 'ADD COLUMN loadout VARCHAR(255) NULL AFTER item';
            if (!in_array('xp', $have, true))        $add[] = 'ADD COLUMN xp INT NULL AFTER rating_after';
            if (!in_array('xp_detail', $have, true)) $add[] = 'ADD COLUMN xp_detail TEXT NULL AFTER xp';
            if ($add) $pdo->exec('ALTER TABLE ' . T('match_players') . ' ' . implode(', ', $add));
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK($lock)");
    }
}
