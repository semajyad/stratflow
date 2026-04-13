<?php
/**
 * GitWebhookController
 *
 * Receives and processes inbound webhooks from GitHub and GitLab.
 * Verifies signatures, parses PR/MR events, and delegates to GitLinkService
 * to create or update links.
 *
 * Both endpoints are PUBLIC (no middleware). Security relies on HMAC/token
 * verification performed within each action before any data is written.
 *
 * GitHub uses the GitHub App model:
 *  - Signature verified against the App-level GITHUB_APP_WEBHOOK_SECRET (env)
 *  - Multi-tenant routing keyed by installation.id from the payload
 *  - Repo allowlist checked via integration_repos table
 *  - installation / installation_repositories meta events keep the allowlist live
 *
 * GitLab continues to use the original HMAC secret per integration.
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
use StratFlow\Services\GitLabClient;
use StratFlow\Services\GeminiService;
use StratFlow\Services\GitLinkService;
use StratFlow\Services\GitPrMatcherService;
use StratFlow\Services\KrScoringService;

class GitWebhookController
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
    // WEBHOOK ACTIONS
    // ===========================

    /**
     * Receive a GitHub App webhook.
     *
     * Security model:
     *  1. Verify HMAC against App-level GITHUB_APP_WEBHOOK_SECRET (raw body).
     *  2. Decode payload and extract installation.id for multi-tenant routing.
     *  3. Handle meta events (installation, installation_repositories) inline.
     *  4. For pull_request events: verify the repo is on the org's allowlist,
     *     then delegate to GitLinkService scoped by org_id.
     *
     * Returns JSON: {"ok": true, "links_affected": N}
     */
    public function receiveGitHub(): void
    {
        header('Content-Type: application/json');

        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            return;
        }

        // Step 1 — verify HMAC before trusting any content
        $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (!GitHubAppClient::verifySignature($rawBody, $signatureHeader)) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: invalid HMAC signature');
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        // Sanitise to printable ASCII to prevent log injection
        $githubEvent = preg_replace('/[^\x20-\x7E]/', '', $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');

        // Step 2 — extract installation_id for multi-tenant routing
        $installationId = (int) ($payload['installation']['id'] ?? 0);
        if ($installationId === 0) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: missing installation.id — not a GitHub App webhook');
            http_response_code(401);
            echo json_encode(['error' => 'installation_missing']);
            return;
        }

        // Step 3 — handle meta events that keep the repo allowlist live
        if ($githubEvent === 'installation') {
            $this->handleInstallationEvent($payload, $installationId);
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0]);
            return;
        }

        if ($githubEvent === 'installation_repositories') {
            $this->handleInstallationReposEvent($payload, $installationId);
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0]);
            return;
        }

        // Step 4a — handle push events (commit messages with SF-{id} or AI fallback)
        if ($githubEvent === 'push') {
            $integration = Integration::findActiveByInstallationId($this->db, $installationId);
            if ($integration === null) {
                http_response_code(404);
                echo json_encode(['error' => 'unknown_installation']);
                return;
            }

            $orgId    = (int) $integration['org_id'];
            $pushData = GitHubAppClient::parsePushEvent($payload);

            if ($pushData === null) {
                http_response_code(200);
                echo json_encode(['ok' => true, 'links_affected' => 0]);
                return;
            }

            $repoRow = IntegrationRepo::findByIntegrationAndGithubId(
                $this->db,
                (int) $integration['id'],
                $pushData['repo_github_id']
            );

            if ($repoRow === null) {
                http_response_code(200);
                echo json_encode(['ok' => true, 'links_affected' => 0, 'reason' => 'repo_not_linked']);
                return;
            }

            $service  = new GitLinkService($this->db, $orgId);
            $affected = 0;
            $aiQueue  = []; // commits with no explicit SF- tag go to AI matching

            foreach ($pushData['commits'] as $commit) {
                $subject = strtok($commit['message'], "\n"); // first line only
                if (preg_match('/\b(SF|StratFlow)[-_\s]?(\d+)\b/i', $commit['message'])) {
                    $linked = $service->linkFromPrBody(
                        $commit['message'],
                        $commit['url'],
                        'github',
                        $subject,
                        'merged',
                        $commit['author'] ?? null
                    );
                    $affected += $linked;
                } else {
                    $aiQueue[] = $commit;
                }
            }

            \StratFlow\Services\Logger::warn(sprintf(
                '[GitWebhook] GitHub push %s branch=%s org_id=%d commits=%d explicit_links=%d ai_queue=%d',
                $pushData['repo_full_name'],
                $pushData['branch'],
                $orgId,
                count($pushData['commits']),
                $affected,
                count($aiQueue)
            ));

            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => $affected]);

            // AI matching for commits with no explicit reference — fire after response
            if (!empty($aiQueue)) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                ignore_user_abort(true);

                $gemini  = $this->makeGemini();
                $matcher = new GitPrMatcherService($this->db, $gemini);
                foreach ($aiQueue as $commit) {
                    $subject = strtok($commit['message'], "\n");
                    $matcher->matchAndLink(
                        $subject,
                        $commit['message'],
                        $pushData['branch'],
                        $commit['url'],
                        $orgId
                    );
                }
            }
            return;
        }

        // Step 4 — resolve integration for PR events
        $integration = Integration::findActiveByInstallationId($this->db, $installationId);
        if ($integration === null) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: unknown installation_id=' . $installationId);
            http_response_code(404);
            echo json_encode(['error' => 'unknown_installation']);
            return;
        }

        $orgId = (int) $integration['org_id'];

        // Step 5 — only pull_request events produce links
        $event = GitHubAppClient::parsePullRequestEvent($payload);
        if ($event === null) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0]);
            return;
        }

        // Step 6 — verify repo is on the org's allowlist
        $repoRow = IntegrationRepo::findByIntegrationAndGithubId(
            $this->db,
            (int) $integration['id'],
            $event['repo_github_id']
        );

        if ($repoRow === null) {
            \StratFlow\Services\Logger::warn(sprintf(
                '[GitWebhook] GitHub: repo_github_id=%d not on allowlist for installation_id=%d',
                $event['repo_github_id'],
                $installationId
            ));
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0, 'reason' => 'repo_not_linked']);
            return;
        }

        // Step 7 — delegate to GitLinkService, scoped to the resolved org
        $service  = new GitLinkService($this->db, $orgId);
        $affected = $this->dispatchEvent($service, $event, 'github');

        \StratFlow\Services\Logger::warn(sprintf(
            '[GitWebhook] GitHub PR %s action=%s org_id=%d links_affected=%d',
            $event['pr_url'],
            $event['action'],
            $orgId,
            $affected
        ));

        http_response_code(200);
        echo json_encode(['ok' => true, 'links_affected' => $affected]);

        // ── Async AI work — fires after HTTP response is sent ──────────────
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);

        $gemini = $this->makeGemini();

        // AI PR matching: fires on opened/reopened/synchronize with no explicit tag found
        if ($affected === 0 && in_array($event['action'], ['opened', 'reopened', 'synchronize'], true)) {
            $matcher = new GitPrMatcherService($this->db, $gemini);
            $matcher->matchAndLink(
                $event['title'],
                $event['body'],
                $event['branch'] ?? '',
                $event['pr_url'],
                $orgId
            );
        }

        // KR scoring: fires on merged PRs
        if ($event['action'] === 'closed' && ($event['merged'] ?? false)) {
            $scorer = new KrScoringService($this->db, $gemini);
            $scorer->scoreForMergedPr($event['pr_url'], $orgId);
        }
    }

    /**
     * Receive a GitLab merge_requests webhook.
     *
     * Looks up the first active GitLab integration for this org to retrieve
     * the webhook token, verifies X-Gitlab-Token, then creates or updates
     * story git links based on the MR description.
     *
     * Returns JSON: {"ok": true, "links_affected": N}
     */
    public function receiveGitLab(): void
    {
        header('Content-Type: application/json');

        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            return;
        }

        $tokenHeader = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';
        $secret      = $this->fetchGitLabSecret();

        if ($secret === null) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitLab: no active integration found — rejecting');
            http_response_code(401);
            echo json_encode(['error' => 'No active GitLab integration']);
            return;
        }

        if (!GitLabClient::verifyToken($tokenHeader, $secret)) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitLab: invalid token — rejecting');
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
            return;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $event = GitLabClient::parseMergeRequestEvent($payload);
        if ($event === null) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitLab: not an MR event — ignored');
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0]);
            return;
        }

        $service  = new GitLinkService($this->db);
        $affected = $this->dispatchEvent($service, $event, 'gitlab');

        \StratFlow\Services\Logger::warn("[GitWebhook] GitLab MR {$event['pr_url']} action={$event['action']} links_affected={$affected}");
        http_response_code(200);
        echo json_encode(['ok' => true, 'links_affected' => $affected]);
    }

    // ===========================
    // META EVENT HANDLERS
    // ===========================

    /**
     * Handle GitHub App installation events (created / deleted / suspend / unsuspend).
     *
     * On 'deleted': marks the matching integration as 'revoked'.
     * On 'created': this fires when someone installs the App outside the
     *   normal stratflow callback flow — we can't assign an org_id without
     *   a logged-in user, so we log it for manual follow-up.
     *
     * @param array $payload        Decoded webhook payload
     * @param int   $installationId Installation ID (already extracted)
     */
    private function handleInstallationEvent(array $payload, int $installationId): void
    {
        $event = GitHubAppClient::parseInstallationEvent($payload);
        if ($event === null) {
            return;
        }

        match ($event['action']) {
            'deleted' => (function () use ($installationId): void {
                $row = Integration::findActiveByInstallationId($this->db, $installationId);
                if ($row !== null) {
                    Integration::update($this->db, (int) $row['id'], ['status' => 'revoked']);
                    \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: installation_id=' . $installationId . ' deleted → revoked');
                }
            })(),
            'suspend' => (function () use ($installationId): void {
                // GitHub App suspended (not deleted) — use 'inactive' so unsuspend can restore it.
                // Admin-initiated disconnects use 'revoked' which unsuspend will never touch.
                $row = Integration::findActiveByInstallationId($this->db, $installationId);
                if ($row !== null) {
                    Integration::update($this->db, (int) $row['id'], ['status' => 'inactive']);
                    \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: installation_id=' . $installationId . ' suspended → inactive');
                }
            })(),
            'unsuspend' => (function () use ($installationId): void {
                // Only reactivate if the integration was suspended (status='inactive'),
                // NOT if it was deliberately revoked by an admin. A revoked integration
                // must be reconnected explicitly via the admin UI.
                $stmt = $this->db->query(
                    "SELECT id FROM integrations
                     WHERE provider = 'github'
                       AND installation_id = :id
                       AND status = 'inactive'
                     LIMIT 1",
                    [':id' => $installationId]
                );
                $row = $stmt->fetch();
                if ($row !== false) {
                    Integration::update($this->db, (int) $row['id'], ['status' => 'active']);
                    \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: installation_id=' . $installationId . ' reactivated from suspended');
                }
            })(),
            default => \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: installation event action=' . $event['action'] . ' installation_id=' . $installationId . ' (no-op)'),
        };
    }

    /**
     * Handle GitHub App installation_repositories events.
     *
     * Upserts newly added repos and deletes removed repos from integration_repos,
     * keeping the allowlist live without requiring an admin to re-open stratflow.
     *
     * @param array $payload        Decoded webhook payload
     * @param int   $installationId Installation ID (already extracted)
     */
    private function handleInstallationReposEvent(array $payload, int $installationId): void
    {
        $event = GitHubAppClient::parseInstallationReposEvent($payload);
        if ($event === null) {
            return;
        }

        $integration = Integration::findActiveByInstallationId($this->db, $installationId);
        if ($integration === null) {
            \StratFlow\Services\Logger::warn('[GitWebhook] GitHub: installation_repositories for unknown installation_id=' . $installationId);
            return;
        }

        $integrationId = (int) $integration['id'];
        $orgId         = (int) $integration['org_id'];

        foreach ($event['added'] as $repo) {
            IntegrationRepo::upsert($this->db, $integrationId, $orgId, $repo['id'], $repo['full_name']);
        }

        foreach ($event['removed'] as $repo) {
            IntegrationRepo::deleteByIntegrationAndGithubId($this->db, $integrationId, $repo['id']);
        }

        \StratFlow\Services\Logger::warn(sprintf(
            '[GitWebhook] GitHub: installation_repositories installation_id=%d added=%d removed=%d',
            $installationId,
            count($event['added']),
            count($event['removed'])
        ));
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Fetch the GitLab webhook secret from the first active GitLab integration.
     *
     * GitLab continues to use the per-integration HMAC secret model.
     *
     * @return string|null The configured secret, or null if not found
     */
    private function fetchGitLabSecret(): ?string
    {
        $stmt = $this->db->query(
            "SELECT config_json FROM integrations
             WHERE provider = 'gitlab' AND status = 'active'
             LIMIT 1"
        );
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $config = json_decode($row['config_json'] ?? '{}', true) ?: [];
        $secret = $config['webhook_secret'] ?? '';

        return $secret !== '' ? $secret : null;
    }

    /**
     * Build a GeminiService from config, or return null if not configured.
     * Returning null causes AI services to gracefully no-op.
     */
    private function makeGemini(): ?GeminiService
    {
        $apiKey = $this->config['gemini']['api_key'] ?? '';
        if ($apiKey === '') {
            return null;
        }
        return new GeminiService($this->config);
    }

    /**
     * Route an event to the appropriate GitLinkService method.
     *
     * @param GitLinkService $service  Configured service instance
     * @param array          $event    Parsed event from GitHubAppClient or GitLabClient
     * @param string         $provider 'github' or 'gitlab'
     * @return int                     Number of links created or updated
     */
    private function dispatchEvent(GitLinkService $service, array $event, string $provider): int
    {
        if (in_array($event['action'], ['opened', 'reopened', 'synchronize'], true)) {
            return $service->linkFromPrBody(
                $event['body'],
                $event['pr_url'],
                $provider,
                $event['title'],
                'open',
                $event['author'] ?? null
            );
        }

        if ($event['action'] === 'closed') {
            $status = $event['merged'] ? 'merged' : 'closed';
            return $service->updateStatusByRefUrl($event['pr_url'], $status);
        }

        return 0;
    }
}
