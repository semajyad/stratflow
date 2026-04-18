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
    private const DEFAULT_SCOPES = [
        'profile:read',
        'profile:write',
        'projects:read',
        'stories:read',
        'stories:write-status',
        'stories:assign',
    ];
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

        $raw  = substr($authHeader, 7);
// strip "Bearer "
        $hash = PersonalAccessToken::hash($raw);
        $token = PersonalAccessToken::findByHash($db, $hash);
        if ($token === null) {
            $this->unauthorized($response, 'Token not found, revoked, or expired. Regenerate at /app/account/tokens');
            return false;
        }

        // Load the full user row — needed to populate the principal
        $selectColumns = [
            'id', 'org_id', 'full_name', 'email', 'role',
            'has_billing_access', 'has_executive_access', 'is_project_admin',
            'jira_account_id', 'team',
        ];
        try {
            $hasAccountType = (bool) $db->query("SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'users'
                   AND column_name = 'account_type'
                 LIMIT 1")->fetch();
            if ($hasAccountType) {
                $selectColumns[] = 'account_type';
            }
        } catch (\Throwable) {
        // Older databases do not have account_type yet.
        }

        $user = $db->query('SELECT ' . implode(', ', $selectColumns) . '
              FROM users
             WHERE id = :id AND org_id = :org_id AND is_active = 1
             LIMIT 1', [':id' => $token['user_id'], ':org_id' => $token['org_id']])->fetch();
        if (!$user) {
        // Token exists but user was deleted or moved to a different org
            $this->unauthorized($response, 'Token owner not found');
            return false;
        }

        if (!$this->isScopeAllowed($token)) {
            $this->forbidden($response, 'Token scope does not permit this action.');
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
    }

    private function forbidden(Response $response, string $message): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden', 'message' => $message], JSON_UNESCAPED_SLASHES);
    }

    private function isScopeAllowed(array $token): bool
    {
        $requiredScope = $this->requiredScopeForRequest();
        if ($requiredScope === null) {
            return true;
        }

        $scopes = $this->extractScopes($token['scopes'] ?? null);
        return in_array($requiredScope, $scopes, true);
    }

    private function extractScopes(mixed $rawScopes): array
    {
        if (is_string($rawScopes) && $rawScopes !== '') {
            $decoded = json_decode($rawScopes, true);
            if (is_array($decoded)) {
                $rawScopes = $decoded;
            }
        }

        if (!is_array($rawScopes) || $rawScopes === []) {
            return self::DEFAULT_SCOPES;
        }

        $scopes = array_values(array_filter(array_map(static fn(mixed $scope): string => is_string($scope) ? trim($scope) : '', $rawScopes)));
        return $scopes === [] ? self::DEFAULT_SCOPES : $scopes;
    }

    private function requiredScopeForRequest(): ?string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        return match (true) {
            $method === 'GET'  && $path === '/api/v1/me' => 'profile:read',
            $method === 'POST' && $path === '/api/v1/me/team' => 'profile:write',
            $method === 'GET'  && $path === '/api/v1/projects' => 'projects:read',
            $method === 'GET'  && ($path === '/api/v1/stories' || $path === '/api/v1/stories/team' || preg_match('#^/api/v1/stories/\d+$#', $path) === 1) => 'stories:read',
            $method === 'POST' && preg_match('#^/api/v1/stories/\d+/status$#', $path) === 1 => 'stories:write-status',
            $method === 'POST' && preg_match('#^/api/v1/stories/\d+/assign$#', $path) === 1 => 'stories:assign',
            default => null,
        };
    }
}
