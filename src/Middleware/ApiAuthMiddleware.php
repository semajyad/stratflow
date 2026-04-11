<?php
/**
 * ApiAuthMiddleware
 *
 * Authenticates JSON API requests using a Personal Access Token (PAT)
 * supplied in the Authorization header:
 *
 *   Authorization: Bearer sf_pat_<token>
 *
 * On success, calls Auth::loginAsPrincipal() which sets an in-memory
 * principal so that downstream controllers can call $auth->user() /
 * $auth->orgId() exactly as they would for a session-authenticated request —
 * without touching the session or setting any cookies.
 *
 * On failure, responds immediately with a 401 JSON payload and returns false
 * (never redirects to /login — API clients must not follow HTML redirects).
 */

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Response;
use StratFlow\Models\PersonalAccessToken;

class ApiAuthMiddleware
{
    /**
     * Validate the PAT and inject a principal into $auth.
     *
     * @param Auth     $auth
     * @param Database $db
     * @param Response $response
     * @return bool True if authenticated, false (with JSON 401 sent) if not
     */
    public function handle(Auth $auth, Database $db, Response $response): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($authHeader, 'Bearer sf_pat_')) {
            $this->unauthorized($response, 'Missing or invalid Authorization header. Expected: Bearer sf_pat_...');
            return false;
        }

        $raw  = substr($authHeader, 7); // strip "Bearer "
        $hash = PersonalAccessToken::hash($raw);
        $token = PersonalAccessToken::findByHash($db, $hash);

        if ($token === null) {
            $this->unauthorized($response, 'Token not found, revoked, or expired. Regenerate at /app/account/tokens');
            return false;
        }

        // Load the full user row — needed to populate the principal
        $user = $db->query(
            'SELECT id, org_id, full_name, email, role,
                    has_billing_access, has_executive_access, is_project_admin,
                    jira_account_id, team
             FROM users
             WHERE id = :id AND org_id = :org_id
             LIMIT 1',
            [':id' => $token['user_id'], ':org_id' => $token['org_id']]
        )->fetch();

        if (!$user) {
            // Token exists but user was deleted or moved to a different org
            $this->unauthorized($response, 'Token owner not found');
            return false;
        }

        $auth->loginAsPrincipal($user);

        // Fire-and-forget — touch happens after response in the happy path
        PersonalAccessToken::touchLastUsed($db, (int) $token['id'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return true;
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    private function unauthorized(Response $response, string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized', 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
