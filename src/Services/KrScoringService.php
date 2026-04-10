<?php
/**
 * KrScoringService
 *
 * Scores merged PRs against Key Results using Gemini AI.
 *
 * When a PR is merged, this service:
 * 1. Finds all story_git_links pointing to that PR URL with status='merged'.
 * 2. Resolves linked work items (hl_work_item or user_story → parent hl_work_item).
 * 3. Looks up all Key Results for those work items.
 * 4. Asks Gemini to score each KR × PR pair (0–10).
 * 5. Upserts KeyResultContribution rows and refreshes ai_momentum on the KR.
 *
 * Usage:
 *   $service = new KrScoringService($db, $gemini);
 *   $service->scoreForMergedPr($prUrl, $orgId);
 */
declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\KeyResult;
use StratFlow\Models\KeyResultContribution;
use StratFlow\Services\Prompts\KrScoringPrompt;

class KrScoringService
{
    /** Number of recent contributions used to build the ai_momentum summary. */
    private const MOMENTUM_WINDOW = 10;

    // ===========================
    // CONSTRUCTOR
    // ===========================

    /**
     * @param Database           $db     Database instance
     * @param GeminiService|null $gemini Gemini service; pass null to disable scoring
     */
    public function __construct(
        private readonly Database       $db,
        private readonly ?GeminiService $gemini
    ) {}

    // ===========================
    // PUBLIC API
    // ===========================

    /**
     * Score all KRs linked to the given merged PR URL.
     *
     * No-op if Gemini is null, if no merged links exist for the URL,
     * or if no work items (and therefore no KRs) are found within the org.
     *
     * @param string $prUrl  Canonical PR URL (must match story_git_links.ref_url)
     * @param int    $orgId  Organisation ID for tenancy scoping
     */
    public function scoreForMergedPr(string $prUrl, int $orgId): void
    {
        if ($this->gemini === null) {
            return;
        }

        $links = $this->db->query(
            "SELECT id, local_type, local_id, ref_label FROM story_git_links
              WHERE ref_url = :url AND status = 'merged'",
            [':url' => $prUrl]
        )->fetchAll();

        if (empty($links)) {
            return;
        }

        $workItemIds = $this->resolveWorkItemIds($links, $orgId);
        if (empty($workItemIds)) {
            return;
        }

        $krs = [];
        foreach ($workItemIds as $wid) {
            foreach (KeyResult::findByWorkItemId($this->db, $wid, $orgId) as $kr) {
                $krs[] = $kr;
            }
        }

        if (empty($krs)) {
            return;
        }

        $prTitle = $links[0]['ref_label'] ?? $prUrl;

        foreach ($krs as $kr) {
            foreach ($links as $link) {
                $this->scoreOneKr($kr, (int) $link['id'], $orgId, $prTitle);
            }
            $this->refreshMomentum((int) $kr['id'], $orgId);
        }
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Score a single KR × git-link pair and upsert the contribution row.
     *
     * Calls Gemini with the KR metadata and PR title. The score from Gemini
     * is clamped to 0–10 before storage (upsert also clamps, defensive here).
     *
     * @param array  $kr       Key result row from KeyResult::findByWorkItemId
     * @param int    $linkId   story_git_links.id for this PR
     * @param int    $orgId    Organisation ID
     * @param string $prTitle  PR display label for the Gemini prompt
     */
    private function scoreOneKr(array $kr, int $linkId, int $orgId, string $prTitle): void
    {
        $input = json_encode([
            'kr_title'       => $kr['title'],
            'kr_description' => $kr['metric_description'] ?? '',
            'kr_target'      => $kr['target_value'] ? "{$kr['target_value']} {$kr['unit']}" : '',
            'pr_title'       => $prTitle,
            'pr_body'        => '',
        ], JSON_UNESCAPED_UNICODE);

        try {
            $result = $this->gemini->generateJson(KrScoringPrompt::PROMPT, $input);
        } catch (\Throwable $e) {
            error_log('[KrScoringService] Gemini error for kr_id=' . $kr['id'] . ': ' . $e->getMessage());
            return;
        }

        $score     = max(0, min(10, (int) ($result['score'] ?? 0)));
        $rationale = (string) ($result['rationale'] ?? '');

        KeyResultContribution::upsert($this->db, (int) $kr['id'], $linkId, $orgId, $score, $rationale);
    }

    /**
     * Rebuild the ai_momentum text for a KR from its most recent contributions.
     *
     * Fetches the last MOMENTUM_WINDOW contributions and writes a plain-text
     * summary back to key_results.ai_momentum.
     *
     * @param int $krId   Key result ID
     * @param int $orgId  Organisation ID
     */
    private function refreshMomentum(int $krId, int $orgId): void
    {
        $recent = KeyResultContribution::findRecentByKeyResultId($this->db, $krId, $orgId, self::MOMENTUM_WINDOW);
        if (empty($recent)) {
            return;
        }

        $summary = 'Recent PRs: ' . implode('; ', array_map(
            fn($c) => '"' . ($c['ref_title'] ?? $c['ref_label'] ?? 'PR') . '" scored ' . ($c['ai_relevance_score'] ?? 0) . '/10',
            $recent
        ));

        KeyResult::update($this->db, $krId, $orgId, ['ai_momentum' => mb_substr($summary, 0, 500)]);
    }

    /**
     * Resolve story_git_links rows to hl_work_item IDs within the org.
     *
     * For 'hl_work_item' links: verifies the item belongs to the org via projects.
     * For 'user_story' links: finds the parent hl_work_item if set.
     * Deduplicates the resulting IDs.
     *
     * @param array $links  Rows from story_git_links
     * @param int   $orgId  Organisation ID
     * @return int[]        Unique hl_work_item IDs
     */
    private function resolveWorkItemIds(array $links, int $orgId): array
    {
        $ids = [];
        foreach ($links as $link) {
            $localType = (string) ($link['local_type'] ?? '');
            $localId   = (int) ($link['local_id'] ?? 0);

            if ($localType === 'hl_work_item') {
                $row = $this->db->query(
                    "SELECT hwi.id FROM hl_work_items hwi
                       JOIN projects p ON hwi.project_id = p.id
                      WHERE hwi.id = :id AND p.org_id = :oid LIMIT 1",
                    [':id' => $localId, ':oid' => $orgId]
                )->fetch();
                if ($row !== false) {
                    $ids[$localId] = $localId;
                }
            } elseif ($localType === 'user_story') {
                $row = $this->db->query(
                    "SELECT us.parent_hl_item_id FROM user_stories us
                       JOIN projects p ON us.project_id = p.id
                      WHERE us.id = :id AND p.org_id = :oid LIMIT 1",
                    [':id' => $localId, ':oid' => $orgId]
                )->fetch();
                if ($row !== false && $row['parent_hl_item_id'] !== null) {
                    $wid = (int) $row['parent_hl_item_id'];
                    $ids[$wid] = $wid;
                }
            }
        }
        return array_values($ids);
    }
}
