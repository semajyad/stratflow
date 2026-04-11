-- Migration 035: Add owner_user_id to risks table
-- Allows assigning an owner (org user) to a risk, similar to story assignee.

ALTER TABLE risks
    ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER roam_status,
    ADD CONSTRAINT fk_risks_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD KEY idx_risks_owner (owner_user_id);
