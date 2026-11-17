-- Artillery 3D schema (default prefix art_). The web installer (install.php) runs this for you.
-- To use a different prefix, replace every art_ below.

CREATE TABLE IF NOT EXISTS `art_users` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rating INT NOT NULL DEFAULT 1000,
  games_played INT UNSIGNED NOT NULL DEFAULT 0,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  xp INT UNSIGNED NOT NULL DEFAULT 0,
  level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  loadout VARCHAR(255) NULL,
  first_win_on DATE NULL,
  banned_until DATETIME NULL,
  ban_reason VARCHAR(200) NULL,
  banned_by VARCHAR(40) NULL,
  cosmetics VARCHAR(255) NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_login_attempts` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  attempted_at INT UNSIGNED NOT NULL,
  KEY ix_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_matches` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_match_players` (
  match_id INT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  hp INT NOT NULL DEFAULT 100,
  alive TINYINT(1) NOT NULL DEFAULT 1,
  x DOUBLE NOT NULL, y DOUBLE NOT NULL, z DOUBLE NOT NULL,
  ammo_heavy TINYINT UNSIGNED NOT NULL DEFAULT 3,
  ammo_cluster TINYINT UNSIGNED NOT NULL DEFAULT 2,
  item VARCHAR(12) NULL,
  loadout VARCHAR(255) NULL,
  cosmetics VARCHAR(255) NULL,
  strikes TINYINT UNSIGNED NOT NULL DEFAULT 0,
  death_turn INT UNSIGNED NULL,
  last_hit_by TINYINT UNSIGNED NULL,
  placement TINYINT UNSIGNED NULL,
  damage_dealt INT NOT NULL DEFAULT 0,
  kills INT NOT NULL DEFAULT 0,
  moved FLOAT NOT NULL DEFAULT 0,
  rating_before INT NOT NULL,
  rating_after INT NULL,
  xp INT NULL,
  xp_detail TEXT NULL,
  last_seen INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (match_id, seat),
  KEY ix_user (user_id),
  FOREIGN KEY (match_id) REFERENCES `art_matches`(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES `art_users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_match_events` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL,
  turn_no INT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  type ENUM('shot','skip','forfeit','turn','item','move','hold') NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_match_id (match_id, id),
  FOREIGN KEY (match_id) REFERENCES `art_matches`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_queue` (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  mode TINYINT UNSIGNED NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'ranked',
  map_pref VARCHAR(16) NULL,
  rating INT NOT NULL,
  joined_at INT UNSIGNED NOT NULL,
  last_poll INT UNSIGNED NOT NULL,
  match_id INT UNSIGNED NULL,
  KEY ix_mode (mode, match_id, joined_at),
  FOREIGN KEY (user_id) REFERENCES `art_users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_rooms` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code CHAR(6) NOT NULL,
  host_id INT UNSIGNED NOT NULL,
  settings TEXT NOT NULL,
  status ENUM('open','started','closed') NOT NULL DEFAULT 'open',
  match_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code),
  KEY ix_status (status),
  FOREIGN KEY (host_id) REFERENCES `art_users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_room_members` (
  room_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  joined_at INT UNSIGNED NOT NULL,
  last_poll INT UNSIGNED NOT NULL,
  PRIMARY KEY (room_id, user_id),
  UNIQUE KEY uq_user (user_id),
  FOREIGN KEY (room_id) REFERENCES `art_rooms`(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES `art_users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_item_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_errors` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source ENUM('server','client') NOT NULL,
  user_id INT UNSIGNED NULL,
  match_id INT UNSIGNED NULL,
  url VARCHAR(255) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  KEY ix_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 5: co-op missions (the campaign is added the first time the lobby lists missions)
CREATE TABLE IF NOT EXISTS `art_missions` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(24) NOT NULL,
  title VARCHAR(40) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  campaign TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  def MEDIUMTEXT NOT NULL,
  author VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  KEY ix_status (status, campaign, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `art_scenario_progress` (
  user_id INT UNSIGNED NOT NULL,
  mission_id INT UNSIGNED NOT NULL,
  stars TINYINT UNSIGNED NOT NULL DEFAULT 0,
  best_shots INT UNSIGNED NULL,
  best_damage INT UNSIGNED NULL,
  plays INT UNSIGNED NOT NULL DEFAULT 0,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, mission_id),
  FOREIGN KEY (user_id) REFERENCES `art_users`(id) ON DELETE CASCADE,
  FOREIGN KEY (mission_id) REFERENCES `art_missions`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

