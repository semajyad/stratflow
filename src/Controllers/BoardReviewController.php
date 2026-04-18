<?php

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\BoardReview;
use StratFlow\Models\Subscription;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\BoardReviewService;
use StratFlow\Services\GeminiService;
use StratFlow\Services\PanelResolverService;

class BoardReviewController
{
    // ===========================
    // PROPERTIES
    // ===========================

    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    // ===========================
    // BOARD TYPE MAPPING
    // ===========================

    private const BOARD_TYPE_MAP = [
        'summary'      => 'executive',
        'roadmap'      => 'executive',
        'work_items'   => 'product_management',
        'user_stories' => 'product_management',
    ];

    // ===========================
    // ACTIONS
    // ===========================

    /**
     * Run a board review AI evaluation for the given screen context.
     *
     * Expects JSON body: project_id, evaluation_level, screen_context, screen_content.
     * Returns JSON with id, conversation, recommendation.
     */
    public function evaluate(): void
    {
        $body = json_decode($this->request->body(), true);
        if (!$body) {
            $this->response->json(['error' => 'Invalid JSON body'], 400);
            return;
        }

        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];

        $projectId = (int) ($body['project_id'] ?? 0);
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Project not found'], 404);
            return;
        }

        if (!Subscription::hasEvaluationBoard($this->db, $orgId)) {
            $this->response->json(['error' => 'Evaluation board not available on your plan'], 403);
            return;
        }

        $evaluationLevel = $body['evaluation_level'] ?? 'devils_advocate';
        $screenContext   = $body['screen_context']   ?? '';
        $screenContent   = $body['screen_content']   ?? '';

        $validLevels = ['devils_advocate', 'red_teaming', 'gordon_ramsay'];
        if (!in_array($evaluationLevel, $validLevels, true)) {
            $evaluationLevel = 'devils_advocate';
        }

        $validContexts = ['summary', 'roadmap', 'work_items', 'user_stories'];
        if (!in_array($screenContext, $validContexts, true) || empty($screenContent)) {
            $this->response->json(['error' => 'Invalid or missing screen_context / screen_content'], 400);
            return;
        }

        $boardType         = self::BOARD_TYPE_MAP[$screenContext];
        $resolver          = new PanelResolverService($this->db);
        [$panel, $members] = $resolver->resolveWithMembers($orgId, $boardType);

        if (empty($members)) {
            $this->response->json(['error' => 'No panel members configured'], 500);
            return;
        }

        try {
            $gemini  = new GeminiService($this->config);
            $service = new BoardReviewService($gemini);
            $result   = $service->run($members, $evaluationLevel, $screenContext, $screenContent);
            $reviewId = BoardReview::create($this->db, [
                'project_id'          => $projectId,
                'panel_id'            => (int) $panel['id'],
                'board_type'          => $boardType,
                'evaluation_level'    => $evaluationLevel,
                'screen_context'      => $screenContext,
                'content_snapshot'    => $screenContent,
                'conversation_json'   => json_encode($result['conversation']),
                'recommendation_json' => json_encode([
                    'summary'   => $result['recommendation']['summary']   ?? '',
                    'rationale' => $result['recommendation']['rationale'] ?? '',
                ]),
                'proposed_changes'    => json_encode($result['recommendation']['proposed_changes']),
            ]);
        } catch (\Throwable $e) {
            $this->response->json(['error' => 'Board review failed: ' . $e->getMessage()], 500);
            return;
        }

        $this->response->json([
            'id'             => $reviewId,
            'conversation'   => $result['conversation'],
            'recommendation' => $result['recommendation'],
        ], 201);
    }

    /**
     * Fetch a stored board review by ID.
     *
     * @param int $id BoardReview primary key
     */
    public function results(int $id): void
    {
        $user   = $this->auth->user();
        $review = BoardReview::findById($this->db, $id);

        if ($review === null) {
            $this->response->json(['error' => 'Review not found'], 404);
            return;
        }

        $project = ProjectPolicy::findViewableProject($this->db, $user, (int) $review['project_id']);
        if ($project === null) {
            $this->response->json(['error' => 'Access denied'], 403);
            return;
        }

        $review['conversation']    = json_decode($review['conversation_json'], true);
        $review['recommendation']  = json_decode($review['recommendation_json'], true);
        $review['proposed_changes'] = json_decode($review['proposed_changes'], true);
        unset($review['conversation_json'], $review['recommendation_json']);

        $this->response->json($review);
    }

    /**
     * Accept a board review — apply proposed_changes transactionally to the underlying data.
     *
     * Uses SELECT FOR UPDATE to prevent TOCTOU race on the status field.
     *
     * @param int $id BoardReview primary key
     */
    public function accept(int $id): void
    {
        $user   = $this->auth->user();
        $userId = (int) $user['id'];

        $this->db->beginTransaction();
        try {
            $review = BoardReview::findByIdForUpdate($this->db, $id);

            if ($review === null) {
                $this->db->rollback();
                $this->response->json(['error' => 'Review not found'], 404);
                return;
            }
            if ($review['status'] !== 'pending') {
                $this->db->rollback();
                $this->response->json(['error' => 'Review already responded to'], 409);
                return;
            }

            $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $review['project_id']);
            if ($project === null) {
                $this->db->rollback();
                $this->response->json(['error' => 'Access denied'], 403);
                return;
            }

            $changes = json_decode($review['proposed_changes'], true) ?? [];
            $this->applyChanges($review['screen_context'], (int) $review['project_id'], $changes);
            BoardReview::updateStatus($this->db, $id, 'accepted', $userId);
            $this->db->commit();
        } catch (\RuntimeException $e) {
            $this->db->rollback();
            $this->response->json(['error' => 'Failed to apply changes: ' . $e->getMessage()], 500);
            return;
        }

        $this->response->json(['status' => 'accepted', 'id' => $id]);
    }

    /**
     * Reject a board review — record the outcome without applying any changes.
     *
     * @param int $id BoardReview primary key
     */
    public function reject(int $id): void
    {
        $user   = $this->auth->user();
        $userId = (int) $user['id'];
        $review = BoardReview::findById($this->db, $id);

        if ($review === null) {
            $this->response->json(['error' => 'Review not found'], 404);
            return;
        }
        if ($review['status'] !== 'pending') {
            $this->response->json(['error' => 'Review already responded to'], 409);
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $review['project_id']);
        if ($project === null) {
            $this->response->json(['error' => 'Access denied'], 403);
            return;
        }

        BoardReview::updateStatus($this->db, $id, 'rejected', $userId);
        $this->response->json(['status' => 'rejected', 'id' => $id]);
    }

    /**
     * Return board review history for a project.
     *
     * Expects query param: project_id. Viewable by any project member.
     */
    public function history(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->get('project_id', 0);

        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Project not found'], 404);
            return;
        }

        $reviews = BoardReview::findByProjectId($this->db, $projectId);
        foreach ($reviews as &$r) {
            $r['conversation']     = json_decode($r['conversation_json'], true);
            $r['recommendation']   = json_decode($r['recommendation_json'], true);
            $r['proposed_changes'] = json_decode($r['proposed_changes'], true);
            unset($r['conversation_json'], $r['recommendation_json'], $r['content_snapshot']);
        }

        $this->response->json($reviews);
    }

    // ===========================
    // APPLY CHANGES
    // ===========================

    /**
     * Dispatch proposed changes to the correct apply method.
     * Called from within accept()'s open transaction — do NOT open another.
     */
    private function applyChanges(string $screenContext, int $projectId, array $changes): void
    {
        match ($screenContext) {
            'summary'      => $this->applySummaryChanges($projectId, $changes),
            'roadmap'      => $this->applyRoadmapChanges($projectId, $changes),
            'work_items'   => $this->applyWorkItemChanges($projectId, $changes),
            'user_stories' => $this->applyUserStoryChanges($projectId, $changes),
            default        => throw new \RuntimeException("Unknown screen context: {$screenContext}"),
        };
    }

    private function applySummaryChanges(int $projectId, array $changes): void
    {
        $revised = $changes['revised_summary'] ?? null;
        if (empty($revised)) {
            throw new \RuntimeException('proposed_changes missing revised_summary');
        }
        $this->db->query(
            "UPDATE documents SET ai_summary = :summary
             WHERE project_id = :project_id
             ORDER BY id DESC LIMIT 1",
            [':summary' => $revised, ':project_id' => $projectId]
        );
    }

    private function applyRoadmapChanges(int $projectId, array $changes): void
    {
        $revised = $changes['revised_mermaid_code'] ?? null;
        if (empty($revised)) {
            throw new \RuntimeException('proposed_changes missing revised_mermaid_code');
        }
        $this->db->query(
            "UPDATE strategy_diagrams SET mermaid_code = :code
             WHERE project_id = :project_id
             ORDER BY id DESC LIMIT 1",
            [':code' => $revised, ':project_id' => $projectId]
        );
    }

    private function applyWorkItemChanges(int $projectId, array $changes): void
    {
        $items = $changes['items'] ?? null;
        if (!is_array($items)) {
            throw new \RuntimeException('proposed_changes missing items array');
        }
        foreach ($items as $item) {
            $action = $item['action'] ?? '';
            match ($action) {
                'add'    => $this->db->query(
                    "INSERT INTO hl_work_items (project_id, priority_number, title, description, created_at)
                     VALUES (:project_id,
                             COALESCE((SELECT MAX(priority_number) FROM hl_work_items WHERE project_id = :project_id2), 0) + 100,
                             :title, :description, NOW())",
                    [':project_id' => $projectId, ':project_id2' => $projectId,
                     ':title' => $item['title'] ?? '', ':description' => $item['description'] ?? '']
                ),
                'modify' => $this->db->query(
                    "UPDATE hl_work_items SET title = :title, description = :description
                     WHERE id = :id AND project_id = :project_id",
                    [':id' => (int) ($item['id'] ?? 0), ':project_id' => $projectId,
                     ':title' => $item['title'] ?? '', ':description' => $item['description'] ?? '']
                ),
                'remove' => $this->db->query(
                    "DELETE FROM hl_work_items WHERE id = :id AND project_id = :project_id",
                    [':id' => (int) ($item['id'] ?? 0), ':project_id' => $projectId]
                ),
                default  => throw new \RuntimeException("Unknown work item action: {$action}"),
            };
        }
    }

    private function applyUserStoryChanges(int $projectId, array $changes): void
    {
        $stories = $changes['stories'] ?? null;
        if (!is_array($stories)) {
            throw new \RuntimeException('proposed_changes missing stories array');
        }
        foreach ($stories as $story) {
            $action = $story['action'] ?? '';
            match ($action) {
                'add'    => $this->db->query(
                    "INSERT INTO user_stories (project_id, priority_number, title, description, created_at)
                     VALUES (:project_id,
                             COALESCE((SELECT MAX(priority_number) FROM user_stories WHERE project_id = :project_id2), 0) + 100,
                             :title, :description, NOW())",
                    [':project_id' => $projectId, ':project_id2' => $projectId,
                     ':title' => $story['title'] ?? '', ':description' => $story['description'] ?? '']
                ),
                'modify' => $this->db->query(
                    "UPDATE user_stories SET title = :title, description = :description
                     WHERE id = :id AND project_id = :project_id",
                    [':id' => (int) ($story['id'] ?? 0), ':project_id' => $projectId,
                     ':title' => $story['title'] ?? '', ':description' => $story['description'] ?? '']
                ),
                'remove' => $this->db->query(
                    "DELETE FROM user_stories WHERE id = :id AND project_id = :project_id",
                    [':id' => (int) ($story['id'] ?? 0), ':project_id' => $projectId]
                ),
                default  => throw new \RuntimeException("Unknown user story action: {$action}"),
            };
        }
    }
}
