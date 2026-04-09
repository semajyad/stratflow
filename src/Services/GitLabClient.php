<?php
/**
 * GitLabClient
 *
 * Static utilities for GitLab webhook processing.
 * Handles X-Gitlab-Token header verification and merge request event parsing.
 * No API calls — webhook-only for Phase 2.
 */

declare(strict_types=1);

namespace StratFlow\Services;

class GitLabClient
{
    /**
     * Verify a GitLab X-Gitlab-Token header against the configured shared secret.
     *
     * GitLab sends the token as a plain string (not HMAC). Uses hash_equals
     * for constant-time comparison to prevent timing attacks.
     *
     * @param string $headerToken Value of X-Gitlab-Token header
     * @param string $secret      Configured webhook secret
     * @return bool               True if the token matches
     */
    public static function verifyToken(string $headerToken, string $secret): bool
    {
        return hash_equals($secret, $headerToken);
    }

    /**
     * Extract provider-agnostic event data from a GitLab merge_requests webhook payload.
     *
     * Normalises the GitLab-specific shape into the same common array used by GitLinkService.
     *
     * Supported object_attributes.action: open, reopen, update, merge, close.
     *
     * @param array $payload Decoded JSON payload from GitLab
     * @return array|null    Normalised event data, or null if not a merge request event
     *                       Shape: ['action' => string, 'merged' => bool,
     *                               'pr_url' => string, 'title' => string,
     *                               'body' => string, 'author' => string]
     */
    public static function parseMergeRequestEvent(array $payload): ?array
    {
        if (($payload['object_kind'] ?? '') !== 'merge_request') {
            return null;
        }

        $attrs  = $payload['object_attributes'] ?? [];
        $action = $attrs['action'] ?? '';

        if (!in_array($action, ['open', 'reopen', 'update', 'merge', 'close'], true)) {
            return null;
        }

        $merged = ($action === 'merge');

        // Map GitLab action names to the shared vocabulary used by GitLinkService
        $normalised = match($action) {
            'open', 'reopen' => 'opened',
            'merge'          => 'closed',
            'close'          => 'closed',
            default          => $action,
        };

        return [
            'action' => $normalised,
            'merged' => $merged,
            'pr_url' => $attrs['url'] ?? '',
            'title'  => $attrs['title'] ?? '',
            'body'   => $attrs['description'] ?? '',
            'author' => $payload['user']['username'] ?? null,
        ];
    }
}
