<?php
/**
 * JiraService — OAuth + API Client
 *
 * Core Jira Cloud API client handling OAuth 2.0 (3LO) authorization,
 * token management, and all REST API calls. Supports automatic token
 * refresh on 401, rate-limit handling on 429, and retry on 5xx.
 *
 * Usage:
 *   $jira = new JiraService($config['jira']);
 *   $url  = $jira->getAuthorizationUrl($state);
 *
 *   // With an existing integration record:
 *   $jira = new JiraService($config['jira'], $integration, $db);
 *   $projects = $jira->getProjects();
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\Integration;

class JiraService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private ?array $integration;
    private ?Database $db;

    private const AUTH_URL  = 'https://auth.atlassian.com/authorize';
    private const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';
    private const API_BASE  = 'https://api.atlassian.com';
    private const SCOPES    = 'read:jira-work write:jira-work manage:jira-webhook offline_access read:sprint:jira-software write:sprint:jira-software read:board-scope:jira-software';

    // ===========================
    // CONSTRUCTOR
    // ===========================

    /**
     * @param array         $config      Jira config array with client_id, client_secret, redirect_uri
     * @param array|null    $integration Integration record for authenticated calls
     * @param Database|null $db          Database instance for token updates
     */
    public function __construct(array $config, ?array $integration = null, ?Database $db = null)
    {
        $this->clientId     = $config['client_id'] ?? '';
        $this->clientSecret = $config['client_secret'] ?? '';
        $this->redirectUri  = $config['redirect_uri'] ?? '';
        $this->integration  = $integration;
        $this->db           = $db;
    }

    // ===========================
    // OAUTH METHODS
    // ===========================

    /**
     * Build the Atlassian OAuth authorization URL.
     *
     * @param string $state CSRF state token
     * @return string       Full authorization URL to redirect user to
     */
    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'audience'      => 'api.atlassian.com',
            'client_id'     => $this->clientId,
            'scope'         => self::SCOPES,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
            'response_type' => 'code',
            'prompt'        => 'consent',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @param string $code Authorization code from callback
     * @return array       {access_token, refresh_token, expires_in}
     * @throws \RuntimeException on failure
     */
    public function exchangeCode(string $code): array
    {
        $payload = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
        ];

        $response = $this->httpPost(self::TOKEN_URL, $payload);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Jira token exchange failed: ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Refresh the access token using the stored refresh token.
     *
     * Rotating refresh tokens: Atlassian may return a new refresh_token
     * on each refresh call, so we always persist the latest one.
     *
     * @throws \RuntimeException on failure
     */
    public function refreshAccessToken(): void
    {
        if (!$this->integration || !$this->db) {
            throw new \RuntimeException('Cannot refresh token without integration record and database');
        }

        $payload = [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->integration['refresh_token'],
        ];

        $response = $this->httpPost(self::TOKEN_URL, $payload);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Jira token refresh failed: ' . json_encode($response));
        }

        $expiresAt = date('Y-m-d H:i:s', time() + ($response['expires_in'] ?? 3600));
        $newRefreshToken = $response['refresh_token'] ?? $this->integration['refresh_token'];

        Integration::updateTokens(
            $this->db,
            (int) $this->integration['id'],
            $response['access_token'],
            $newRefreshToken,
            $expiresAt
        );

        // Update in-memory integration for subsequent calls
        $this->integration['access_token']     = $response['access_token'];
        $this->integration['refresh_token']    = $newRefreshToken;
        $this->integration['token_expires_at'] = $expiresAt;
    }

    /**
     * Get accessible Atlassian resources (sites) for a given access token.
     *
     * @param string $accessToken Bearer token
     * @return array              Array of {id, url, name} resources
     * @throws \RuntimeException on failure
     */
    public function getAccessibleResources(string $accessToken): array
    {
        $ch = curl_init(self::API_BASE . '/oauth/token/accessible-resources');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Failed to get accessible resources: HTTP ' . $httpCode);
        }

        return json_decode($body, true) ?: [];
    }

    // ===========================
    // JIRA API METHODS
    // ===========================

    /**
     * Get all projects visible to the authenticated user.
     *
     * @return array Array of Jira project objects
     */
    public function getProjects(): array
    {
        return $this->makeAuthenticatedRequest('GET', '/rest/api/3/project');
    }

    /**
     * Get available issue types for a project.
     *
     * @param string $projectKey Jira project key (e.g. 'PROJ')
     * @return array             Array of issue type objects
     */
    public function getIssueTypes(string $projectKey): array
    {
        return $this->makeAuthenticatedRequest('GET', '/rest/api/3/issue/createmeta/' . $projectKey . '/issuetypes');
    }

    /**
     * Get all fields available in the Jira instance.
     *
     * @return array Array of field definition objects
     */
    public function getFields(): array
    {
        return $this->makeAuthenticatedRequest('GET', '/rest/api/3/field');
    }

    /**
     * Create a new Jira issue.
     *
     * @param array $fields Issue fields payload
     * @return array        Created issue response (includes id, key, self)
     */
    public function createIssue(array $fields): array
    {
        return $this->makeAuthenticatedRequest('POST', '/rest/api/3/issue', ['fields' => $fields]);
    }

    /**
     * Update an existing Jira issue.
     *
     * @param string $issueKey Issue key (e.g. 'PROJ-123')
     * @param array  $fields   Fields to update
     */
    public function updateIssue(string $issueKey, array $fields): void
    {
        $this->makeAuthenticatedRequest('PUT', '/rest/api/3/issue/' . $issueKey, ['fields' => $fields]);
    }

    /**
     * Get a single Jira issue by key.
     *
     * @param string $issueKey     Issue key (e.g. 'PROJ-123')
     * @param array  $expandFields Optional fields to expand
     * @return array               Issue data
     */
    public function getIssue(string $issueKey, array $expandFields = []): array
    {
        $query = '';
        if (!empty($expandFields)) {
            $query = '?fields=' . implode(',', $expandFields);
        }

        return $this->makeAuthenticatedRequest('GET', '/rest/api/3/issue/' . $issueKey . $query);
    }

    /**
     * Search for issues using JQL.
     *
     * @param string $jql        JQL query string
     * @param array  $fields     Fields to return
     * @param int    $maxResults Maximum results (default 50)
     * @return array             Search results with issues array
     */
    public function searchIssues(string $jql, array $fields = [], int $maxResults = 50): array
    {
        $body = [
            'jql'        => $jql,
            'maxResults' => $maxResults,
        ];
        if (!empty($fields)) {
            $body['fields'] = $fields;
        }

        return $this->makeAuthenticatedRequest('POST', '/rest/api/3/search/jql', $body);
    }

    /**
     * Create multiple issues in bulk (max 50 per call).
     *
     * @param array $issues Array of issue field payloads
     * @return array        Bulk create response
     */
    public function createIssueBulk(array $issues): array
    {
        $payload = [
            'issueUpdates' => array_map(fn($fields) => ['fields' => $fields], $issues),
        ];

        return $this->makeAuthenticatedRequest('POST', '/rest/api/3/issue/bulk', $payload);
    }

    /**
     * Register a webhook for real-time updates.
     *
     * @param string $jql    JQL filter for webhook events
     * @param array  $events Event types to listen for
     * @param string $url    Callback URL
     * @return array         Webhook registration response
     */
    public function registerWebhook(string $jql, array $events, string $url): array
    {
        return $this->makeAuthenticatedRequest('POST', '/rest/api/3/webhook', [
            'webhooks' => [
                [
                    'jqlFilter' => $jql,
                    'events'    => $events,
                    'url'       => $url,
                ],
            ],
        ]);
    }

    // ===========================
    // AGILE / SPRINT API
    // ===========================

    /**
     * Get boards for a project.
     */
    public function getBoards(string $projectKey): array
    {
        return $this->makeAuthenticatedRequest('GET', '/rest/agile/1.0/board?projectKeyOrId=' . urlencode($projectKey));
    }

    /**
     * Get all sprints for a board.
     */
    public function getBoardSprints(int $boardId): array
    {
        return $this->makeAuthenticatedRequest('GET', "/rest/agile/1.0/board/{$boardId}/sprint");
    }

    /**
     * Create a sprint on a board.
     */
    public function createSprint(int $boardId, string $name, ?string $startDate = null, ?string $endDate = null): array
    {
        $body = [
            'name'          => $name,
            'originBoardId' => $boardId,
        ];
        if ($startDate) $body['startDate'] = $startDate;
        if ($endDate)   $body['endDate']   = $endDate;

        return $this->makeAuthenticatedRequest('POST', '/rest/agile/1.0/sprint', $body);
    }

    /**
     * Move issues into a sprint.
     */
    public function moveIssuesToSprint(int $sprintId, array $issueKeys): void
    {
        if (empty($issueKeys)) return;

        $this->makeAuthenticatedRequest('POST', "/rest/agile/1.0/sprint/{$sprintId}/issue", [
            'issues' => $issueKeys,
        ]);
    }

    /**
     * Refresh webhook registrations to extend expiry.
     */
    public function refreshWebhooks(): void
    {
        // Get existing webhook IDs from config_json
        $config = json_decode($this->integration['config_json'] ?? '{}', true);
        $webhookIds = $config['webhook_ids'] ?? [];

        if (!empty($webhookIds)) {
            $this->makeAuthenticatedRequest('PUT', '/rest/api/3/webhook/refresh', [
                'webhookIds' => $webhookIds,
            ]);
        }
    }

    // ===========================
    // AUTHENTICATED REQUEST HELPER
    // ===========================

    /**
     * Make an authenticated request to the Jira Cloud REST API.
     *
     * Handles 401 (token refresh + retry), 429 (rate limit + retry),
     * and 5xx (single retry after 2s delay).
     *
     * @param string     $method HTTP method (GET, POST, PUT, DELETE)
     * @param string     $path   API path (e.g. /rest/api/3/project)
     * @param array|null $body   Request body for POST/PUT
     * @return array             Decoded JSON response
     * @throws \RuntimeException on persistent failure
     */
    private function makeAuthenticatedRequest(string $method, string $path, ?array $body = null): array
    {
        if (!$this->integration) {
            throw new \RuntimeException('No integration record set for authenticated request');
        }

        $cloudId = $this->integration['cloud_id'];
        $url = self::API_BASE . '/ex/jira/' . $cloudId . $path;

        $maxRetries = 2;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $ch = curl_init($url);
            $headers = [
                'Authorization: Bearer ' . $this->integration['access_token'],
                'Accept: application/json',
                'Content-Type: application/json',
            ];

            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CUSTOMREQUEST  => $method,
            ];

            if ($body !== null && in_array($method, ['POST', 'PUT'], true)) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }

            curl_setopt_array($ch, $opts);
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                if ($this->db && $this->integration) {
                    Integration::recordError($this->db, (int) $this->integration['id'], 'cURL error: ' . $curlError);
                }
                throw new \RuntimeException('Jira API cURL error: ' . $curlError);
            }

            // 204 No Content (e.g. successful PUT)
            if ($httpCode === 204) {
                if ($this->db && $this->integration) {
                    Integration::clearError($this->db, (int) $this->integration['id']);
                }
                return [];
            }

            // Success
            if ($httpCode >= 200 && $httpCode < 300) {
                if ($this->db && $this->integration) {
                    Integration::clearError($this->db, (int) $this->integration['id']);
                }
                return json_decode($responseBody, true) ?: [];
            }

            // 401 Unauthorized — refresh token and retry once
            if ($httpCode === 401 && $attempt === 0) {
                try {
                    $this->refreshAccessToken();
                    continue;
                } catch (\Throwable $e) {
                    if ($this->db && $this->integration) {
                        Integration::recordError($this->db, (int) $this->integration['id'], 'Token refresh failed: ' . $e->getMessage());
                    }
                    throw new \RuntimeException('Jira token refresh failed: ' . $e->getMessage());
                }
            }

            // 429 Rate Limited — wait and retry
            if ($httpCode === 429 && $attempt === 0) {
                $retryAfter = 5;
                $decoded = json_decode($responseBody, true);
                if (isset($decoded['retryAfter'])) {
                    $retryAfter = (int) $decoded['retryAfter'];
                }
                sleep(min($retryAfter, 30));
                continue;
            }

            // 5xx Server Error — retry once after 2s
            if ($httpCode >= 500 && $attempt === 0) {
                sleep(2);
                continue;
            }

            // Non-retryable error — extract detailed Jira error info
            $errorDetail = json_decode($responseBody, true);
            $errorParts = [];
            if (!empty($errorDetail['errorMessages'])) {
                $errorParts = array_merge($errorParts, $errorDetail['errorMessages']);
            }
            if (!empty($errorDetail['errors']) && is_array($errorDetail['errors'])) {
                foreach ($errorDetail['errors'] as $field => $msg) {
                    $errorParts[] = "{$field}: {$msg}";
                }
            }
            if (!empty($errorDetail['message'])) {
                $errorParts[] = $errorDetail['message'];
            }
            $errorMsg = !empty($errorParts) ? implode('; ', $errorParts) : ('HTTP ' . $httpCode);

            error_log("[StratFlow] Jira API error ($httpCode): $errorMsg");
            error_log("[StratFlow] Jira request body: " . ($body ? json_encode($body) : 'none'));

            if ($this->db && $this->integration) {
                Integration::recordError($this->db, (int) $this->integration['id'], $errorMsg);
            }

            throw new \RuntimeException('Jira API error: ' . $errorMsg . ' (HTTP ' . $httpCode . ')');
        }

        throw new \RuntimeException('Jira API request failed after retries');
    }

    // ===========================
    // ADF CONVERSION HELPERS
    // ===========================

    /**
     * Convert plain text to Atlassian Document Format (ADF).
     *
     * Splits text by newlines into paragraph nodes.
     *
     * @param string $text Plain text input
     * @return array       ADF document structure
     */
    public function textToAdf(string $text): array
    {
        $paragraphs = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $paragraphs[] = [
                    'type'    => 'paragraph',
                    'content' => [],
                ];
            } else {
                $paragraphs[] = [
                    'type'    => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $trimmed,
                        ],
                    ],
                ];
            }
        }

        return [
            'version' => 1,
            'type'    => 'doc',
            'content' => $paragraphs,
        ];
    }

    /**
     * Extract plain text from an ADF document structure.
     *
     * Recursively walks the content tree, concatenating text nodes.
     *
     * @param array $adf ADF document
     * @return string    Plain text
     */
    public function adfToText(array $adf): string
    {
        return $this->extractTextFromNode($adf);
    }

    /**
     * Recursively extract text from an ADF node.
     *
     * @param array $node ADF node
     * @return string     Extracted text
     */
    private function extractTextFromNode(array $node): string
    {
        $text = '';

        if (($node['type'] ?? '') === 'text') {
            return $node['text'] ?? '';
        }

        if (!empty($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= $this->extractTextFromNode($child);
            }
            // Add newline after block-level elements
            if (in_array($node['type'] ?? '', ['paragraph', 'heading', 'blockquote', 'listItem'], true)) {
                $text .= "\n";
            }
        }

        return $text;
    }

    // ===========================
    // INTERNAL HTTP HELPER
    // ===========================

    /**
     * Make a simple POST request (used for OAuth token endpoint).
     *
     * @param string $url     Target URL
     * @param array  $payload Form data
     * @return array          Decoded JSON response
     * @throws \RuntimeException on failure
     */
    private function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Jira OAuth cURL error: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('Jira OAuth error: HTTP ' . $httpCode . ' — ' . $body);
        }

        return json_decode($body, true) ?: [];
    }
}
