<?php

/**
 * StrategicBaseline Model
 *
 * Static data-access methods for the `strategic_baselines` table.
 * Stores point-in-time snapshots of a project's scope and plan,
 * used by the Drift Engine to detect deviations from the original baseline.
 *
 * Columns: id, project_id, snapshot_json, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class StrategicBaseline
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new baseline snapshot and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, snapshot_json (JSON-encoded string or array)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $snapshotJson = is_array($data['snapshot_json'])
            ? json_encode($data['snapshot_json'])
            : $data['snapshot_json'];
        $db->query("INSERT INTO strategic_baselines (project_id, snapshot_json)
             VALUES (:project_id, :snapshot_json)", [
                ':project_id'    => $data['project_id'],
                ':snapshot_json' => $snapshotJson,
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return the most recently created baseline for a project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array|null         Row as associative array, or null if none exists
     */
    public static function findLatestByProjectId(Database $db, int $projectId): ?array
    {
        $stmt = $db->query("SELECT * FROM strategic_baselines
             WHERE project_id = :project_id
             ORDER BY created_at DESC
             LIMIT 1", [':project_id' => $projectId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Return all baselines for a project, ordered by created_at descending.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of baseline rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT * FROM strategic_baselines
             WHERE project_id = :project_id
             ORDER BY created_at DESC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }
}
