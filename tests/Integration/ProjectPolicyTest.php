<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Security\ProjectPolicy;

class ProjectPolicyTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $viewerId;
    private static int $editorId;
    private static int $managerId;
    private static int $openProjectId;
    private static int $restrictedProjectId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(\getTestDbConfig());

        self::$db->query(
            "CREATE TABLE IF NOT EXISTS project_memberships (
                project_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                membership_role ENUM('viewer','editor','project_admin') NOT NULL DEFAULT 'viewer',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (project_id, user_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
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
        self::$db->query("DELETE FROM user_capabilities");
        self::$db->query("DELETE FROM account_type_capabilities");
        self::$db->query("DELETE FROM capabilities");
        self::$db->query(
            "INSERT INTO capabilities (`key`, description) VALUES
                ('workflow.view', 'View workflow content'),
                ('workflow.edit', 'Edit workflow content'),
                ('project.edit_settings', 'Edit settings'),
                ('project.manage_access', 'Manage access'),
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

        self::$db->query(
            "DELETE FROM project_memberships"
        );
        self::$db->query(
            "DELETE pm FROM project_members pm
             JOIN projects p ON p.id = pm.project_id
             JOIN organisations o ON o.id = p.org_id
             WHERE o.name = 'Test Org - ProjectPolicyTest'"
        );
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - ProjectPolicyTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - ProjectPolicyTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - ProjectPolicyTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - ProjectPolicyTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'viewer_projectpolicy@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Viewer User', 'user']
        );
        self::$viewerId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'editor_projectpolicy@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Editor User', 'user']
        );
        self::$editorId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, is_project_admin)
             VALUES (?, ?, ?, ?, ?, ?)",
            [self::$orgId, 'manager_projectpolicy@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Manager User', 'user', 1]
        );
        self::$managerId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by, visibility) VALUES (?, ?, ?, ?)",
            [self::$orgId, 'Open Project - ProjectPolicyTest', self::$managerId, 'everyone']
        );
        self::$openProjectId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by, visibility) VALUES (?, ?, ?, ?)",
            [self::$orgId, 'Restricted Project - ProjectPolicyTest', self::$managerId, 'restricted']
        );
        self::$restrictedProjectId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO project_memberships (project_id, user_id, membership_role) VALUES (?, ?, ?)",
            [self::$restrictedProjectId, self::$editorId, 'editor']
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query(
            "DELETE FROM project_memberships WHERE project_id IN (?, ?)",
            [self::$openProjectId, self::$restrictedProjectId]
        );
        self::$db->query(
            "DELETE pm FROM project_members pm
             JOIN projects p ON p.id = pm.project_id
             WHERE p.org_id = ?",
            [self::$orgId]
        );
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM user_capabilities");
        self::$db->query("DELETE FROM account_type_capabilities");
        self::$db->query("DELETE FROM capabilities");
    }

    #[Test]
    public function viewerCanViewOpenProjectButNotEditIt(): void
    {
        $viewer = [
            'id' => self::$viewerId,
            'org_id' => self::$orgId,
            'role' => 'viewer',
        ];

        $viewable = ProjectPolicy::findViewableProject(self::$db, $viewer, self::$openProjectId);
        $editable = ProjectPolicy::findEditableProject(self::$db, $viewer, self::$openProjectId);

        $this->assertNotNull($viewable);
        $this->assertNull($editable);
    }

    #[Test]
    public function restrictedProjectRequiresMembershipForStandardUsers(): void
    {
        $viewer = [
            'id' => self::$viewerId,
            'org_id' => self::$orgId,
            'role' => 'viewer',
        ];
        $editor = [
            'id' => self::$editorId,
            'org_id' => self::$orgId,
            'role' => 'user',
        ];

        $this->assertNull(ProjectPolicy::findViewableProject(self::$db, $viewer, self::$restrictedProjectId));
        $this->assertNotNull(ProjectPolicy::findViewableProject(self::$db, $editor, self::$restrictedProjectId));
        $this->assertNotNull(ProjectPolicy::findEditableProject(self::$db, $editor, self::$restrictedProjectId));
    }

    #[Test]
    public function projectManagerCanManageRestrictedProjectWithoutMembership(): void
    {
        $manager = [
            'id' => self::$managerId,
            'org_id' => self::$orgId,
            'role' => 'user',
            'is_project_admin' => 1,
        ];

        $manageable = ProjectPolicy::findManageableProject(self::$db, $manager, self::$restrictedProjectId);

        $this->assertNotNull($manageable);
    }
}
