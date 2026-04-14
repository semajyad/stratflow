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
        $db->query("INSERT INTO sync_log
                (integration_id, direction, action, local_type,
                 local_id, external_id, details_json, status)
             VALUES
                (:integration_id, :direction, :action, :local_type,
                 :local_id, :external_id, :details_json, :status)", [
                ':integration_id' => $data['integration_id'],
                ':direction'      => $data['direction'],
                ':action'         => $data['action'],
                ':local_type'     => $data['local_type'] ?? null,
                ':local_id'       => $data['local_id'] ?? null,
                ':external_id'    => $data['external_id'] ?? null,
                ':details_json'   => $data['details_json'] ?? null,
                ':status'         => $data['status'] ?? 'success',
            ]);
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
        $stmt = $db->query("SELECT * FROM sync_log
             WHERE integration_id = :integration_id
             ORDER BY created_at DESC
             LIMIT :lim", [
                ':integration_id' => $integrationId,
                ':lim'            => $limit,
            ]);
        return $stmt->fetchAll();
    }

    /**
     * Return paginated, optionally filtered sync log entries.
     *
     * @param Database    $db            Database instance
     * @param int         $integrationId Integration ID
     * @param int         $page          Current page (1-based)
     * @param int         $perPage       Rows per page
     * @param string|null $direction     Filter by direction ('push'/'pull') or null for all
     * @param string|null $status        Filter by status ('success'/'error') or null for all
     * @return array{rows: array, total: int}  Log rows and total matching count
     */
    public static function findByIntegrationPaginated(Database $db, int $integrationId, int $page = 1, int $perPage = 50, ?string $direction = null, ?string $status = null): array
    {
        $where  = 'WHERE integration_id = :integration_id';
        $params = [':integration_id' => $integrationId];
        if ($direction !== null) {
            $where .= ' AND direction = :direction';
            $params[':direction'] = $direction;
        }

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        // Total count for pagination
        $countStmt = $db->query("SELECT COUNT(*) AS cnt FROM sync_log {$where}", $params);
        $total = (int) $countStmt->fetch()['cnt'];
// Paginated rows
        $offset = ($page - 1) * $perPage;
        $params[':lim']    = $perPage;
        $params[':offset'] = $offset;
        $stmt = $db->query("SELECT * FROM sync_log {$where}
             ORDER BY created_at DESC
             LIMIT :lim OFFSET :offset", $params);
        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
        ];
    }

    /**
     * Count total sync log entries for an integration.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     * @return int                    Total row count
     */
    public static function countByIntegration(Database $db, int $integrationId): int
    {
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM sync_log
             WHERE integration_id = :integration_id", [':integration_id' => $integrationId]);
        return (int) $stmt->fetch()['cnt'];
    }

    /**
     * Return all sync log entries for an integration, with optional filters.
     *
     * Used for CSV export — no pagination limit.
     *
     * @param Database    $db            Database instance
     * @param int         $integrationId Integration ID
     * @param string|null $direction     Filter by direction or null for all
     * @param string|null $status        Filter by status or null for all
     * @return array                     All matching log rows, newest first
     */
    public static function findAllByIntegration(Database $db, int $integrationId, ?string $direction = null, ?string $status = null): array
    {
        $where  = 'WHERE integration_id = :integration_id';
        $params = [':integration_id' => $integrationId];
        if ($direction !== null) {
            $where .= ' AND direction = :direction';
            $params[':direction'] = $direction;
        }

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $stmt = $db->query("SELECT * FROM sync_log {$where}
             ORDER BY created_at DESC", $params);
        return $stmt->fetchAll();
    }
}
