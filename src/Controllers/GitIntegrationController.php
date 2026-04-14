<?php

/**
 * GitIntegrationController
 *
 * Admin actions for GitHub and GitLab webhook integration management.
 * Handles connect (create integration + generate secret), disconnect,
 * and secret regeneration.
 *
 * All routes require 'auth' + 'admin' + 'csrf' middleware.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Integration;

class GitIntegrationController
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
     * Connect or re-connect a Git provider webhook integration.
     *
     * Creates the integration row if it doesn't exist, or reactivates an
     * existing one. Generates a fresh 32-byte hex webhook secret and stores
     * it in config_json.webhook_secret.
     *
     * POST /app/admin/integrations/git/{provider}/connect
     */
    public function connect(string $provider): void
    {
        if (!$this->isValidProvider($provider)) {
            $_SESSION['flash_error'] = 'Unknown Git provider.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $secret = bin2hex(random_bytes(32));
        $config = json_encode(['webhook_secret' => $secret]);
        $existing = Integration::findByOrgAndProvider($this->db, $orgId, $provider);
        if ($existing) {
            $existingConfig = json_decode($existing['config_json'] ?? '{}', true) ?: [];
            $existingConfig['webhook_secret'] = $secret;
            Integration::update($this->db, (int) $existing['id'], [
                'status'      => 'active',
                'config_json' => json_encode($existingConfig),
            ]);
        } else {
            Integration::create($this->db, [
                'org_id'      => $orgId,
                'provider'    => $provider,
                'display_name' => ucfirst($provider) . ' Webhook',
                'status'      => 'active',
                'config_json' => $config,
            ]);
        }

        $_SESSION['flash_message'] = ucfirst($provider) . ' webhook connected. Copy the secret below and add it to your repository settings.';
        $this->response->redirect('/app/admin/integrations');
    }

    /**
     * Disconnect a Git provider webhook integration.
     *
     * Sets status to 'inactive' but preserves the integration row and secret
     * so it can be reconnected without losing config.
     *
     * POST /app/admin/integrations/git/{provider}/disconnect
     */
    public function disconnect(string $provider): void
    {
        if (!$this->isValidProvider($provider)) {
            $_SESSION['flash_error'] = 'Unknown Git provider.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $existing = Integration::findByOrgAndProvider($this->db, $orgId, $provider);
        if ($existing) {
            Integration::update($this->db, (int) $existing['id'], ['status' => 'inactive']);
        }

        $_SESSION['flash_message'] = ucfirst($provider) . ' webhook disconnected.';
        $this->response->redirect('/app/admin/integrations');
    }

    /**
     * Regenerate the webhook secret for a Git provider integration.
     *
     * Rolls the secret without changing the integration status.
     * The user must update their repository webhook settings with the new secret.
     *
     * POST /app/admin/integrations/git/{provider}/regenerate-secret
     */
    public function regenerateSecret(string $provider): void
    {
        if (!$this->isValidProvider($provider)) {
            $_SESSION['flash_error'] = 'Unknown Git provider.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $existing = Integration::findByOrgAndProvider($this->db, $orgId, $provider);
        if (!$existing) {
            $_SESSION['flash_error'] = ucfirst($provider) . ' is not connected.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $newSecret = bin2hex(random_bytes(32));
        $config    = json_decode($existing['config_json'] ?? '{}', true) ?: [];
        $config['webhook_secret'] = $newSecret;
        Integration::update($this->db, (int) $existing['id'], [
            'config_json' => json_encode($config),
        ]);
        $_SESSION['flash_message'] = ucfirst($provider) . ' webhook secret regenerated. Update your repository webhook settings with the new secret.';
        $this->response->redirect('/app/admin/integrations');
    }

    /**
     * Return the plaintext webhook secret as JSON for an admin user.
     *
     * Used by the admin UI's "Reveal" button instead of embedding the
     * secret in the HTML source on first paint. Requires auth+admin+csrf
     * middleware so only a logged-in org admin for the same org can fetch it.
     *
     * POST /app/admin/integrations/git/{provider}/reveal-secret
     */
    public function revealSecret(string $provider): void
    {
        if (!$this->isValidProvider($provider)) {
            $this->response->json(['error' => 'Unknown Git provider.'], 400);
            return;
        }

        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $existing = Integration::findByOrgAndProvider($this->db, $orgId, $provider);
        if (!$existing) {
            $this->response->json(['error' => ucfirst($provider) . ' is not connected.'], 404);
            return;
        }

        $config = json_decode($existing['config_json'] ?? '{}', true) ?: [];
        $secret = $config['webhook_secret'] ?? '';
        if ($secret === '') {
            $this->response->json(['error' => 'No secret set.'], 404);
            return;
        }

        $this->response->json(['secret' => $secret]);
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Validate the provider string against the allowed set.
     *
     * @param string $provider Provider name from the URL
     * @return bool            True for 'github' or 'gitlab'
     */
    private function isValidProvider(string $provider): bool
    {
        return in_array($provider, ['github', 'gitlab'], true);
    }
}
