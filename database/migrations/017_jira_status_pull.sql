-- Migration 017: Jira status pull support
--
-- 1. Add last_jira_sync_at to user_stories and hl_work_items so we can
--    record when each item's status was last pulled from Jira.
-- 2. Extend sync_log.action enum with 'status_pull' for status-only pull events.
-- 3. Add an index on sync_mappings(external_key) for fast webhook lookups.

ALTER TABLE hl_work_items
    ADD COLUMN last_jira_sync_at DATETIME NULL;

ALTER TABLE user_stories
    ADD COLUMN last_jira_sync_at DATETIME NULL;

-- Extend the enum — list ALL values to preserve existing data
ALTER TABLE sync_log
    MODIFY COLUMN action ENUM('create','update','delete','skip','status_pull') NOT NULL;

-- Index on external_key for fast webhook + bulk-pull lookups.
-- Runs unconditionally; MigrationRunner backfill (error 1061) handles existing deployments.
-- 009_jira_integration.sql only has idx_external on (integration_id, external_id),
-- not on external_key, so this is new.
CREATE INDEX idx_external_key ON sync_mappings (external_key);
