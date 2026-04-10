-- Add status tracking for Jira sync + sync metadata
ALTER TABLE hl_work_items ADD COLUMN status ENUM('backlog','in_progress','in_review','done') NOT NULL DEFAULT 'backlog';
ALTER TABLE user_stories ADD COLUMN status ENUM('backlog','in_progress','in_review','done') NOT NULL DEFAULT 'backlog';

-- Token encryption support
ALTER TABLE integrations ADD COLUMN token_iv VARCHAR(64) NULL;
ALTER TABLE integrations ADD COLUMN token_tag VARCHAR(64) NULL;

