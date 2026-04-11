-- Migration 032: User team membership
--
-- Adds a `team` column to users so developers can be associated with a team.
-- Used by the MCP list_team_stories tool to filter stories by team_assigned.

ALTER TABLE users
  ADD COLUMN team VARCHAR(100) NULL AFTER jira_account_id;
