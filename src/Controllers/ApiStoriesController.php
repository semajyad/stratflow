<?php
/**
 * ApiStoriesController
 *
 * JSON API endpoints for user stories, authenticated via Personal Access Tokens.
 * All queries are scoped to the authenticated user's org_id.
 *
 * Routes (all require api_auth middleware):
 *   GET  /api/v1/me                      — sanity endpoint for MCP boot
 *   GET  /api/v1/stories                 — list stories (mine=1, status, project_id, limit)
 *   GET  /api/v1/stories/{id}            — full story context
 *   POST /api/v1/stories/{id}/status     — transition status
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\UserStory;
use StratFlow\Services\AuditLogger;

class ApiStoriesController
{
    /** Allowed status values (mirrors user_stories.status ENUM) */
    private const ALLOWED_STATUSES = ['backlog', 'in_progress', 'in_review', 'done'];

    protected Request  $request;
    protected Response $response;
    protected Auth     $auth;
    protected Database $db;
    protected array    $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    // ===========================
    // SANITY / BOOT
    // ===========================

    /**
     * GET /api/v1/me
     *
     * Returns the authenticated user's identity. The MCP server calls this
     * at startup to verify the token is valid before doing anything else.
     */
    public function me(): void
    {
        $user = $this->auth->user();
        $this->json([
            'id'    => (int) $user['id'],
            'name'  => $user['name'] ?? '',
            'email' => $user['email'],
            'org_id' => (int) $user['org_id'],
        ]);
    }

    // ===========================
    // LIST
    // ===========================

    /**
     * GET /api/v1/stories
     *
     * Query params:
     *   mine=1          Filter to stories assigned to the authenticated user
     *   status=a,b,c    Comma-separated status values (validated against enum)
     *   project_id=N    Scope to a specific project (must belong to org)
     *   limit=N         Max results (default 50, max 200)
     */
    public function index(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $userId = (int) $user['id'];

        $mine      = $this->request->get('mine') === '1';
        $limitRaw  = (int) $this->request->get('limit', '50');
        $limit     = min(max($limitRaw, 1), 200);
        $projectId = (int) $this->request->get('project_id', '0');

        // Build WHERE clauses
        $where  = ['p.org_id = :org_id'];
        $params = [':org_id' => $orgId];

        if ($mine) {
            $where[]          = 'us.assignee_user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        // Status filter — validate each value against the allowed enum
        $statusRaw = $this->request->get('status', '');
        if ($statusRaw !== '') {
            $requested = array_filter(array_map('trim', explode(',', $statusRaw)));
            $valid = array_intersect($requested, self::ALLOWED_STATUSES);
            if (!empty($valid)) {
                $placeholders = implode(', ', array_map(fn($i) => ":status{$i}", array_keys($valid)));
                $where[] = "us.status IN ({$placeholders})";
                foreach (array_values($valid) as $i => $s) {
                    $params[":status{$i}"] = $s;
                }
            }
        }

        // Project filter — must belong to this org
        if ($projectId > 0) {
            $projCheck = $this->db->query(
                'SELECT id FROM projects WHERE id = :id AND org_id = :org_id LIMIT 1',
                [':id' => $projectId, ':org_id' => $orgId]
            )->fetch();
            if (!$projCheck) {
                $this->jsonError('Project not found or not in your organisation', 404);
                return;
            }
            $where[]             = 'us.project_id = :project_id';
            $params[':project_id'] = $projectId;
        }

        $whereClause = implode(' AND ', $where);

        $rows = $this->db->query(
            "SELECT us.id, us.title, us.status, us.size, us.assignee_user_id,
                    p.id AS project_id, p.name AS project_name,
                    us.updated_at
             FROM user_stories us
             JOIN projects p ON p.id = us.project_id
             WHERE {$whereClause}
             ORDER BY us.priority_number ASC
             LIMIT :lim",
            array_merge($params, [':lim' => $limit])
        )->fetchAll();

        $data = array_map(fn($r) => [
            'id'         => (int) $r['id'],
            'sf_ref'     => 'SF-' . $r['id'],
            'title'      => $r['title'],
            'status'     => $r['status'],
            'size'       => $r['size'] !== null ? (int) $r['size'] : null,
            'project'    => ['id' => (int) $r['project_id'], 'name' => $r['project_name']],
            'updated_at' => $r['updated_at'],
        ], $rows);

        $this->json(['data' => $data, 'count' => count($data)]);
    }

    // ===========================
    // SHOW
    // ===========================

    /**
     * GET /api/v1/stories/{id}
     *
     * Returns full story context including:
     * - description, acceptance_criteria, kr_hypothesis
     * - parent hl_work_item
     * - project
     * - current sprint (if allocated)
     * - recent git links (last 10)
     */
    public function show(string $id): void
    {
        $orgId = (int) $this->auth->orgId();
        $storyId = (int) $id;

        $story = $this->db->query(
            "SELECT us.*,
                    p.name  AS project_name,
                    p.org_id AS project_org_id,
                    hw.title AS parent_title,
                    hw.description AS parent_description,
                    u.full_name AS assignee_name
             FROM user_stories us
             JOIN projects p  ON p.id  = us.project_id
             LEFT JOIN hl_work_items hw ON hw.id = us.parent_hl_item_id
             LEFT JOIN users u ON u.id = us.assignee_user_id
             WHERE us.id = :id",
            [':id' => $storyId]
        )->fetch();

        if (!$story || (int) $story['project_org_id'] !== $orgId) {
            $this->jsonError('Story not found or not in your organisation', 404);
            return;
        }

        // Current sprint
        $sprint = $this->db->query(
            "SELECT sp.id, sp.name, sp.start_date, sp.end_date
             FROM sprint_stories ss
             JOIN sprints sp ON sp.id = ss.sprint_id
             WHERE ss.user_story_id = :sid
             ORDER BY sp.start_date DESC
             LIMIT 1",
            [':sid' => $storyId]
        )->fetch() ?: null;

        // Git links (last 10)
        $gitLinks = $this->db->query(
            "SELECT link_type, url, pr_title, sha, created_at
             FROM story_git_links
             WHERE local_item_type = 'user_story' AND local_item_id = :sid
             ORDER BY created_at DESC
             LIMIT 10",
            [':sid' => $storyId]
        )->fetchAll();

        $this->json(['data' => [
            'id'                  => (int) $story['id'],
            'sf_ref'              => 'SF-' . $story['id'],
            'title'               => $story['title'],
            'description'         => $story['description'],
            'acceptance_criteria' => $story['acceptance_criteria'],
            'kr_hypothesis'       => $story['kr_hypothesis'],
            'size'                => $story['size'] !== null ? (int) $story['size'] : null,
            'status'              => $story['status'],
            'quality_score'       => $story['quality_score'] !== null ? (int) $story['quality_score'] : null,
            'team_assigned'       => $story['team_assigned'],
            'assignee'            => $story['assignee_name'],
            'project'             => [
                'id'   => (int) $story['project_id'],
                'name' => $story['project_name'],
            ],
            'parent'              => $story['parent_hl_item_id'] ? [
                'id'          => (int) $story['parent_hl_item_id'],
                'title'       => $story['parent_title'],
                'description' => $story['parent_description'],
            ] : null,
            'sprint'              => $sprint ? [
                'id'         => (int) $sprint['id'],
                'name'       => $sprint['name'],
                'start_date' => $sprint['start_date'],
                'end_date'   => $sprint['end_date'],
            ] : null,
            'git_links'           => array_map(fn($g) => [
                'type'       => $g['link_type'],
                'url'        => $g['url'],
                'title'      => $g['pr_title'],
                'sha'        => $g['sha'],
                'created_at' => $g['created_at'],
            ], $gitLinks),
            'commit_trailer_hint' => 'Include "Refs SF-' . $story['id'] . '" in your commit message or PR description to auto-link.',
            'branch_suggestion'   => 'sf-' . $story['id'] . '-' . $this->slugify((string) $story['title']),
            'updated_at'          => $story['updated_at'],
        ]]);
    }

    // ===========================
    // STATUS TRANSITION
    // ===========================

    /**
     * POST /api/v1/stories/{id}/status
     *
     * Body: {"status": "in_progress"}
     *
     * Validates the target status against the allowed enum and writes it.
     * Audits the transition with source='mcp'.
     */
    public function updateStatus(string $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $storyId = (int) $id;

        $body      = $this->request->json();
        $newStatus = trim((string) ($body['status'] ?? ''));

        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->jsonError(
                'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES),
                422
            );
            return;
        }

        // Verify story exists and belongs to this org
        $story = $this->db->query(
            "SELECT us.id, us.status, p.org_id AS project_org_id
             FROM user_stories us
             JOIN projects p ON p.id = us.project_id
             WHERE us.id = :id",
            [':id' => $storyId]
        )->fetch();

        if (!$story || (int) $story['project_org_id'] !== $orgId) {
            $this->jsonError('Story not found or not in your organisation', 404);
            return;
        }

        UserStory::update($this->db, $storyId, ['status' => $newStatus]);

        AuditLogger::log(
            $this->db,
            (int) $user['id'],
            AuditLogger::ADMIN_ACTION,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'story_status_updated', 'story_id' => $storyId, 'from' => $story['status'], 'to' => $newStatus, 'source' => 'mcp']
        );

        $this->json(['data' => [
            'id'     => $storyId,
            'sf_ref' => 'SF-' . $storyId,
            'status' => $newStatus,
        ]]);
    }

    // ===========================
    // ASSIGN
    // ===========================

    /**
     * POST /api/v1/stories/{id}/assign
     *
     * Assigns the story to the authenticated user (self-assign).
     * Body: {} — no body needed; the assignee is always the PAT owner.
     */
    public function assign(string $id): void
    {
        $user    = $this->auth->user();
        $orgId   = (int) $user['org_id'];
        $userId  = (int) $user['id'];
        $storyId = (int) $id;

        $story = $this->db->query(
            "SELECT us.id, us.title, p.org_id AS project_org_id
             FROM user_stories us
             JOIN projects p ON p.id = us.project_id
             WHERE us.id = :id",
            [':id' => $storyId]
        )->fetch();

        if (!$story || (int) $story['project_org_id'] !== $orgId) {
            $this->jsonError('Story not found or not in your organisation', 404);
            return;
        }

        UserStory::update($this->db, $storyId, ['assignee_user_id' => $userId]);

        AuditLogger::log(
            $this->db,
            $userId,
            AuditLogger::ADMIN_ACTION,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'story_assigned', 'story_id' => $storyId, 'assignee_user_id' => $userId, 'source' => 'mcp']
        );

        $this->json(['data' => [
            'id'       => $storyId,
            'sf_ref'   => 'SF-' . $storyId,
            'title'    => $story['title'],
            'assignee' => $user['name'] ?? $user['email'],
        ]]);
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function jsonError(string $message, int $status): void
    {
        $this->json(['error' => $message], $status);
    }

    /**
     * Convert a story title to a URL-safe branch name fragment.
     */
    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', trim($text));
        return substr($text, 0, 50);
    }
}
