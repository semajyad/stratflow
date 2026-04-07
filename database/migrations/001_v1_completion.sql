-- Migration 001: V1 Completion (Items 6-9)
-- Run: docker compose exec mysql mysql -ustratflow -pstratflow_secret stratflow < /var/www/html/database/migrations/001_v1_completion.sql

SET FOREIGN_KEY_CHECKS = 0;

-- Item 6: Prioritisation columns on hl_work_items
ALTER TABLE hl_work_items
  ADD COLUMN rice_reach INT UNSIGNED NULL,
  ADD COLUMN rice_impact INT UNSIGNED NULL,
  ADD COLUMN rice_confidence INT UNSIGNED NULL,
  ADD COLUMN rice_effort INT UNSIGNED NULL,
  ADD COLUMN wsjf_business_value INT UNSIGNED NULL,
  ADD COLUMN wsjf_time_criticality INT UNSIGNED NULL,
  ADD COLUMN wsjf_risk_reduction INT UNSIGNED NULL,
  ADD COLUMN wsjf_job_size INT UNSIGNED NULL,
  ADD COLUMN final_score DECIMAL(10,2) NULL;

ALTER TABLE projects
  ADD COLUMN selected_framework ENUM('rice','wsjf') NULL;

-- Item 7: Risks
CREATE TABLE IF NOT EXISTS risks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  likelihood TINYINT UNSIGNED NOT NULL DEFAULT 3,
  impact TINYINT UNSIGNED NOT NULL DEFAULT 3,
  mitigation TEXT NULL,
  priority DECIMAL(5,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS risk_item_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  risk_id INT UNSIGNED NOT NULL,
  work_item_id INT UNSIGNED NOT NULL,
  FOREIGN KEY (risk_id) REFERENCES risks(id) ON DELETE CASCADE,
  FOREIGN KEY (work_item_id) REFERENCES hl_work_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_risk_item (risk_id, work_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item 8: User Stories
CREATE TABLE IF NOT EXISTS user_stories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  parent_hl_item_id INT UNSIGNED NULL,
  priority_number INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  parent_link VARCHAR(255) NULL,
  team_assigned VARCHAR(255) NULL,
  size INT UNSIGNED NULL,
  blocked_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_hl_item_id) REFERENCES hl_work_items(id) ON DELETE SET NULL,
  FOREIGN KEY (blocked_by) REFERENCES user_stories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item 9: Sprints
CREATE TABLE IF NOT EXISTS sprints (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  team_capacity INT UNSIGNED NULL,
  status ENUM('planning','active','completed') NOT NULL DEFAULT 'planning',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sprint_stories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sprint_id INT UNSIGNED NOT NULL,
  user_story_id INT UNSIGNED NOT NULL,
  FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
  FOREIGN KEY (user_story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
  UNIQUE KEY uq_sprint_story (sprint_id, user_story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
