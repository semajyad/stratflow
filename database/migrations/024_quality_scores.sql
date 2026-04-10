-- Migration 024: Story Quality Phase B
-- Adds quality_score and quality_breakdown columns to work items and stories.
-- Requires migration 023 (kr_hypothesis) to have run first.

ALTER TABLE hl_work_items
  ADD COLUMN quality_score     TINYINT UNSIGNED NULL AFTER kr_hypothesis,
  ADD COLUMN quality_breakdown JSON             NULL AFTER quality_score;

ALTER TABLE user_stories
  ADD COLUMN quality_score     TINYINT UNSIGNED NULL AFTER kr_hypothesis,
  ADD COLUMN quality_breakdown JSON             NULL AFTER quality_score;
