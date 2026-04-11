<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Security\PermissionService;

class PermissionServiceTest extends TestCase
{
    private static ?Database $db = null;
    private static int $dbOrgId = 0;
    private static int $dbUserId = 0;
    private static int $dbProjectId = 0;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(\getTestDbConfig());

        self::$db->query(
            "CREATE TABLE IF NOT EXISTS capabilities (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$db->query(
            "CREATE TABLE IF NOT EXISTS account_type_capabilities (
                account_type ENUM('viewer','member','manager','org_admin','superadmin','developer') NOT NULL,
                capability_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (account_type, capability_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$db->query(
            "CREATE TABLE IF NOT EXISTS user_capabilities (
                user_id INT UNSIGNED NOT NULL,
                capability_id INT UNSIGNED NOT NULL,
                effect ENUM('grant','deny') NOT NULL DEFAULT 'grant',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, capability_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$db->query(
            "CREATE TABLE IF NOT EXISTS project_memberships (
                project_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                membership_role ENUM('viewer','editor','project_admin') NOT NULL DEFAULT 'viewer',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (project_id, user_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$db->query("DELETE FROM user_capabilities");
        self::$db->query("DELETE FROM account_type_capabilities");
        self::$db->query("DELETE FROM capabilities");

        self::$db->query(
            "INSERT INTO capabilities (`key`, description) VALUES
                ('workflow.view', 'View workflow content'),
                ('workflow.edit', 'Edit workflow content'),
                ('billing.view', 'View billing'),
                ('project.edit_settings', 'Edit settings'),
                ('project.manage_access', 'Manage access'),
                ('project.delete', 'Delete project'),
                ('tokens.manage_own', 'Manage tokens'),
                ('api.use_own_tokens', 'Use own tokens')"
        );
        self::$db->query(
            "INSERT INTO account_type_capabilities (account_type, capability_id)
             SELECT 'viewer', id FROM capabilities WHERE `key` IN ('workflow.view', 'tokens.manage_own', 'api.use_own_tokens')"
        );
        self::$db->query(
            "INSERT INTO account_type_capabilities (account_type, capability_id)
             SELECT 'member', id FROM capabilities WHERE `key` IN ('workflow.view', 'workflow.edit', 'tokens.manage_own', 'api.use_own_tokens')"
        );

        self::$db->query("DELETE FROM project_memberships");
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON o.id = p.org_id
             WHERE o.name = 'Test Org - PermissionServiceTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON o.id = u.org_id
             WHERE o.name = 'Test Org - PermissionServiceTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - PermissionServiceTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES ('Test Org - PermissionServiceTest')"
        );
        self::$dbOrgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$dbOrgId, 'dbcap@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'DB Cap User', 'user']
        );
        self::$dbUserId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by, visibility) VALUES (?, ?, ?, ?)",
            [self::$dbOrgId, 'Permission DB Project', self::$dbUserId, 'restricted']
        );
        self::$dbProjectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }

