<?php
/**
 * AuditLog Model
 *
 * Query helpers for reading audit log entries. Used by the superadmin
 * audit log viewer for compliance review and incident investigation.
 *
 * Table: audit_logs
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class AuditLog
{
    /**
     * Find audit log entries by user ID (most recent first).
     *
     * @param Database $db     Database connection
     * @param int      $userId User ID to filter by
     * @param int      $limit  Maximum rows to return
     * @return array           Array of audit log rows
     */
    public static function findByUserId(Database $db, int $userId, int $limit = 100): array
    {
        $stmt = $db->query(
            "SELECT al.*, u.full_name, u.email
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.user_id = :uid
             ORDER BY al.created_at DESC
             LIMIT :lim",
            ['uid' => $userId, 'lim' => $limit]
        );
        return $stmt->fetchAll();
    }

    /**
     * Find audit log entries by event type (most recent first).
     *
     * @param Database $db   Database connection
     * @param string   $type Event type to filter by
     * @param int      $limit Maximum rows to return
     * @return array          Array of audit log rows
     */
    public static function findByEventType(Database $db, string $type, int $limit = 100): array
    {
        $stmt = $db->query(
            "SELECT al.*, u.full_name, u.email
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.event_type = :type
             ORDER BY al.created_at DESC
             LIMIT :lim",
            ['type' => $type, 'lim' => $limit]
        );
        return $stmt->fetchAll();
    }

    /**
     * Find the most recent audit log entries across all users and types.
     *
     * @param Database $db    Database connection
     * @param int      $limit Maximum rows to return
     * @return array          Array of audit log rows
     */
    public static function findRecent(Database $db, int $limit = 200): array
    {
        $stmt = $db->query(
            "SELECT al.*, u.full_name, u.email
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT :lim",
            ['lim' => $limit]
        );
        return $stmt->fetchAll();
    }

    /**
     * Find audit logs with filters for export. No limit.
     */
    public static function findFiltered(Database $db, ?int $orgId = null, ?string $eventType = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $sql = "SELECT al.*, u.full_name, u.email, u.org_id
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE 1=1";
        $params = [];

        if ($orgId !== null) {
            $sql .= " AND (u.org_id = :org_id OR al.user_id IS NULL)";
            $params[':org_id'] = $orgId;
        }
        if ($eventType !== null && $eventType !== '') {
            $sql .= " AND al.event_type = :event_type";
            $params[':event_type'] = $eventType;
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $sql .= " AND al.created_at >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null && $dateTo !== '') {
            $sql .= " AND al.created_at <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $sql .= " ORDER BY al.created_at DESC";

        $stmt = $db->query($sql, $params);
        return $stmt->fetchAll();
    }
}
