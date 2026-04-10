<?php
/**
 * GitPrMatcherService
 *
 * AI fallback for linking GitHub pull requests to work items when no
 * explicit SF-{id} reference appears in the PR body.
 *
 * Calls Gemini with the PR metadata and a list of open work items,
 * then delegates to GitLinkService::linkAiMatched() for any match
 * that exceeds the confidence threshold.
 *
 * Usage:
 *   $service = new GitPrMatcherService($db, $gemini);
 *   $linked  = $service->matchAndLink($prTitle, $prBody, $branch, $prUrl, $orgId);
 */
declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Services\Prompts\GitPrMatchPrompt;

class GitPrMatcherService
{
    /** Minimum Gemini confidence score required to insert a link. */
    private const CONFIDENCE_THRESHOLD = 0.7;

    // ===========================
    // CONSTRUCTOR
    // ===========================

    /**
     * @param Database            $db     Database instance
     * @param GeminiService|null  $gemini Gemini service; pass null to disable AI matching
     */
    public function __construct(
        private readonly Database       $db,
        private readonly ?GeminiService $gemini
    ) {}

    // ===========================
    // PUBLIC API
    // ===========================

    /**
     * Match a PR against open work items and insert AI-matched links.
     *
     * Returns 0 immediately if $gemini is null or no candidates exist.
     *
     * @param string $prTitle PR title
     * @param string $prBody  Raw PR description (truncated internally to 1 500 chars)
     * @param string $branch  Source branch name
     * @param string $prUrl   Canonical PR URL
     * @param int    $orgId   Organisation ID for tenancy scoping
     * @return int            Number of new links inserted
     */
    public function matchAndLink(
        string $prTitle,
        string $prBody,
        string $branch,
        string $prUrl,
        int    $orgId
    ): int {
        if ($this->gemini === null) {
            return 0;
        }

        $candidates = $this->loadCandidates($orgId);
        if (empty($candidates)) {
            return 0;
        }

        $input = json_encode([
            'pr_title'   => $prTitle,
            'pr_body'    => mb_substr($prBody, 0, 1500),
            'branch'     => $branch,
            'candidates' => $candidates,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $matches = $this->gemini->generateJson(GitPrMatchPrompt::PROMPT, $input);
        } catch (\Throwable $e) {
            error_log('[GitPrMatcherService] Gemini error: ' . $e->getMessage());
            return 0;
        }

        if (!is_array($matches)) {
            return 0;
        }

        $toLink = [];
        foreach ($matches as $match) {
            $confidence = (float) ($match['confidence'] ?? 0.0);
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                continue;
            }
            $id   = (int) ($match['id']   ?? 0);
            $type = (string) ($match['type'] ?? '');
            if ($id > 0 && in_array($type, ['user_story', 'hl_work_item'], true)) {
                $toLink[] = ['local_type' => $type, 'local_id' => $id];
            }
        }

        if (empty($toLink)) {
            return 0;
        }

        $service = new GitLinkService($this->db, $orgId);
        return $service->linkAiMatched($toLink, $prUrl, $prTitle, 'github');
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Load open user stories and OKR work items as Gemini candidates.
     *
     * Limits: 100 user stories + 50 hl_work_items (OKR-tagged only).
     *
     * @param int $orgId Organisation ID
     * @return array     Flat list of candidate arrays for the Gemini prompt
     */
    private function loadCandidates(int $orgId): array
    {
        $candidates = [];

        $stories = $this->db->query(
            "SELECT us.id, us.title, us.description
               FROM user_stories us
               JOIN projects p ON us.project_id = p.id
              WHERE p.org_id = :oid
                AND us.status NOT IN ('done')
              LIMIT 100",
            [':oid' => $orgId]
        )->fetchAll();

        foreach ($stories as $s) {
            $candidates[] = [
                'id'          => (int) $s['id'],
                'type'        => 'user_story',
                'title'       => $s['title'],
                'description' => mb_substr((string) ($s['description'] ?? ''), 0, 300),
            ];
        }

        $items = $this->db->query(
            "SELECT hwi.id, hwi.title, hwi.description
               FROM hl_work_items hwi
               JOIN projects p ON hwi.project_id = p.id
              WHERE p.org_id = :oid
                AND hwi.status NOT IN ('done')
                AND hwi.okr_title IS NOT NULL
              LIMIT 50",
            [':oid' => $orgId]
        )->fetchAll();

        foreach ($items as $item) {
            $candidates[] = [
                'id'          => (int) $item['id'],
                'type'        => 'hl_work_item',
                'title'       => $item['title'],
                'description' => mb_substr((string) ($item['description'] ?? ''), 0, 300),
            ];
        }

        return $candidates;
    }
}
