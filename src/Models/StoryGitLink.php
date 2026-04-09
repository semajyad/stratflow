<?php
/**
 * StoryGitLink Model
 *
 * Static data-access methods for the `story_git_links` table.
 * Records PR, commit, and branch references linked to user stories
 * or high-level work items. Provider-agnostic: both manual pastes and
 * auto-linked webhook events write to the same table.
 *
 * Columns: id, local_type, local_id, provider, ref_type, ref_url,
 *          ref_label, status, author, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class StoryGitLink
{
    // ===========================
    // READ
    // ===========================

    /**
     * Return all git links for a single story or work item, newest first.
     *
     * @param Database $db        Database instance
     * @param string   $localType 'user_story' or 'hl_work_item'
     * @param int      $localId   Primary key of the local item
     * @return array              Array of link rows as associative arrays
     */
    public static function findByLocalItem(Database $db, string $localType, int $localId): array
    {
        $stmt = $db->query(
            "SELECT * FROM story_git_links
             WHERE local_type = :local_type AND local_id = :local_id
             ORDER BY created_at DESC",
            [
                ':local_type' => $localType,
                ':local_id'   => $localId,
            ]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single link by its primary key.
     *
     * Prefer this over findByRefUrl when you know the id, because ref_url
     * is not unique across rows — it is only unique per (local_type, local_id).
     *
     * @param Database $db Database instance
     * @param int      $id Link primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM story_git_links WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Find a single link by its ref URL (used for webhook deduplication).
     *
     * @param Database $db    Database instance
     * @param string   $refUrl Full URL of the PR, commit, or branch
     * @return array|null      Row as associative array, or null if not found
     */
    public static function findByRefUrl(Database $db, string $refUrl): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM story_git_links WHERE ref_url = :ref_url LIMIT 1",
            [':ref_url' => $refUrl]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Count all git links for a single story or work item.
     *
     * Used to render the row badge without a full data fetch.
     *
     * @param Database $db        Database instance
     * @param string   $localType 'user_story' or 'hl_work_item'
     * @param int      $localId   Primary key of the local item
     * @return int                Number of linked refs
     */
    public static function countByLocalItem(Database $db, string $localType, int $localId): int
    {
        $stmt = $db->query(
            "SELECT COUNT(*) FROM story_git_links
             WHERE local_type = :local_type AND local_id = :local_id",
            [
                ':local_type' => $localType,
                ':local_id'   => $localId,
            ]
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * Bulk-fetch all git link rows for a set of local IDs of the same type.
     *
     * Returns a map of local_id => array of link rows (newest first).
     * Use this in the traceability view to avoid N+1 queries when rendering
     * git links for many items in a single page load.
     *
     * @param Database $db        Database instance
     * @param string   $localType 'user_story' or 'hl_work_item'
     * @param int[]    $localIds  Array of primary keys to query
     * @return array<int, array>  Map of local_id => array of link rows
     */
    public static function findByLocalItemsBulk(Database $db, string $localType, array $localIds): array
    {
        if (empty($localIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($localIds), '?'));
        $stmt = $db->getPdo()->prepare(
            "SELECT * FROM story_git_links
             WHERE local_type = ? AND local_id IN ({$placeholders})
             ORDER BY created_at DESC"
        );

        $params = array_merge([$localType], array_values($localIds));
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['local_id']][] = $row;
        }

        return $map;
    }

    /**
     * Bulk-fetch git link counts for a set of local IDs of the same type.
     *
     * Returns an associative array keyed by local_id.
     * Use this in list controllers to avoid N+1 queries.
     *
     * @param Database $db        Database instance
     * @param string   $localType 'user_story' or 'hl_work_item'
     * @param int[]    $localIds  Array of primary keys to query
     * @return array<int, int>    Map of local_id => count
     */
    public static function countsByLocalIds(Database $db, string $localType, array $localIds): array
    {
        if (empty($localIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($localIds), '?'));
        $stmt = $db->getPdo()->prepare(
            "SELECT local_id, COUNT(*) AS cnt
             FROM story_git_links
             WHERE local_type = ? AND local_id IN ({$placeholders})
             GROUP BY local_id"
        );

        $params = array_merge([$localType], array_values($localIds));
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['local_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new git link and return its new ID.
     *
     * Performs an INSERT IGNORE on the unique key (local_type, local_id, ref_url)
     * so duplicate calls are safe. If the row already exists, returns 0.
     *
     * @param Database $db   Database instance
     * @param array    $data Required: local_type, local_id, provider, ref_type, ref_url.
     *                       Optional: ref_label, status, author.
     * @return int           ID of the inserted row, or 0 if already exists
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT IGNORE INTO story_git_links
                (local_type, local_id, provider, ref_type, ref_url, ref_label, status, author)
             VALUES
                (:local_type, :local_id, :provider, :ref_type, :ref_url, :ref_label, :status, :author)",
            [
                ':local_type' => $data['local_type'],
                ':local_id'   => $data['local_id'],
                ':provider'   => $data['provider'],
                ':ref_type'   => $data['ref_type'],
                ':ref_url'    => $data['ref_url'],
                ':ref_label'  => $data['ref_label'] ?? null,
                ':status'     => $data['status'] ?? 'unknown',
                ':author'     => $data['author'] ?? null,
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update the status of a single link by ID.
     *
     * @param Database $db     Database instance
     * @param int      $id     Link primary key
     * @param string   $status New status value: 'open', 'merged', 'closed', or 'unknown'
     * @return bool            True if a row was updated
     */
    public static function updateStatus(Database $db, int $id, string $status): bool
    {
        $stmt = $db->query(
            "UPDATE story_git_links SET status = :status, updated_at = NOW() WHERE id = :id",
            [':status' => $status, ':id' => $id]
        );

        return $stmt->rowCount() > 0;
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a single link by ID with a scoping guard.
     *
     * The guard verifies local_type and local_id match the caller's context,
     * preventing accidental deletion of a link belonging to another item.
     *
     * @param Database $db        Database instance
     * @param int      $id        Link primary key
     * @param string   $localType Expected local_type ('user_story' or 'hl_work_item')
     * @param int      $localId   Expected local_id
     * @return bool               True if a row was deleted
     */
    public static function deleteById(Database $db, int $id, string $localType, int $localId): bool
    {
        $stmt = $db->query(
            "DELETE FROM story_git_links
             WHERE id = :id AND local_type = :local_type AND local_id = :local_id",
            [
                ':id'         => $id,
                ':local_type' => $localType,
                ':local_id'   => $localId,
            ]
        );

        return $stmt->rowCount() > 0;
    }
}
