<?php
/**
 * AccessTokenController
 *
 * Manages Personal Access Tokens (PATs) for the authenticated user.
 * Tokens are used by external tooling (e.g. stratflow-mcp) to authenticate
 * against the JSON API (/api/v1/*) without a browser session.
 *
 * All routes require 'auth' middleware. Token creation and revocation
 * also require 'csrf' middleware.
 *
 * Routes:
 *   GET  /app/account/tokens             — list tokens
 *   POST /app/account/tokens             — create token
 *   POST /app/account/tokens/{id}/revoke — revoke token
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\PersonalAccessToken;
use StratFlow\Models\User;
use StratFlow\Services\AuditLogger;

class AccessTokenController
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

    // ===========================
    // LIST
    // ===========================

    /**
     * Render the token management page.
     *
     * Displays all active tokens (prefix + name + last_used_at).
     * If a freshly-created token was flashed into the session, it is
     * shown once in a copy-to-clipboard box and then cleared.
     */
    public function index(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $userId = (int) $user['id'];

        $tokens = PersonalAccessToken::listForUser($this->db, $userId, $orgId);

        // Consume the one-time flash of the raw token (shown once at creation)
        $newTokenRaw = $_SESSION['_flash']['new_pat'] ?? null;
        unset($_SESSION['_flash']['new_pat']);

        $this->response->render('account/access-tokens', [
            'user'          => $user,
            'tokens'        => $tokens,
            'new_token_raw' => $newTokenRaw,
            'app_url'       => rtrim($this->config['app']['url'] ?? '', '/'),
            'active_page'   => 'account-tokens',
        ], 'app');
    }

    // ===========================
    // CREATE
    // ===========================

    /**
     * Generate and persist a new PAT, flash the raw value once, then redirect.
     *
     * The raw token is never retrievable after this redirect.
     */
    public function create(): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $userId = (int) $user['id'];

        $name = trim($this->request->post('name', ''));
        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Token name is required.';
            $this->response->redirect('/app/account/tokens');
            return;
        }

        if (strlen($name) > 100) {
            $_SESSION['_flash']['error'] = 'Token name must be 100 characters or fewer.';
            $this->response->redirect('/app/account/tokens');
            return;
        }

        $generated = PersonalAccessToken::generate();
        PersonalAccessToken::create(
            $this->db,
            $userId,
            $orgId,
            $name,
            $generated['raw'],
            $generated['prefix']
        );

        // Flash the raw token — shown once, not stored in DB
        $_SESSION['_flash']['new_pat'] = $generated['raw'];

        AuditLogger::log(
            $this->db,
            $userId,
            AuditLogger::API_KEY_USED,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'pat_created', 'name' => $name, 'prefix' => $generated['prefix']]
        );

        $this->response->redirect('/app/account/tokens');
    }

    // ===========================
    // REVOKE
    // ===========================

    /**
     * Revoke a token owned by the current user.
     *
     * Scoped by both user_id and org_id — cannot revoke another user's token.
     */
    public function revoke(string $id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $userId = (int) $user['id'];
        $tokenId = (int) $id;

        $revoked = PersonalAccessToken::revoke($this->db, $tokenId, $userId, $orgId);

        if ($revoked) {
            AuditLogger::log(
                $this->db,
                $userId,
                AuditLogger::API_KEY_USED,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                ['action' => 'pat_revoked', 'token_id' => $tokenId]
            );
            $_SESSION['_flash']['success'] = 'Token revoked successfully.';
        } else {
            $_SESSION['_flash']['error'] = 'Token not found or already revoked.';
        }

        $this->response->redirect('/app/account/tokens');
    }

    /**
     * Save the authenticated user's team membership.
     *
     * Used by the team card on the Developer Tokens page so the MCP
     * list_team_stories tool knows which team to filter by.
     */
    public function saveTeam(): void
    {
        $user   = $this->auth->user();
        $userId = (int) $user['id'];

        $team = trim((string) ($_POST['team'] ?? ''));

        User::update($this->db, $userId, ['team' => $team !== '' ? $team : null]);

        $_SESSION['_flash']['success'] = 'Team saved.';
        $this->response->redirect('/app/account/tokens');
    }
}
