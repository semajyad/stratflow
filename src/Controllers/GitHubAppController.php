<?php

/**
 * GitHubAppController
 *
 * Admin actions for the GitHub App installation flow.
 *
 * install()    — redirect admin to GitHub's install page
 * callback()   — receive installation_id back from GitHub, persist integration
 * disconnect() — mark a specific integration as revoked
 *
 * All routes require 'auth' + 'admin'. The callback route omits 'csrf' because
 * GitHub redirects back to us (browser GET); it is protected by a state nonce
 * stored in session by install() and verified in callback().
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Integration;
use StratFlow\Models\IntegrationRepo;
use StratFlow\Services\GitHubAppClient;

class GitHubAppController
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
     * Redirect the admin to the GitHub App installation page.
     *
     * Stores a random state nonce in session; GitHub echoes it back in the
     * callback so we can verify the redirect is genuine and hasn't been
     * tampered with.
     *
     * GET /app/admin/integrations/github/install
     */
    public function install(): void
    {
        $appSlug = $_ENV['GITHUB_APP_SLUG'] ?? '';
        if ($appSlug === '') {
            $_SESSION['flash_error'] = 'GITHUB_APP_SLUG is not configured.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['github_install_state'] = $state;
        $installUrl = 'https://github.com/apps/' . rawurlencode($appSlug)
            . '/installations/new?state=' . rawurlencode($state);
        $this->response->redirect($installUrl);
    }

    /**
     * Handle the redirect back from GitHub after the admin installs the App.
     *
     * GitHub sends: ?installation_id=123&setup_action=install&state=<nonce>
     *
     * 1. Verify the state nonce matches what we stored.
     * 2. Fetch the repo list via GitHubAppClient.
     * 3. Insert a new integrations row (creates a fresh one per GitHub account
     *    so orgs can have multiple installations).
     * 4. Bulk-upsert integration_repos rows.
     *
     * GET /app/admin/integrations/github/callback
     */
    public function callback(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
// --- State nonce verification ---
        $state         = $this->request->get('state', '');
        $sessionState  = $_SESSION['github_install_state'] ?? '';
        unset($_SESSION['github_install_state']);
        if ($state === '' || !hash_equals($sessionState, $state)) {
            $_SESSION['flash_error'] = 'Invalid GitHub install state. Please try again.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $installationId = (int) $this->request->get('installation_id', '0');
        $setupAction    = $this->request->get('setup_action', '');
        if ($installationId === 0) {
            $_SESSION['flash_error'] = 'Missing installation_id from GitHub callback.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        // Handle delete/cancel — GitHub redirects with setup_action=delete on uninstall
        if ($setupAction === 'delete') {
// Handled via webhook; nothing to persist here
            $_SESSION['flash_message'] = 'GitHub App uninstalled.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        try {
// --- Fetch repo list and account info ---
            $jwt         = GitHubAppClient::mintAppJwt();
            $repos       = GitHubAppClient::listInstallationRepos($installationId);
            $accountLogin = $this->resolveAccountLogin($installationId, $jwt);
            $this->db->getPdo()->beginTransaction();
// Create a new integrations row for this GitHub account.
            // We do NOT upsert — an org can accumulate many installations.
            // If this installation_id already exists (double-click), the UNIQUE
            // key on (provider, installation_id) will prevent a duplicate and we
            // find + reactivate the existing row instead.
            // Check for any existing row with this installation_id (active, inactive, or revoked).
            // findActiveByInstallationId only finds 'active' rows; we also need to find
            // revoked/inactive rows here to handle re-installs gracefully instead of
            // hitting the UNIQUE key constraint and throwing a confusing error.
            $existingStmt = $this->db->query("SELECT id, org_id FROM integrations
                 WHERE provider = 'github' AND installation_id = :id
                 LIMIT 1", [':id' => $installationId]);
            $existingRow = $existingStmt->fetch();
            if ($existingRow !== false) {
            // Re-activating an existing (possibly revoked/inactive) integration.
                // Verify it still belongs to this org — prevents one org from hijacking
                // another org's installation_id via the callback.
                if ((int) $existingRow['org_id'] !== $orgId) {
                    $this->db->getPdo()->rollBack();
                    \StratFlow\Services\Logger::warn('[GitHubApp] callback: installation_id=' . $installationId . ' org mismatch');
                    $_SESSION['flash_error'] = 'This GitHub installation is already connected to a different organisation.';
                    $this->response->redirect('/app/admin/integrations');
                    return;
                }
                $integrationId = (int) $existingRow['id'];
                Integration::update($this->db, $integrationId, [
                    'status'        => 'active',
                    'account_login' => $accountLogin,
                ]);
            } else {
                $integrationId = Integration::create($this->db, [
                    'org_id'          => $orgId,
                    'provider'        => 'github',
                    'display_name'    => 'GitHub App — ' . $accountLogin,
                    'status'          => 'active',
                    'installation_id' => $installationId,
                    'account_login'   => $accountLogin,
                    'config_json'     => '{}',
                ]);
            }

            // Bulk-upsert repos
            foreach ($repos as $repo) {
                IntegrationRepo::upsert($this->db, $integrationId, $orgId, $repo['id'], $repo['full_name']);
            }

            $this->db->getPdo()->commit();
            $repoCount = count($repos);
            $_SESSION['flash_message'] = sprintf('Connected to @%s — %d %s linked.', $accountLogin, $repoCount, $repoCount === 1 ? 'repo' : 'repos');
        } catch (\Throwable $e) {
            if ($this->db->getPdo()->inTransaction()) {
                $this->db->getPdo()->rollBack();
            }
            \StratFlow\Services\Logger::warn('[GitHubApp] callback error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to complete GitHub App installation. Please try again.';
        }

        $this->response->redirect('/app/admin/integrations');
    }

    /**
     * Disconnect a specific GitHub App installation.
     *
     * Marks the integration as 'revoked'. Does not delete integration_repos rows
     * immediately — they are cascade-deleted if the row is later hard-deleted,
     * or can be reactivated. The GitHub App itself is not uninstalled; the admin
     * must do that from github.com/settings/installations.
     *
     * POST /app/admin/integrations/github/{id}/disconnect
     */
    public function disconnect(string|int $id): void
    {
        $id    = (int) $id;
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
// Verify the integration belongs to this org before updating
        $stmt = $this->db->query("SELECT id FROM integrations
             WHERE id = :id AND org_id = :org_id AND provider = 'github'
             LIMIT 1", [':id' => $id, ':org_id' => $orgId]);
        if ($stmt->fetch() === false) {
            $_SESSION['flash_error'] = 'GitHub integration not found.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        Integration::update($this->db, $id, ['status' => 'revoked']);
        $_SESSION['flash_message'] = 'GitHub installation disconnected.';
        $this->response->redirect('/app/admin/integrations');
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Fetch the account login for a newly installed GitHub App.
     *
     * Uses the App JWT to call GET /app/installations/{id} and returns the
     * account.login field. Falls back to 'unknown' on any API error so the
     * install flow is not blocked.
     *
     * @param int    $installationId GitHub installation ID
     * @param string $jwt            App-level JWT
     * @return string                GitHub account login or 'unknown'
     */
    private function resolveAccountLogin(int $installationId, string $jwt): string
    {
        try {
            $ch = curl_init('https://api.github.com/app/installations/' . $installationId);
            if ($ch === false) {
                return 'unknown';
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $jwt,
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                    'User-Agent: StratFlow-GitHub-App',
                ],
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $code !== 200) {
                return 'unknown';
            }

            $data = json_decode((string) $raw, true);
            return (string) ($data['account']['login'] ?? 'unknown');
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
