-- Migration 028: Per-project visibility and member access control

ALTER TABLE projects
    ADD COLUMN visibility ENUM('everyone','restricted') NOT NULL DEFAULT 'everyone'
    AFTER jira_project_key;

CREATE TABLE IF NOT EXISTS project_members (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_user (project_id, user_id),
  KEY idx_project (project_id),
  KEY idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
