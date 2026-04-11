-- Migration 034: Closed status for work items & stories; status + ROAM for risks
--
-- 1. Extend status ENUM on hl_work_items and user_stories to include 'closed'
-- 2. Add status ENUM('open','closed') to risks
-- 3. Add roam_status ENUM('resolved','owned','accepted','mitigated') to risks
--
-- MODIFY COLUMN is idempotent when the same definition is applied again.
-- ADD COLUMN is caught by the 1060 duplicate-column skip in init-db.php.

ALTER TABLE hl_work_items
  MODIFY COLUMN status ENUM('backlog','in_progress','in_review','done','closed') NOT NULL DEFAULT 'backlog';

ALTER TABLE user_stories
  MODIFY COLUMN status ENUM('backlog','in_progress','in_review','done','closed') NOT NULL DEFAULT 'backlog';

ALTER TABLE risks
  ADD COLUMN status ENUM('open','closed') NOT NULL DEFAULT 'open' AFTER priority;

ALTER TABLE risks
  ADD COLUMN roam_status ENUM('resolved','owned','accepted','mitigated') NULL AFTER status;
