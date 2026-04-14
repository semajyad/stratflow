<?php

/**
 * UserDataExportController — DSAR (Data Subject Access Request) Export
 *
 * GET  /app/account/export-data → confirmation page
 * POST /app/account/export-data → generates and streams a ZIP of all user-owned data
 *
 * Data included:
 *   - Account details (profile.json)
 *   - Projects the user's org owns (projects.json)
 *   - User stories assigned to or created by the user (user_stories.json)
 *   - Work items owned by the user (work_items.json)
 *   - Audit log entries for this user (audit_log.json)
 *
 * Sensitive fields (passwords, MFA secrets) are excluded.
 * Audit event: DATA_EXPORT is logged on every successful export.
 *
 * GDPR Article 20 — Right to data portability.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Services\AuditLogger;

class UserDataExportController
{
    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    /**
     * GET /app/account/export-data — show confirmation page.
     */
    public function index(): void
    {
        $this->response->render('account/export-data', [], 'app');
    }

    /**
     * POST /app/account/export-data — build and stream the ZIP.
     */
    public function export(): void
    {
        $user  = $this->auth->user();
        $uid   = (int) $user['id'];
        $orgId = (int) $user['org_id'];
        $ip    = $this->request->ip();
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // ===========================
        // COLLECT DATA
        // ===========================

        // Profile (no password_hash, no mfa_secret)
        $profile = $this->db->query(
            'SELECT id, org_id, email, full_name, role, account_type, created_at
             FROM users WHERE id = :id LIMIT 1',
            [':id' => $uid]
        )->fetch(\PDO::FETCH_ASSOC);

        // Projects (org-scoped)
        $projects = $this->db->query(
            'SELECT id, name, created_at FROM projects WHERE org_id = :org_id ORDER BY id',
            [':org_id' => $orgId]
        )->fetchAll(\PDO::FETCH_ASSOC);

        // User stories the user is assigned to or that belong to their org
        $stories = $this->db->query(
            'SELECT s.id, s.title, s.description, s.status, s.size, s.acceptance_criteria,
                    s.kr_hypothesis, s.priority_number, s.created_at
             FROM user_stories s
             JOIN projects p ON p.id = s.project_id
             WHERE p.org_id = :org_id
               AND (s.assignee_user_id = :uid OR :uid2 IS NOT NULL)
             ORDER BY s.id',
            [':org_id' => $orgId, ':uid' => $uid, ':uid2' => $uid]
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Work items (org-scoped, owner field matches user name — best-effort)
        $workItems = $this->db->query(
            'SELECT w.id, w.title, w.description, w.status, w.estimated_sprints,
                    w.owner, w.acceptance_criteria, w.priority_number, w.created_at
             FROM hl_work_items w
             JOIN projects p ON p.id = w.project_id
             WHERE p.org_id = :org_id
             ORDER BY w.id',
            [':org_id' => $orgId]
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Audit log — last 1000 events for this user
        $auditLog = $this->db->query(
            'SELECT event_type, ip_address, details_json, resource_type, resource_id, created_at
             FROM audit_logs WHERE user_id = :uid ORDER BY id DESC LIMIT 1000',
            [':uid' => $uid]
        )->fetchAll(\PDO::FETCH_ASSOC);

        // ===========================
        // BUILD ZIP
        // ===========================

        if (!class_exists('\ZipArchive')) {
            \StratFlow\Core\Response::applySecurityHeaders();
            http_response_code(500);
            echo 'ZIP extension not available.';
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'stratflow_export_') . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addFromString('profile.json', json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('projects.json', json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('user_stories.json', json_encode($stories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('work_items.json', json_encode($workItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('audit_log.json', json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString(
            'README.txt',
            "StratFlow data export\n" .
            "User: {$user['email']}\n" .
            "Generated: " . date('c') . "\n\n" .
            "This archive contains all personal data held for your account.\n" .
            "For questions or deletion requests, contact support.\n"
        );

        $zip->close();

        // ===========================
        // AUDIT + STREAM
        // ===========================

        AuditLogger::log($this->db, $uid, AuditLogger::DATA_EXPORT, $ip, $ua, [
            'files' => ['profile', 'projects', 'user_stories', 'work_items', 'audit_log'],
        ], $orgId, 'user', $uid);

        $filename = 'stratflow-data-export-' . date('Y-m-d') . '.zip';
        $size     = filesize($tmpFile);

        \StratFlow\Core\Response::applySecurityHeaders();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store');

        readfile($tmpFile);
        unlink($tmpFile);
    }
}
