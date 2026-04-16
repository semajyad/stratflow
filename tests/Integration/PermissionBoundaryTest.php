<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Core\Session;
use StratFlow\Controllers\WorkItemController;
use StratFlow\Models\HLWorkItem;

/**
 * PermissionBoundaryTest
 *
 * Verifies that permission enforcement blocks operations at the controller
 * level and that the DB row is unchanged after a blocked attempt.
 * Checking only the HTTP redirect is insufficient — these tests inspect DB
 * state directly after each controller call.
 *
 * Pattern:
 *   1. Record original DB value
 *   2. Invoke controller action as the restricted actor
 *   3. Assert DB value is unchanged
 */
class PermissionBoundaryTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private Database $db;
    private int $orgId    = 0;
    private int $orgBId   = 0;
    private int $ownerId  = 0;
    private int $viewerId = 0;
    private int $orgBUserId = 0;
    private int $orgAdminId = 0;
    private int $projectId  = 0;

    protected function setUp(): void
    {
        $this->db = new Database(getTestDbConfig());

        // Scrub stale data — FK-safe: delete children before parent
        $this->db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON o.id = p.org_id
             WHERE o.name LIKE 'PermBoundaryTest%'"
        );
        $this->db->query(
            "DELETE u FROM users u
             JOIN organisations o ON o.id = u.org_id
             WHERE o.name LIKE 'PermBoundaryTest%'"
        );
        $this->db->query("DELETE FROM organisations WHERE name LIKE 'PermBoundaryTest%'");

        // Ensure the capabilities tables have the minimum rows PermissionService
        // needs. PermissionService::supportsDatabaseBackedCapabilities() returns
        // true when all three tables exist — so if they're empty it returns no
        // capabilities for any account_type, breaking permission checks.
        // We insert the required capabilities using INSERT IGNORE so concurrent
        // test suites (PermissionServiceTest etc.) are not disrupted.
        $this->db->query(
            "INSERT IGNORE INTO capabilities (`key`, description) VALUES
             ('workflow.view',  'View workflow'),
             ('workflow.edit',  'Edit workflow'),
             ('project.view_all', 'View all projects'),
             ('project.create', 'Create project'),
             ('project.edit_settings', 'Edit project settings'),
             ('project.manage_access', 'Manage project access'),
             ('project.delete', 'Delete project'),
             ('admin.access',   'Admin access'),
             ('users.manage',   'Manage users'),
             ('teams.manage',   'Manage teams'),
             ('settings.manage','Manage settings'),
             ('integrations.manage','Manage integrations'),
             ('audit_logs.view','View audit logs'),
             ('tokens.manage_own','Manage own tokens'),
             ('api.use_own_tokens','Use own API tokens')"
        );
        // Seed org_admin capabilities (the role used in testOrgAdminCanEditAnyProjectInOrg)
        $this->db->query(
            "INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
             SELECT 'org_admin', id FROM capabilities
             WHERE `key` IN (
                 'workflow.view','workflow.edit','project.view_all','project.create',
                 'project.edit_settings','project.manage_access','project.delete',
                 'admin.access','users.manage','teams.manage','settings.manage',
                 'integrations.manage','audit_logs.view','tokens.manage_own','api.use_own_tokens'
             )"
        );
        // Seed viewer capabilities (viewer role used in blocking tests)
        $this->db->query(
            "INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
             SELECT 'viewer', id FROM capabilities
             WHERE `key` IN ('workflow.view','tokens.manage_own','api.use_own_tokens')"
        );
        // Seed member capabilities
        $this->db->query(
            "INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
             SELECT 'member', id FROM capabilities
             WHERE `key` IN ('workflow.view','workflow.edit','tokens.manage_own','api.use_own_tokens')"
        );

        // Org A
        $this->db->query("INSERT INTO organisations (name) VALUES (?)", ['PermBoundaryTest Org A']);
        $this->orgId = (int) $this->db->lastInsertId();

        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, account_type)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->orgId, 'pb_owner_' . $this->orgId . '@test.invalid',
             password_hash('pass', PASSWORD_DEFAULT), 'Owner', 'user', 'member']
        );
        $this->ownerId = (int) $this->db->lastInsertId();

        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, account_type)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->orgId, 'pb_viewer_' . $this->orgId . '@test.invalid',
             password_hash('pass', PASSWORD_DEFAULT), 'Viewer', 'viewer', 'viewer']
        );
        $this->viewerId = (int) $this->db->lastInsertId();

        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, account_type)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->orgId, 'pb_admin_' . $this->orgId . '@test.invalid',
             password_hash('pass', PASSWORD_DEFAULT), 'OrgAdmin', 'org_admin', 'org_admin']
        );
        $this->orgAdminId = (int) $this->db->lastInsertId();

        $this->db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [$this->orgId, 'PB Project', $this->ownerId]
        );
        $this->projectId = (int) $this->db->lastInsertId();

        // Org B
        $this->db->query("INSERT INTO organisations (name) VALUES (?)", ['PermBoundaryTest Org B']);
        $this->orgBId = (int) $this->db->lastInsertId();

        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, account_type)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->orgBId, 'pb_orgb_' . $this->orgBId . '@test.invalid',
             password_hash('pass', PASSWORD_DEFAULT), 'Org B User', 'user', 'member']
        );
        $this->orgBUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM project_memberships WHERE project_id = ?", [$this->projectId]);
        $this->db->query("DELETE FROM hl_work_items WHERE project_id = ?", [$this->projectId]);
        $this->db->query("DELETE FROM projects WHERE org_id IN (?, ?)", [$this->orgId, $this->orgBId]);
        $this->db->query("DELETE FROM users WHERE org_id IN (?, ?)", [$this->orgId, $this->orgBId]);
        $this->db->query("DELETE FROM organisations WHERE id IN (?, ?)", [$this->orgId, $this->orgBId]);
    }

    // ===========================
    // HELPERS
    // ===========================

    /** Insert a work item and return its ID. */
    private function insertWorkItem(string $title = 'Original Title'): int
    {
        $this->db->query(
            "INSERT INTO hl_work_items (project_id, priority_number, title) VALUES (?, ?, ?)",
            [$this->projectId, 1, $title]
        );
        return (int) $this->db->lastInsertId();
    }

    /** Fetch the title of a work item directly from DB. */
    private function fetchTitle(int $itemId): string
    {
        $row = $this->db->query(
            "SELECT title FROM hl_work_items WHERE id = ?",
            [$itemId]
        )->fetch();
        return (string) ($row['title'] ?? '');
    }

    /**
     * Build a WorkItemController with a mocked Auth returning the given user,
     * and POST data set to attempt a title change.
     *
     * @param array       $actorUser  User array returned by Auth::user()
     * @param int         $itemId     Work item to update
     * @param string      $newTitle   Title value sent in the POST body
     * @param string|null &$redirectedTo  Captures redirect target
     */
    private function makeController(
        array $actorUser,
        int $itemId,
        string $newTitle,
        ?string &$redirectedTo
    ): WorkItemController {
        // Mock Auth to return our actor without touching the real session
        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['user'])
            ->getMock();
        $auth->method('user')->willReturn($actorUser);

        // Mock Response to capture redirects without sending headers.
        // render() and json() are void — stub them explicitly as no-ops to
        // avoid PHPUnit 11 deprecation warnings about unmocked void methods.
        $response = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect', 'render', 'json'])
            ->getMock();
        $response->method('redirect')
            ->willReturnCallback(function (string $url) use (&$redirectedTo): void {
                $redirectedTo = $url;
            });
        $response->method('render')->willReturnCallback(function (): void {});
        $response->method('json')->willReturnCallback(function (): void {});

        // Mock Request to return the new title as POST data.
        // We cannot use ->method('method') shorthand because 'method' is itself
        // in onlyMethods — doing so would call the mocked method rather than
        // configure it. Use expects($this->any()) to stay explicit.
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post', 'get', 'method'])
            ->getMock();
        $request->expects($this->any())->method('post')
            ->willReturnCallback(function (string $key, $default = null) use ($newTitle) {
                return $key === 'title' ? $newTitle : $default;
            });
        $request->expects($this->any())->method('get')->willReturn(null);
        $request->expects($this->any())->method('method')->willReturn('POST');

        return new WorkItemController($request, $response, $auth, $this->db, []);
    }

    // ===========================
    // TESTS
    // ===========================

    /**
     * A viewer-role user POSTs an update to a work item.
     * ProjectPolicy::findEditableProject() checks WORKFLOW_EDIT, which viewers
     * do not have. The controller must redirect and the DB title must be unchanged.
     */
    #[Test]
    public function testViewerRoleCannotEditWorkItem(): void
    {
        $itemId = $this->insertWorkItem('Original Title');
        $originalTitle = $this->fetchTitle($itemId);

        $redirectedTo = null;
        $viewer = [
            'id'           => $this->viewerId,
            'org_id'       => $this->orgId,
            'role'         => 'viewer',
            'account_type' => 'viewer',
            'is_project_admin'   => 0,
            'has_billing_access' => 0,
            'has_executive_access' => 0,
        ];

        $ctrl = $this->makeController($viewer, $itemId, 'Hacked Title', $redirectedTo);
        $ctrl->update($itemId);

        $this->assertSame(
            $originalTitle,
            $this->fetchTitle($itemId),
            'DB title must be unchanged after viewer attempts update'
        );
        $this->assertNotNull($redirectedTo, 'Controller must redirect when access is denied');
    }

    /**
     * User from Org B attempts to update a work item owned by Org A.
     * Project::findById() scopes by org_id, so findEditableProject() returns
     * null and the controller redirects without writing.
     */
    #[Test]
    public function testCrossOrgAccessBlocked(): void
    {
        $itemId = $this->insertWorkItem('Original Title');
        $originalTitle = $this->fetchTitle($itemId);

        $redirectedTo = null;
        $orgBUser = [
            'id'           => $this->orgBUserId,
            'org_id'       => $this->orgBId,   // Wrong org — different from item's project
            'role'         => 'user',
            'account_type' => 'member',
            'is_project_admin'    => 0,
            'has_billing_access'  => 0,
            'has_executive_access' => 0,
        ];

        $ctrl = $this->makeController($orgBUser, $itemId, 'Cross-Org Attack', $redirectedTo);
        $ctrl->update($itemId);

        $this->assertSame(
            $originalTitle,
            $this->fetchTitle($itemId),
            'DB title must be unchanged after cross-org update attempt'
        );
        $this->assertNotNull($redirectedTo, 'Controller must redirect when org boundary is crossed');
    }

    /**
     * A project with visibility='restricted' requires the user to have a
     * project_memberships row. A user with no membership must be blocked.
     */
    #[Test]
    public function testProjectMembershipRequiredForRestrictedProjects(): void
    {
        // Make the project restricted
        $this->db->query(
            "UPDATE projects SET visibility = 'restricted' WHERE id = ?",
            [$this->projectId]
        );

        $itemId = $this->insertWorkItem('Restricted Item');
        $originalTitle = $this->fetchTitle($itemId);

        $redirectedTo = null;

        // Regular member with no project_memberships row
        $nonMember = [
            'id'           => $this->ownerId,
            'org_id'       => $this->orgId,
            'role'         => 'user',
            'account_type' => 'member',
            'is_project_admin'    => 0,
            'has_billing_access'  => 0,
            'has_executive_access' => 0,
        ];

        // Ensure no membership row exists
        $this->db->query(
            "DELETE FROM project_memberships WHERE project_id = ? AND user_id = ?",
            [$this->projectId, $this->ownerId]
        );

        $ctrl = $this->makeController($nonMember, $itemId, 'Forced Update', $redirectedTo);
        $ctrl->update($itemId);

        $this->assertSame(
            $originalTitle,
            $this->fetchTitle($itemId),
            'DB title must be unchanged when user has no project membership on restricted project'
        );
        $this->assertNotNull($redirectedTo, 'Controller must redirect non-member from restricted project');

        // Restore visibility
        $this->db->query(
            "UPDATE projects SET visibility = 'everyone' WHERE id = ?",
            [$this->projectId]
        );
    }

    /**
     * An org_admin has WORKFLOW_EDIT globally. They must be able to update any
     * work item in their organisation and the DB change must be committed.
     */
    #[Test]
    public function testOrgAdminCanEditAnyProjectInOrg(): void
    {
        $itemId = $this->insertWorkItem('Before Admin Edit');

        $redirectedTo = null;
        $orgAdmin = [
            'id'           => $this->orgAdminId,
            'org_id'       => $this->orgId,
            'role'         => 'org_admin',
            'account_type' => 'org_admin',
            'is_project_admin'    => 0,
            'has_billing_access'  => 0,
            'has_executive_access' => 0,
        ];

        $ctrl = $this->makeController($orgAdmin, $itemId, 'Admin Updated Title', $redirectedTo);

        // The controller writes the title first, then calls markQualityPending().
        // On deployments without the quality_status column, markQualityPending()
        // throws a PDOException. We catch that so we can still assert on the DB
        // state — the title update is committed before the exception fires.
        try {
            $ctrl->update($itemId);
        } catch (\PDOException $e) {
            // Tolerate missing quality_status column on older deployments.
            if (!str_contains($e->getMessage(), 'quality_status')) {
                throw $e;
            }
        }

        $this->assertSame(
            'Admin Updated Title',
            $this->fetchTitle($itemId),
            'DB title must be updated when org_admin performs the edit'
        );
    }

    /**
     * Setting is_active = 0 on a user row must cause Auth::check() to return
     * false, because the check query filters on u.is_active = 1.
     */
    #[Test]
    public function testInactiveUserBlockedByAuth(): void
    {
        // Mark the viewer inactive at the DB level
        $this->db->query(
            "UPDATE users SET is_active = 0 WHERE id = ?",
            [$this->viewerId]
        );

        // Build a Session that returns the viewer's session data
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set', 'destroy'])
            ->getMock();

        $sessionUser = [
            'id'     => $this->viewerId,
            'org_id' => $this->orgId,
        ];

        $session->method('get')
            ->willReturnCallback(function (string $key) use ($sessionUser) {
                return $key === 'user' ? $sessionUser : null;
            });
        $session->method('destroy')->willReturnCallback(function (): void {});

        $auth = new Auth($session, $this->db);

        $this->assertFalse(
            $auth->check(),
            'Auth::check() must return false for an inactive user (is_active = 0)'
        );

        // Restore
        $this->db->query("UPDATE users SET is_active = 1 WHERE id = ?", [$this->viewerId]);
    }

    /**
     * After a password is changed in the DB (password_changed_at updated),
     * Auth::check() still returns true for an existing session because the
     * current implementation does not compare password_changed_at to the
     * session issue time.
     *
     * This test documents the current behaviour. If session invalidation on
     * password change is implemented (e.g. storing issued_at in session and
     * comparing to password_changed_at), update this test accordingly.
     */
    #[Test]
    public function testSessionInvalidationAfterPasswordChange(): void
    {
        // Record the pre-change state
        $before = $this->db->query(
            "SELECT password_changed_at FROM users WHERE id = ?",
            [$this->viewerId]
        )->fetch();

        // Simulate a password change
        $this->db->query(
            "UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?",
            [password_hash('new_password', PASSWORD_DEFAULT), $this->viewerId]
        );

        $after = $this->db->query(
            "SELECT password_changed_at FROM users WHERE id = ?",
            [$this->viewerId]
        )->fetch();

        // Confirm password_changed_at was updated in the DB
        $this->assertNotNull(
            $after['password_changed_at'],
            'password_changed_at must be set after a password change'
        );

        // Build an Auth instance with the viewer's existing session
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set', 'destroy'])
            ->getMock();

        $sessionUser = [
            'id'     => $this->viewerId,
            'org_id' => $this->orgId,
        ];
        $session->method('get')
            ->willReturnCallback(function (string $key) use ($sessionUser) {
                return $key === 'user' ? $sessionUser : null;
            });
        $session->method('destroy')->willReturnCallback(function (): void {});

        $auth = new Auth($session, $this->db);

        // Current implementation: check() only validates is_active — it does
        // NOT invalidate sessions based on password_changed_at.
        // This assertion documents the current behaviour.
        // Change to assertFalse() once session invalidation is implemented.
        $this->assertTrue(
            $auth->check(),
            'Current implementation: session is NOT invalidated on password change. ' .
            'Update to assertFalse() once invalidation-on-password-change is implemented.'
        );
    }
}
