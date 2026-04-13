<?php
/**
 * ProjectGitHubController
 *
 * Per-project GitHub repo subscription management.
 *
 * A project manager selects which repos (from any GitHub App installation in
 * their org) should feed PR links into their project. The selection is stored
 * in project_repo_links as a many-to-many relationship.
 *
 * edit() — render the repo picker for a project
 * save() — persist the updated selection (diff-then-apply in a transaction)
 *
 * Routes require 'auth' + 'csrf' (on POST). No admin gate — any project member
 * should be able to subscribe their project to repos.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\IntegrationRepo;
use StratFlow\Models\Project;
use StratFlow\Models\ProjectRepoLink;
use StratFlow\Security\ProjectPolicy;

class ProjectGitHubController
{
    // ===========================
    // PROPERTIES
    // ===========================

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
    // ACTIONS
    // ===========================

    /**
     * Render the GitHub repo picker for a project.
     *
     * The picker shows every repo available across all active GitHub App
     * installations in the org, grouped by account_login. Currently linked
     * repos are pre-checked.
     *
     * GET /app/projects/{id}/github/edit
     */
    public function edit(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $project = ProjectPolicy::findManageableProject($this->db, $user, $id);
        if ($project === null) {
            $_SESSION['flash_error'] = 'Project not found.';
            $this->response->redirect('/app/home');
            return;
        }

        $allRepos    = IntegrationRepo::findAllForOrg($this->db, $orgId);
        $linkedIds   = ProjectRepoLink::findRepoIdsByProject($this->db, $id, $orgId);
        $linkedIdSet = array_flip($linkedIds);

        // Group repos by GitHub account login for the template
        $reposByAccount = [];
        foreach ($allRepos as $repo) {
            $account = $repo['account_login'] ?? 'Unknown account';
            $reposByAccount[$account][] = $repo;
        }

        $this->response->render('project/github-repos', [
            'user'            => $user,
            'project'         => $project,
            'repos_by_account' => $reposByAccount,
            'linked_id_set'   => $linkedIdSet,
            'csrf_token'      => $_SESSION['csrf_token'] ?? '',
            'active_page'     => 'projects',
            'flash_message'   => $_SESSION['flash_message'] ?? null,
            'flash_error'     => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save the repo selection for a project.
     *
     * Reads the posted integration_repo_ids[], validates each belongs to the
     * current org, then applies the diff (add new links, remove deselected ones)
     * in a transaction.
     *
     * POST /app/projects/{id}/github/save
     */
    public function save(int $id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $userId = (int) $user['id'];

        $project = ProjectPolicy::findManageableProject($this->db, $user, $id);
        if ($project === null) {
            $_SESSION['flash_error'] = 'Project not found.';
            $this->response->redirect('/app/home');
            return;
        }

        // Collect submitted repo IDs — may be empty if admin unchecked all
        $submitted = $this->request->post('integration_repo_ids', []);
        if (!is_array($submitted)) {
            $submitted = [];
        }
        $submittedIds = array_map('intval', $submitted);

        // Validate each submitted ID belongs to this org (prevent cross-org tamper)
        $validatedIds = [];
        foreach ($submittedIds as $repoId) {
            if ($repoId > 0 && IntegrationRepo::findByIdForOrg($this->db, $repoId, $orgId) !== null) {
                $validatedIds[] = $repoId;
            }
        }

        $currentIds   = ProjectRepoLink::findRepoIdsByProject($this->db, $id, $orgId);
        $currentSet   = array_flip($currentIds);
        $validatedSet = array_flip($validatedIds);

        $toAdd    = array_diff_key($validatedSet, $currentSet);
        $toRemove = array_diff_key($currentSet, $validatedSet);

        if (empty($toAdd) && empty($toRemove)) {
            $_SESSION['flash_message'] = 'No changes to save.';
            $this->response->redirect('/app/projects/' . $id . '/github/edit');
            return;
        }

        try {
            $this->db->getPdo()->beginTransaction();

            foreach (array_keys($toAdd) as $repoId) {
                ProjectRepoLink::create($this->db, $id, (int) $repoId, $orgId, $userId);
            }

            foreach (array_keys($toRemove) as $repoId) {
                ProjectRepoLink::delete($this->db, $id, (int) $repoId, $orgId);
            }

            $this->db->getPdo()->commit();

            $added   = count($toAdd);
            $removed = count($toRemove);
            $parts   = [];
            if ($added > 0) {
                $parts[] = $added . ' repo' . ($added === 1 ? '' : 's') . ' added';
            }
            if ($removed > 0) {
                $parts[] = $removed . ' repo' . ($removed === 1 ? '' : 's') . ' removed';
            }
            $_SESSION['flash_message'] = implode(', ', $parts) . '.';
        } catch (\Throwable $e) {
            if ($this->db->getPdo()->inTransaction()) {
                $this->db->getPdo()->rollBack();
            }
            \StratFlow\Services\Logger::warn('[ProjectGitHub] save error project_id=' . $id . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to save repo selection. Please try again.';
        }

        $this->response->redirect('/app/projects/' . $id . '/github/edit');
    }
}
