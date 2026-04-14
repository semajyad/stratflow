<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\RiskController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * RiskControllerTest
 *
 * Tests for close(), setRoam(), update(), store(), and index() on RiskController.
 * DB is mocked; PermissionService falls back to legacy role mode (tableExists = false).
 *
 * Using org_admin user so ProjectPolicy::findEditableProject needs only:
 *   1. Project::findById DB query (one fetch)
 *   2. No project-membership DB queries (org_admin has PROJECT_VIEW_ALL in legacy caps)
 */
class RiskControllerTest extends ControllerTestCase
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

    private array $riskRow = [
        'id'            => 1,
        'project_id'    => 5,
        'title'         => 'Test Risk',
        'likelihood'    => 3,
        'impact'        => 3,
        'status'        => 'open',
        'roam_status'   => null,
        'owner_user_id' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Legacy capability mode: PermissionService won't make extra DB queries
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

    /** Configure DB to return sequential stmts for consecutive query() calls */
    private function configureDb(\PDOStatement ...$stmts): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(...$stmts);
    }

    private function makeController(array $post = []): RiskController
    {
        return new RiskController(
            $this->makePostRequest($post),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    private function makeGetController(array $query = []): RiskController
    {
        return new RiskController(
            $this->makeGetRequest($query),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    // ===========================
    // close()
    // ===========================

    #[Test]
    public function closeRedirectsToRisksPageOnSuccess(): void
    {
        // 1. Risk::findById, 2. Project::findById (ProjectPolicy), 3. Risk::update
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()
        );

        $this->makeController(['project_id' => '5'])->close('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('project_id=5', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function closeRedirectsEarlyWhenRiskNotFound(): void
    {
        // Risk::findById returns nothing; risk ID in request mismatches project_id → early redirect
        $this->configureDb($this->makeEmptyStmt());

        $this->makeController(['project_id' => '5'])->close('999');

        // Risk not found OR project_id mismatch → redirect back (not to /app/home)
        $this->assertNotNull($this->response->redirectedTo);
    }

    #[Test]
    public function closeRedirectsToHomeWhenProjectNotEditable(): void
    {
        // Risk found, but project not found (returns false)
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeEmptyStmt()   // Project::findById returns false
        );

        $this->makeController(['project_id' => '5'])->close('1');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // setRoam()
    // ===========================

    #[Test]
    public function setRoamAcceptsValidRoamStatusAndRedirects(): void
    {
        // 1. Project::findById (ProjectPolicy), 2. Risk::update
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()
        );

        $ctrl = new RiskController(
            $this->makePostRequest(['project_id' => '5', 'roam_status' => 'resolved']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->setRoam('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function setRoamSkipsUpdateForInvalidRoamValue(): void
    {
        // Project found, but invalid roam_status should redirect without calling update
        $this->configureDb(
            $this->makeRowStmt($this->projectRow)
        );

        $ctrl = new RiskController(
            $this->makePostRequest(['project_id' => '5', 'roam_status' => 'not_a_roam']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->setRoam('1');

        // Still redirects (no error thrown)
        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function setRoamRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new RiskController(
            $this->makePostRequest(['project_id' => '5', 'roam_status' => 'owned']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->setRoam('1');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // update()
    // ===========================

    #[Test]
    public function updateAcceptsValidRoamStatusAndRedirects(): void
    {
        // 1. Risk::findById, 2. Project::findById, 3. Risk::update, 4. RiskItemLink::deleteByRiskId
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),
            $this->makeEmptyStmt()
        );

        $ctrl = new RiskController(
            $this->makePostRequest([
                'title'       => 'Updated Risk',
                'likelihood'  => '3',
                'impact'      => '3',
                'roam_status' => 'mitigated',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function updateDoesNotErrorOnInvalidRoamStatus(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),
            $this->makeEmptyStmt()
        );

        $ctrl = new RiskController(
            $this->makePostRequest([
                'title'       => 'Risk Title',
                'likelihood'  => '2',
                'impact'      => '2',
                'roam_status' => 'bad_value',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function updateAcceptsOwnerUserIdAndRedirects(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt(),
            $this->makeEmptyStmt()
        );

        $ctrl = new RiskController(
            $this->makePostRequest([
                'title'         => 'Risk Title',
                'likelihood'    => '3',
                'impact'        => '3',
                'owner_user_id' => '7',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function updateRedirectsToHomeWhenRiskNotFound(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new RiskController(
            $this->makePostRequest(['title' => 'x', 'likelihood' => '1', 'impact' => '1']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('999');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function updateRedirectsToRisksOnEmptyTitle(): void
    {
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeRowStmt($this->projectRow)
        );

        $ctrl = new RiskController(
            $this->makePostRequest(['title' => '', 'likelihood' => '3', 'impact' => '3']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->update('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // store()
    // ===========================

    #[Test]
    public function storeCreatesRiskAndRedirects(): void
    {
        // 1. Project::findById, 2. Risk INSERT (no fetchAll needed)
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()
        );
        $this->db->method('lastInsertId')->willReturn('42');

        $ctrl = new RiskController(
            $this->makePostRequest([
                'project_id' => '5',
                'title'      => 'New Risk',
                'likelihood' => '2',
                'impact'     => '4',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->store();

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function storeRedirectsWhenTitleIsEmpty(): void
    {
        $this->configureDb($this->makeRowStmt($this->projectRow));

        $ctrl = new RiskController(
            $this->makePostRequest(['project_id' => '5', 'title' => '']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->store();

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function storeRedirectsToHomeWhenProjectNotEditable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $ctrl = new RiskController(
            $this->makePostRequest(['project_id' => '5', 'title' => 'Risk']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->store();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // index()
    // ===========================

    #[Test]
    public function indexRendersRisksTemplateWithOrgUsers(): void
    {
        // index() query sequence: Project::findById, Risk::findByProjectId, HLWorkItem::findByProjectId,
        // User::findByOrgId, Subscription::hasEvaluationBoard
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),             // Project
            $this->makeEmptyStmt(),                             // Risks list (empty)
            $this->makeEmptyStmt(),                             // Work items list (empty)
            $this->makeEmptyStmt(),                             // Org users list (empty)
            $this->makeEmptyStmt()                              // Subscription::hasEvaluationBoard → false
        );

        $this->makeGetController(['project_id' => '5'])->index();

        $this->assertSame('risks', $this->response->renderedTemplate);
        $this->assertArrayHasKey('org_users', $this->response->renderedData);
    }

    #[Test]
    public function indexRedirectsToHomeWhenProjectNotViewable(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $this->makeGetController(['project_id' => '999'])->index();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // generate()
    // ===========================

    #[Test]
    public function generateRedirectsWhenNoWorkItemsExist(): void
    {
        // 1. Project::findById, 2. HLWorkItem::findByProjectId (empty)
        $this->configureDb(
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()  // No work items
        );

        $this->makeController(['project_id' => '5'])->generate();

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    #[Test]
    public function generateRedirectsToHomeWhenProjectNotEditable(): void
    {
        // Project not found
        $this->configureDb($this->makeEmptyStmt());

        $this->makeController(['project_id' => '5'])->generate();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // delete()
    // ===========================

    #[Test]
    public function deleteRemovesRiskAndRedirects(): void
    {
        // 1. Risk::findById, 2. Project::findById (ProjectPolicy), 3. Risk::delete
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeRowStmt($this->projectRow),
            $this->makeEmptyStmt()
        );

        $this->makeController(['project_id' => '5'])->delete('1');

        $this->assertStringContainsString('/app/risks', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('project_id=5', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function deleteRedirectsToHomeWhenRiskNotFound(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $this->makeController(['project_id' => '5'])->delete('999');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function deleteRedirectsToHomeWhenProjectNotEditable(): void
    {
        // Risk found, but project not editable
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeEmptyStmt()
        );

        $this->makeController(['project_id' => '5'])->delete('1');

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // generateMitigation()
    // ===========================

    #[Test]
    public function generateMitigationReturnsErrorWhenRiskNotFound(): void
    {
        $this->configureDb($this->makeEmptyStmt());

        $this->makeController([])->generateMitigation('999');

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
        $this->assertSame(404, $this->response->jsonStatus);
    }

    #[Test]
    public function generateMitigationReturnsErrorWhenProjectNotEditable(): void
    {
        // Risk found, but project not editable
        $this->configureDb(
            $this->makeRowStmt($this->riskRow),
            $this->makeEmptyStmt()
        );

        $this->makeController([])->generateMitigation('1');

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
        $this->assertSame(403, $this->response->jsonStatus);
    }
}