        self::$db->query("DELETE FROM project_memberships WHERE project_id = ?", [self::$dbProjectId]);
        self::$db->query("DELETE FROM user_capabilities WHERE user_id = ?", [self::$dbUserId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$dbProjectId]);
        self::$db->query("DELETE FROM users WHERE id = ?", [self::$dbUserId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$dbOrgId]);
        self::$db->query("DELETE FROM account_type_capabilities");
        self::$db->query("DELETE FROM capabilities");
    }

    #[Test]
    public function viewerIsReadOnly(): void
    {
        $viewer = ['role' => 'viewer'];

        $this->assertTrue(PermissionService::can($viewer, PermissionService::WORKFLOW_VIEW));
        $this->assertFalse(PermissionService::can($viewer, PermissionService::WORKFLOW_EDIT));
        $this->assertFalse(PermissionService::can($viewer, PermissionService::PROJECT_CREATE));
    }

    #[Test]
    public function userCanEditWorkflowButNotManageProjects(): void
    {
        $user = ['role' => 'user'];

        $this->assertTrue(PermissionService::can($user, PermissionService::WORKFLOW_EDIT));
        $this->assertFalse(PermissionService::can($user, PermissionService::PROJECT_EDIT_SETTINGS));
    }

    #[Test]
    public function projectManagerGetsProjectCapabilities(): void
    {
        $user = ['role' => 'project_manager'];

        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_CREATE));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_MANAGE_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_DELETE));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL));
    }

    #[Test]
    public function orgAdminGetsAdminAccess(): void
    {
        $user = ['role' => 'org_admin'];

        $this->assertTrue(PermissionService::can($user, PermissionService::ADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::USERS_MANAGE));
        $this->assertFalse(PermissionService::can($user, PermissionService::SUPERADMIN_ACCESS));
    }

    #[Test]
    public function developerOnlyGetsTokenAndApiAccess(): void
    {
        $user = ['role' => 'developer'];

        $this->assertTrue(PermissionService::can($user, PermissionService::TOKENS_MANAGE_OWN));
        $this->assertTrue(PermissionService::can($user, PermissionService::API_USE_OWN_TOKENS));
        $this->assertFalse(PermissionService::can($user, PermissionService::WORKFLOW_VIEW));
        $this->assertFalse(PermissionService::can($user, PermissionService::WORKFLOW_EDIT));
    }

    #[Test]
    public function flagsGrantAdditionalCapabilities(): void
    {
        $user = [
            'role' => 'viewer',
            'is_project_admin' => 1,
            'has_billing_access' => 1,
            'has_executive_access' => 1,
        ];

        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_CREATE));
        $this->assertTrue(PermissionService::can($user, PermissionService::BILLING_VIEW));
        $this->assertTrue(PermissionService::canViewExecutive($user));
    }

    #[Test]
    public function superadminGetsWildcardAccess(): void
    {
        $user = ['role' => 'superadmin'];

        $this->assertTrue(PermissionService::can($user, PermissionService::SUPERADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::ADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_DELETE));
    }

    #[Test]
    public function assignableRolesDependOnActor(): void
    {
        $orgAdminRoles = PermissionService::assignableRolesFor(['role' => 'org_admin']);
        $superadminRoles = PermissionService::assignableRolesFor(['role' => 'superadmin']);

        $this->assertNotContains('developer', $orgAdminRoles);
        $this->assertContains('developer', $superadminRoles);
        $this->assertContains('superadmin', $superadminRoles);
    }

    #[Test]
    public function databaseBackedGrantsAndDeniesOverrideDefaults(): void
    {
        self::$db->query("DELETE FROM user_capabilities WHERE user_id = ?", [self::$dbUserId]);
        self::$db->query(
            "INSERT INTO user_capabilities (user_id, capability_id, effect)
             SELECT ?, id, 'grant' FROM capabilities WHERE `key` = 'billing.view'",
            [self::$dbUserId]
        );
        self::$db->query(
            "INSERT INTO user_capabilities (user_id, capability_id, effect)
             SELECT ?, id, 'deny' FROM capabilities WHERE `key` = 'workflow.view'",
            [self::$dbUserId]
        );

        $viewer = [
            'id' => self::$dbUserId,
            'org_id' => self::$dbOrgId,
            'role' => 'viewer',
            'account_type' => 'viewer',
        ];

        $this->assertTrue(PermissionService::can($viewer, PermissionService::BILLING_VIEW, self::$db));
        $this->assertFalse(PermissionService::can($viewer, PermissionService::WORKFLOW_VIEW, self::$db));
    }

    #[Test]
    public function projectMembershipRoleCanGrantProjectScopedEditing(): void
    {
        self::$db->query("DELETE FROM project_memberships WHERE project_id = ?", [self::$dbProjectId]);
        self::$db->query(
            "INSERT INTO project_memberships (project_id, user_id, membership_role) VALUES (?, ?, ?)",
            [self::$dbProjectId, self::$dbUserId, 'editor']
        );

        $viewer = [
            'id' => self::$dbUserId,
            'org_id' => self::$dbOrgId,
            'role' => 'viewer',
            'account_type' => 'viewer',
        ];

        $this->assertFalse(PermissionService::can($viewer, PermissionService::WORKFLOW_EDIT, self::$db));
        $this->assertTrue(PermissionService::can($viewer, PermissionService::WORKFLOW_EDIT, self::$db, self::$dbProjectId));
    }
}
