<?php
/**
 * SyncLog Model
 *
 * Static data-access methods for the `sync_log` table.
 * Records every push/pull operation for audit and debugging
 * of integration syncs.
 *
 * Columns: id, integration_id, direction, action, local_type,
 *          local_id, external_id, details_json, status, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class SyncLog
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new sync log entry and return its ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: integration_id, direction, action,
     *                       local_type, local_id, external_id,
     *                       details_json, status
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO sync_log
                (integration_id, direction, action, local_type,
                 local_id, external_id, details_json, status)
             VALUES
                (:integration_id, :direction, :action, :local_type,
                 :local_id, :external_id, :details_json, :status)",
            [
                ':integration_id' => $data['integration_id'],
                ':direction'      => $data['direction'],
                ':action'         => $data['action'],
                ':local_type'     => $data['local_type'] ?? null,
                ':local_id'       => $data['local_id'] ?? null,
                ':external_id'    => $data['external_id'] ?? null,
                ':details_json'   => $data['details_json'] ?? null,
                ':status'         => $data['status'] ?? 'success',
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return recent sync log entries for an integration.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     * @param int      $limit         Maximum entries to return (default 50)
     * @return array                  Array of log rows, newest first
     */
    public static function findByIntegration(Database $db, int $integrationId, int $limit = 50): array
    {
        $stmt = $db->query(
            "SELECT * FROM sync_log
             WHERE integration_id = :integration_id
             ORDER BY created_at DESC
             LIMIT :lim",
            [
                ':integration_id' => $integrationId,
                ':lim'            => $limit,
            ]
        );

        return $stmt->fetchAll();
    }
}
