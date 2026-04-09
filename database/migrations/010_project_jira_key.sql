-- Add Jira project key and board ID to projects
-- If columns already exist, these will error — handled by init-db.php
ALTER TABLE projects ADD COLUMN jira_project_key VARCHAR(20) NULL;
ALTER TABLE projects ADD COLUMN jira_board_id INT UNSIGNED NULL;
