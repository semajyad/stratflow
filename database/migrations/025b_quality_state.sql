-- Migration 025: Quality Scoring State Machine
-- Adds state columns to both tables so the async worker can track progress,
-- surface errors, and implement exponential-backoff retry.
-- Requires migration 024 (quality_score / quality_breakdown) to have run first.

ALTER TABLE user_stories
  ADD COLUMN quality_status          ENUM('pending','scored','failed','skipped')
                                     NOT NULL DEFAULT 'pending' AFTER quality_breakdown,
  ADD COLUMN quality_scored_at       DATETIME                   NULL AFTER quality_status,
  ADD COLUMN quality_attempts        TINYINT UNSIGNED           NOT NULL DEFAULT 0 AFTER quality_scored_at,
  ADD COLUMN quality_last_attempt_at DATETIME                   NULL AFTER quality_attempts,
  ADD COLUMN quality_error           VARCHAR(500)               NULL AFTER quality_last_attempt_at;

ALTER TABLE hl_work_items
  ADD COLUMN quality_status          ENUM('pending','scored','failed','skipped')
                                     NOT NULL DEFAULT 'pending' AFTER quality_breakdown,
  ADD COLUMN quality_scored_at       DATETIME                   NULL AFTER quality_status,
  ADD COLUMN quality_attempts        TINYINT UNSIGNED           NOT NULL DEFAULT 0 AFTER quality_scored_at,
  ADD COLUMN quality_last_attempt_at DATETIME                   NULL AFTER quality_attempts,
  ADD COLUMN quality_error           VARCHAR(500)               NULL AFTER quality_last_attempt_at;

-- Worker index: the hot path is filtering by status + last_attempt_at for backoff.
CREATE INDEX idx_user_stories_quality_work
  ON user_stories (quality_status, quality_last_attempt_at);

CREATE INDEX idx_hl_work_items_quality_work
  ON hl_work_items (quality_status, quality_last_attempt_at);

-- Backfill: promote existing data to reflect real state.
UPDATE user_stories SET quality_status = 'scored' WHERE quality_score IS NOT NULL;
UPDATE user_stories SET quality_status = 'pending' WHERE quality_score IS NULL;

UPDATE hl_work_items SET quality_status = 'scored' WHERE quality_score IS NOT NULL;
UPDATE hl_work_items SET quality_status = 'pending' WHERE quality_score IS NULL;
