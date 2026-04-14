<?php

/**
 * GovernanceItem Model
 *
 * Static data-access methods for the `governance_queue` table.
 * Stores proposed changes to a project that require human review
 * before being applied, supporting the Drift Engine's change-control
 * gate for new stories, scope changes, size changes, and dependency changes.
 *
 * Columns: id, project_id, change_type, proposed_change_json,
 *          status, reviewed_by, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class GovernanceItem
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new governance queue item and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, change_type,
     *                       proposed_change_json (JSON-encoded string or array),
     *                       status (optional), reviewed_by (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $proposedJson = is_array($data['proposed_change_json'])
            ? json_encode($data['proposed_change_json'])
            : $data['proposed_change_json'];
        $db->query("INSERT INTO governance_queue
                (project_id, change_type, proposed_change_json, status, reviewed_by)
             VALUES
                (:project_id, :change_type, :proposed_change_json, :status, :reviewed_by)", [
                ':project_id'          => $data['project_id'],
                ':change_type'         => $data['change_type'],
                ':proposed_change_json' => $proposedJson,
                ':status'              => $data['status'] ?? 'pending',
                ':reviewed_by'         => $data['reviewed_by'] ?? null,
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all pending governance items for a project, ordered by created_at ascending.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of pending governance item rows as associative arrays
     */
    public static function findPendingByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT * FROM governance_queue
             WHERE project_id = :project_id
               AND status = 'pending'
             ORDER BY created_at ASC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Return all governance items for a project regardless of status,
     * ordered by created_at descending.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of governance item rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT * FROM governance_queue
             WHERE project_id = :project_id
             ORDER BY created_at DESC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Find a single governance queue item by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Governance item primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query("SELECT * FROM governance_queue WHERE id = :id LIMIT 1", [':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update the status of a governance item, optionally recording the reviewer.
     *
     * @param Database $db         Database instance
     * @param int      $id         Governance item primary key
     * @param string   $status     New status: 'pending', 'approved', or 'rejected'
     * @param int|null $reviewedBy User ID of the reviewer, or null if not yet reviewed
     */
    public static function updateStatus(Database $db, int $id, string $status, ?int $reviewedBy = null): void
    {
        $db->query("UPDATE governance_queue
             SET status = :status, reviewed_by = :reviewed_by
             WHERE id = :id", [
                ':status'      => $status,
                ':reviewed_by' => $reviewedBy,
                ':id'          => $id,
            ]);
    }
}
