-- Migration 033: Team assignment on work items
--
-- Adds team_assigned to hl_work_items so PMs assign work to a team at the
-- epic level. User stories inherit the team from their parent work item,
-- removing the need to assign team on each story individually.

ALTER TABLE hl_work_items
  ADD COLUMN team_assigned VARCHAR(100) NULL AFTER owner;
