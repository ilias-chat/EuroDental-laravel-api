-- Add admin_delivery_received_by_user_id to tasks table (who received the payment when finishing a delivery task)
-- Run only if you have not already run the Laravel migration 2026_03_06_100000_add_admin_delivery_received_by_to_tasks_table
-- Use the block that matches your database (PostgreSQL or MySQL).

-- ========== PostgreSQL ==========
ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS admin_delivery_received_by_user_id BIGINT NULL;

ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_admin_delivery_received_by_user_id_foreign;
ALTER TABLE tasks
    ADD CONSTRAINT tasks_admin_delivery_received_by_user_id_foreign
    FOREIGN KEY (admin_delivery_received_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS tasks_admin_delivery_received_by_user_id_index ON tasks(admin_delivery_received_by_user_id);


-- ========== MySQL / MariaDB ==========
-- Uncomment and run if you use MySQL.
-- ALTER TABLE tasks
--     ADD COLUMN admin_delivery_received_by_user_id BIGINT UNSIGNED NULL,
--     ADD CONSTRAINT tasks_admin_delivery_received_by_user_id_foreign
--         FOREIGN KEY (admin_delivery_received_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
-- CREATE INDEX tasks_admin_delivery_received_by_user_id_index ON tasks(admin_delivery_received_by_user_id);
