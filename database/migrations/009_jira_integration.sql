SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    provider ENUM('jira','azure_devops') NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    cloud_id VARCHAR(255) NULL,
    access_token TEXT NULL,
    refresh_token TEXT NULL,
    token_expires_at DATETIME NULL,
    site_url VARCHAR(500) NULL,
    config_json JSON NULL,
    status ENUM('active','paused','error','disconnected') NOT NULL DEFAULT 'disconnected',
    last_sync_at DATETIME NULL,
    error_message TEXT NULL,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE,
    INDEX idx_org_provider (org_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id INT UNSIGNED NOT NULL,
    local_type ENUM('hl_work_item','user_story','sprint') NOT NULL,
    local_id INT UNSIGNED NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    external_key VARCHAR(100) NULL,
    external_url VARCHAR(500) NULL,
    sync_hash VARCHAR(64) NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mapping (integration_id, local_type, local_id),
    INDEX idx_external (integration_id, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id INT UNSIGNED NOT NULL,
    direction ENUM('push','pull') NOT NULL,
    action ENUM('create','update','delete','skip') NOT NULL,
    local_type VARCHAR(50) NULL,
    local_id INT UNSIGNED NULL,
    external_id VARCHAR(255) NULL,
    details_json JSON NULL,
    status ENUM('success','error') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE,
    INDEX idx_integration_time (integration_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
