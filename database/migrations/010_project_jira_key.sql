SET @col1 = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'jira_project_key');
SET @sql1 = IF(@col1 = 0, 'ALTER TABLE projects ADD COLUMN jira_project_key VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @col2 = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'jira_board_id');
SET @sql2 = IF(@col2 = 0, 'ALTER TABLE projects ADD COLUMN jira_board_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
