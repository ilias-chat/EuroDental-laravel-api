-- Run once in the API database, after the original duplicate-review tables exist.
-- Adds audit metadata only; no client or business records are modified.
ALTER TABLE `client_merge_audits`
    ADD COLUMN `review_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `undo_data` JSON NULL,
    ADD COLUMN `undone_by` BIGINT UNSIGNED NULL,
    ADD COLUMN `undone_at` TIMESTAMP NULL DEFAULT NULL,
    ADD INDEX `client_merge_audits_review_id_index` (`review_id`);

-- Register the manual schema change so Laravel does not repeat it later.
-- Execute this statement only after the ALTER TABLE succeeds.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT
    '2026_10_29_230000_add_undo_to_client_merge_audits',
    (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_10_29_230000_add_undo_to_client_merge_audits'
);
