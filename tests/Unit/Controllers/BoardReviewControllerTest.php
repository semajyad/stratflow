<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\BoardReviewController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class BoardReviewControllerTest extends ControllerTestCase
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

    private array $reviewRow = [
        'id'                  => 1,
        'project_id'          => 5,
        'panel_id'            => 1,
        'board_type'          => 'executive',
        'evaluation_level'    => 'devils_advocate',
        'screen_context'      => 'summary',
        'content_snapshot'    => 'Original content.',
        'conversation_json'   => '[{"speaker":"CEO","message":"This looks weak."}]',
        'recommendation_json' => '{"summary":"Revised approach needed.","rationale":"Cost risk."}',
        'proposed_changes'    => '{"revised_summary":"New summary text."}',
        'status'              => 'pending',
        'responded_by'        => null,
        'responded_at'        => null,
        'created_at'          => '2026-01-01 00:00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user);
    }

    // ===========================
    // HELPERS
    // ===========================

    private function ctrl(?FakeRequest $r = null): BoardReviewController
    {
        return new BoardReviewController(
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
    // evaluate() — validation paths
    // ===========================

    #[Test]
    public function evaluateReturns400WhenBodyIsInvalid(): void
    {
        $request = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], 'invalid json');
        $this->ctrl($request)->evaluate();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('Invalid JSON body', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturns404WhenProjectNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // ProjectPolicy::findEditableProject → Project::findById
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_context' => 'summary', 'screen_content' => 'content']);
        $this->ctrl($request)->evaluate();

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Project not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturns403WhenNoSubscription(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),  // ProjectPolicy::findEditableProject
            $this->stmt(false),            // Subscription::hasEvaluationBoard
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_context' => 'summary', 'screen_content' => 'content']);
        $this->ctrl($request)->evaluate();

        $this->assertSame(403, $this->response->jsonStatus);
        $this->assertStringContainsString('Evaluation board not available', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturns400WhenScreenContentEmpty(): void
    {
        $subscriptionRow = ['id' => 1, 'org_id' => 10, 'has_evaluation_board' => 1];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),       // ProjectPolicy
            $this->stmt($subscriptionRow),     // Subscription
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_context' => 'summary', 'screen_content' => '']);
        $this->ctrl($request)->evaluate();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('screen_content', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturns400WhenScreenContextInvalid(): void
    {
        $subscriptionRow = ['id' => 1, 'org_id' => 10, 'has_evaluation_board' => 1];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt($subscriptionRow),
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_context' => 'invalid_context', 'screen_content' => 'some content']);
        $this->ctrl($request)->evaluate();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertStringContainsString('screen_context', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturns500WhenPanelMembersEmpty(): void
    {
        $sub   = ['id' => 1, 'org_id' => 10, 'has_evaluation_board' => 1];
        $panel = ['id' => 7, 'panel_type' => 'executive', 'name' => 'Executive Panel', 'org_id' => 10];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt($sub),
            $this->stmt(false, [$panel]),  // PersonaPanel::findByOrgId
            $this->stmt(false, []),         // PersonaMember::findByPanelId (empty)
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_context' => 'summary', 'screen_content' => 'content']);
        $this->ctrl($request)->evaluate();

        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertStringContainsString('No panel members', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function evaluateReturns500WhenAiServiceThrows(): void
    {
        $sub    = ['id' => 1, 'org_id' => 10, 'has_evaluation_board' => 1];
        $panel  = ['id' => 7, 'panel_type' => 'executive', 'name' => 'Executive Panel', 'org_id' => 10];
        $member = ['id' => 1, 'panel_id' => 7, 'role_title' => 'CEO', 'prompt_description' => 'Strategy'];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt($sub),
            $this->stmt(false, [$panel]),    // PersonaPanel::findByOrgId
            $this->stmt(false, [$member]),   // PersonaMember::findByPanelId
        );

        $request = $this->jsonReq(['project_id' => 5, 'screen_context' => 'summary', 'screen_content' => 'content']);
        $this->ctrl($request)->evaluate();

        // GeminiService constructor throws TypeError (no gemini.api_key in test config) → caught
        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertArrayHasKey('error', $this->response->jsonPayload);
    }

    // ===========================
    // results($id) — all paths
    // ===========================

    #[Test]
    public function resultsReturns404WhenReviewNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // BoardReview::findById
        );

        $this->ctrl()->results(999);

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Review not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function resultsReturns403WhenProjectNotViewable(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->reviewRow), // BoardReview::findById
            $this->stmt(false),             // ProjectPolicy::findViewableProject
        );

        $this->ctrl()->results(1);

        $this->assertSame(403, $this->response->jsonStatus);
        $this->assertStringContainsString('Access denied', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function resultsReturnsDecodedReviewOnSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->reviewRow), // BoardReview::findById
            $this->stmt($this->project),   // ProjectPolicy::findViewableProject
        );

        $this->ctrl()->results(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame(1, $this->response->jsonPayload['id'] ?? null);
        $this->assertIsArray($this->response->jsonPayload['conversation'] ?? null);
        $this->assertIsArray($this->response->jsonPayload['recommendation'] ?? null);
        $this->assertIsArray($this->response->jsonPayload['proposed_changes'] ?? null);
        $this->assertFalse(isset($this->response->jsonPayload['conversation_json']));
        $this->assertFalse(isset($this->response->jsonPayload['recommendation_json']));
    }

    // ===========================
    // accept($id) — all paths
    // ===========================

    #[Test]
    public function acceptReturns404WhenReviewNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // BoardReview::findByIdForUpdate
        );

        $this->ctrl()->accept(999);

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Review not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptReturns409WhenAlreadyResponded(): void
    {
        $respondedRow = array_merge($this->reviewRow, ['status' => 'accepted']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($respondedRow), // BoardReview::findByIdForUpdate
        );

        $this->ctrl()->accept(1);

        $this->assertSame(409, $this->response->jsonStatus);
        $this->assertStringContainsString('already responded', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptReturns403WhenProjectNotEditable(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->reviewRow), // BoardReview::findByIdForUpdate
            $this->stmt(false),             // ProjectPolicy::findEditableProject
        );

        $this->ctrl()->accept(1);

        $this->assertSame(403, $this->response->jsonStatus);
        $this->assertStringContainsString('Access denied', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptReturns200AndStatusAcceptedOnSuccess(): void
    {
        $this->db->method('query')->willReturnCallback(function () {
            return $this->stmt($this->project);
        });

        // Override: first call returns reviewRow (findByIdForUpdate), second returns project
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->reviewRow), // findByIdForUpdate
            $this->stmt($this->project),   // findEditableProject
            $this->stmt(null),             // applySummaryChanges UPDATE
            $this->stmt(null),             // updateStatus
        );

        $ctrl = new BoardReviewController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->accept(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('accepted', $this->response->jsonPayload['status'] ?? null);
        $this->assertSame(1, $this->response->jsonPayload['id'] ?? null);
    }

    #[Test]
    public function acceptAppliesRoadmapChangesOnSuccess(): void
    {
        $roadmapRow = array_merge($this->reviewRow, [
            'screen_context'   => 'roadmap',
            'proposed_changes' => '{"revised_mermaid_code":"gantt\n  section A\n  Task: 0, 7"}',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($roadmapRow),     // findByIdForUpdate
            $this->stmt($this->project),  // findEditableProject
            $this->stmt(null),             // UPDATE strategy_diagrams
            $this->stmt(null),             // updateStatus
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('accepted', $this->response->jsonPayload['status'] ?? null);
    }

    #[Test]
    public function acceptReturns500WhenRoadmapMissingCode(): void
    {
        $roadmapRow = array_merge($this->reviewRow, [
            'screen_context'   => 'roadmap',
            'proposed_changes' => '{}',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($roadmapRow),
            $this->stmt($this->project),
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertStringContainsString('Failed to apply changes', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptAppliesWorkItemChangesOnSuccess(): void
    {
        $workItemsRow = array_merge($this->reviewRow, [
            'screen_context'   => 'work_items',
            'proposed_changes' => json_encode(['items' => [
                ['action' => 'add',    'title' => 'New item', 'description' => 'Desc'],
                ['action' => 'modify', 'id' => 1, 'title' => 'Updated', 'description' => 'New desc'],
                ['action' => 'remove', 'id' => 2],
            ]]),
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($workItemsRow),   // findByIdForUpdate
            $this->stmt($this->project),  // findEditableProject
            $this->stmt(null),             // INSERT (add)
            $this->stmt(null),             // UPDATE (modify)
            $this->stmt(null),             // DELETE (remove)
            $this->stmt(null),             // updateStatus
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('accepted', $this->response->jsonPayload['status'] ?? null);
    }

    #[Test]
    public function acceptReturns500WhenWorkItemsKeyMissing(): void
    {
        $workItemsRow = array_merge($this->reviewRow, [
            'screen_context'   => 'work_items',
            'proposed_changes' => '{"wrong_key": []}',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($workItemsRow),
            $this->stmt($this->project),
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertStringContainsString('Failed to apply changes', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptReturns500WhenWorkItemActionIsUnknown(): void
    {
        $workItemsRow = array_merge($this->reviewRow, [
            'screen_context'   => 'work_items',
            'proposed_changes' => json_encode(['items' => [
                ['action' => 'unknown', 'title' => 'x'],
            ]]),
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($workItemsRow),
            $this->stmt($this->project),
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertStringContainsString('Failed to apply changes', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptAppliesUserStoryChangesOnSuccess(): void
    {
        $storiesRow = array_merge($this->reviewRow, [
            'screen_context'   => 'user_stories',
            'proposed_changes' => json_encode(['stories' => [
                ['action' => 'add',    'title' => 'As a user', 'description' => '...'],
                ['action' => 'modify', 'id' => 1, 'title' => 'Updated story', 'description' => '...'],
                ['action' => 'remove', 'id' => 2],
            ]]),
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($storiesRow),     // findByIdForUpdate
            $this->stmt($this->project),  // findEditableProject
            $this->stmt(null),             // INSERT (add)
            $this->stmt(null),             // UPDATE (modify)
            $this->stmt(null),             // DELETE (remove)
            $this->stmt(null),             // updateStatus
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('accepted', $this->response->jsonPayload['status'] ?? null);
    }

    #[Test]
    public function acceptReturns500WhenStoriesKeyMissing(): void
    {
        $storiesRow = array_merge($this->reviewRow, [
            'screen_context'   => 'user_stories',
            'proposed_changes' => '{}',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($storiesRow),
            $this->stmt($this->project),
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertStringContainsString('Failed to apply changes', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function acceptReturns500WhenUserStoryActionIsUnknown(): void
    {
        $storiesRow = array_merge($this->reviewRow, [
            'screen_context'   => 'user_stories',
            'proposed_changes' => json_encode(['stories' => [
                ['action' => 'unknown', 'title' => 'x'],
            ]]),
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->actingAs($this->user);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($storiesRow),
            $this->stmt($this->project),
        );

        $ctrl = new BoardReviewController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
        $ctrl->accept(1);

        $this->assertSame(500, $this->response->jsonStatus);
        $this->assertStringContainsString('Failed to apply changes', $this->response->jsonPayload['error'] ?? '');
    }

    // ===========================
    // reject($id) — all paths
    // ===========================

    #[Test]
    public function rejectReturns404WhenReviewNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // BoardReview::findById
        );

        $this->ctrl()->reject(999);

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Review not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function rejectReturns409WhenAlreadyResponded(): void
    {
        $respondedRow = array_merge($this->reviewRow, ['status' => 'rejected']);
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($respondedRow), // BoardReview::findById
        );

        $this->ctrl()->reject(1);

        $this->assertSame(409, $this->response->jsonStatus);
        $this->assertStringContainsString('already responded', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function rejectReturns403WhenProjectNotEditable(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->reviewRow), // BoardReview::findById
            $this->stmt(false),             // ProjectPolicy::findEditableProject
        );

        $this->ctrl()->reject(1);

        $this->assertSame(403, $this->response->jsonStatus);
        $this->assertStringContainsString('Access denied', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function rejectReturns200AndStatusRejectedOnSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->reviewRow), // BoardReview::findById
            $this->stmt($this->project),   // ProjectPolicy::findEditableProject
            $this->stmt(null),             // BoardReview::updateStatus
        );

        $this->ctrl()->reject(1);

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('rejected', $this->response->jsonPayload['status'] ?? null);
        $this->assertSame(1, $this->response->jsonPayload['id'] ?? null);
    }

    // ===========================
    // history() — all paths
    // ===========================

    #[Test]
    public function historyReturns404WhenProjectNotViewable(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // ProjectPolicy::findViewableProject
        );

        $request = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($request)->history();

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertStringContainsString('Project not found', $this->response->jsonPayload['error'] ?? '');
    }

    #[Test]
    public function historyReturnsDecodedReviewsOnSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),         // ProjectPolicy::findViewableProject
            $this->stmt(null, [$this->reviewRow]), // BoardReview::findByProjectId
        );

        $request = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($request)->history();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertIsArray($this->response->jsonPayload);
        $this->assertCount(1, $this->response->jsonPayload);

        $r = $this->response->jsonPayload[0];
        $this->assertIsArray($r['conversation'] ?? null);
        $this->assertFalse(isset($r['conversation_json']));
        $this->assertFalse(isset($r['content_snapshot']));
    }
}
