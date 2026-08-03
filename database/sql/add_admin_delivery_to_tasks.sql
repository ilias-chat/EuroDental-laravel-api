-- Add admin_delivery_amount and admin_delivery_task_id to tasks table
-- Run this only if you have not already run the Laravel migration 2026_03_06_000000_add_admin_delivery_to_tasks_table
-- Use the block that matches your database (PostgreSQL or MySQL).

-- ========== PostgreSQL ==========
ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS admin_delivery_amount DECIMAL(10, 2) NULL,
    ADD COLUMN IF NOT EXISTS admin_delivery_task_id BIGINT NULL;

ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_admin_delivery_task_id_foreign;
ALTER TABLE tasks
    ADD CONSTRAINT tasks_admin_delivery_task_id_foreign
    FOREIGN KEY (admin_delivery_task_id) REFERENCES tasks(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS tasks_admin_delivery_task_id_index ON tasks(admin_delivery_task_id);


-- ========== MySQL / MariaDB ==========
-- Uncomment and run if you use MySQL. Remove AFTER ... if column incomplete_reason does not exist.
-- ALTER TABLE tasks
--     ADD COLUMN admin_delivery_amount DECIMAL(10, 2) NULL,
--     ADD COLUMN admin_delivery_task_id BIGINT UNSIGNED NULL,
--     ADD CONSTRAINT tasks_admin_delivery_task_id_foreign
--         FOREIGN KEY (admin_delivery_task_id) REFERENCES tasks(id) ON DELETE SET NULL;
-- CREATE INDEX tasks_admin_delivery_task_id_index ON tasks(admin_delivery_task_id);
