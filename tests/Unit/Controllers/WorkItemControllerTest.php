<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\WorkItemController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * WorkItemControllerTest
 *
 * Tests close() and update() on WorkItemController.
 * DB is mocked; PermissionService uses legacy role mode (tableExists = false).
 * org_admin user avoids project-membership DB queries.
 */
class WorkItemControllerTest extends ControllerTestCase
{
    // ===========================
    // FIXTURES
    // ===========================

    private array $orgAdminUser = [
        'id'                  => 1,
        'org_id'              => 10,
        'role'                => 'org_admin',
        'email'               => 'admin@test.invalid',
        'is_active'           => 1,
        'has_billing_access'  => 0,
        'has_executive_access' => 0,
        'is_project_admin'    => 0,
    ];

    private array $projectRow = [
        'id'         => 5,
        'org_id'     => 10,
        'name'       => 'Test Project',
        'visibility' => 'everyone',
    ];

    private array $itemRow = [
        'id'                 => 1,
        'project_id'         => 5,
        'title'              => 'Test Work Item',
        'description'        => 'Some description',
        'status'             => 'backlog',
        'estimated_sprints'  => 2,
        'owner'              => null,
        'team_assigned'      => null,
        'okr_title'          => null,
        'okr_description'    => null,
        'acceptance_criteria' => null,
        'kr_hypothesis'      => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->orgAdminUser);
    }

    // ===========================
    // HELPERS
    // ===========================

    private function makeEmptyStmt(): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        return $stmt;
    }

    private function makeRowStmt(mixed $fetchReturn): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchReturn);
        $stmt->method('fetchAll')->willReturn(is_array($fetchReturn) ? [$fetchReturn] : []);
        return $stmt;
    }

    private function configureDb(\PDOStatement ...$stmts): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(...$stmts);
    }

    // ===========================
    // close()
    // ===========================

    #[Test]
    public function closeFlipsStatusToClosedAndRedirects(): void
    {
        // 1. HLWorkItem::findById, 2. Project::findById (ProjectPolicy), 3. HLWorkItem::update
        $this->configureDb(
            $this->makeRowStmt($this->itemRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->close('1');

        $this->assertStringContainsString('/app/work-items', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('project_id=5', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function closeRedirectsToHomeWhenItemNotFound(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->close('999');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function closeRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->itemRow),
            $this->makeEmptyStmt()  // Project not found
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->close('1');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // update()
    // ===========================

    #[Test]
    public function updatePassesAllowedFieldsAndRedirects(): void
    {
        // 1. HLWorkItem::findById, 2. Project::findById, 3+ update/quality stmts
        $this->configureDb(
            $this->makeRowStmt($this->itemRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),  // HLWorkItem::update
            $this->makeEmptyStmt(),  // HLWorkItem::markQualityPending
            $this->makeEmptyStmt()   // extra update if desc changed
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest([
                'title'             => 'Updated Work Item',
                'description'       => 'Updated description',
                'estimated_sprints' => '3',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('1');

        $this->assertStringContainsString('/app/work-items', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function updateRedirectsToHomeWhenItemNotFound(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makePostRequest(['title' => 'x']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('999');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function updateRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->itemRow),
            $this->makeEmptyStmt()  // Project not found
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(['title' => 'x']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('1');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // index()
    // ===========================

    #[Test]
    public function indexRendersWorkItemsTemplateWhenProjectViewable(): void
    {
        // index() query sequence:
        // 1. Project::findById (ProjectPolicy)
        // 2. HLWorkItem::findByProjectId
        // 3. StrategyDiagram::findByProjectId
        // 4. StoryGitLink::countsByLocalIds
        // 5. KeyResult::findByWorkItemIds
        // 6. Organisation::findById
        // 7. SystemSettings::get
        // 8. Team::findByOrgId
        // 9. Subscription::hasEvaluationBoard
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),  // HLWorkItem::findByProjectId (empty list)
            $this->makeEmptyStmt(),  // StrategyDiagram::findByProjectId
            $this->makeEmptyStmt(),  // StoryGitLink::countsByLocalIds
            $this->makeEmptyStmt(),  // KeyResult::findByWorkItemIds
            $this->makeRowStmt(['id' => 10, 'settings_json' => null]),  // Organisation::findById
            $this->makeEmptyStmt(),  // SystemSettings::get
            $this->makeEmptyStmt(),  // Team::findByOrgId
            $this->makeEmptyStmt()   // Subscription::hasEvaluationBoard
        );

        $ctrl = new WorkItemController(
            $this->makeGetRequest(['project_id' => '5']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->index();

        $this->assertSame('work-items', $this->response->renderedTemplate);
        $this->assertIsArray($this->response->renderedData);
    }

    #[Test]
    public function indexRedirectsToHomeWhenProjectNotViewable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makeGetRequest(['project_id' => '999']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->index();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // store()
    // ===========================

    #[Test]
    public function storeCreatesWorkItemAndRedirects(): void
    {
        // 1. Project::findById (ProjectPolicy), 2. HLWorkItem::findByProjectId (get max priority),
        // 3. HLWorkItem::create
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),  // findByProjectId for priority calc
            $this->makeEmptyStmt()   // create statement
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest([
                'project_id'        => '5',
                'title'             => 'New Work Item',
                'description'       => 'New description',
                'estimated_sprints' => '3',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->store();

        $this->assertStringContainsString('/app/work-items', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('project_id=5', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function storeRedirectsWhenTitleEmpty(): void
    {
        $this->configureDb($this->makeRowStmt($this->projectRow));

        $ctrl = new WorkItemController(
            $this->makePostRequest(['project_id' => '5', 'title' => '']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->store();

        $this->assertStringContainsString('/app/work-items', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function storeRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makePostRequest(['project_id' => '5', 'title' => 'Item']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->store();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // delete()
    // ===========================

    #[Test]
    public function deleteRemovesWorkItemAndRedirects(): void
    {
        // 1. HLWorkItem::findById
        // 2. Project::findById (ProjectPolicy)
        // 3. HLWorkItem::delete
        // 4. HLWorkItem::findByProjectId (for re-numbering)
        $this->configureDb(
            $this->makeRowStmt($this->itemRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),  // delete
            $this->makeEmptyStmt()   // findByProjectId (empty remaining items, so batchUpdatePriority not called)
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->delete('1');

        $this->assertStringContainsString('/app/work-items', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function deleteRedirectsToHomeWhenItemNotFound(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->delete('999');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function deleteRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->itemRow),
            $this->makeEmptyStmt()  // Project not found
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->delete('1');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // generate()
    // ===========================

    #[Test]
    public function generateRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makePostRequest(['project_id' => '999']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->generate();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function generateRedirectsToDiagramWhenDiagramNotFound(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()  // StrategyDiagram::findByProjectId
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(['project_id' => '5']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->generate();

        $this->assertStringContainsString('/app/diagram', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // regenerateSizing()
    // ===========================

    #[Test]
    public function regenerateSizingRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new WorkItemController(
            $this->makePostRequest(['project_id' => '999']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->regenerateSizing();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function regenerateSizingRedirectsWhenNoWorkItems(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()  // HLWorkItem::findByProjectId (empty)
        );

        $ctrl = new WorkItemController(
            $this->makePostRequest(['project_id' => '5']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->regenerateSizing();

        $this->assertStringContainsString('/app/work-items', $this->response->redirectedTo ?? '');
    }
}
