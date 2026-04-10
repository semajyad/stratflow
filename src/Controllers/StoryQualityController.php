<?php
/**
 * StoryQualityController
 *
 * Admin CRUD for the story_quality_config table.
 * Handles the Settings → Story Quality Rules page where org admins
 * can add custom splitting patterns and mandatory conditions.
 *
 * GET  /app/admin/story-quality-rules               — index (render settings page)
 * POST /app/admin/story-quality-rules               — store (add custom rule)
 * POST /app/admin/story-quality-rules/{id}/delete   — delete (custom only)
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\StoryQualityConfig;

class StoryQualityController
{
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

    /**
     * Render the Story Quality Rules settings page.
     *
     * Seeds default splitting patterns for the org on first visit.
     *
     * GET /app/admin/story-quality-rules
     */
    public function index(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        // Seed defaults if this org has never visited the page
        StoryQualityConfig::seedDefaults($this->db, $orgId);

        $rules = StoryQualityConfig::findByOrgId($this->db, $orgId);

        $this->response->render('admin/story-quality-rules', [
            'user'          => $user,
            'active_page'   => 'admin',
            'rules'         => $rules,
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Add a custom splitting pattern or mandatory condition.
     *
     * POST /app/admin/story-quality-rules
     */
    public function store(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $ruleType = $this->request->post('rule_type', '');
        $label    = trim((string) $this->request->post('label', ''));

        if ($label === '') {
            $_SESSION['flash_error'] = 'Label is required.';
            $this->response->redirect('/app/admin/story-quality-rules');
            return;
        }

        if (!in_array($ruleType, ['splitting_pattern', 'mandatory_condition'], true)) {
            $_SESSION['flash_error'] = 'Invalid rule type.';
            $this->response->redirect('/app/admin/story-quality-rules');
            return;
        }

        StoryQualityConfig::create($this->db, [
            'org_id'    => $orgId,
            'rule_type' => $ruleType,
            'label'     => $label,
        ]);

        $_SESSION['flash_message'] = 'Quality rule added.';
        $this->response->redirect('/app/admin/story-quality-rules');
    }

    /**
     * Delete a custom quality rule (defaults are protected).
     *
     * POST /app/admin/story-quality-rules/{id}/delete
     *
     * @param string|int $id Rule primary key from route
     */
    public function delete(string|int $id): void
    {
        $id    = (int) $id;
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        StoryQualityConfig::delete($this->db, $id, $orgId);

        $_SESSION['flash_message'] = 'Rule removed.';
        $this->response->redirect('/app/admin/story-quality-rules');
    }
}
