-- Run ONCE in the shared EuroDental database before deploying task following.
-- Skip this SQL file if using the Laravel migration instead.
CREATE TABLE `task_followers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `task_followers_task_id_user_id_unique` (`task_id`, `user_id`),
    KEY `task_followers_user_id_foreign` (`user_id`),
    CONSTRAINT `task_followers_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `task_followers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run only after CREATE TABLE succeeds.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_30_200000_create_task_followers_table',
       (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_30_200000_create_task_followers_table'
);
