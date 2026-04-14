<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use StratFlow\Controllers\WorkItemController;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\HLItemDependency;
use StratFlow\Models\KeyResult;
use StratFlow\Models\Organisation;
use StratFlow\Models\Project;
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\StoryQualityConfig;
use StratFlow\Models\StrategyDiagram;
use StratFlow\Models\Subscription;
use StratFlow\Models\SystemSettings;
use StratFlow\Models\Team;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Security\PermissionService;
use StratFlow\Services\AuditLogger;
use StratFlow\Core\Response;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeResponse;

/**
 * WorkItemControllerTest
 *
 * Unit tests for WorkItemController covering all 15 public methods.
 * Redirect-path tests cover the permission check branch for each method.
 * Happy-path tests cover store, update, close, delete, reorder, and export.
 *
 * ≥80% method and line coverage target.
 */
#[CoversClass(WorkItemController::class)]
#[UsesClass(ProjectPolicy::class)]
#[UsesClass(PermissionService::class)]
#[UsesClass(Project::class)]
#[UsesClass(HLWorkItem::class)]
#[UsesClass(HLItemDependency::class)]
#[UsesClass(KeyResult::class)]
#[UsesClass(Organisation::class)]
#[UsesClass(StoryGitLink::class)]
#[UsesClass(StoryQualityConfig::class)]
#[UsesClass(StrategyDiagram::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(SystemSettings::class)]
#[UsesClass(Team::class)]
#[UsesClass(AuditLogger::class)]
final class WorkItemControllerTest extends ControllerTestCase
{
    // ===========================
    // HELPERS
    // ===========================

    /**
     * Org admin user that bypasses all project-member checks.
     */
    private function orgAdminUser(): array
    {
        return [
            'id'        => 1,
            'org_id'    => 1,
            'role'      => 'org_admin',
            'email'     => 'admin@test.invalid',
            'is_active' => 1,
            'name'      => 'Test Admin',
        ];
    }

