<?php

/**
 * GitHubAppClient
 *
 * GitHub App authentication and webhook utilities.
 *
 * Responsibilities:
 *  - Mint App-level JWTs (RS256) for GitHub API authentication
 *  - Exchange JWTs for short-lived installation access tokens
 *  - Cache installation tokens for their lifetime (stored in integrations table)
 *  - Verify X-Hub-Signature-256 webhook signatures (App-level shared secret)
 *  - Parse pull_request and installation webhook payloads
 *
 * Environment variables required:
 *   GITHUB_APP_ID              Numeric GitHub App ID
 *   GITHUB_APP_PRIVATE_KEY_PATH Absolute path to the PEM private key (chmod 600)
 *   GITHUB_APP_WEBHOOK_SECRET  Shared secret set on the GitHub App
 *   GITHUB_APP_SLUG            URL slug of the App (for install redirect URL)
 */

declare(strict_types=1);

namespace StratFlow\Services;

class GitHubAppClient
{
    private const GITHUB_API_BASE = 'https://api.github.com';
// Per-process token cache: installation_id => ['token' => string, 'expires_at' => int]
    /** @var array<int, array{token: string, expires_at: int}> */
    private static array $tokenCache = [];
// ===========================
    // JWT / TOKEN MINTING
    // ===========================

    /**
     * Mint a GitHub App JWT valid for up to 10 minutes.
     *
     * Uses RS256 (PKCS#1 RSA-SHA256). The JWT is used to authenticate App-level
     * API calls (e.g. minting installation tokens).
     *
     * @return string Signed JWT
     * @throws \RuntimeException if private key path is not configured or unreadable
     */
    public static function mintAppJwt(): string
    {
        $appId = $_ENV['GITHUB_APP_ID'] ?? '';
        if ($appId === '') {
            throw new \RuntimeException('[GitHubAppClient] GITHUB_APP_ID not configured');
        }

        // Prefer inline PEM content (GITHUB_APP_PRIVATE_KEY env var) over a file path.
        // On Railway and other PaaS environments, storing the key as an env var is
        // simpler than managing file mounts. The path-based fallback supports local Docker.
        $pem = $_ENV['GITHUB_APP_PRIVATE_KEY'] ?? '';
        if ($pem === '') {
            $keyPath = $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] ?? '';
            if ($keyPath === '') {
                throw new \RuntimeException('[GitHubAppClient] Neither GITHUB_APP_PRIVATE_KEY nor GITHUB_APP_PRIVATE_KEY_PATH is configured');
            }
            $pem = file_get_contents($keyPath);
            if ($pem === false) {
                throw new \RuntimeException('[GitHubAppClient] Cannot read private key at: ' . $keyPath);
            }
        }

