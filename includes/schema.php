<?php
defined('ART3D') || exit;
/** Returns the CREATE TABLE statements for a given table prefix. */
function schema_sql(string $p): array
{
    $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS `{$p}users` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rating INT NOT NULL DEFAULT 1000,
  games_played INT UNSIGNED NOT NULL DEFAULT 0,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  username_key VARCHAR(24) NOT NULL DEFAULT '',
  alias VARCHAR(20) NULL,
  alias_key VARCHAR(24) NULL,
  alias_changed_at DATETIME NULL,
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_alias_key (alias_key),
  KEY ix_username_key (username_key),
  KEY ix_rating (rating)
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}login_attempts` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  attempted_at INT UNSIGNED NOT NULL,
  KEY ix_ip_time (ip, attempted_at)
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}matches` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mode TINYINT UNSIGNED NOT NULL,
  game_mode VARCHAR(8) NOT NULL DEFAULT 'ranked',
  map_id VARCHAR(16) NULL,
  rules TEXT NULL,
  objects MEDIUMTEXT NULL,
  effects TEXT NULL,
  status ENUM('active','finished') NOT NULL DEFAULT 'active',
  terrain MEDIUMBLOB NOT NULL,
  wind_ang DOUBLE NOT NULL DEFAULT 0,
  wind_spd DOUBLE NOT NULL DEFAULT 0,
  turn_no INT UNSIGNED NOT NULL DEFAULT 1,
  current_seat TINYINT UNSIGNED NOT NULL DEFAULT 0,
  turn_deadline INT UNSIGNED NOT NULL,
  last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  winner_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY ix_status (status)
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}match_players` (
  match_id INT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  hp INT NOT NULL DEFAULT 100,
  alive TINYINT(1) NOT NULL DEFAULT 1,
  x DOUBLE NOT NULL, y DOUBLE NOT NULL, z DOUBLE NOT NULL,
  ammo_heavy TINYINT UNSIGNED NOT NULL DEFAULT 3,
  ammo_cluster TINYINT UNSIGNED NOT NULL DEFAULT 2,
  item VARCHAR(12) NULL,
  strikes TINYINT UNSIGNED NOT NULL DEFAULT 0,
  death_turn INT UNSIGNED NULL,
  last_hit_by TINYINT UNSIGNED NULL,
  placement TINYINT UNSIGNED NULL,
  damage_dealt INT NOT NULL DEFAULT 0,
  kills INT NOT NULL DEFAULT 0,
  rating_before INT NOT NULL,
  rating_after INT NULL,
  last_seen INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (match_id, seat),
  KEY ix_user (user_id),
  FOREIGN KEY (match_id) REFERENCES `{$p}matches`(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES `{$p}users`(id) ON DELETE CASCADE
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}match_events` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL,
  turn_no INT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  type ENUM('shot','skip','forfeit','turn','item') NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_match_id (match_id, id),
  FOREIGN KEY (match_id) REFERENCES `{$p}matches`(id) ON DELETE CASCADE
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}queue` (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  mode TINYINT UNSIGNED NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'ranked',
  map_pref VARCHAR(16) NULL,
  rating INT NOT NULL,
  joined_at INT UNSIGNED NOT NULL,
  last_poll INT UNSIGNED NOT NULL,
  match_id INT UNSIGNED NULL,
  KEY ix_mode (mode, match_id, joined_at),
  FOREIGN KEY (user_id) REFERENCES `{$p}users`(id) ON DELETE CASCADE
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}rooms` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code CHAR(6) NOT NULL,
  host_id INT UNSIGNED NOT NULL,
  settings TEXT NOT NULL,
  status ENUM('open','started','closed') NOT NULL DEFAULT 'open',
  match_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code),
  KEY ix_status (status),
  FOREIGN KEY (host_id) REFERENCES `{$p}users`(id) ON DELETE CASCADE
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}room_members` (
  room_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  joined_at INT UNSIGNED NOT NULL,
  last_poll INT UNSIGNED NOT NULL,
  PRIMARY KEY (room_id, user_id),
  UNIQUE KEY uq_user (user_id),
  FOREIGN KEY (room_id) REFERENCES `{$p}rooms`(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES `{$p}users`(id) ON DELETE CASCADE
) $e",
"CREATE TABLE IF NOT EXISTS `{$p}item_log` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  game_mode VARCHAR(8) NOT NULL,
  turn_no INT UNSIGNED NOT NULL,
  item VARCHAR(12) NOT NULL,
  action ENUM('drop','claim','replaced','destroyed','use') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_item (item, action),
  KEY ix_match (match_id)
) $e",
    ];
}
