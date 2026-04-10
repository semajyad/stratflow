-- Migration 031: Developer role + Jira account ID
--
-- 1. Adds 'developer' to the users.role ENUM.
--    Developer accounts: can log in and create PATs, but are NOT counted
--    against the org's seat limit and cannot access workflow pages.
--    Intended for engineers who interact with StratFlow only via the MCP server.
--
-- 2. Adds jira_account_id to users for bidirectional Jira user mapping.
--    Used when syncing Jira users as developer accounts, and when pushing
--    story assignments to Jira (maps StratFlow assignee → Jira accountId).

ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'user', 'org_admin', 'superadmin', 'developer'
  ) NOT NULL DEFAULT 'user';

ALTER TABLE users
  ADD COLUMN jira_account_id VARCHAR(255) NULL AFTER role,
  ADD KEY ix_users_jira_account (jira_account_id);
