-- Migration 037: Add jira_display_name column to users table
-- Caches the Jira user's display name alongside jira_account_id so the UI can
-- show a human-readable label without a live Jira API call.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS jira_display_name VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Cached display name of the linked Jira user'
        AFTER jira_account_id;
