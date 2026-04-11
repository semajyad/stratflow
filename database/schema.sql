SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) NOT NULL PRIMARY KEY,
    data MEDIUMBLOB NOT NULL,
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organisations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    stripe_customer_id VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    settings_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('viewer','user','project_manager','org_admin','superadmin','developer') NOT NULL DEFAULT 'user',
    jira_account_id    VARCHAR(255) NULL,
    has_billing_access TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL,
    plan_type ENUM('product','consultancy') NOT NULL,
    status ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    user_seat_limit INT UNSIGNED NOT NULL DEFAULT 5,
    has_evaluation_board TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
    selected_framework ENUM('rice','wsjf') NULL,
    created_by INT UNSIGNED NOT NULL,
    jira_project_key VARCHAR(20) NULL,
    jira_board_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    extracted_text LONGTEXT NULL,
    ai_summary TEXT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS strategy_diagrams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    mermaid_code TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagram_nodes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    diagram_id INT UNSIGNED NOT NULL,
    node_key VARCHAR(100) NOT NULL,
    label VARCHAR(255) NOT NULL,
    okr_title VARCHAR(255) NULL,
    okr_description TEXT NULL,
    FOREIGN KEY (diagram_id) REFERENCES strategy_diagrams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hl_work_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    diagram_id INT UNSIGNED NULL,
    priority_number INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    strategic_context TEXT NULL,
    okr_title VARCHAR(255) NULL,
    okr_description TEXT NULL,
    owner VARCHAR(255) NULL,
    estimated_sprints INT UNSIGNED NOT NULL DEFAULT 2,
    rice_reach INT UNSIGNED NULL,
    rice_impact INT UNSIGNED NULL,
    rice_confidence INT UNSIGNED NULL,
    rice_effort INT UNSIGNED NULL,
    wsjf_business_value INT UNSIGNED NULL,
    wsjf_time_criticality INT UNSIGNED NULL,
    wsjf_risk_reduction INT UNSIGNED NULL,
    wsjf_job_size INT UNSIGNED NULL,
    final_score DECIMAL(10,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    requires_review TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('backlog','in_progress','in_review','done','closed') NOT NULL DEFAULT 'backlog',
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (diagram_id) REFERENCES strategy_diagrams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 5: Work item dependencies
CREATE TABLE IF NOT EXISTS hl_item_dependencies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    depends_on_id INT UNSIGNED NOT NULL,
    dependency_type ENUM('hard','soft') NOT NULL DEFAULT 'hard',
    FOREIGN KEY (item_id) REFERENCES hl_work_items(id) ON DELETE CASCADE,
    FOREIGN KEY (depends_on_id) REFERENCES hl_work_items(id) ON DELETE CASCADE,
    UNIQUE KEY uq_dependency (item_id, depends_on_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item 7: Risk tracking
CREATE TABLE IF NOT EXISTS risks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    likelihood TINYINT UNSIGNED NOT NULL DEFAULT 3,
    impact TINYINT UNSIGNED NOT NULL DEFAULT 3,
    mitigation TEXT NULL,
    priority DECIMAL(5,2) NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    roam_status ENUM('resolved','owned','accepted','mitigated') NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_risks_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_risks_owner (owner_user_id)
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
    team_assigned       VARCHAR(255)    NULL,
    assignee_user_id    INT UNSIGNED NULL,
    size INT UNSIGNED NULL,
    blocked_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    requires_review TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('backlog','in_progress','in_review','done','closed') NOT NULL DEFAULT 'backlog',
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_hl_item_id) REFERENCES hl_work_items(id) ON DELETE SET NULL,
    FOREIGN KEY (blocked_by) REFERENCES user_stories(id) ON DELETE SET NULL,
    FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item 9: Sprint planning
CREATE TABLE IF NOT EXISTS sprints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    team_capacity INT UNSIGNED NULL,
    team_id INT UNSIGNED NULL,
    status ENUM('planning','active','completed') NOT NULL DEFAULT 'planning',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sprint_stories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sprint_id INT UNSIGNED NOT NULL,
    user_story_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
    FOREIGN KEY (user_story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_sprint_story (sprint_id, user_story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 2: Admin Features
CREATE TABLE IF NOT EXISTS teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    capacity INT UNSIGNED NULL,
    jira_board_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_team_user (team_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 3: Sounding Boards
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

-- Phase 4: Strategic Drift Engine
CREATE TABLE IF NOT EXISTS strategic_baselines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    snapshot_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drift_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    alert_type ENUM('scope_creep','capacity_tripwire','dependency_tripwire','alignment') NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    details_json JSON NOT NULL,
    status ENUM('active','acknowledged','resolved') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS governance_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    change_type ENUM('new_story','scope_change','size_change','dependency_change') NOT NULL,
    proposed_change_json JSON NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password tokens for email-based password set/reset flows
CREATE TABLE IF NOT EXISTS password_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    type ENUM('set_password', 'reset_password') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security hardening: audit logs (HIPAA / SOC 2 / PCI-DSS)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NULL,
    details_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security hardening: generic rate limiting
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key VARCHAR(50) NOT NULL,
    identifier VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key_id_time (rate_key, identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jira / Azure DevOps integrations
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
    local_type ENUM('hl_work_item','user_story','sprint','risk') NOT NULL,
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
