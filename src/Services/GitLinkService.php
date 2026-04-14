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
    private ?int $orgId;
/**
     * @param Database $db    Database instance
     * @param int|null $orgId Organisation ID for tenancy scoping.
     *                        Pass null only for GitLab (which still uses the legacy
     *                        single-integration model and has no org_id in the handler).
     */
    public function __construct(Database $db, ?int $orgId = null)
    {
        $this->db    = $db;
        $this->orgId = $orgId;
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
    public function linkFromPrBody(string $body, string $prUrl, string $provider, string $prTitle = '', string $status = 'open', ?string $author = null): int
    {
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
                \StratFlow\Services\Logger::warn('[GitLink] linkFromPrBody per-id failure (id=' . $numericId . '): ' . $e->getMessage());
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
     * When $this->orgId is set (GitHub App path), only links whose local item
     * belongs to that org are updated. This prevents a cross-tenant status
     * poisoning attack where Org B sends a closed event with a PR URL that
     * happens to appear in Org A's story_git_links.
     *
     * @param string $refUrl Full URL of the PR/MR
     * @param string $status New status value ('merged', 'closed', etc.)
     * @return int           Number of links updated
     */
    public function updateStatusByRefUrl(string $refUrl, string $status): int
    {
        $stmt = $this->db->query("SELECT id, status, local_type, local_id FROM story_git_links WHERE ref_url = :ref_url", [':ref_url' => $refUrl]);
        $rows    = $stmt->fetchAll();
        $updated = 0;
        foreach ($rows as $row) {
        // Org tenancy check — only update links whose local item belongs to this org.
            // story_git_links has no org_id column (MVP deferred), so we resolve via the
            // local item. Skip items we cannot confirm belong to this org.
            if ($this->orgId !== null && !$this->localItemBelongsToOrg((string) $row['local_type'], (int) $row['local_id'])) {
                continue;
            }

            if ($row['status'] !== $status) {
                StoryGitLink::updateStatus($this->db, (int) $row['id'], $status);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Check whether a local item (user_story or hl_work_item) belongs to $this->orgId.
     *
     * Both user_stories and hl_work_items carry org membership through their
     * project_id → projects.org_id relationship (neither table has a direct
     * org_id column). We JOIN through projects to enforce tenancy.
     *
     * Used to scope updateStatusByRefUrl and linkAiMatched when org_id is set.
     *
     * @param string $localType 'user_story' or 'hl_work_item'
     * @param int    $localId   Local item primary key
     * @return bool             True if the item belongs to $this->orgId
     */
    private function localItemBelongsToOrg(string $localType, int $localId): bool
    {
        $table = match ($localType) {
            'user_story'   => 'user_stories',
            'hl_work_item' => 'hl_work_items',
            default        => null,
        };
        if ($table === null) {
            return false;
        }

        $stmt = $this->db->query("SELECT 1 FROM `{$table}` t
               JOIN projects p ON p.id = t.project_id
              WHERE t.id = :id AND p.org_id = :org_id LIMIT 1", [':id' => $localId, ':org_id' => $this->orgId]);
        return $stmt->fetch() !== false;
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

    /**
     * Insert AI-matched links for a set of resolved local items.
     *
     * Called by GitPrMatcherService after Gemini identifies high-confidence
     * matches. Skips items that don't belong to the current org or already
     * have a link for the given ref URL. Sets ai_matched = 1.
     *
     * @param array  $items    Array of ['local_type' => string, 'local_id' => int]
     * @param string $refUrl   Canonical PR/MR URL
     * @param string $refTitle PR title used to build the display label
     * @param string $provider 'github' or 'gitlab'
     * @return int             Number of new links inserted
     */
    public function linkAiMatched(array $items, string $refUrl, string $refTitle, string $provider): int
    {
        $count = 0;
        foreach ($items as $item) {
            $localType = (string) ($item['local_type'] ?? '');
            $localId   = (int) ($item['local_id'] ?? 0);
            if ($localId === 0) {
                continue;
            }

            if (!$this->localItemBelongsToOrg($localType, $localId)) {
                continue;
            }

            $existing = $this->findExistingLink($localType, $localId, $refUrl);
            if ($existing !== null) {
                continue;
            }

            $refLabel = $this->buildRefLabel($refUrl, $refTitle);
            $this->db->query("INSERT INTO story_git_links
                    (local_type, local_id, provider, ref_type, ref_url, ref_label, status, ai_matched)
                 VALUES
                    (:lt, :lid, :prov, 'pr', :ref_url, :ref_label, 'open', 1)", [
                    ':lt'        => $localType,
                    ':lid'       => $localId,
                    ':prov'      => $provider,
                    ':ref_url'   => $refUrl,
                    ':ref_label' => $refLabel,
                ]);
            $count++;
        }
        return $count;
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Resolve a numeric SF-{id} reference to a local item.
     *
     * Tries user_stories first, then hl_work_items.
     * When $this->orgId is set, the resolved item must belong to that org.
     *
     * @param int $id Numeric ID from the PR body reference
     * @return array{string, int|null} [local_type, local_id] or [string, null] if not found
     */
    private function resolveLocalItem(int $id): array
    {
        $story = UserStory::findById($this->db, $id);
        if ($story !== null) {
            if ($this->orgId !== null && (int) ($story['org_id'] ?? 0) !== $this->orgId) {
                return ['user_story', null];
            }
            return ['user_story', $id];
        }

        $workItem = HLWorkItem::findById($this->db, $id);
        if ($workItem !== null) {
            if ($this->orgId !== null && (int) ($workItem['org_id'] ?? 0) !== $this->orgId) {
                return ['hl_work_item', null];
            }
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
        $stmt = $this->db->query("SELECT * FROM story_git_links
             WHERE local_type = :local_type AND local_id = :local_id AND ref_url = :ref_url
             LIMIT 1", [
                ':local_type' => $localType,
                ':local_id'   => $localId,
                ':ref_url'    => $refUrl,
            ]);
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
