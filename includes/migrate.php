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
              'names' => 'SELECT alias, alias_key, username_key, alias_changed_at FROM ' . T('users') . ' LIMIT 0'] as $k => $probe) {
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
    } finally {
        $pdo->query("SELECT RELEASE_LOCK($lock)");
    }
}
