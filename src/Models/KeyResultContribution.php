<?php
declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class KeyResultContribution
{
    // ===========================
    // WRITE
    // ===========================

    /**
     * Insert or update a contribution row (keyed on kr + link).
     * Score is clamped to 0–10 before insertion.
     *
     * @param Database $db
     * @param int      $keyResultId      Key result this PR contributes to
     * @param int      $storyGitLinkId   story_git_links.id for the merged PR
     * @param int      $orgId            Tenant scope
     * @param int      $score            AI relevance score 0–10
     * @param string|null $rationale     AI one-sentence rationale
     */
    public static function upsert(
        Database $db,
        int      $keyResultId,
        int      $storyGitLinkId,
        int      $orgId,
        int      $score,
        ?string  $rationale
    ): void {
        $score = max(0, min(10, $score));
        $db->query(
            "INSERT INTO key_result_contributions
                (key_result_id, story_git_link_id, org_id, ai_relevance_score, ai_rationale)
             VALUES (:kr_id, :link_id, :org_id, :score, :rationale)
             ON DUPLICATE KEY UPDATE
               ai_relevance_score = VALUES(ai_relevance_score),
               ai_rationale       = VALUES(ai_rationale),
               scored_at          = NOW()",
            [
                ':kr_id'     => $keyResultId,
                ':link_id'   => $storyGitLinkId,
                ':org_id'    => $orgId,
                ':score'     => $score,
                ':rationale' => $rationale,
            ]
        );
    }

    // ===========================
    // READ
    // ===========================

    /**
     * All contributions for a KR, joined to the git link for display.
     *
     * @param Database $db
     * @param int      $keyResultId
     * @param int      $orgId        Tenant scope
     * @return array
     */
    public static function findByKeyResultId(Database $db, int $keyResultId, int $orgId): array
    {
        return $db->query(
            "SELECT krc.*, sgl.ref_url, sgl.ref_label
               FROM key_result_contributions krc
               JOIN story_git_links sgl ON krc.story_git_link_id = sgl.id
              WHERE krc.key_result_id = :kr_id AND krc.org_id = :oid
              ORDER BY krc.scored_at DESC",
            [':kr_id' => $keyResultId, ':oid' => $orgId]
        )->fetchAll();
    }

    /**
     * Last N contributions for a KR — used to build ai_momentum summary.
     *
     * @param Database $db
     * @param int      $keyResultId
     * @param int      $orgId
     * @param int      $limit        Max rows to return (default 10)
     * @return array
     */
    public static function findRecentByKeyResultId(
        Database $db,
        int      $keyResultId,
        int      $orgId,
        int      $limit = 10
    ): array {
        return $db->query(
            "SELECT krc.ai_relevance_score, krc.ai_rationale, sgl.ref_label
               FROM key_result_contributions krc
               JOIN story_git_links sgl ON krc.story_git_link_id = sgl.id
              WHERE krc.key_result_id = :kr_id AND krc.org_id = :oid
              ORDER BY krc.scored_at DESC
              LIMIT :lim",
            [':kr_id' => $keyResultId, ':oid' => $orgId, ':lim' => $limit]
        )->fetchAll();
    }
}
