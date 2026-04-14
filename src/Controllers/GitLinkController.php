<?php

/**
 * GitLinkController
 *
 * CRUD actions for manually managing git links on user stories and work items.
 * All actions require authentication and CSRF protection.
 * Multi-tenancy is enforced by walking local_item → project → org_id.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Project;
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\UserStory;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\GitLinkService;

class GitLinkController
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
    // ACTIONS
    // ===========================

    /**
     * Return git links for a single local item as JSON.
     *
     * Used by the modal to populate the git-links section via AJAX after open.
     *
     * GET /app/git-links?local_type=user_story&local_id=42
     */
    public function index(): void
    {
        header('Content-Type: application/json');
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $localType = $this->request->get('local_type', '');
        $localId   = (int) $this->request->get('local_id', 0);
        if (!$this->verifyOwnership($localType, $localId, $orgId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $links = StoryGitLink::findByLocalItem($this->db, $localType, $localId);
        echo json_encode(['ok' => true, 'links' => $links]);
    }

    /**
     * Create a new manual git link for a story or work item.
     *
     * POST /app/git-links
     * Body: local_type, local_id, ref_url
     *
     * Returns JSON with the newly created link on success.
     */
    public function create(): void
    {
        header('Content-Type: application/json');
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $localType = $this->request->post('local_type', '');
        $localId   = (int) $this->request->post('local_id', 0);
        $refUrl    = trim($this->request->post('ref_url', ''));
        if (!in_array($localType, ['user_story', 'hl_work_item'], true) || $localId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid local_type or local_id']);
            return;
        }

        if ($refUrl === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ref_url is required']);
            return;
        }

        if (!$this->verifyOwnership($localType, $localId, $orgId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $service = new GitLinkService($this->db);
        $classified = $service->classifyRef($refUrl);
        try {
            $newId = StoryGitLink::create($this->db, [
                'local_type' => $localType,
                'local_id'   => $localId,
                'provider'   => 'manual',
                'ref_type'   => $classified['ref_type'],
                'ref_url'    => $refUrl,
                'ref_label'  => $classified['ref_label'],
                'status'     => 'unknown',
            ]);
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[GitLink] create failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            return;
        }

        if ($newId === 0) {
// Unique constraint triggered — link already exists for this (local_type, local_id, ref_url)
            http_response_code(409);
            echo json_encode(['error' => 'This ref is already linked']);
            return;
        }

        // Load by primary key so we cannot accidentally return a row from a different
        // (local_type, local_id) that happens to share the same URL.
        $link = StoryGitLink::findById($this->db, $newId);
        echo json_encode(['ok' => true, 'link' => $link]);
    }

    /**
     * Delete a git link by ID.
     *
     * POST /app/git-links/{id}/delete
     *
     * Scoped by local_type and local_id supplied in the POST body so the
     * scoping guard in StoryGitLink::deleteById can verify ownership.
     */
    public function delete($id): void
    {
        header('Content-Type: application/json');
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $localType = $this->request->post('local_type', '');
        $localId   = (int) $this->request->post('local_id', 0);
        if (!$this->verifyOwnership($localType, $localId, $orgId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $deleted = StoryGitLink::deleteById($this->db, (int) $id, $localType, $localId);
        if (!$deleted) {
            http_response_code(404);
            echo json_encode(['error' => 'Link not found or already deleted']);
            return;
        }

        echo json_encode(['ok' => true]);
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Verify that the local item (story or work item) belongs to the caller's org.
     *
     * Walks local_id → project_id → org_id and compares against $orgId.
     *
     * @param string $localType 'user_story' or 'hl_work_item'
     * @param int    $localId   Primary key of the local item
     * @param int    $orgId     Caller's organisation ID from auth session
     * @return bool             True if the item belongs to the org
     */
    private function verifyOwnership(string $localType, int $localId, int $orgId): bool
    {
        if (!in_array($localType, ['user_story', 'hl_work_item'], true) || $localId <= 0) {
            return false;
        }

        if ($localType === 'user_story') {
            $item = UserStory::findById($this->db, $localId);
        } else {
            $item = HLWorkItem::findById($this->db, $localId);
        }

        if ($item === null) {
            return false;
        }

        return ProjectPolicy::findEditableProject($this->db, $this->auth->user(), (int) $item['project_id']) !== null;
    }
}
