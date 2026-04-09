-- Migration 018: Git Links
-- Creates the story_git_links table for tracking PR/commit/branch references
-- against user stories and high-level work items.

CREATE TABLE IF NOT EXISTS story_git_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    local_type ENUM('user_story','hl_work_item') NOT NULL,
    local_id INT UNSIGNED NOT NULL,
    provider ENUM('github','gitlab','manual') NOT NULL,
    ref_type ENUM('pr','commit','branch') NOT NULL,
    ref_url VARCHAR(512) NOT NULL,
    ref_label VARCHAR(255) NULL,
    status ENUM('open','merged','closed','unknown') NOT NULL DEFAULT 'unknown',
    author VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_link (local_type, local_id, ref_url),
    KEY idx_local (local_type, local_id),
    KEY idx_ref_url (ref_url)
);