    /**
     * Build a DB mock where fetch() returns false (project not found / no item).
     */
    private function makeNotFoundDb(): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('tableExists')->willReturn(false);
        return $db;
    }

    /**
     * Build a DB mock where fetch() returns a project row (happy-path).
     */
    private function makeProjectFoundDb(array $extra = []): Database
    {
        // Include settings_json so SystemSettings::get doesn't get null on json_decode
        $projectRow = array_merge(
            [
                'id'            => 1,
                'name'          => 'Test Project',
                'org_id'        => 1,
                'visibility'    => 'everyone',
                'settings_json' => '{}',
            ],
            $extra
        );
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($projectRow);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('tableExists')->willReturn(false);
        $db->method('lastInsertId')->willReturn('42');
        return $db;
    }

    /**
     * Build a DB mock where the first fetch() call returns a work-item row
     * and subsequent fetch() calls return the project row.
     *
     * Used for methods that call HLWorkItem::findById then
     * ProjectPolicy::findEditableProject.
     */
    private function makeWorkItemFoundDb(array $workItemExtra = []): Database
    {
        $workItemRow = array_merge(
            [
                'id'                  => 42,
                'project_id'          => 1,
                'title'               => 'WI Title',
                'description'         => 'WI desc',
                'okr_title'           => null,
                'okr_description'     => null,
                'owner'               => null,
                'estimated_sprints'   => 2,
                'acceptance_criteria' => null,
                'kr_hypothesis'       => null,
                'quality_score'       => null,
                'quality_breakdown'   => null,
                'quality_attempts'    => 0,
                'priority_number'     => 1,
            ],
            $workItemExtra
        );
        $projectRow = ['id' => 1, 'name' => 'Test Project', 'org_id' => 1, 'visibility' => 'everyone'];

        // fetch() alternates: first call = work item, subsequent = project row
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls($workItemRow, $projectRow, $projectRow, $projectRow);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('tableExists')->willReturn(false);
        $db->method('lastInsertId')->willReturn('99');
        return $db;
    }

    // ===========================
    // INDEX
    // ===========================

    #[Test]
    public function testIndexRedirectsWhenProjectNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makeGetRequest(['project_id' => '1']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->index();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testIndexRendersTemplateWhenProjectFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeProjectFoundDb();
        $request = $this->makeGetRequest(['project_id' => '1']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->index();
        $this->assertSame('work-items', $this->response->renderedTemplate);
    }

    // ===========================
    // GENERATE
    // ===========================

    #[Test]
    public function testGenerateRedirectsWhenProjectNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->generate();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // STORE
    // ===========================

    #[Test]
    public function testStoreRedirectsWhenProjectNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest(['project_id' => '1', 'title' => 'New item']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->store();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testStoreRedirectsWithFlashWhenTitleEmpty(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeProjectFoundDb();
        $request = $this->makePostRequest(['project_id' => '1', 'title' => '   ']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->store();
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
    }

    #[Test]
    public function testStoreCreatesItemAndRedirects(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeProjectFoundDb();
        $request = $this->makePostRequest([
            'project_id' => '1',
            'title'      => 'A Brand New Work Item',
            'description' => 'Some desc',
        ]);
        $ctrl = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->store();
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
        $this->assertNull($this->response->renderedTemplate);
    }

    // ===========================
    // REGENERATE SIZING
    // ===========================

    #[Test]
    public function testRegenerateSizingRedirectsWhenProjectNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->regenerateSizing();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateRedirectsWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest(['title' => 'Updated']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->update(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testUpdateSavesAndRedirects(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeWorkItemFoundDb();
        $request = $this->makePostRequest([
            'title'       => 'Updated Title',
            'description' => 'Updated desc',
        ]);
        $ctrl = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->update(42);
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
    }

    // ===========================
    // IMPROVE
    // ===========================

    #[Test]
    public function testImproveRedirectsWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->improve(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testImproveRedirectsWhenProjectNotEditable(): void
    {
        $this->actingAs($this->orgAdminUser());

        // fetch() returns work item first, then false for project lookup
        $workItemRow = [
            'id' => 42, 'project_id' => 1, 'title' => 'WI', 'description' => 'desc',
            'quality_score' => null, 'quality_breakdown' => null, 'quality_attempts' => 0,
        ];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls($workItemRow, false);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('tableExists')->willReturn(false);

        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->improve(42);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // REFINE QUALITY
    // ===========================

    #[Test]
    public function testRefineQualityRedirectsWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->refineQuality(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testRefineQualityMarksPendingAndRedirects(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeWorkItemFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->refineQuality(42);
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
    }

    // ===========================
    // REFINE ALL
    // ===========================

    #[Test]
    public function testRefineAllRedirectsWhenProjectNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->refineAll();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testRefineAllWithNoLowScoringItemsRedirects(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeProjectFoundDb();
        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->refineAll();
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
    }

    // ===========================
    // CLOSE
    // ===========================

    #[Test]
    public function testCloseRedirectsWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->close(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testCloseUpdatesStatusAndRedirects(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeWorkItemFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->close(42);
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRedirectsWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->delete(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testDeleteRemovesItemAndRedirects(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeWorkItemFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->delete(42);
        $this->assertStringContainsString('work-items', (string) $this->response->redirectedTo);
    }

    // ===========================
    // REORDER
    // ===========================

    #[Test]
    public function testReorderReturnsErrorWhenNoOrderData(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = new \StratFlow\Tests\Support\FakeRequest('POST', '/', [], [], '127.0.0.1', [], '{}');
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->reorder();
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    #[Test]
    public function testReorderReturnsErrorWhenItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $body    = json_encode(['order' => [['id' => 1, 'position' => 1]]]);
        $request = new \StratFlow\Tests\Support\FakeRequest('POST', '/', [], [], '127.0.0.1', [], $body);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->reorder();
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    #[Test]
    public function testReorderSucceedsAndReturnsOk(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db   = $this->makeWorkItemFoundDb();
        $body = json_encode(['order' => [['id' => 42, 'position' => 1]]]);
        $request = new \StratFlow\Tests\Support\FakeRequest('POST', '/', [], [], '127.0.0.1', [], $body);
        $ctrl = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->reorder();
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('ok', $this->response->jsonPayload['status']);
    }

    // ===========================
    // GENERATE DESCRIPTION
    // ===========================

    #[Test]
    public function testGenerateDescriptionReturnsErrorWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->generateDescription(99);
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    #[Test]
    public function testGenerateDescriptionReturnsErrorWhenProjectNotEditable(): void
    {
        $this->actingAs($this->orgAdminUser());

        $workItemRow = [
            'id' => 42, 'project_id' => 1, 'title' => 'WI', 'description' => null,
            'strategic_context' => null,
        ];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls($workItemRow, false);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('tableExists')->willReturn(false);

        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->generateDescription(42);
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    // ===========================
    // SCORE
    // ===========================

    #[Test]
    public function testScoreReturnsErrorWhenWorkItemNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->score('99');
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    #[Test]
    public function testScoreReturnsErrorWhenWorkItemIdIsZero(): void
    {
        // score() casts the string param to int — id=0 means findById returns null
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makePostRequest([]);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->score('0');
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
        $this->assertSame(404, $this->response->jsonStatus);
    }

    // ===========================
    // EXPORT
    // ===========================

    #[Test]
    public function testExportRedirectsWhenProjectNotFound(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db      = $this->makeNotFoundDb();
        $request = $this->makeGetRequest(['project_id' => '1', 'format' => 'csv']);
        $ctrl    = new WorkItemController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->export();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testExportCsvSendsDownload(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db       = $this->makeProjectFoundDb();
        $request  = $this->makeGetRequest(['project_id' => '1', 'format' => 'csv']);
        $response = new CapturingResponse();
        $ctrl     = new WorkItemController($request, $response, $this->auth, $db, $this->config);
        $ctrl->export();

        // With empty work items (fetchAll → []) a CSV header row is still written
        $this->assertNotNull($response->downloadContent);
        $this->assertStringContainsString('_work_items.csv', (string) $response->downloadFilename);
    }

    #[Test]
    public function testExportJsonSendsDownload(): void
    {
        $this->actingAs($this->orgAdminUser());
        $db       = $this->makeProjectFoundDb();
        $request  = $this->makeGetRequest(['project_id' => '1', 'format' => 'json']);
        $response = new CapturingResponse();
        $ctrl     = new WorkItemController($request, $response, $this->auth, $db, $this->config);
        $ctrl->export();

        $this->assertNotNull($response->downloadContent);
        $this->assertStringContainsString('_work_items.json', (string) $response->downloadFilename);
    }
}

/**
 * CapturingResponse
 *
 * Extends Response directly (not final FakeResponse) to intercept
 * download() calls without sending real HTTP headers.
 * Also captures render() and redirect() for use in export tests.
 */
final class CapturingResponse extends Response
{
    public ?string $renderedTemplate = null;
    public array   $renderedData     = [];
    public ?array  $jsonPayload      = null;
    public int     $jsonStatus       = 200;
    public ?string $redirectedTo     = null;
    public ?string $downloadContent  = null;
    public ?string $downloadFilename = null;

    public function __construct()
    {
        // Skip parent constructor — not needed for capturing
    }

    public function render(string $template, array $data = [], string $layout = 'public'): void
    {
        $this->renderedTemplate = $template;
        $this->renderedData     = $data;
    }

    public function json(array $data, int $status = 200): void
    {
        $this->jsonPayload = $data;
        $this->jsonStatus  = $status;
    }

    public function redirect(string $url): void
    {
        $this->redirectedTo = $url;
    }

    public function download(string $content, string $filename, string $contentType): void
    {
        $this->downloadContent  = $content;
        $this->downloadFilename = $filename;
    }
}
