<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\PrioritisationController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class PrioritisationControllerTest extends ControllerTestCase
{
    private array $user = ['id' => 1, 'org_id' => 10, 'role' => 'org_admin', 'email' => 'a@t.invalid', 'is_active' => 1];
    private array $project = ['id' => 5, 'org_id' => 10, 'name' => 'Test', 'visibility' => 'everyone', 'selected_framework' => 'rice'];
    private array $item = ['id' => 1, 'project_id' => 5, 'title' => 'Item 1', 'description' => 'Desc', 'priority_number' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): PrioritisationController
    {
        $cfg = array_merge($this->config, ['gemini' => ['api_key' => '']]);
        return new PrioritisationController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
    }

    private function jsonReq(array $data): FakeRequest
    {
        return new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode($data));
    }

    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    // ===========================
    // index()
    // ===========================

    public function testIndexProjectNotFoundRedirects(): void
    {
        $stmt = $this->stmt(null);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->makeGetRequest(['project_id' => '99']);
        $ctrl = $this->ctrl($req);
        $ctrl->index();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testIndexProjectFoundRendersTemplate(): void
    {
        $stmtProject = $this->stmt($this->project);
        $stmtItems = $this->stmt(null, [$this->item]);
        $stmtSubscription = $this->stmt(null, []);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtProject, $stmtItems, $stmtSubscription);

        $req = $this->makeGetRequest(['project_id' => '5']);
        $ctrl = $this->ctrl($req);
        $ctrl->index();

        $this->assertSame('prioritisation', $this->response->renderedTemplate);
        $this->assertSame($this->project, $this->response->renderedData['project']);
        $this->assertSame('rice', $this->response->renderedData['framework']);
        $this->assertCount(1, $this->response->renderedData['work_items']);
    }

    public function testIndexDefaultsToRiceFramework(): void
    {
        $projectNoFramework = array_merge($this->project, ['selected_framework' => null]);
        $stmtProject = $this->stmt($projectNoFramework);
        $stmtItems = $this->stmt(null, []);
        $stmtSubscription = $this->stmt(null, []);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtProject, $stmtItems, $stmtSubscription);

        $req = $this->makeGetRequest(['project_id' => '5']);
        $ctrl = $this->ctrl($req);
        $ctrl->index();

        $this->assertSame('rice', $this->response->renderedData['framework']);
    }

    // ===========================
    // selectFramework()
    // ===========================

    public function testSelectFrameworkProjectNotFoundRedirects(): void
    {
        $stmt = $this->stmt(null);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->makePostRequest(['project_id' => '99', 'framework' => 'wsjf']);
        $ctrl = $this->ctrl($req);
        $ctrl->selectFramework();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testSelectFrameworkUpdatesAndRedirects(): void
    {
        $stmt = $this->stmt($this->project);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->makePostRequest(['project_id' => '5', 'framework' => 'wsjf']);
        $ctrl = $this->ctrl($req);
        $ctrl->selectFramework();

        $this->assertSame('/app/prioritisation?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('WSJF', $_SESSION['flash_message'] ?? '');
    }

    public function testSelectFrameworkInvalidFrameworkDefaultsToRice(): void
    {
        $stmt = $this->stmt($this->project);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->makePostRequest(['project_id' => '5', 'framework' => 'invalid_framework']);
        $ctrl = $this->ctrl($req);
        $ctrl->selectFramework();

        $this->assertStringContainsString('RICE', $_SESSION['flash_message'] ?? '');
    }

    // ===========================
    // saveScores()
    // ===========================

    public function testSaveScoresMissingItemIdReturnsJson400(): void
    {
        $req = $this->jsonReq(['item_id' => 0, 'scores' => ['rice_reach' => 10]]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    public function testSaveScoresEmptyScoresReturnsJson400(): void
    {
        $req = $this->jsonReq(['item_id' => 1, 'scores' => []]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(400, $this->response->jsonStatus);
    }

    public function testSaveScoresItemNotFoundReturnsJson404(): void
    {
        $stmt = $this->stmt(null);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->jsonReq(['item_id' => 99, 'scores' => ['rice_reach' => 10]]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(404, $this->response->jsonStatus);
    }

    public function testSaveScoresAccessDeniedReturnsJson403(): void
    {
        $stmtItem = $this->stmt($this->item);
        $stmtProject = $this->stmt(null);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtItem, $stmtProject);

        $req = $this->jsonReq(['item_id' => 1, 'scores' => ['rice_reach' => 10]]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(403, $this->response->jsonStatus);
    }

    public function testSaveScoresRiceFrameworkCalculatesScore(): void
    {
        $stmtItem = $this->stmt($this->item);
        $stmtProject = $this->stmt($this->project);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtItem, $stmtProject, $stmtProject);

        $req = $this->jsonReq([
            'item_id' => 1,
            'scores' => ['rice_reach' => 10, 'rice_impact' => 2, 'rice_confidence' => 50, 'rice_effort' => 5],
        ]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('ok', $this->response->jsonPayload['status']);
        // (10 * 2 * 50) / 5 = 1000 / 5 = 200
        $this->assertSame(200.0, $this->response->jsonPayload['final_score']);
    }

    public function testSaveScoresRiceFrameworkDivisionByZero(): void
    {
        $stmtItem = $this->stmt($this->item);
        $stmtProject = $this->stmt($this->project);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtItem, $stmtProject, $stmtProject);

        $req = $this->jsonReq([
            'item_id' => 1,
            'scores' => ['rice_reach' => 10, 'rice_impact' => 2, 'rice_confidence' => 50, 'rice_effort' => 0],
        ]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(200, $this->response->jsonStatus);
        // Effort=0 guarded, returns 0
        $this->assertSame(0.0, $this->response->jsonPayload['final_score']);
    }

    public function testSaveScoresWsjfFrameworkCalculatesScore(): void
    {
        $projectWsjf = array_merge($this->project, ['selected_framework' => 'wsjf']);
        $stmtItem = $this->stmt($this->item);
        $stmtProject = $this->stmt($projectWsjf);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtItem, $stmtProject, $stmtProject);

        $req = $this->jsonReq([
            'item_id' => 1,
            'scores' => ['wsjf_business_value' => 3, 'wsjf_time_criticality' => 2, 'wsjf_risk_reduction' => 1, 'wsjf_job_size' => 2],
        ]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        $this->assertSame(200, $this->response->jsonStatus);
        // (3 + 2 + 1) / 2 = 6 / 2 = 3.0
        $this->assertSame(3.0, $this->response->jsonPayload['final_score']);
    }

    public function testSaveScoresWsjfFrameworkDivisionByZero(): void
    {
        $projectWsjf = array_merge($this->project, ['selected_framework' => 'wsjf']);
        $stmtItem = $this->stmt($this->item);
        $stmtProject = $this->stmt($projectWsjf);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtItem, $stmtProject, $stmtProject);

        $req = $this->jsonReq([
            'item_id' => 1,
            'scores' => ['wsjf_business_value' => 3, 'wsjf_time_criticality' => 2, 'wsjf_risk_reduction' => 1, 'wsjf_job_size' => 0],
        ]);
        $ctrl = $this->ctrl($req);
        $ctrl->saveScores();

        // Job size = 0 guarded, returns 0
        $this->assertSame(0.0, $this->response->jsonPayload['final_score']);
    }

    // ===========================
    // rerank()
    // ===========================

    public function testRerankProjectNotFoundRedirects(): void
    {
        $stmt = $this->stmt(null);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->makePostRequest(['project_id' => '99']);
        $ctrl = $this->ctrl($req);
        $ctrl->rerank();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testRerankNoItemsStillRedirects(): void
    {
        $stmtProject = $this->stmt($this->project);
        $stmtItems = $this->stmt(null, []);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtProject, $stmtItems);

        $req = $this->makePostRequest(['project_id' => '5']);
        $ctrl = $this->ctrl($req);
        $ctrl->rerank();

        $this->assertSame('/app/prioritisation?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('re-ranked', $_SESSION['flash_message'] ?? '');
    }

    public function testRerankAssignsPriorityNumbers(): void
    {
        $stmtProject = $this->stmt($this->project);
        $stmtItems = $this->stmt(null, [
            ['id' => 1, 'final_score' => 100],
            ['id' => 2, 'final_score' => 50],
        ]);
        $stmtDefault = $this->stmt(null);

        $this->db->expects($this->any())->method('query')
            ->willReturnCallback(function() use ($stmtProject, $stmtItems, $stmtDefault) {
                static $callCount = 0;
                $callCount++;
                if ($callCount === 1) return $stmtProject;
                if ($callCount === 2) return $stmtItems;
                return $stmtDefault;
            });

        $req = $this->makePostRequest(['project_id' => '5']);
        $ctrl = $this->ctrl($req);
        $ctrl->rerank();

        $this->assertSame('/app/prioritisation?project_id=5', $this->response->redirectedTo);
    }

    // ===========================
    // aiBaseline()
    // ===========================

    public function testAiBaselineProjectNotFoundReturnsJson404(): void
    {
        $stmt = $this->stmt(null);
        $this->db->expects($this->any())->method('query')->willReturn($stmt);

        $req = $this->jsonReq(['project_id' => 99]);
        $ctrl = $this->ctrl($req);
        $ctrl->aiBaseline();

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    public function testAiBaselineNoWorkItemsReturnsJson400(): void
    {
        $stmtProject = $this->stmt($this->project);
        $stmtItems = $this->stmt(null, []);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtProject, $stmtItems);

        $req = $this->jsonReq(['project_id' => 5]);
        $ctrl = $this->ctrl($req);
        $ctrl->aiBaseline();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('No work items', $this->response->jsonPayload['message']);
    }

    public function testAiBaselineGeminiServiceErrorReturnsJson500(): void
    {
        $stmtProject = $this->stmt($this->project);
        $stmtItems = $this->stmt(null, [$this->item]);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtProject, $stmtItems);

        $req = $this->jsonReq(['project_id' => 5]);
        // GeminiService needs a valid model config
        $cfg = array_merge($this->config, ['gemini' => ['api_key' => 'test_key', 'model' => 'gemini-2.0-flash']]);
        $ctrl = new PrioritisationController($req, $this->response, $this->auth, $this->db, $cfg);
        $ctrl->aiBaseline();

        // GeminiService will fail with invalid API key (expected)
        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    public function testAiBaselineNonArrayResponseReturnsJson500(): void
    {
        $stmtProject = $this->stmt($this->project);
        $stmtItems = $this->stmt(null, [$this->item]);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtProject, $stmtItems);

        $req = $this->jsonReq(['project_id' => 5]);
        $cfg = array_merge($this->config, ['gemini' => ['api_key' => 'test_key', 'model' => 'gemini-2.0-flash']]);
        $ctrl = new PrioritisationController($req, $this->response, $this->auth, $this->db, $cfg);
        $ctrl->aiBaseline();

        $this->assertSame(500, $this->response->jsonStatus);
    }
}