        // Railway stores env vars as single-line; restore newlines if collapsed.
        $pem = str_replace('\n', "\n", $pem);
        $now = time();
        $header  = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            'iat' => $now - 60,   // allow 60s clock skew
            'exp' => $now + 540,  // 9 minutes (GitHub max is 10)
            'iss' => $appId,
        ], JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $payload;
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('[GitHubAppClient] Failed to load RSA private key');
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('[GitHubAppClient] openssl_sign failed');
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Return a valid installation access token, minting a fresh one if needed.
     *
     * Tokens are cached per installation_id for the duration of the process.
     * GitHub installation tokens last 1 hour; we refresh 5 minutes early.
     *
     * @param int $installationId GitHub installation ID
     * @return string             Installation access token
     * @throws \RuntimeException  on API failure
     */
    public static function getInstallationToken(int $installationId): string
    {
        $cached = self::$tokenCache[$installationId] ?? null;
        if ($cached !== null && $cached['expires_at'] > time() + 300) {
            return $cached['token'];
        }

        $jwt = self::mintAppJwt();
        $url = self::GITHUB_API_BASE . '/app/installations/' . $installationId . '/access_tokens';
        $response = self::apiPost($url, [], $jwt);
        $token     = $response['token'] ?? '';
        $expiresAt = $response['expires_at'] ?? '';
        if ($token === '') {
            throw new \RuntimeException('[GitHubAppClient] Empty token in installation access_tokens response');
        }

        self::$tokenCache[$installationId] = [
            'token'      => $token,
            'expires_at' => $expiresAt !== '' ? (int) strtotime($expiresAt) : time() + 3600,
        ];
        return $token;
    }

    // ===========================
    // REPO LISTING
    // ===========================

    /**
     * Return the list of repos accessible to a GitHub App installation.
     *
     * Paginates until all repos are fetched (GitHub returns up to 100 per page).
     *
     * @param int $installationId GitHub installation ID
     * @return array              Array of ['id' => int, 'full_name' => string]
     * @throws \RuntimeException  on API failure
     */
    public static function listInstallationRepos(int $installationId): array
    {
        $token = self::getInstallationToken($installationId);
        $repos = [];
        $page  = 1;
        do {
            $url  = self::GITHUB_API_BASE . '/installation/repositories?per_page=100&page=' . $page;
            $body = self::apiGet($url, $token);
            $batch = $body['repositories'] ?? [];
            foreach ($batch as $repo) {
                $repos[] = [
                    'id'        => (int) $repo['id'],
                    'full_name' => (string) $repo['full_name'],
                ];
            }

            $total = (int) ($body['total_count'] ?? 0);
            $page++;
        } while (count($repos) < $total && count($batch) === 100);
        return $repos;
    }

    // ===========================
    // WEBHOOK VERIFICATION
    // ===========================

    /**
     * Verify a GitHub X-Hub-Signature-256 header against the App-level secret.
     *
     * Uses hash_equals for constant-time comparison to prevent timing attacks.
     * The secret comes from GITHUB_APP_WEBHOOK_SECRET env var.
     *
     * @param string $rawBody         Raw request body bytes
     * @param string $signatureHeader Value of X-Hub-Signature-256 header
     * @return bool                   True if signature is valid
     */
    public static function verifySignature(string $rawBody, string $signatureHeader): bool
    {
        $secret = $_ENV['GITHUB_APP_WEBHOOK_SECRET'] ?? '';
        if ($secret === '') {
            \StratFlow\Services\Logger::warn('[GitHubAppClient] GITHUB_APP_WEBHOOK_SECRET not configured');
            return false;
        }

        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $providedHash = substr($signatureHeader, 7);
        $expectedHash = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expectedHash, $providedHash);
    }

    // ===========================
    // PAYLOAD PARSERS
    // ===========================

    /**
     * Extract normalised event data from a GitHub pull_request webhook payload.
     *
     * Returns null if the payload is not a supported PR event.
     * Supported actions: opened, reopened, synchronize, closed.
     *
     * @param array $payload Decoded JSON payload
     * @return array|null    Normalised event data or null
     *                       Shape: ['action', 'merged', 'pr_url', 'title',
     *                               'body', 'author', 'repo_github_id']
     */
    public static function parsePullRequestEvent(array $payload): ?array
    {
        if (!isset($payload['pull_request'])) {
            return null;
        }

        $pr     = $payload['pull_request'];
        $action = $payload['action'] ?? '';
        if (!in_array($action, ['opened', 'reopened', 'synchronize', 'closed'], true)) {
            return null;
        }

        return [
            'action'         => $action,
            'merged'         => ($action === 'closed') && ($pr['merged'] ?? false),
            'pr_url'         => $pr['html_url'] ?? '',
            'title'          => $pr['title'] ?? '',
            'body'           => $pr['body'] ?? '',
            'branch'         => $pr['head']['ref'] ?? '',
            'author'         => $pr['user']['login'] ?? null,
            'repo_github_id' => (int) ($payload['repository']['id'] ?? 0),
        ];
    }

    /**
     * Parse an installation_repositories event (repos added/removed from an install).
     *
     * @param array $payload Decoded JSON payload
     * @return array|null    ['installation_id', 'added' => [...], 'removed' => [...]]
     *                       Each item: ['id' => int, 'full_name' => string]
     */
    public static function parseInstallationReposEvent(array $payload): ?array
    {
        if (!isset($payload['installation']) || !isset($payload['repositories_added'], $payload['repositories_removed'])) {
            return null;
        }

        $map = static function (array $list): array {

            $out = [];
            foreach ($list as $r) {
                $out[] = ['id' => (int) $r['id'], 'full_name' => (string) $r['full_name']];
            }
            return $out;
        };
        return [
            'installation_id' => (int) $payload['installation']['id'],
            'added'           => $map($payload['repositories_added']),
            'removed'         => $map($payload['repositories_removed']),
        ];
    }

    /**
     * Parse an installation event (new install or uninstall of the App).
     *
     * @param array $payload Decoded JSON payload
     * @return array|null    ['installation_id', 'action', 'account_login', 'account_type']
     */
    /**
     * Parse a GitHub push event payload into commit data.
     *
     * Returns null if the payload has no commits (e.g. tag pushes).
     *
     * @param array $payload Decoded push webhook payload
     * @return array|null    ['repo_github_id', 'repo_full_name', 'branch', 'commits']
     *                       Each commit: ['sha', 'message', 'url', 'author']
     */
    public static function parsePushEvent(array $payload): ?array
    {
        $commits = $payload['commits'] ?? [];
        if (empty($commits)) {
            return null;
        }

        $ref    = $payload['ref'] ?? '';
        $branch = preg_replace('#^refs/heads/#', '', $ref);
        $parsed = [];
        foreach ($commits as $c) {
            $parsed[] = [
                'sha'     => (string) ($c['id'] ?? ''),
                'message' => (string) ($c['message'] ?? ''),
                'url'     => (string) ($c['url'] ?? ''),
                'author'  => $c['author']['username'] ?? ($c['author']['name'] ?? null),
            ];
        }

        return [
            'repo_github_id' => (int) ($payload['repository']['id'] ?? 0),
            'repo_full_name' => (string) ($payload['repository']['full_name'] ?? ''),
            'branch'         => $branch,
            'commits'        => $parsed,
        ];
    }

    public static function parseInstallationEvent(array $payload): ?array
    {
        if (!isset($payload['installation'])) {
            return null;
        }

        $install = $payload['installation'];
        $action  = $payload['action'] ?? '';
        if (!in_array($action, ['created', 'deleted', 'suspend', 'unsuspend'], true)) {
            return null;
        }

        return [
            'installation_id' => (int) $install['id'],
            'action'          => $action,
            'account_login'   => (string) ($install['account']['login'] ?? ''),
            'account_type'    => (string) ($install['account']['type'] ?? ''),
        ];
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Make an authenticated GET request to the GitHub API.
     *
     * @param string $url   Full API URL
     * @param string $token Bearer token (installation or App JWT)
     * @return array        Decoded JSON response body
     * @throws \RuntimeException on curl error or non-2xx response
     */
    private static function apiGet(string $url, string $token): array
    {
        return self::apiRequest('GET', $url, [], $token);
    }

    /**
     * Make an authenticated POST request to the GitHub API.
     *
     * @param string $url     Full API URL
     * @param array  $body    Request body (encoded as JSON)
     * @param string $token   Bearer token
     * @return array          Decoded JSON response body
     * @throws \RuntimeException on curl error or non-2xx response
     */
    private static function apiPost(string $url, array $body, string $token): array
    {
        return self::apiRequest('POST', $url, $body, $token);
    }

    /**
     * Execute a curl request to the GitHub API.
     */
    private static function apiRequest(string $method, string $url, array $body, string $token): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('[GitHubAppClient] curl_init failed');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: StratFlow-GitHub-App',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err !== '') {
            throw new \RuntimeException('[GitHubAppClient] curl error: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('[GitHubAppClient] Invalid JSON response (HTTP ' . $code . ')');
        }

        if ($code < 200 || $code >= 300) {
            $msg = $decoded['message'] ?? 'unknown error';
            throw new \RuntimeException('[GitHubAppClient] GitHub API error HTTP ' . $code . ': ' . $msg);
        }

        return $decoded;
    }

    /**
     * Base64url-encode a string (RFC 4648 §5, no padding).
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
