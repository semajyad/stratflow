-- Migration 030: User story assignee
--
-- Adds assignee_user_id to user_stories so stories can be assigned to a
-- specific team member. Used by the MCP API's "mine=1" filter and by the
-- UI's story assignment dropdown.
--
-- ON DELETE SET NULL: deleting a user unassigns their stories rather than
-- cascading a delete.

ALTER TABLE user_stories
  ADD COLUMN assignee_user_id BIGINT UNSIGNED NULL AFTER team_assigned,
  ADD CONSTRAINT fk_user_stories_assignee
    FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD KEY ix_user_stories_assignee (assignee_user_id);
