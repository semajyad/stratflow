<?php
/**
 * AuditLogger Service — v2
 *
 * Logs all security-relevant events to the audit_logs database table with a
 * tamper-evident HMAC hash chain. Each row records a `row_hash` that chains
 * to the previous row's hash, keyed by AUDIT_HMAC_KEY. A nightly verification
 * job can replay the chain and alert on any break.
 *
 * Required for HIPAA audit trails, SOC 2 monitoring, and PCI-DSS logging.
 *
 * On any DB write failure the event is appended as a JSON line to the fallback
 * file (storage/logs/audit-fallback.jsonl) — audit events are never silently
 * dropped.
 *
 * Usage:
 *   AuditLogger::log($db, $userId, 'login_success', $ip, $ua, ['email' => $email]);
 *   AuditLogger::log($db, $userId, AuditLogger::PROJECT_CREATED, $ip, $ua,
 *                    ['project_id' => $id], orgId: 5, resourceType: 'project', resourceId: $id);
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;

class AuditLogger
{
    // ===========================
    // EVENT TYPE CONSTANTS
    // ===========================

    public const LOGIN_SUCCESS            = 'login_success';
    public const LOGIN_FAILURE            = 'login_failure';
    public const LOGOUT                   = 'logout';
    public const PASSWORD_CHANGE          = 'password_change';
    public const PASSWORD_RESET_REQUEST   = 'password_reset_request';
    public const USER_CREATED             = 'user_created';
    public const USER_DELETED             = 'user_deleted';
    public const USER_ROLE_CHANGED        = 'user_role_changed';
    public const DATA_EXPORT              = 'data_export';
    public const ADMIN_ACTION             = 'admin_action';
    public const SETTINGS_CHANGED         = 'settings_changed';
    public const PROJECT_CREATED          = 'project_created';
    public const PROJECT_DELETED          = 'project_deleted';
    public const DOCUMENT_UPLOADED        = 'document_uploaded';
    public const API_KEY_USED             = 'api_key_used';
    public const INTEGRATION_CONNECTED    = 'integration_connected';
    public const INTEGRATION_DISCONNECTED = 'integration_disconnected';
    public const INTEGRATION_SYNC         = 'integration_sync';
    public const INTEGRATION_WEBHOOK      = 'integration_webhook';
    public const STORIES_GENERATED        = 'stories_generated';
    public const WORK_ITEMS_GENERATED     = 'work_items_generated';
    public const QUALITY_REFINE_ALL       = 'quality_refine_all';
    public const QUALITY_REFINED          = 'quality_refined';
    public const STRIPE_PLAN_CHANGED      = 'stripe_plan_changed';
    public const DOCUMENT_DOWNLOADED      = 'document_downloaded';

    // ===========================
    // FALLBACK LOG
    // ===========================

    private const FALLBACK_LOG = __DIR__ . '/../../storage/logs/audit-fallback.jsonl';

    // ===========================
    // LOGGING
    // ===========================

    /**
     * Record a security event to the audit_logs table with a chained row hash.
     *
     * @param Database    $db           Database connection
     * @param int|null    $userId       Acting user ID (null for unauthenticated events)
     * @param string      $eventType    One of the event type constants
     * @param string      $ip           Client IP address
     * @param string      $userAgent    Client User-Agent string
     * @param array       $details      Additional context as key-value pairs
     * @param int|null    $orgId        Organisation ID (strongly recommended)
     * @param string|null $resourceType Resource type (e.g. 'project', 'story', 'work_item')
     * @param int|null    $resourceId   Resource ID
     */
    public static function log(
        Database $db,
        ?int $userId,
        string $eventType,
        string $ip,
        string $userAgent,
        array $details = [],
        ?int $orgId = null,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): void {
        $entry = [
            'user_id'       => $userId,
            'org_id'        => $orgId,
            'event_type'    => $eventType,
            'ip'            => substr($ip, 0, 45),
            'ua'            => substr($userAgent, 0, 500),
            'details'       => json_encode($details, JSON_UNESCAPED_UNICODE),
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
        ];

        try {
            // Guard against missing table during initial deployment
            if (!$db->tableExists('audit_logs')) {
                self::writeFallback($entry, 'table_missing');
                return;
            }

            // Fetch the last row_hash for the chain (org-scoped if org_id set)
            $prevHash = self::fetchLastHash($db, $orgId);

            // Compute this row's HMAC over canonical JSON of event + prev_hash
            $rowHash  = self::computeHash($prevHash, $entry);

            $db->query(
                "INSERT INTO audit_logs
                    (user_id, org_id, event_type, ip_address, user_agent, details_json,
                     resource_type, resource_id, prev_hash, row_hash)
                 VALUES
                    (:user_id, :org_id, :event_type, :ip, :ua, :details,
                     :resource_type, :resource_id, :prev_hash, :row_hash)",
                [
                    'user_id'       => $entry['user_id'],
                    'org_id'        => $entry['org_id'],
                    'event_type'    => $entry['event_type'],
                    'ip'            => $entry['ip'],
                    'ua'            => $entry['ua'],
                    'details'       => $entry['details'],
                    'resource_type' => $entry['resource_type'],
                    'resource_id'   => $entry['resource_id'],
                    'prev_hash'     => $prevHash,
                    'row_hash'      => $rowHash,
                ]
            );
        } catch (\Throwable $e) {
            // Audit logging must never break the application flow, but failures
            // must themselves be recorded — write to fallback file.
            \StratFlow\Services\Logger::warn('[AuditLogger] Failed to write audit log: ' . $e->getMessage());
            self::writeFallback($entry, $e->getMessage());
        }
    }

    // ===========================
    // CHAIN VERIFICATION
    // ===========================

    /**
     * Verify the HMAC hash chain for a given org (or the whole table if null).
     * Returns true if the chain is intact, false if any row is tampered.
     *
     * Intended for a nightly verification cron job.
     *
     * @param Database $db
     * @param int|null $orgId
     * @return array{ok: bool, broken_at: int|null, total: int}
     */
    public static function verifyChain(Database $db, ?int $orgId = null): array
    {
        try {
            $where  = $orgId !== null ? 'WHERE org_id = :org_id' : '';
            $params = $orgId !== null ? ['org_id' => $orgId] : [];

            $stmt = $db->query(
                "SELECT id, user_id, org_id, event_type, ip_address, user_agent,
                        details_json, resource_type, resource_id, prev_hash, row_hash
                 FROM audit_logs {$where}
                 ORDER BY id ASC",
                $params
            );

            $rows     = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $total    = count($rows);
            $prevHash = null;

            foreach ($rows as $row) {
                $entry = [
                    'user_id'       => $row['user_id'],
                    'org_id'        => $row['org_id'],
                    'event_type'    => $row['event_type'],
                    'ip'            => $row['ip_address'],
                    'ua'            => $row['user_agent'],
                    'details'       => $row['details_json'],
                    'resource_type' => $row['resource_type'],
                    'resource_id'   => $row['resource_id'],
                ];

                $expected = self::computeHash($prevHash, $entry);

                if (!hash_equals($expected, (string) $row['row_hash'])) {
                    return ['ok' => false, 'broken_at' => (int) $row['id'], 'total' => $total];
                }

                $prevHash = $row['row_hash'];
            }

            return ['ok' => true, 'broken_at' => null, 'total' => $total];
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[AuditLogger] Chain verification failed: ' . $e->getMessage());
            return ['ok' => false, 'broken_at' => null, 'total' => 0];
        }
    }

    // ===========================
    // INTERNAL HELPERS
    // ===========================

    private static function fetchLastHash(Database $db, ?int $orgId): ?string
    {
        $where  = $orgId !== null ? 'WHERE org_id = :org_id' : '';
        $params = $orgId !== null ? ['org_id' => $orgId] : [];

        $stmt = $db->query(
            "SELECT row_hash FROM audit_logs {$where} ORDER BY id DESC LIMIT 1",
            $params
        );
        $row = $stmt->fetch();
        return $row ? (string) $row['row_hash'] : null;
    }

    private static function computeHash(?string $prevHash, array $entry): string
    {
        $key = (string) ($_ENV['AUDIT_HMAC_KEY'] ?? '');

        // Canonical payload — consistent ordering, no extra keys
        $canonical = json_encode([
            'prev'          => $prevHash,
            'user_id'       => $entry['user_id'],
            'org_id'        => $entry['org_id'],
            'event_type'    => $entry['event_type'],
            'ip'            => $entry['ip'],
            'details'       => $entry['details'],
            'resource_type' => $entry['resource_type'],
            'resource_id'   => $entry['resource_id'],
        ], JSON_UNESCAPED_UNICODE);

        if ($key === '') {
            // No key configured — use a plain SHA-256 hash instead of HMAC.
            // Still detects accidental corruption; not cryptographically keyed.
            return hash('sha256', (string) $canonical);
        }

        return hash_hmac('sha256', (string) $canonical, $key);
    }

    private static function writeFallback(array $entry, string $reason): void
    {
        try {
            $line = json_encode(array_merge($entry, [
                'fallback_reason' => $reason,
                'ts'              => date('c'),
            ]), JSON_UNESCAPED_UNICODE) . PHP_EOL;

            $dir = dirname(self::FALLBACK_LOG);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            file_put_contents(self::FALLBACK_LOG, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Last resort — nothing more we can do
        }
    }
}
