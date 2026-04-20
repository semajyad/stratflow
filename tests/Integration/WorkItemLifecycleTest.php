<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Controllers\WorkItemController;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Tests\Support\FakeRequest;
use StratFlow\Tests\Support\FakeResponse;

/**
 * WorkItemLifecycleTest
 *
 * Integration tests for the full work item lifecycle via WorkItemController
 * against the real Docker MySQL database. Tests verify DB state after each
 * controller action — not just response codes.
 */
class WorkItemLifecycleTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $orgIdB;
    private static int $userId;
    private static int $userIdB;
    private static int $projectId;
    private static int $projectIdB;

    /** Minimal config array required by controller (no live keys needed for DB tests). */
    private static array $config = [
        'app'    => ['url' => 'http://localhost', 'debug' => false],
        'stripe' => [
            'secret_key'      => 'sk_test_xxx',
            'publishable_key' => 'pk_test_xxx',
            'webhook_secret'  => 'whsec_xxx',
            'price_product'   => 'price_xxx',
        ],
        'jira'   => [],
    ];

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());
        self::seedPermissionCapabilities();

        self::seedCapabilityFixtures();

        // Clean up any leftovers from a previous failed run (FK-safe order)
        foreach (['Test Org - WorkItemLifecycleTest A', 'Test Org - WorkItemLifecycleTest B'] as $orgName) {
            self::$db->query(
                "DELETE p FROM projects p JOIN organisations o ON p.org_id = o.id WHERE o.name = ?",
                [$orgName]
            );
            self::$db->query(
                "DELETE u FROM users u JOIN organisations o ON u.org_id = o.id WHERE o.name = ?",
                [$orgName]
            );
            self::$db->query("DELETE FROM organisations WHERE name = ?", [$orgName]);
        }

        // Org A — the test actor's organisation
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - WorkItemLifecycleTest A']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, account_type) VALUES (?, ?, ?, ?, ?, ?)",
            [self::$orgId, 'wil_actor@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Actor User', 'org_admin', 'org_admin']
        );
        self::$userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - WorkItemLifecycleTest A', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Org B — a separate organisation (for org isolation tests)
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - WorkItemLifecycleTest B']);
        self::$orgIdB = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role, account_type) VALUES (?, ?, ?, ?, ?, ?)",
            [self::$orgIdB, 'wil_other@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Other User', 'org_admin', 'org_admin']
        );
        self::$userIdB = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgIdB, 'Test Project - WorkItemLifecycleTest B', self::$userIdB]
        );
        self::$projectIdB = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe: work items are CASCADE-deleted with projects
        self::$db->query("DELETE FROM projects WHERE org_id IN (?, ?)", [self::$orgId, self::$orgIdB]);
        self::$db->query("DELETE FROM users WHERE org_id IN (?, ?)", [self::$orgId, self::$orgIdB]);
        self::$db->query("DELETE FROM organisations WHERE id IN (?, ?)", [self::$orgId, self::$orgIdB]);
    }

    protected function tearDown(): void
    {
        // Remove all work items from both test projects after each test
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
        HLWorkItem::deleteByProjectId(self::$db, self::$projectIdB);
        // Clear any session flash state set during the test to avoid bleed
        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_warning']);
    }

    private static function seedPermissionCapabilities(): void
    {
        self::$db->query(
            "INSERT IGNORE INTO capabilities (`key`, description) VALUES
             ('workflow.view', 'View workflow'),
             ('workflow.edit', 'Edit workflow'),
             ('project.view_all', 'View all projects')"
        );
        self::$db->query(
            "INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
             SELECT 'org_admin', id FROM capabilities
             WHERE `key` IN ('workflow.view', 'workflow.edit', 'project.view_all')"
        );
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build a controller for the test actor (Org A user).
     */
    private function makeController(FakeRequest $request, FakeResponse $response): WorkItemController
    {
        $auth = $this->createMock(Auth::class);
        $auth->method('check')->willReturn(true);
        $auth->method('user')->willReturn([
            'id'                  => self::$userId,
            'org_id'              => self::$orgId,
            'role'                => 'org_admin',
            'account_type'        => 'org_admin',
            'is_active'           => 1,
            'has_billing_access'  => 0,
            'has_executive_access'=> 0,
            'is_project_admin'    => 0,
        ]);
        $auth->method('orgId')->willReturn(self::$orgId);

        return new WorkItemController($request, $response, $auth, self::$db, self::$config);
    }

    /**
     * Build a controller for an Org B user (used in isolation tests).
     */
    private function makeControllerOrgB(FakeRequest $request, FakeResponse $response): WorkItemController
    {
        $auth = $this->createMock(Auth::class);
        $auth->method('check')->willReturn(true);
        $auth->method('user')->willReturn([
            'id'                  => self::$userIdB,
            'org_id'              => self::$orgIdB,
            'role'                => 'org_admin',
            'account_type'        => 'org_admin',
            'is_active'           => 1,
            'has_billing_access'  => 0,
            'has_executive_access'=> 0,
            'is_project_admin'    => 0,
        ]);
        $auth->method('orgId')->willReturn(self::$orgIdB);

        return new WorkItemController($request, $response, $auth, self::$db, self::$config);
    }

    private static function seedCapabilityFixtures(): void
    {
        self::$db->query(
            "INSERT IGNORE INTO capabilities (`key`, description) VALUES
             ('workflow.view', 'View workflow'),
             ('workflow.edit', 'Edit workflow'),
             ('project.view_all', 'View all projects'),
             ('project.create', 'Create project'),
             ('project.edit_settings', 'Edit project settings'),
             ('project.manage_access', 'Manage project access'),
             ('project.delete', 'Delete project'),
             ('admin.access', 'Admin access'),
             ('users.manage', 'Manage users'),
             ('teams.manage', 'Manage teams'),
             ('settings.manage', 'Manage settings'),
             ('integrations.manage', 'Manage integrations'),
             ('audit_logs.view', 'View audit logs'),
             ('tokens.manage_own', 'Manage own tokens'),
             ('api.use_own_tokens', 'Use own API tokens')"
        );

        self::$db->query(
            "INSERT IGNORE INTO account_type_capabilities (account_type, capability_id)
             SELECT 'org_admin', id FROM capabilities
             WHERE `key` IN (
                 'workflow.view','workflow.edit','project.view_all','project.create',
                 'project.edit_settings','project.manage_access','project.delete',
                 'admin.access','users.manage','teams.manage','settings.manage',
                 'integrations.manage','audit_logs.view','tokens.manage_own','api.use_own_tokens'
             )"
        );
    }

    // ===========================
    // TESTS
    // ===========================

    #[Test]
    public function testStoreCreatesWorkItemInDatabase(): void
    {
        $request = new FakeRequest('POST', '/', [
            'project_id' => self::$projectId,
            'title'      => 'Integration Lifecycle Item',
            'status'     => 'backlog',
        ]);
        $response = new FakeResponse();

        $this->makeController($request, $response)->store();

        $stmt = self::$db->query(
            "SELECT * FROM hl_work_items WHERE project_id = ? AND title = ?",
            [self::$projectId, 'Integration Lifecycle Item']
        );
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'Work item row should exist in hl_work_items after store()');
        $this->assertSame('Integration Lifecycle Item', $row['title']);
        $this->assertSame('/app/work-items?project_id=' . self::$projectId, $response->redirectedTo);
    }

    #[Test]
    public function testStoreRequiresTitleAndRejectsEmpty(): void
    {
        $request = new FakeRequest('POST', '/', [
            'project_id' => self::$projectId,
            'title'      => '',
        ]);
        $response = new FakeResponse();

        $this->makeController($request, $response)->store();

        $stmt = self::$db->query(
            "SELECT COUNT(*) AS cnt FROM hl_work_items WHERE project_id = ?",
            [self::$projectId]
        );
        $count = (int) $stmt->fetch()['cnt'];

        $this->assertSame(0, $count, 'No row should be inserted when title is empty');
        $this->assertStringContainsString('/app/work-items', $response->redirectedTo ?? '');
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testUpdatePersistsFieldsToDatabase(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Before Update',
            'description'     => 'Original description',
        ]);

        $request = new FakeRequest('POST', '/', [
            'title'       => 'After Update',
            'description' => 'Revised description',
        ]);
        $response = new FakeResponse();

        $this->makeController($request, $response)->update($id);

        $row = HLWorkItem::findById(self::$db, $id);
        $this->assertSame('After Update', $row['title']);
        $this->assertSame('Revised description', $row['description']);
    }

    #[Test]
    public function testCloseChangesStatusInDatabase(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Item To Close',
        ]);

        $request  = new FakeRequest('POST', '/');
        $response = new FakeResponse();

        $this->makeController($request, $response)->close($id);

        $row = HLWorkItem::findById(self::$db, $id);
        $this->assertSame('closed', $row['status'], 'Status should be "closed" after close() action');
    }

    #[Test]
    public function testDeleteRemovesRowFromDatabase(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Item To Delete',
        ]);

        // Verify it exists before deletion
        $this->assertNotNull(HLWorkItem::findById(self::$db, $id));

        $request  = new FakeRequest('POST', '/');
        $response = new FakeResponse();

        $this->makeController($request, $response)->delete($id);

        $this->assertNull(HLWorkItem::findById(self::$db, $id), 'Row should be gone after delete()');
    }

    #[Test]
    public function testReorderUpdatesPositionInDatabase(): void
    {
        $idA = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Reorder Item A',
        ]);
        $idB = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Reorder Item B',
        ]);

        // Swap positions: A→2, B→1
        $body    = json_encode(['order' => [['id' => $idA, 'position' => 2], ['id' => $idB, 'position' => 1]]]);
        $request = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], $body);
        $response = new FakeResponse();

        $this->makeController($request, $response)->reorder();

        $rowA = HLWorkItem::findById(self::$db, $idA);
        $rowB = HLWorkItem::findById(self::$db, $idB);

        $this->assertSame(2, (int) $rowA['priority_number'], 'Item A should now be at position 2');
        $this->assertSame(1, (int) $rowB['priority_number'], 'Item B should now be at position 1');
        $this->assertSame(['status' => 'ok'], $response->jsonPayload);
    }

    #[Test]
    public function testUnauthorisedUserCannotModifyAnotherOrgsWorkItem(): void
    {
        // Create a work item owned by Org B
        $idB = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectIdB,
            'priority_number' => 1,
            'title'           => 'Org B Sensitive Item',
        ]);

        // Org A user attempts to close Org B's work item
        $request  = new FakeRequest('POST', '/');
        $response = new FakeResponse();

        $this->makeController($request, $response)->close($idB);

        // The item should NOT be closed — policy should redirect away
        $row = HLWorkItem::findById(self::$db, $idB);
        $this->assertNotSame('closed', $row['status'] ?? null, 'Org A should not be able to close Org B work item');
        $this->assertStringContainsString('/app/home', $response->redirectedTo ?? '', 'Should redirect to /app/home on access denial');

        // Also verify delete is blocked
        $responseD = new FakeResponse();
        $this->makeController($request, $responseD)->delete($idB);
        $this->assertNotNull(HLWorkItem::findById(self::$db, $idB), 'Org B item should survive delete attempt by Org A');
    }
}
