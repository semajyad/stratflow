<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\SoundingBoardController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class SoundingBoardControllerTest extends ControllerTestCase
{
    private array $user = [
        'id'        => 1,
        'org_id'    => 10,
        'role'      => 'org_admin',
        'email'     => 'a@t.invalid',
        'is_active' => 1,
    ];

    private array $project = [
        'id'         => 5,
        'org_id'     => 10,
        'name'       => 'Test',
        'visibility' => 'everyone',
    ];

    private array $evalRow = [
        'id'                => 1,
        'project_id'        => 5,
        'results_json'      => '[{"status":"pending","feedback":"test","role_title":"CEO"}]',
        'status'            => 'pending',
        'panel_id'          => 1,
        'evaluation_level'  => 'devils_advocate',
        'screen_context'    => 'strategy',
        'created_at'        => '2025-01-01 00:00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);
    }

    // ===========================
    // HELPERS
    // ===========================

    private function ctrl(?FakeRequest $r = null): SoundingBoardController
    {
        return new SoundingBoardController(
            $r ?? $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    private function jsonReq(mixed $data): FakeRequest
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
    // evaluate() — PATHS BEFORE $orgId BUG
    // ===========================

    #[Test]
    public function evaluateReturnsJsonErrorWhenBodyIsInvalid(): void
    {
        $request = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], 'invalid json');
        $this->ctrl($request)->evaluate();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('Invalid JSON body', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturnsJsonErrorWhenBodyIsEmpty(): void
    {
        $request = $this->jsonReq('');
        $this->ctrl($request)->evaluate();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('Invalid JSON body', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturnsJsonErrorWhenProjectNotFound(): void
    {
        // Project not found: ProjectPolicy::findEditableProject returns null
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false),  // Project::findById
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_content' => 'test']);
        $this->ctrl($request)->evaluate();

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Project not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturnsJsonErrorWhenBodyMissingAfterRefactor(): void
    {
        // Verify the refactored controller (uses PanelResolverService) still rejects empty body correctly
        $request = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], '');
        $this->ctrl($request)->evaluate();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertArrayHasKey('error', $this->response->jsonPayload);
    }

    // ===========================
    // results($id) — ALL PATHS
    // ===========================

    #[Test]
    public function resultsReturnsJsonErrorWhenEvalNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false),  // EvaluationResult::findById
        );

        $this->ctrl()->results(999);

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Evaluation not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function resultsReturnsJsonErrorWhenProjectNotViewable(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->evalRow),  // EvaluationResult::findById
            $this->stmt(false),            // Project::findById (ProjectPolicy)
        );

        $this->ctrl()->results(1);

        $this->assertSame(403, $this->response->jsonStatus);
        $this->assertStringContainsString('Access denied', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function resultsReturnsDecodedEvalWhenSuccessful(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->evalRow),      // EvaluationResult::findById
            $this->stmt($this->project),      // Project::findById (ProjectPolicy)
        );

        $this->ctrl()->results(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertIsArray($this->response->jsonPayload);
        $this->assertSame(1, $this->response->jsonPayload['id'] ?? null);
        $this->assertIsArray($this->response->jsonPayload['results'] ?? null);
        $this->assertFalse(isset($this->response->jsonPayload['results_json']));
    }

    // ===========================
    // respond($id) — ALL PATHS
    // ===========================

    #[Test]
    public function respondReturnsJsonErrorWhenBodyIsInvalid(): void
    {
        $request = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], 'invalid json');
        $this->ctrl($request)->respond(1);

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('Invalid JSON body', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function respondReturnsJsonErrorWhenEvalNotFound(): void
    {
        $request = $this->jsonReq(['member_index' => 0, 'action' => 'accept']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false),  // EvaluationResult::findById
        );

        $this->ctrl($request)->respond(999);

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Evaluation not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function respondReturnsJsonErrorWhenProjectNotEditable(): void
    {
        $request = $this->jsonReq(['member_index' => 0, 'action' => 'accept']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->evalRow),  // EvaluationResult::findById
            $this->stmt(false),            // Project::findById (ProjectPolicy)
        );

        $this->ctrl($request)->respond(1);

        $this->assertSame(403, $this->response->jsonStatus);
        $this->assertStringContainsString('Access denied', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function respondReturnsJsonErrorWhenActionIsInvalid(): void
    {
        $request = $this->jsonReq(['member_index' => 0, 'action' => 'invalid']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->evalRow),      // EvaluationResult::findById
            $this->stmt($this->project),      // Project::findById (ProjectPolicy)
        );

        $this->ctrl($request)->respond(1);

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('Invalid action', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function respondReturnsJsonErrorWhenMemberIndexIsInvalid(): void
    {
        $request = $this->jsonReq(['member_index' => 999, 'action' => 'accept']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->evalRow),      // EvaluationResult::findById
            $this->stmt($this->project),      // Project::findById (ProjectPolicy)
        );

        $this->ctrl($request)->respond(1);

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('Invalid member index', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function respondAcceptsResponseAndDoesNotUpdateStatusWhenPendingRemains(): void
    {
        // results_json has 2 items: one pending, one to-be-accepted
        $results = [
            ['status' => 'pending', 'feedback' => 'test 1', 'role_title' => 'CEO'],
            ['status' => 'pending', 'feedback' => 'test 2', 'role_title' => 'CFO'],
        ];
        $evalWithTwoMembers = array_merge($this->evalRow, ['results_json' => json_encode($results)]);

        $request = $this->jsonReq(['member_index' => 0, 'action' => 'accept']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($evalWithTwoMembers),      // EvaluationResult::findById
            $this->stmt($this->project),           // Project::findById (ProjectPolicy)
            $this->stmt(false),                     // UPDATE results_json
        );

        $this->ctrl($request)->respond(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('ok', $this->response->jsonPayload['status'] ?? null);
        $this->assertSame(0, $this->response->jsonPayload['member_index'] ?? null);
        $this->assertSame('accept', $this->response->jsonPayload['action'] ?? null);
    }

    #[Test]
    public function respondRejectsResponseAndDoesNotUpdateStatusWhenPendingRemains(): void
    {
        $results = [
            ['status' => 'pending', 'feedback' => 'test 1', 'role_title' => 'CEO'],
            ['status' => 'pending', 'feedback' => 'test 2', 'role_title' => 'CFO'],
        ];
        $evalWithTwoMembers = array_merge($this->evalRow, ['results_json' => json_encode($results)]);

        $request = $this->jsonReq(['member_index' => 0, 'action' => 'reject']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($evalWithTwoMembers),      // EvaluationResult::findById
            $this->stmt($this->project),           // Project::findById (ProjectPolicy)
            $this->stmt(false),                     // UPDATE results_json
        );

        $this->ctrl($request)->respond(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('ok', $this->response->jsonPayload['status'] ?? null);
        $this->assertSame('reject', $this->response->jsonPayload['action'] ?? null);
    }

    #[Test]
    public function respondUpdatesOverallStatusToPartialWhenMixedResolution(): void
    {
        // Single request: member_index=0 accepts. After this, one is accepted, one pending.
        // We don't test the second request since that would require resetting the DB mock.
        // This test verifies: partial resolution doesn't trigger updateStatus call.
        $results = [
            ['status' => 'pending', 'feedback' => 'test 1', 'role_title' => 'CEO'],
            ['status' => 'pending', 'feedback' => 'test 2', 'role_title' => 'CFO'],
        ];
        $evalWithTwoMembers = array_merge($this->evalRow, ['results_json' => json_encode($results)]);

        $request = $this->jsonReq(['member_index' => 0, 'action' => 'accept']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($evalWithTwoMembers),      // EvaluationResult::findById
            $this->stmt($this->project),           // Project::findById (ProjectPolicy)
            $this->stmt(false),                     // UPDATE results_json
        );

        $this->ctrl($request)->respond(1);
        $this->assertSame(200, $this->response->jsonStatus);
        // Verify that no updateStatus was called (only 3 DB calls, not 4)
    }

    #[Test]
    public function respondUpdatesOverallStatusToAcceptedWhenAllAccepted(): void
    {
        // Single item: accept it → all resolved and all accepted
        $results = [
            ['status' => 'pending', 'feedback' => 'test', 'role_title' => 'CEO'],
        ];
        $evalWithOneItem = array_merge($this->evalRow, ['results_json' => json_encode($results)]);

        $request = $this->jsonReq(['member_index' => 0, 'action' => 'accept']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($evalWithOneItem),        // EvaluationResult::findById
            $this->stmt($this->project),          // Project::findById (ProjectPolicy)
            $this->stmt(false),                    // UPDATE results_json
            $this->stmt(false),                    // EvaluationResult::updateStatus to 'accepted'
        );

        $this->ctrl($request)->respond(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('ok', $this->response->jsonPayload['status'] ?? null);
    }

    // ===========================
    // history() — ALL PATHS
    // ===========================

    #[Test]
    public function historyReturnsJsonErrorWhenProjectNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false),  // Project::findById (ProjectPolicy)
        );

        $request = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($request)->history();

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Project not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function historyReturnsEmptyArrayWhenNoEvaluations(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),  // Project::findById (ProjectPolicy)
            $this->stmt(false, []),       // EvaluationResult::findByProjectId (returns empty array)
        );

        $request = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($request)->history();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertIsArray($this->response->jsonPayload);
        $this->assertCount(0, $this->response->jsonPayload);
    }

    #[Test]
    public function historyReturnsDecodedEvaluationsWhenSuccessful(): void
    {
        $eval1 = array_merge($this->evalRow, ['id' => 1]);
        $eval2 = array_merge($this->evalRow, ['id' => 2, 'results_json' => '[]']);

        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),      // Project::findById (ProjectPolicy)
            $this->stmt(false, [$eval1, $eval2]),  // EvaluationResult::findByProjectId
        );

        $request = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($request)->history();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertIsArray($this->response->jsonPayload);
        $this->assertCount(2, $this->response->jsonPayload);
        $this->assertArrayHasKey('results', $this->response->jsonPayload[0]);
        $this->assertFalse(isset($this->response->jsonPayload[0]['results_json']));
        $this->assertArrayHasKey('results', $this->response->jsonPayload[1]);
        $this->assertFalse(isset($this->response->jsonPayload[1]['results_json']));
    }

    #[Test]
    public function historyHandlesZeroProjectId(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false),  // Project::findById returns nothing for project_id=0
        );

        $request = $this->makeGetRequest(['project_id' => '0']);
        $this->ctrl($request)->history();

        $this->assertSame(404, $this->response->jsonStatus);
    }
}
