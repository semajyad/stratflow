<?php
/**
 * GitLinkService
 *
 * Business logic for creating and updating git links.
 * Handles PR body parsing (SF-{id} / StratFlow-{id} references),
 * ref classification (PR URL / commit SHA / branch), and bulk status updates.
 *
 * Used by GitWebhookController (auto-linking) and GitLinkController (manual linking).
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\UserStory;

class GitLinkService
{
    // Guards against pathological PR bodies: we cap the size we regex over
    // and the number of distinct IDs we'll actually write to the database.
    private const MAX_BODY_LENGTH = 65536;
    private const MAX_MATCHES     = 20;

    // ===========================
    // PROPERTIES
    // ===========================

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ===========================
    // PUBLIC API
    // ===========================

    /**
     * Parse a PR body for SF-{id} / StratFlow-{id} references and create
     * links for every match.
     *
     * If a link with the same (local_type, local_id, ref_url) already exists,
     * its status is updated rather than a duplicate being inserted.
     *
     * Regex: /\b(SF|StratFlow)[-_\s]?(\d+)\b/i
     * Each numeric ID is matched against user_stories first; if not found,
     * falls back to hl_work_items.
     *
     * @param string      $body     Raw PR description text
     * @param string      $prUrl    Canonical URL of the pull/merge request
     * @param string      $provider 'github' or 'gitlab'
     * @param string      $prTitle  PR title used as ref_label prefix
     * @param string      $status   Initial link status ('open', 'merged', 'closed')
     * @param string|null $author   PR author login
     * @return int                  Number of links created or updated
     */
    public function linkFromPrBody(
        string  $body,
        string  $prUrl,
        string  $provider,
        string  $prTitle = '',
        string  $status = 'open',
        ?string $author = null
    ): int {
        // Guard against pathological inputs — an adversarial or bot-generated
        // PR body should never cause unbounded regex work or DB writes.
        if ($body === '' || strlen($body) > self::MAX_BODY_LENGTH) {
            return 0;
        }

        $matches = [];
        preg_match_all('/\b(SF|StratFlow)[-_\s]?(\d+)\b/i', $body, $matches);

        if (empty($matches[2])) {
            return 0;
        }

        $ids = array_unique(array_map('intval', $matches[2]));
        if (count($ids) > self::MAX_MATCHES) {
            $ids = array_slice($ids, 0, self::MAX_MATCHES);
        }

        $affected = 0;

        foreach ($ids as $numericId) {
            try {
                [$localType, $localId] = $this->resolveLocalItem($numericId);
                if ($localId === null) {
                    continue;
                }

                $refLabel = $this->buildRefLabel($prUrl, $prTitle);
                $existing = $this->findExistingLink($localType, $localId, $prUrl);

                if ($existing !== null) {
                    if ($existing['status'] !== $status) {
                        StoryGitLink::updateStatus($this->db, (int) $existing['id'], $status);
                    }
                    $affected++;
                    continue;
                }

                $id = StoryGitLink::create($this->db, [
                    'local_type' => $localType,
                    'local_id'   => $localId,
                    'provider'   => $provider,
                    'ref_type'   => 'pr',
                    'ref_url'    => $prUrl,
                    'ref_label'  => $refLabel,
                    'status'     => $status,
                    'author'     => $author,
                ]);

                if ($id > 0) {
                    $affected++;
                }
            } catch (\Throwable $e) {
                // Don't let a transient DB error abort the whole webhook —
                // log and keep processing the remaining matches. The webhook
                // handler still returns 200 so the provider doesn't retry.
                error_log('[GitLink] linkFromPrBody per-id failure (id=' . $numericId . '): ' . $e->getMessage());
                continue;
            }
        }

        return $affected;
    }

    /**
     * Update status of all links pointing to a given PR URL.
     *
     * Called on merge/close webhook events. Works across all local types.
     *
     * @param string $refUrl Full URL of the PR/MR
     * @param string $status New status value ('merged', 'closed', etc.)
     * @return int           Number of links updated
     */
    public function updateStatusByRefUrl(string $refUrl, string $status): int
    {
        $stmt = $this->db->query(
            "SELECT id, status FROM story_git_links WHERE ref_url = :ref_url",
            [':ref_url' => $refUrl]
        );

        $rows = $stmt->fetchAll();
        $updated = 0;

        foreach ($rows as $row) {
            if ($row['status'] !== $status) {
                StoryGitLink::updateStatus($this->db, (int) $row['id'], $status);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Classify arbitrary user input into a ref_type and ref_label pair.
     *
     * Rules (applied in order):
     * - GitHub PR URL  (github.com/.../pull/N)       → ('pr', 'PR #N')
     * - GitLab MR URL  (gitlab.com/.../merge_requests/N) → ('pr', 'MR #N')
     * - 40-char hex string                            → ('commit', short 7-char sha)
     * - 7-char hex string                             → ('commit', sha)
     * - Anything else with a slash                    → ('branch', last path segment)
     * - Else                                          → ('branch', raw input)
     *
     * @param string $input Raw user-supplied ref string or URL
     * @return array{ref_type: string, ref_label: string}
     */
    public function classifyRef(string $input): array
    {
        $input = trim($input);

        // GitHub PR
        if (preg_match('#github\.com/.+/pull/(\d+)#i', $input, $m)) {
            return ['ref_type' => 'pr', 'ref_label' => 'PR #' . $m[1]];
        }

        // GitLab MR
        if (preg_match('#gitlab\.com/.+/merge_requests/(\d+)#i', $input, $m)) {
            return ['ref_type' => 'pr', 'ref_label' => 'MR #' . $m[1]];
        }

        // Full 40-char commit SHA
        if (preg_match('/^[0-9a-f]{40}$/i', $input)) {
            return ['ref_type' => 'commit', 'ref_label' => substr($input, 0, 7)];
        }

        // Short 7-char commit SHA
        if (preg_match('/^[0-9a-f]{7}$/i', $input)) {
            return ['ref_type' => 'commit', 'ref_label' => $input];
        }

        // URL or path-like string → treat as branch, use last segment
        if (str_contains($input, '/')) {
            $segment = rtrim(basename(parse_url($input, PHP_URL_PATH) ?? $input), '/');
            return ['ref_type' => 'branch', 'ref_label' => $segment ?: $input];
        }

        // Plain branch name
        return ['ref_type' => 'branch', 'ref_label' => $input];
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Resolve a numeric SF-{id} reference to a local item.
     *
     * Tries user_stories first, then hl_work_items.
     *
     * @param int $id Numeric ID from the PR body reference
     * @return array{string, int|null} [local_type, local_id] or [string, null] if not found
     */
    private function resolveLocalItem(int $id): array
    {
        $story = UserStory::findById($this->db, $id);
        if ($story !== null) {
            return ['user_story', $id];
        }

        $workItem = HLWorkItem::findById($this->db, $id);
        if ($workItem !== null) {
            return ['hl_work_item', $id];
        }

        return ['user_story', null];
    }

    /**
     * Look up an existing link row by (local_type, local_id, ref_url).
     *
     * @param string $localType 'user_story' or 'hl_work_item'
     * @param int    $localId   Local item ID
     * @param string $refUrl    Full PR/MR URL
     * @return array|null       Existing row or null
     */
    private function findExistingLink(string $localType, int $localId, string $refUrl): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM story_git_links
             WHERE local_type = :local_type AND local_id = :local_id AND ref_url = :ref_url
             LIMIT 1",
            [
                ':local_type' => $localType,
                ':local_id'   => $localId,
                ':ref_url'    => $refUrl,
            ]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Build a display label for a PR link.
     *
     * Prioritises extracting a PR/MR number from the URL; falls back to title.
     *
     * @param string $prUrl   PR/MR URL
     * @param string $prTitle PR title
     * @return string         Display label (max 200 chars)
     */
    private function buildRefLabel(string $prUrl, string $prTitle): string
    {
        if (preg_match('#/pull/(\d+)#', $prUrl, $m)) {
            $label = 'PR #' . $m[1];
            if ($prTitle !== '') {
                $label .= ': ' . $prTitle;
            }
            return substr($label, 0, 200);
        }

        if (preg_match('#/merge_requests/(\d+)#', $prUrl, $m)) {
            $label = 'MR #' . $m[1];
            if ($prTitle !== '') {
                $label .= ': ' . $prTitle;
            }
            return substr($label, 0, 200);
        }

        return substr($prTitle ?: $prUrl, 0, 200);
    }
}
