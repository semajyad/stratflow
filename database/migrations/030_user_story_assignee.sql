-- Migration 030: User story assignee
--
-- Adds assignee_user_id to user_stories so stories can be assigned to a
-- specific team member. Used by the MCP API's "mine=1" filter and by the
-- UI's story assignment dropdown.
--
-- Split into separate statements so init-db.php can skip each one
-- independently on re-runs (duplicate column / duplicate key are caught).

ALTER TABLE user_stories
  ADD COLUMN assignee_user_id INT UNSIGNED NULL AFTER team_assigned;

ALTER TABLE user_stories
  ADD KEY ix_user_stories_assignee (assignee_user_id);

ALTER TABLE user_stories
  ADD CONSTRAINT fk_user_stories_assignee
    FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL;
