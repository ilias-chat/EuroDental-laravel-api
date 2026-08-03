-- =============================================================================
-- Eurodental — User performance flags (consolidated) — MySQL / MariaDB
-- =============================================================================
-- Covers what Laravel migrations do:
--   2026_04_05_120000_create_user_flag_events_table
--   2026_04_05_120001_add_user_flags_permission
--   2026_04_06_100000_restructure_user_flag_events_retired_on_row
--   2026_04_07_120000_create_user_flag_titles_table
--
-- BEFORE RUNNING: backup your database.
-- If `user_flag_events` already exists WITH column `direction` and `reverse`
-- rows, run `php artisan migrate` for that migration first (data merge), OR
-- merge manually; then run this script for any missing pieces.
--
-- If `permissions` has no `group` column, use the INSERT variant in section 1b.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1a) Permission `user_flags` (with `group` column — Laravel 2025_06_03+)
-- ---------------------------------------------------------------------------
INSERT INTO `permissions` (`code`, `label`, `group`, `created_at`, `updated_at`)
SELECT 'user_flags', 'Gérer les drapeaux performance utilisateur (vert/rouge)', NULL, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `code` = 'user_flags');

-- 1b) Alternative if `permissions.group` does not exist:
-- INSERT INTO `permissions` (`code`, `label`, `created_at`, `updated_at`)
-- SELECT 'user_flags', 'Gérer les drapeaux performance utilisateur (vert/rouge)', NOW(), NOW()
-- FROM DUAL
-- WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `code` = 'user_flags');

-- ---------------------------------------------------------------------------
-- 2) Table `user_flag_titles` + seed rows
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_flag_titles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(16) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_flag_titles_kind_title_unique` (`kind`, `title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `user_flag_titles` (`kind`, `title`, `created_at`, `updated_at`) VALUES
('green', 'Excellence — service client', NOW(), NOW()),
('green', 'Performance remarquable', NOW(), NOW()),
('red', 'Suivi à renforcer', NOW(), NOW()),
('red', 'Incident ou réclamation', NOW(), NOW());

-- ---------------------------------------------------------------------------
-- 3) Table `user_flag_events` — create if missing (final shape, no `direction`)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_flag_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `user_flag_title_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `note` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `retired_at` TIMESTAMP NULL DEFAULT NULL,
  `retired_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `retired_note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_flag_events_user_id_kind_index` (`user_id`, `kind`),
  CONSTRAINT `user_flag_events_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_flag_events_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_flag_events_retired_by_foreign`
    FOREIGN KEY (`retired_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_flag_events_user_flag_title_id_foreign`
    FOREIGN KEY (`user_flag_title_id`) REFERENCES `user_flag_titles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4) Upgrade an *existing* `user_flag_events` created by the old migration
--    (had `direction`; may lack retired_* / user_flag_title_id). Skips if N/A.
--    NOTE: Merging `reverse` into `add` rows is NOT fully duplicated here —
--    prefer `php artisan migrate` for 2026_04_06_100000 once if you still have
--    `direction` + data. After that, you can drop `direction` below.
-- ---------------------------------------------------------------------------

-- 4a) Add `retired_at` if missing
SET @db := DATABASE();
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'retired_at'),
    'SELECT 1',
    'ALTER TABLE `user_flag_events` ADD COLUMN `retired_at` TIMESTAMP NULL DEFAULT NULL AFTER `note`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b) Add `retired_by` if missing
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'retired_by'),
    'SELECT 1',
    'ALTER TABLE `user_flag_events` ADD COLUMN `retired_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `retired_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4c) Add `retired_note` if missing
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'retired_note'),
    'SELECT 1',
    'ALTER TABLE `user_flag_events` ADD COLUMN `retired_note` TEXT NULL AFTER `retired_by`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4d) FK for `retired_by` if column exists but FK missing (name may vary)
SET @fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'user_flag_events'
    AND CONSTRAINT_NAME = 'user_flag_events_retired_by_foreign'
);
SET @sql := IF(@fk = 0 AND EXISTS(
    SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'retired_by'
  ),
  'ALTER TABLE `user_flag_events` ADD CONSTRAINT `user_flag_events_retired_by_foreign` FOREIGN KEY (`retired_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4e) Add `user_flag_title_id` after `kind` if missing
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'user_flag_title_id'),
    'SELECT 1',
    'ALTER TABLE `user_flag_events` ADD COLUMN `user_flag_title_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `kind`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4f) FK for `user_flag_title_id` if missing
SET @fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'user_flag_events'
    AND CONSTRAINT_NAME = 'user_flag_events_user_flag_title_id_foreign'
);
SET @sql := IF(@fk = 0 AND EXISTS(
    SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'user_flag_title_id'
  ),
  'ALTER TABLE `user_flag_events` ADD CONSTRAINT `user_flag_events_user_flag_title_id_foreign` FOREIGN KEY (`user_flag_title_id`) REFERENCES `user_flag_titles` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4g) Drop `direction` only if it still exists (after you merged reverse rows!)
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_flag_events' AND COLUMN_NAME = 'direction'),
    'ALTER TABLE `user_flag_events` DROP COLUMN `direction`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Done. Assign `user_flags` to profiles in the admin UI (profile_permission),
-- or insert into `profile_permission` if you know profile_id / permission_id.
-- =============================================================================

/*
-- ---------------------------------------------------------------------------
-- PostgreSQL (reference only — run separately, not mixed with MySQL above)
-- ---------------------------------------------------------------------------
INSERT INTO permissions (code, label, "group", created_at, updated_at)
SELECT 'user_flags', 'Gérer les drapeaux performance utilisateur (vert/rouge)', NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'user_flags');

CREATE TABLE IF NOT EXISTS user_flag_titles (
  id BIGSERIAL PRIMARY KEY,
  kind VARCHAR(16) NOT NULL,
  title VARCHAR(255) NOT NULL,
  created_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
  CONSTRAINT user_flag_titles_kind_title_unique UNIQUE (kind, title)
);

INSERT INTO user_flag_titles (kind, title, created_at, updated_at) VALUES
('green', 'Excellence — service client', NOW(), NOW()),
('green', 'Performance remarquable', NOW(), NOW()),
('red', 'Suivi à renforcer', NOW(), NOW()),
('red', 'Incident ou réclamation', NOW(), NOW())
ON CONFLICT DO NOTHING;

-- Then alter/create user_flag_events to match Laravel (retired_*, user_flag_title_id, no direction).
*/
