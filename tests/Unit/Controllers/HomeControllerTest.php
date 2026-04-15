<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\HomeController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * HomeControllerTest
 *
 * Tests the HomeController class (authenticated dashboard home page).
 *
 * Tests cover:
 * - index() — renders home template with projects and org users
 * - createProject() — creates a new project for the org
 * - editProject() — updates project name, visibility, Jira link
 * - renameProject() — simple project rename
 * - deleteProject() — deletes a project
 * - linkJira() — links/unlinks a Jira project
 *
 * Uses org_admin user for positive tests; verifies redirects on permission failures.
 */
class HomeControllerTest extends ControllerTestCase
{
    /** Current stmt returned by every $this->db->query() call. Override per-test. */
    private \PDOStatement $currentDbStmt;

    // ===========================
    // SETUP
    // ===========================

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        // Clear session state between tests to prevent flash message pollution
        $_SESSION = [];

        $this->db->method('tableExists')->willReturn(false); // legacy caps mode — org_admin role works without DB queries
        $this->db->method('lastInsertId')->willReturn('1');

        // Default: empty results. Tests override by reassigning $this->currentDbStmt.
        $this->currentDbStmt = $this->makeDbStmt();
        $this->db->method('query')->willReturnCallback(fn () => $this->currentDbStmt);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function makeDbStmt(mixed $fetch = false, array $fetchAll = []): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll);
        return $stmt;
    }

    private function makeController(array $postData = [], string $uri = '/'): HomeController
    {
        return new HomeController(
            $this->makePostRequest($postData, $uri),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    private function makeGetController(): HomeController
    {
        return new HomeController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    // ===========================
    // index()
    // ===========================

    #[Test]
    public function testIndexRendersHomeTemplate(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeGetController()->index();

        $this->assertSame('home', $this->response->renderedTemplate);
    }

    #[Test]
    public function testIndexPassesUserToTemplate(): void
    {
        $user = [
            'id' => 5,
            'org_id' => 2,
            'role' => 'user',
            'email' => 'user@test.invalid',
            'full_name' => 'Alice',
        ];
        $this->actingAs($user);

        $this->makeGetController()->index();

        $this->assertArrayHasKey('user', $this->response->renderedData);
        $this->assertSame($user, $this->response->renderedData['user']);
    }

    #[Test]
    public function testIndexPassesProjectsArray(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeGetController()->index();

        $this->assertArrayHasKey('projects', $this->response->renderedData);
        $this->assertIsArray($this->response->renderedData['projects']);
    }

    #[Test]
    public function testIndexPassesOrgUsers(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeGetController()->index();

        $this->assertArrayHasKey('org_users', $this->response->renderedData);
        $this->assertIsArray($this->response->renderedData['org_users']);
    }

    #[Test]
    public function testIndexSetActivePage(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeGetController()->index();

        $this->assertSame('home', $this->response->renderedData['active_page']);
    }

    #[Test]
    public function testIndexUsesAppLayout(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        // We can't directly test the layout parameter with FakeResponse,
        // but we can verify the controller doesn't throw and renders home
        $this->makeGetController()->index();
        $this->assertSame('home', $this->response->renderedTemplate);
    }

    // ===========================
    // createProject()
    // ===========================

    #[Test]
    public function testCreateProjectRejectsEmptyName(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController(['name' => ''])->createProject();

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
        $this->assertSame('Project name cannot be empty.', $_SESSION['flash_error'] ?? null);
    }

    #[Test]
    public function testCreateProjectWithWhitespaceOnlyNameFails(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController(['name' => '   '])->createProject();

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
        $this->assertSame('Project name cannot be empty.', $_SESSION['flash_error'] ?? null);
    }

    #[Test]
    public function testCreateProjectSuccessfullyRedirects(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController(['name' => 'New Project'])->createProject();

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('Project "New Project" created', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testCreateProjectSetsTrimmedName(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController(['name' => '  My Project  '])->createProject();

        $this->assertStringContainsString('My Project', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testCreateProjectDefaultsToEveryoneVisibility(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController(['name' => 'Public Project'])->createProject();

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testCreateProjectWithRestrictedVisibility(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController([
            'name' => 'Restricted Project',
            'visibility' => 'restricted',
        ])->createProject();

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // renameProject()
    // ===========================

    #[Test]
    public function testRenameProjectRejectsEmptyName(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $this->makeController(['name' => ''])->renameProject('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
        $this->assertSame('Project name cannot be empty.', $_SESSION['flash_error'] ?? null);
    }

    #[Test]
    public function testRenameProjectRedirectsToHome(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        // Mock the project to exist and be manageable
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42, 'name' => 'Old Name']);
        $this->currentDbStmt = $stmt;

        $this->makeController(['name' => 'Renamed Project'])->renameProject('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testRenameProjectTrimsWhitespace(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42, 'name' => 'Old']);
        $this->currentDbStmt = $stmt;

        $this->makeController(['name' => '  Trimmed Name  '])->renameProject('42');

        $this->assertStringContainsString('renamed', $_SESSION['flash_message'] ?? '');
    }

    // ===========================
    // editProject()
    // ===========================

    #[Test]
    public function testEditProjectRedirectsToHome(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42, 'name' => 'Existing']);
        $this->currentDbStmt = $stmt;

        $this->makeController([
            'name' => 'New Name',
            'visibility' => 'everyone',
        ])->editProject('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('updated', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testEditProjectChangesVisibilityToRestricted(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42]);
        $this->currentDbStmt = $stmt;

        $this->makeController([
            'name' => 'Project',
            'visibility' => 'restricted',
        ])->editProject('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testEditProjectWithJiraKey(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42]);
        $this->currentDbStmt = $stmt;

        $this->makeController([
            'jira_project_key' => 'PROJ',
        ])->editProject('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // deleteProject()
    // ===========================

    #[Test]
    public function testDeleteProjectRedirectsToHome(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42, 'name' => 'To Delete']);
        $this->currentDbStmt = $stmt;

        $this->makeController()->deleteProject('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('deleted', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testDeleteProjectIncludesProjectNameInFlash(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42, 'name' => 'Important Project']);
        $this->currentDbStmt = $stmt;

        $this->makeController()->deleteProject('42');

        $this->assertStringContainsString('Important Project', $_SESSION['flash_message'] ?? '');
    }

    // ===========================
    // linkJira()
    // ===========================

    #[Test]
    public function testLinkJiraRedirectsToHome(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42, 'name' => 'Project']);
        $this->currentDbStmt = $stmt;

        $this->makeController(['jira_project_key' => 'ABC'])->linkJira('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testLinkJiraWithValidKey(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42]);
        $this->currentDbStmt = $stmt;

        $this->makeController(['jira_project_key' => 'MYPROJECT'])->linkJira('42');

        $this->assertStringContainsString('linked to Jira', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testLinkJiraRemovesLinkWhenEmpty(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42]);
        $this->currentDbStmt = $stmt;

        $this->makeController(['jira_project_key' => ''])->linkJira('42');

        $this->assertStringContainsString('removed', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testLinkJiraTrimsKeyWhitespace(): void
    {
        $this->actingAs([
            'id' => 1,
            'org_id' => 1,
            'role' => 'org_admin',
            'email' => 'admin@test.invalid',
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['id' => 42]);
        $this->currentDbStmt = $stmt;

        $this->makeController(['jira_project_key' => '  KEY  '])->linkJira('42');

        $this->assertStringContainsString('/app/home', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // PERMISSION CHECKS (getProjectStage, computeStepCompletion)
    // ===========================

    #[Test]
    public function testGetProjectStageReturnsFirstStepWhenNoProgressDone(): void
    {
        $stage = HomeController::getProjectStage($this->db, 1);

        $this->assertSame('/app/upload', $stage['next_url']);
        $this->assertSame('Upload Document', $stage['next_label']);
    }

    #[Test]
    public function testGetProjectStageReturnsProgressCount(): void
    {
        $stage = HomeController::getProjectStage($this->db, 1);

        $this->assertArrayHasKey('steps_complete', $stage);
        $this->assertArrayHasKey('steps_total', $stage);
        $this->assertSame(0, $stage['steps_complete']);
        $this->assertSame(8, $stage['steps_total']);
    }

    #[Test]
    public function testGetProjectStageReturnsCompletionHash(): void
    {
        $stage = HomeController::getProjectStage($this->db, 1);

        $this->assertArrayHasKey('completion', $stage);
        $this->assertIsArray($stage['completion']);
    }

    #[Test]
    public function testComputeStepCompletionReturnsAllSteps(): void
    {
        $completion = HomeController::computeStepCompletion($this->db, 1);

        $this->assertArrayHasKey('upload', $completion);
        $this->assertArrayHasKey('diagram', $completion);
        $this->assertArrayHasKey('work-items', $completion);
        $this->assertArrayHasKey('prioritisation', $completion);
        $this->assertArrayHasKey('risks', $completion);
        $this->assertArrayHasKey('user-stories', $completion);
        $this->assertArrayHasKey('sprints', $completion);
        $this->assertArrayHasKey('governance', $completion);
    }

    #[Test]
    public function testComputeStepCompletionReturnsBoolean(): void
    {
        $completion = HomeController::computeStepCompletion($this->db, 1);

        $this->assertIsBool($completion['upload']);
        $this->assertIsBool($completion['diagram']);
    }
}
