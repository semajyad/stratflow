-- Link teams to Jira boards and sprints to teams
ALTER TABLE teams ADD COLUMN jira_board_id INT UNSIGNED NULL;
ALTER TABLE sprints ADD COLUMN team_id INT UNSIGNED NULL;
ALTER TABLE sprints ADD CONSTRAINT fk_sprints_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL;
