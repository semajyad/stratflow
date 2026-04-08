<?php
/**
 * AuditLogger Service
 *
 * Logs all security-relevant events to the audit_logs database table.
 * Required for HIPAA audit trails, SOC 2 monitoring, and PCI-DSS logging.
 *
 * Usage:
 *   AuditLogger::log($db, $userId, 'login_success', $ip, $ua, ['email' => $email]);
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;

class AuditLogger
{
    // ===========================
    // EVENT TYPE CONSTANTS
    // ===========================

    public const LOGIN_SUCCESS         = 'login_success';
    public const LOGIN_FAILURE         = 'login_failure';
    public const LOGOUT                = 'logout';
    public const PASSWORD_CHANGE       = 'password_change';
    public const PASSWORD_RESET_REQUEST = 'password_reset_request';
    public const USER_CREATED          = 'user_created';
    public const USER_DELETED          = 'user_deleted';
    public const USER_ROLE_CHANGED     = 'user_role_changed';
    public const DATA_EXPORT           = 'data_export';
    public const ADMIN_ACTION          = 'admin_action';
    public const SETTINGS_CHANGED      = 'settings_changed';
    public const PROJECT_CREATED       = 'project_created';
    public const PROJECT_DELETED       = 'project_deleted';
    public const DOCUMENT_UPLOADED     = 'document_uploaded';
    public const API_KEY_USED          = 'api_key_used';

    // ===========================
    // LOGGING
    // ===========================

    /**
     * Record a security event to the audit_logs table.
     *
     * @param Database    $db        Database connection
     * @param int|null    $userId    Acting user ID (null for unauthenticated events)
     * @param string      $eventType One of the event type constants
     * @param string      $ip        Client IP address
     * @param string      $userAgent Client User-Agent string
     * @param array       $details   Additional context as key-value pairs
     */
    public static function log(
        Database $db,
        ?int $userId,
        string $eventType,
        string $ip,
        string $userAgent,
        array $details = []
    ): void {
        try {
            // Guard against missing table during initial deployment
            if (!$db->tableExists('audit_logs')) {
                return;
            }

            $db->query(
                "INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent, details_json)
                 VALUES (:user_id, :event_type, :ip, :ua, :details)",
                [
                    'user_id'    => $userId,
                    'event_type' => $eventType,
                    'ip'         => substr($ip, 0, 45),
                    'ua'         => substr($userAgent, 0, 500),
                    'details'    => json_encode($details, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            // Audit logging must never break the application flow
            error_log('[AuditLogger] Failed to write audit log: ' . $e->getMessage());
        }
    }
}
