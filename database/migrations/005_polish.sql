-- Migration 005: Phase 5 Polish

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS hl_item_dependencies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  depends_on_id INT UNSIGNED NOT NULL,
  dependency_type ENUM('hard','soft') NOT NULL DEFAULT 'hard',
  FOREIGN KEY (item_id) REFERENCES hl_work_items(id) ON DELETE CASCADE,
  FOREIGN KEY (depends_on_id) REFERENCES hl_work_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_dependency (item_id, depends_on_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
