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
}
