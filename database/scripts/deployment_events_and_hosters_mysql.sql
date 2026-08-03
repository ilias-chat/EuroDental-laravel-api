-- ============================================================
-- Deployment events and hosters - MySQL
-- Run this script to apply the same changes as the Laravel
-- migrations 2026_02_13_100000 and 2026_02_13_100001
-- ============================================================

-- 1. Create deployment_events table
CREATE TABLE IF NOT EXISTS `deployment_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `deployment_id` BIGINT UNSIGNED NOT NULL,
    `event_type` VARCHAR(255) NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `event_time` DATETIME NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    CONSTRAINT `deployment_events_deployment_id_foreign`
        FOREIGN KEY (`deployment_id`) REFERENCES `deployments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `deployment_events_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Index for listing events by deployment (optional; FK often creates one)
-- CREATE INDEX `deployment_events_deployment_id_index` ON `deployment_events` (`deployment_id`);

-- 2. Add hosters column to deployments (after team_member_ids)
ALTER TABLE `deployments`
    ADD COLUMN `hosters` JSON NULL AFTER `team_member_ids`;

-- ============================================================
-- Rollback (run only to undo the above changes)
-- ============================================================
-- DROP TABLE IF EXISTS `deployment_events`;
-- ALTER TABLE `deployments` DROP COLUMN `hosters`;
