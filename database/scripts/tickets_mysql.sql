-- ============================================================
-- Tickets feature - MySQL / MariaDB
-- Creates tickets, ticket_replies, ticket_attachments tables
-- and adds tickets_create, tickets_manage permissions.
-- Run with: mysql -u your_user -p your_database < tickets_mysql.sql
-- Or execute in phpMyAdmin / MySQL Workbench.
-- ============================================================

-- 1. Create tickets table
CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(255) NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT tickets_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX tickets_user_id_index ON tickets (user_id);
CREATE INDEX tickets_status_index ON tickets (status);

-- 2. Create ticket_replies table
CREATE TABLE IF NOT EXISTS ticket_replies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT ticket_replies_ticket_id_foreign
        FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_replies_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX ticket_replies_ticket_id_index ON ticket_replies (ticket_id);

-- 3. Create ticket_attachments table
CREATE TABLE IF NOT EXISTS ticket_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    ticket_reply_id BIGINT UNSIGNED NULL,
    image_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT ticket_attachments_ticket_id_foreign
        FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_attachments_ticket_reply_id_foreign
        FOREIGN KEY (ticket_reply_id) REFERENCES ticket_replies (id) ON DELETE CASCADE,
    CONSTRAINT ticket_attachments_image_id_foreign
        FOREIGN KEY (image_id) REFERENCES images (id) ON DELETE CASCADE
);

CREATE INDEX ticket_attachments_ticket_id_index ON ticket_attachments (ticket_id);

-- 4. Add ticket permissions (skip if already present)
INSERT IGNORE INTO permissions (code, label, `group`, created_at, updated_at)
VALUES ('tickets_create', 'Créer des tickets', NULL, NOW(), NOW());

INSERT IGNORE INTO permissions (code, label, `group`, created_at, updated_at)
VALUES ('tickets_manage', 'Gérer et répondre à tous les tickets', NULL, NOW(), NOW());

-- ============================================================
-- Rollback (run only to undo the above changes)
-- ============================================================
-- DROP TABLE IF EXISTS ticket_attachments;
-- DROP TABLE IF EXISTS ticket_replies;
-- DROP TABLE IF EXISTS tickets;
-- DELETE FROM permissions WHERE code IN ('tickets_create', 'tickets_manage');
