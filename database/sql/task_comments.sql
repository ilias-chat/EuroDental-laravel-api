-- Run once in the EuroDental database, before deploying task comments endpoints.
CREATE TABLE `task_comments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `task_comments_task_id_id_index` (`task_id`, `id`),
    CONSTRAINT `task_comments_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `task_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run only after CREATE TABLE succeeds. Skip this SQL file if using artisan migrate.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_30_190000_create_task_comments_table',
       (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_30_190000_create_task_comments_table'
);
