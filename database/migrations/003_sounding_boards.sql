-- Migration 003: Sounding Boards (Phase 3)

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS persona_panels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  panel_type ENUM('executive','product_management') NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS persona_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  panel_id INT UNSIGNED NOT NULL,
  role_title VARCHAR(255) NOT NULL,
  prompt_description TEXT NOT NULL,
  FOREIGN KEY (panel_id) REFERENCES persona_panels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_results (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  panel_id INT UNSIGNED NOT NULL,
  evaluation_level ENUM('devils_advocate','red_teaming','gordon_ramsay') NOT NULL,
  screen_context VARCHAR(100) NOT NULL,
  results_json JSON NOT NULL,
  status ENUM('pending','accepted','rejected','partial') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (panel_id) REFERENCES persona_panels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subscriptions
  ADD COLUMN has_evaluation_board TINYINT(1) NOT NULL DEFAULT 0;

SET FOREIGN_KEY_CHECKS = 1;
