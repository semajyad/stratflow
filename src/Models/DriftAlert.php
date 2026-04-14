<?php

/**
 * DriftAlert Model
 *
 * Static data-access methods for the `drift_alerts` table.
 * Stores alerts raised by the Drift Engine when a project deviates
 * from its strategic baseline (scope creep, capacity/dependency
 * tripwires, or alignment issues).
 *
 * Columns: id, project_id, alert_type, severity, details_json,
 *          status, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class DriftAlert
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new drift alert and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, alert_type, severity (optional),
     *                       details_json (JSON-encoded string or array),
     *                       status (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $detailsJson = is_array($data['details_json'])
            ? json_encode($data['details_json'])
            : $data['details_json'];
        $db->query("INSERT INTO drift_alerts
                (project_id, alert_type, severity, details_json, status)
             VALUES
                (:project_id, :alert_type, :severity, :details_json, :status)", [
                ':project_id'   => $data['project_id'],
                ':alert_type'   => $data['alert_type'],
                ':severity'     => $data['severity'] ?? 'warning',
                ':details_json' => $detailsJson,
                ':status'       => $data['status'] ?? 'active',
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all active alerts for a project, ordered by created_at descending.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of active alert rows as associative arrays
     */
    public static function findActiveByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT * FROM drift_alerts
             WHERE project_id = :project_id
               AND status = 'active'
             ORDER BY created_at DESC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Return all alerts for a project regardless of status, ordered by created_at descending.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of alert rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT * FROM drift_alerts
             WHERE project_id = :project_id
             ORDER BY created_at DESC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Find a single drift alert by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Alert primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query("SELECT * FROM drift_alerts WHERE id = :id LIMIT 1", [':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update the status of a drift alert (e.g. acknowledge or resolve it).
     *
     * @param Database $db     Database instance
     * @param int      $id     Alert primary key
     * @param string   $status New status: 'active', 'acknowledged', or 'resolved'
     */
    public static function updateStatus(Database $db, int $id, string $status): void
    {
        $db->query("UPDATE drift_alerts SET status = :status WHERE id = :id", [
                ':status' => $status,
                ':id'     => $id,
            ]);
    }

    // ===========================
    // COUNT
    // ===========================

    /**
     * Count active drift alerts for a project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return int                Number of active alerts
     */
    public static function countActiveByProjectId(Database $db, int $projectId): int
    {
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM drift_alerts
             WHERE project_id = :project_id
               AND status = 'active'", [':project_id' => $projectId]);
        $row = $stmt->fetch();
        return (int) ($row['cnt'] ?? 0);
    }
}
