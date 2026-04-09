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
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Integration;
use StratFlow\Services\GitHubClient;
use StratFlow\Services\GitLabClient;
use StratFlow\Services\GitLinkService;

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
     * Receive a GitHub pull_request webhook.
     *
     * Looks up the first active GitHub integration to retrieve the webhook
     * secret, verifies the X-Hub-Signature-256 header, then creates or
     * updates story git links based on the PR body.
     *
     * Returns JSON: {"ok": true, "links_affected": N}
     */
    public function receiveGitHub(): void
    {
        header('Content-Type: application/json');

        $rawBody = file_get_contents('php://input');

        if (empty($rawBody)) {
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            return;
        }

        $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $secret = $this->fetchWebhookSecret('github');

        if ($secret === null) {
            error_log('[GitWebhook] GitHub: no active integration found — rejecting');
            http_response_code(401);
            echo json_encode(['error' => 'No active GitHub integration']);
            return;
        }

        if (!GitHubClient::verifySignature($rawBody, $signatureHeader, $secret)) {
            error_log('[GitWebhook] GitHub: invalid HMAC signature — rejecting');
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

        $event = GitHubClient::parsePullRequestEvent($payload);
        if ($event === null) {
            error_log('[GitWebhook] GitHub: not a PR event — ignored');
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0]);
            return;
        }

        $service = new GitLinkService($this->db);
        $affected = $this->dispatchEvent($service, $event, 'github');

        error_log("[GitWebhook] GitHub PR {$event['pr_url']} action={$event['action']} links_affected={$affected}");
        http_response_code(200);
        echo json_encode(['ok' => true, 'links_affected' => $affected]);
    }

    /**
     * Receive a GitLab merge_requests webhook.
     *
     * Looks up the first active GitLab integration to retrieve the webhook
     * secret, verifies the X-Gitlab-Token header, then creates or updates
     * story git links based on the MR description.
     *
     * Returns JSON: {"ok": true, "links_affected": N}
     */
    public function receiveGitLab(): void
    {
        header('Content-Type: application/json');

        $rawBody = file_get_contents('php://input');

        if (empty($rawBody)) {
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            return;
        }

        $tokenHeader = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';
        $secret = $this->fetchWebhookSecret('gitlab');

        if ($secret === null) {
            error_log('[GitWebhook] GitLab: no active integration found — rejecting');
            http_response_code(401);
            echo json_encode(['error' => 'No active GitLab integration']);
            return;
        }

        if (!GitLabClient::verifyToken($tokenHeader, $secret)) {
            error_log('[GitWebhook] GitLab: invalid token — rejecting');
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
            error_log('[GitWebhook] GitLab: not an MR event — ignored');
            http_response_code(200);
            echo json_encode(['ok' => true, 'links_affected' => 0]);
            return;
        }

        $service = new GitLinkService($this->db);
        $affected = $this->dispatchEvent($service, $event, 'gitlab');

        error_log("[GitWebhook] GitLab MR {$event['pr_url']} action={$event['action']} links_affected={$affected}");
        http_response_code(200);
        echo json_encode(['ok' => true, 'links_affected' => $affected]);
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Fetch the webhook_secret from the first active integration for a provider.
     *
     * Phase 2 assumption: one GitHub/GitLab integration per install.
     *
     * @param string $provider 'github' or 'gitlab'
     * @return string|null     The configured secret, or null if not found
     */
    private function fetchWebhookSecret(string $provider): ?string
    {
        $stmt = $this->db->query(
            "SELECT config_json FROM integrations
             WHERE provider = :provider AND status = 'active'
             LIMIT 1",
            [':provider' => $provider]
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
     * Route an event to the appropriate GitLinkService method.
     *
     * Opened/reopened events create links; merged/closed events update status.
     *
     * @param GitLinkService $service  Configured service instance
     * @param array          $event    Parsed event from GitHubClient or GitLabClient
     * @param string         $provider 'github' or 'gitlab'
     * @return int                     Number of links created or updated
     */
    private function dispatchEvent(GitLinkService $service, array $event, string $provider): int
    {
        if ($event['action'] === 'opened' || $event['action'] === 'reopened') {
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

        // synchronize / update — no link changes needed
        return 0;
    }
}
