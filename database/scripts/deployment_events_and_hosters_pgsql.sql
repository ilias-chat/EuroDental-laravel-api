-- ============================================================
-- Deployment events and hosters - PostgreSQL
-- Run this script to apply the same changes as the Laravel
-- migrations 2026_02_13_100000 and 2026_02_13_100001
-- ============================================================

-- 1. Create deployment_events table
CREATE TABLE IF NOT EXISTS deployment_events (
    id BIGSERIAL PRIMARY KEY,
    deployment_id BIGINT NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    user_id BIGINT NULL,
    event_time TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT deployment_events_deployment_id_foreign
        FOREIGN KEY (deployment_id) REFERENCES deployments (id) ON DELETE CASCADE,
    CONSTRAINT deployment_events_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
);

-- Index for listing events by deployment
CREATE INDEX IF NOT EXISTS deployment_events_deployment_id_index ON deployment_events (deployment_id);

-- 2. Add hosters column to deployments (after team_member_ids)
-- PostgreSQL: ADD COLUMN does not support AFTER; column order is logical only
ALTER TABLE deployments
    ADD COLUMN IF NOT EXISTS hosters JSONB NULL;

-- Optional: comment to document usage
COMMENT ON COLUMN deployments.hosters IS 'Array of user IDs (hosters already in the deployment city), stored as JSON array';

-- ============================================================
-- Rollback (run only to undo the above changes)
-- ============================================================
-- DROP TABLE IF EXISTS deployment_events;
-- ALTER TABLE deployments DROP COLUMN IF EXISTS hosters;
