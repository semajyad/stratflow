-- Migration 036: Capability-backed permissions and role-based project memberships

SET @has_account_type := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'account_type'
);

SET @add_account_type_sql := IF(
    @has_account_type = 0,
    "ALTER TABLE users
        ADD COLUMN account_type ENUM('viewer','member','manager','org_admin','superadmin','developer') NULL
        AFTER role",
    'SELECT 1'
);
PREPARE add_account_type_stmt FROM @add_account_type_sql;
EXECUTE add_account_type_stmt;
DEALLOCATE PREPARE add_account_type_stmt;

UPDATE users
SET account_type = CASE role
    WHEN 'viewer' THEN 'viewer'
    WHEN 'user' THEN 'member'
    WHEN 'project_manager' THEN 'manager'
    WHEN 'org_admin' THEN 'org_admin'
    WHEN 'superadmin' THEN 'superadmin'
    WHEN 'developer' THEN 'developer'
    ELSE 'member'
END
WHERE account_type IS NULL;

ALTER TABLE users
    MODIFY COLUMN account_type ENUM('viewer','member','manager','org_admin','superadmin','developer') NOT NULL DEFAULT 'member';

CREATE TABLE IF NOT EXISTS capabilities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_type_capabilities (
    account_type ENUM('viewer','member','manager','org_admin','superadmin','developer') NOT NULL,
    capability_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (account_type, capability_id),
    CONSTRAINT fk_account_type_capability_capability
        FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_capabilities (
    user_id INT UNSIGNED NOT NULL,
    capability_id INT UNSIGNED NOT NULL,
    effect ENUM('grant','deny') NOT NULL DEFAULT 'grant',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, capability_id),
    CONSTRAINT fk_user_capabilities_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_capabilities_capability
        FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_memberships (
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    membership_role ENUM('viewer','editor','project_admin') NOT NULL DEFAULT 'viewer',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id),
    CONSTRAINT fk_project_memberships_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_memberships_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_project_memberships_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO capabilities (`key`, description) VALUES
    ('admin.access', 'Access the organisation administration area'),
    ('workflow.view', 'View workflow content'),
    ('workflow.edit', 'Edit workflow content'),
    ('project.create', 'Create projects'),
    ('project.view_all', 'View all organisation projects'),
    ('project.edit_settings', 'Edit project settings'),
    ('project.manage_access', 'Manage project memberships and access'),
    ('project.delete', 'Delete projects'),
    ('users.manage', 'Manage organisation users'),
    ('teams.manage', 'Manage organisation teams'),
    ('settings.manage', 'Manage organisation settings'),
    ('integrations.manage', 'Manage integrations'),
    ('audit_logs.view', 'View audit logs'),
    ('billing.view', 'View billing'),
    ('billing.manage', 'Manage billing'),
    ('executive.view', 'View executive dashboard'),
    ('tokens.manage_own', 'Manage own personal access tokens'),
    ('api.use_own_tokens', 'Use own personal access tokens'),
    ('superadmin.access', 'Access superadmin areas');

INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
SELECT 'viewer', id FROM capabilities WHERE `key` IN (
    'workflow.view', 'tokens.manage_own', 'api.use_own_tokens'
);

INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
SELECT 'member', id FROM capabilities WHERE `key` IN (
    'workflow.view', 'workflow.edit', 'tokens.manage_own', 'api.use_own_tokens'
);

INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
SELECT 'manager', id FROM capabilities WHERE `key` IN (
    'workflow.view', 'workflow.edit', 'project.create', 'project.edit_settings',
    'project.manage_access', 'tokens.manage_own', 'api.use_own_tokens'
);

INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
SELECT 'org_admin', id FROM capabilities WHERE `key` IN (
    'admin.access', 'workflow.view', 'workflow.edit', 'project.create', 'project.view_all',
    'project.edit_settings', 'project.manage_access', 'project.delete', 'users.manage',
    'teams.manage', 'settings.manage', 'integrations.manage', 'audit_logs.view',
    'billing.view', 'billing.manage', 'tokens.manage_own', 'api.use_own_tokens'
);

INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
SELECT 'developer', id FROM capabilities WHERE `key` IN (
    'tokens.manage_own', 'api.use_own_tokens'
);

INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
SELECT 'superadmin', id FROM capabilities;

INSERT IGNORE INTO user_capabilities (user_id, capability_id, effect)
SELECT u.id, c.id, 'grant'
FROM users u
JOIN capabilities c ON c.`key` IN ('project.create', 'project.view_all', 'project.edit_settings', 'project.manage_access', 'project.delete')
WHERE u.is_project_admin = 1;

INSERT IGNORE INTO user_capabilities (user_id, capability_id, effect)
SELECT u.id, c.id, 'grant'
FROM users u
JOIN capabilities c ON c.`key` IN ('billing.view', 'billing.manage')
WHERE u.has_billing_access = 1;

INSERT IGNORE INTO user_capabilities (user_id, capability_id, effect)
SELECT u.id, c.id, 'grant'
FROM users u
JOIN capabilities c ON c.`key` = 'executive.view'
WHERE u.has_executive_access = 1;

INSERT IGNORE INTO project_memberships (project_id, user_id, membership_role)
SELECT pm.project_id, pm.user_id, 'editor'
FROM project_members pm;
