<?php

/**
 * GitHubClient
 *
 * Static utilities for GitHub App webhook processing.
 * Handles HMAC-SHA256 signature verification and PR event payload parsing.
 * No API calls — webhook-only for Phase 2.
 */

declare(strict_types=1);

namespace StratFlow\Services;

class GitHubClient
{
    /**
     * Verify a GitHub X-Hub-Signature-256 header against the raw request body.
     *
     * GitHub sends the header as "sha256=<hex_digest>".
     * Uses hash_equals for constant-time comparison to prevent timing attacks.
     *
     * @param string $rawBody         Raw request body bytes
     * @param string $signatureHeader Value of X-Hub-Signature-256 header
     * @param string $secret          Shared webhook secret
     * @return bool                   True if the signature is valid
     */
    public static function verifySignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $providedHash = substr($signatureHeader, 7);
        $expectedHash = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expectedHash, $providedHash);
    }

    /**
     * Extract provider-agnostic event data from a GitHub pull_request webhook payload.
     *
     * Normalises the GitHub-specific shape into a common array used by GitLinkService.
     *
     * Supported actions: opened, reopened, synchronize, closed (merged or not).
     *
     * @param array $payload Decoded JSON payload from GitHub
     * @return array|null    Normalised event data, or null if not a PR event
     *                       Shape: ['action' => string, 'merged' => bool,
     *                               'pr_url' => string, 'title' => string,
     *                               'body' => string, 'author' => string]
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

        $merged = ($action === 'closed') && ($pr['merged'] ?? false);
        return [
            'action' => $action,
            'merged' => $merged,
            'pr_url' => $pr['html_url'] ?? '',
            'title'  => $pr['title'] ?? '',
            'body'   => $pr['body'] ?? '',
            'author' => $pr['user']['login'] ?? null,
        ];
    }
}
