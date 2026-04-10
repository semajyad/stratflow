<?php
/**
 * SuperadminController
 *
 * Handles the ThreePoints superadmin area: cross-organisation management,
 * system default persona panels, and superadmin role assignment.
 *
 * All routes require 'auth' + 'superadmin' middleware (superadmin role only).
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\AuditLog;
use StratFlow\Models\Organisation;
use StratFlow\Models\PersonaMember;
use StratFlow\Models\PersonaPanel;
use StratFlow\Models\Subscription;
use StratFlow\Models\SystemSettings;
use StratFlow\Models\User;
use StratFlow\Services\AuditLogger;

class SuperadminController
{
    protected Request  $request;
    protected Response $response;
    protected Auth     $auth;
    protected Database $db;
    protected array    $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    /**
     * Render the superadmin dashboard with system-wide counts.
     *
     * Shows total organisations, total users, and active subscriptions.
     */
    public function index(): void
    {
        $user = $this->auth->user();

        $orgCount = $this->countAll('organisations');
        $userCount = $this->countAll('users', 'is_active = 1');
        $subCount = $this->countAll('subscriptions', "status = 'active'");

        $this->response->render('superadmin/index', [
            'user'               => $user,
            'org_count'          => $orgCount,
            'user_count'         => $userCount,
            'subscription_count' => $subCount,
            'active_page'        => 'superadmin',
            'flash_message'      => $_SESSION['flash_message'] ?? null,
            'flash_error'        => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    // =========================================================================
    // ORGANISATION MANAGEMENT
    // =========================================================================

    /**
     * List all organisations with status, user count, and subscription info.
     */
    public function organisations(): void
    {
        $user = $this->auth->user();
        $orgs = Organisation::findAll($this->db);

        // Load subscription for each org
        $orgSubs = [];
        foreach ($orgs as $org) {
            $orgSubs[(int) $org['id']] = Subscription::findByOrgId($this->db, (int) $org['id']);
        }

        // Load all users for the assign-superadmin dropdown
        $allUsers = $this->getAllUsers();

        $this->response->render('superadmin/organisations', [
            'user'          => $user,
            'orgs'          => $orgs,
            'org_subs'      => $orgSubs,
            'all_users'     => $allUsers,
            'active_page'   => 'superadmin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create a new organisation with an optional initial subscription.
     */
    public function createOrg(): void
    {
        $name = trim((string) $this->request->post('org_name', ''));
        $planType = trim((string) $this->request->post('plan_type', 'product'));

        if ($name === '') {
            $_SESSION['flash_error'] = 'Organisation name is required.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $orgId = Organisation::create($this->db, [
            'name' => $name,
            'stripe_customer_id' => '',
            'is_active' => 1,
        ]);

        $billingMethod   = $this->request->post('billing_method', 'invoiced') === 'stripe' ? '' : 'manual_' . time();
        $seatLimit       = max(1, (int) $this->request->post('seat_limit', 5));
        $billingPeriod   = in_array((int) $this->request->post('billing_period_months', 1), [1, 3, 6, 12], true)
                           ? (int) $this->request->post('billing_period_months', 1) : 1;
        $pricePerSeat    = max(0, (int) round((float) $this->request->post('price_per_seat', 0) * 100));
        $nextInvoiceDate = $this->request->post('next_invoice_date', '') ?: null;

        if (in_array($planType, ['product', 'consultancy'], true)) {
            \StratFlow\Models\Subscription::create($this->db, [
                'org_id'                 => $orgId,
                'stripe_subscription_id' => $billingMethod,
                'plan_type'              => $planType,
                'status'                 => 'active',
                'started_at'             => date('Y-m-d H:i:s'),
            ]);
            \StratFlow\Models\Subscription::updateSeatLimit($this->db, $orgId, $seatLimit);
            $this->db->query(
                "UPDATE subscriptions
                 SET billing_method = :bmethod, billing_period_months = :bperiod,
                     price_per_seat_cents = :price, next_invoice_date = :next_inv
                 WHERE org_id = :org ORDER BY id DESC LIMIT 1",
                [
                    ':bmethod' => $billingMethod === '' ? 'stripe' : 'invoice',
                    ':bperiod' => $billingPeriod,
                    ':price'   => $pricePerSeat,
                    ':next_inv'=> $nextInvoiceDate,
                    ':org'     => $orgId,
                ]
            );
        }

        $user = $this->auth->user();
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION,
            $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'org_created', 'org_id' => $orgId, 'org_name' => $name]);

        $_SESSION['flash_message'] = "Organisation \"{$name}\" created.";
        $this->response->redirect('/superadmin/organisations');
    }

    /**
     * Toggle Jira integration access for an organisation.
     */
    public function toggleJira($id): void
    {
        $orgId = (int) $id;
        $org = Organisation::findById($this->db, $orgId);
        if (!$org) {
            $_SESSION['flash_error'] = 'Organisation not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        // Get or create integration record
        $integration = \StratFlow\Models\Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        $action = (string) $this->request->post('action', '');

        if ($action === 'enable') {
            if ($integration) {
                \StratFlow\Models\Integration::update($this->db, (int) $integration['id'], ['status' => 'disconnected']);
            } else {
                \StratFlow\Models\Integration::create($this->db, [
                    'org_id' => $orgId,
                    'provider' => 'jira',
                    'display_name' => 'Jira Cloud',
                    'status' => 'disconnected',
                ]);
            }
            $_SESSION['flash_message'] = "Jira integration enabled for \"{$org['name']}\". They can now connect from their admin panel.";
        } elseif ($action === 'disable') {
            if ($integration) {
                // Delete sync mappings and the integration record entirely
                \StratFlow\Models\SyncMapping::deleteByIntegration($this->db, (int) $integration['id']);
                \StratFlow\Models\Integration::delete($this->db, (int) $integration['id']);
            }
            $_SESSION['flash_message'] = "Jira integration disabled for \"{$org['name']}\".";
        }

        $user = $this->auth->user();
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION,
            $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'jira_toggle', 'org_id' => $orgId, 'jira_action' => $action]);

        $this->response->redirect('/superadmin/organisations');
    }

    /**
     * Handle organisation actions: suspend, enable, or delete.
     *
     * @param string $id Organisation primary key (from route param)
     */
    public function updateOrg($id): void
    {
        $orgId  = (int) $id;
        $action = (string) $this->request->post('action', '');

        $org = Organisation::findById($this->db, $orgId);
        if (!$org) {
            $_SESSION['flash_error'] = 'Organisation not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $user = $this->auth->user();
        $ip   = $this->request->ip();
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';

        switch ($action) {
            case 'suspend':
                Organisation::suspend($this->db, $orgId);
                AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION, $ip, $ua, [
                    'action' => 'org_suspended', 'org_id' => $orgId, 'org_name' => $org['name'],
                ]);
                $_SESSION['flash_message'] = 'Organisation "' . $org['name'] . '" suspended.';
                break;

            case 'enable':
                Organisation::enable($this->db, $orgId);
                AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION, $ip, $ua, [
                    'action' => 'org_enabled', 'org_id' => $orgId, 'org_name' => $org['name'],
                ]);
                $_SESSION['flash_message'] = 'Organisation "' . $org['name'] . '" enabled.';
                break;

            case 'delete':
                Organisation::delete($this->db, $orgId);
                AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION, $ip, $ua, [
                    'action' => 'org_deleted', 'org_id' => $orgId, 'org_name' => $org['name'],
                ]);
                $_SESSION['flash_message'] = 'Organisation "' . $org['name'] . '" deleted.';
                break;

            case 'edit':
                $newName       = trim((string) $this->request->post('org_name', ''));
                $newPlanType   = in_array($this->request->post('plan_type'), ['product', 'consultancy'], true)
                                 ? $this->request->post('plan_type') : 'product';
                $newSeats      = max(1, (int) $this->request->post('seat_limit', 5));
                $billingMethod = $this->request->post('billing_method', 'invoiced') === 'stripe' ? 'stripe' : 'invoice';
                $billingPeriod = in_array((int) $this->request->post('billing_period_months', 1), [1, 3, 6, 12], true)
                                 ? (int) $this->request->post('billing_period_months', 1) : 1;
                $pricePerSeat  = max(0, (int) round((float) $this->request->post('price_per_seat', 0) * 100));
                $nextInvoice   = $this->request->post('next_invoice_date', '') ?: null;

                if ($newName !== '') {
                    Organisation::update($this->db, $orgId, ['name' => $newName]);
                }

                // Derive new stripe_subscription_id from billing method:
                // invoice → manual_ prefix; stripe → empty (Stripe connects separately)
                $newSubId = $billingMethod === 'invoice' ? 'manual_' . time() : '';

                $sub = Subscription::findByOrgId($this->db, $orgId);
                if ($sub) {
                    $this->db->query(
                        "UPDATE subscriptions
                         SET plan_type = :plan, user_seat_limit = :seats, stripe_subscription_id = :sub_id,
                             billing_method = :bmethod, billing_period_months = :bperiod,
                             price_per_seat_cents = :price, next_invoice_date = :next_inv
                         WHERE org_id = :org
                         ORDER BY id DESC LIMIT 1",
                        [
                            ':plan'     => $newPlanType,
                            ':seats'    => $newSeats,
                            ':sub_id'   => $newSubId,
                            ':bmethod'  => $billingMethod,
                            ':bperiod'  => $billingPeriod,
                            ':price'    => $pricePerSeat,
                            ':next_inv' => $nextInvoice,
                            ':org'      => $orgId,
                        ]
                    );
                } else {
                    Subscription::create($this->db, [
                        'org_id'                 => $orgId,
                        'stripe_subscription_id' => $newSubId,
                        'plan_type'              => $newPlanType,
                        'status'                 => 'active',
                        'started_at'             => date('Y-m-d H:i:s'),
                    ]);
                    Subscription::updateSeatLimit($this->db, $orgId, $newSeats);
                }

                AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION, $ip, $ua, [
                    'action'    => 'org_edited', 'org_id' => $orgId, 'org_name' => $newName ?: $org['name'],
                    'plan_type' => $newPlanType, 'seats' => $newSeats, 'billing' => $billingMethod,
                ]);
                $_SESSION['flash_message'] = 'Organisation "' . ($newName ?: $org['name']) . '" updated.';
                break;

            case 'update_seats':
                $newLimit = max(1, (int) $this->request->post('seat_limit', 5));
                Subscription::updateSeatLimit($this->db, $orgId, $newLimit);
                AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION, $ip, $ua, [
                    'action' => 'seat_limit_changed', 'org_id' => $orgId, 'org_name' => $org['name'], 'new_limit' => $newLimit,
                ]);
                $_SESSION['flash_message'] = 'Seat limit for "' . $org['name'] . '" updated to ' . $newLimit . '.';
                break;

            case 'rename':
                $newName = trim((string) $this->request->post('org_name', ''));
                if ($newName !== '') {
                    Organisation::update($this->db, $orgId, ['name' => $newName]);
                    AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION, $ip, $ua, [
                        'action' => 'org_renamed', 'org_id' => $orgId, 'old_name' => $org['name'], 'new_name' => $newName,
                    ]);
                    $_SESSION['flash_message'] = 'Organisation renamed to "' . $newName . '".';
                }
                break;

            default:
                $_SESSION['flash_error'] = 'Unknown action.';
        }

        $this->response->redirect('/superadmin/organisations');
    }

    /**
     * Export all organisation data as a JSON download.
     *
     * @param string $id Organisation primary key (from route param)
     */
    public function exportOrg($id): void
    {
        $orgId = (int) $id;
        $data  = Organisation::exportData($this->db, $orgId);

        if (!$data) {
            $_SESSION['flash_error'] = 'Organisation not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $user = $this->auth->user();
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::DATA_EXPORT, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'org_id' => $orgId,
            'type'   => 'org_export',
        ]);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'org_' . $orgId . '_export_' . date('Y-m-d') . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    // =========================================================================
    // DEFAULT PERSONA MANAGEMENT
    // =========================================================================

    /**
     * Display and manage system default persona panels (org_id IS NULL).
     *
     * Seeds the default panels if none exist yet.
     */

    // =========================================================================
    // APP-WIDE DEFAULTS
    // =========================================================================

    /**
     * Render the app-wide defaults configuration page.
     */
    public function defaults(): void
    {
        $user     = $this->auth->user();
        $settings = SystemSettings::get($this->db);

        $maskKey = static function (string $key): string {
            if ($key === '') {
                return '';
            }
            return '•••••••••' . substr($key, -4);
        };

        $apiKeys = [
            'google'    => $maskKey($_ENV['GEMINI_API_KEY']    ?? ''),
            'openai'    => $maskKey($_ENV['OPENAI_API_KEY']    ?? ''),
            'anthropic' => $maskKey($_ENV['ANTHROPIC_API_KEY'] ?? ''),
        ];

        $this->response->render('superadmin/defaults', [
            'user'          => $user,
            'settings'      => $settings,
            'api_keys'      => $apiKeys,
            'active_page'   => 'superadmin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save app-wide defaults.
     */
    public function saveDefaults(): void
    {
        $user = $this->auth->user();

        $data = [
            'ai_provider'            => trim((string) $this->request->post('ai_provider', 'google')),
            'ai_model'               => trim((string) $this->request->post('ai_model', 'gemini-2.5-flash')),
            'default_seat_limit'     => max(1, (int) $this->request->post('default_seat_limit', 5)),
            'default_plan_type'      => $this->request->post('default_plan_type', 'product'),
            'default_billing_method' => $this->request->post('default_billing_method', 'invoiced'),
            'feature_sounding_board' => (bool) $this->request->post('feature_sounding_board', false),
            'feature_executive'      => (bool) $this->request->post('feature_executive', false),
            'feature_xero'           => (bool) $this->request->post('feature_xero', false),
            'feature_jira'           => (bool) $this->request->post('feature_jira', false),
            'feature_github'         => (bool) $this->request->post('feature_github', false),
            'feature_gitlab'         => (bool) $this->request->post('feature_gitlab', false),
            'feature_story_quality'  => (bool) $this->request->post('feature_story_quality', false),
            'quality_threshold'      => min(100, max(0, (int) $this->request->post('quality_threshold', 70))),
            'quality_enforcement'    => $this->request->post('quality_enforcement', 'warn'),
            'support_email'          => trim((string) $this->request->post('support_email', '')),
            'mail_from_name'         => trim((string) $this->request->post('mail_from_name', 'StratFlow')),
            // Billing rates — convert dollar input to cents
            'billing_currency'             => strtoupper(trim((string) $this->request->post('billing_currency', 'NZD'))),
            'billing_rate_monthly_cents'   => max(0, (int) round((float) $this->request->post('billing_rate_monthly', 0) * 100)),
            'billing_rate_quarterly_cents' => max(0, (int) round((float) $this->request->post('billing_rate_quarterly', 0) * 100)),
            'billing_rate_6monthly_cents'  => max(0, (int) round((float) $this->request->post('billing_rate_6monthly', 0) * 100)),
            'billing_rate_annual_cents'    => max(0, (int) round((float) $this->request->post('billing_rate_annual', 0) * 100)),
        ];

        SystemSettings::save($this->db, $data);

        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::ADMIN_ACTION,
            $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'system_defaults_updated']);

        $_SESSION['flash_message'] = 'App-wide defaults saved.';
        $this->response->redirect('/superadmin/defaults');
    }

    public function personas(): void
    {
        $user   = $this->auth->user();
        $panels = PersonaPanel::findDefaults($this->db);

        // Seed defaults if none exist
        if (empty($panels)) {
            $this->seedDefaultPersonas();
            $panels = PersonaPanel::findDefaults($this->db);
        }

        // Load members for each panel
        $panelMembers = [];
        foreach ($panels as $panel) {
            $panelMembers[(int) $panel['id']] = PersonaMember::findByPanelId($this->db, (int) $panel['id']);
        }

        $this->response->render('superadmin/personas', [
            'user'          => $user,
            'panels'        => $panels,
            'panel_members' => $panelMembers,
            'active_page'   => 'superadmin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save updated prompt descriptions for default persona members.
     *
     * Expects POST data keyed as member_{id} with new prompt_description values.
     */
    public function savePersona(): void
    {
        $post = $_POST;

        foreach ($post as $key => $value) {
            if (strpos($key, 'member_') === 0) {
                $memberId = (int) str_replace('member_', '', $key);
                if ($memberId > 0) {
                    PersonaMember::update($this->db, $memberId, [
                        'prompt_description' => trim((string) $value),
                    ]);
                }
            }
        }

        $_SESSION['flash_message'] = 'Default personas updated successfully.';
        $this->response->redirect('/superadmin/personas');
    }

    // =========================================================================
    // SUPERADMIN ROLE ASSIGNMENT
    // =========================================================================

    /**
     * Assign the superadmin role to a user.
     *
     * Expects user_id in POST data.
     */
    public function assignSuperadmin(): void
    {
        $userId = (int) $this->request->post('user_id', '0');

        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'Please select a user.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $target = User::findById($this->db, $userId);
        if (!$target) {
            $_SESSION['flash_error'] = 'User not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $oldRole = $target['role'];
        User::update($this->db, $userId, ['role' => 'superadmin']);

        $user = $this->auth->user();
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::USER_ROLE_CHANGED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'target_user_id' => $userId,
            'old_role'       => $oldRole,
            'new_role'       => 'superadmin',
        ]);

        $_SESSION['flash_message'] = 'User "' . $target['full_name'] . '" is now a superadmin.';
        $this->response->redirect('/superadmin/organisations');
    }

    // =========================================================================
    // AUDIT LOGS
    // =========================================================================

    /**
     * Display the audit log viewer with filtering by event type.
     *
     * GET /superadmin/audit-logs — shows recent security events for
     * HIPAA, SOC 2, and PCI-DSS compliance review.
     */
    public function auditLogs(): void
    {
        $user       = $this->auth->user();
        $filterType = $this->request->get('type', '');
        $filterType = is_string($filterType) && $filterType !== '' ? $filterType : null;

        if ($filterType !== null) {
            $logs = AuditLog::findByEventType($this->db, $filterType, 200);
        } else {
            $logs = AuditLog::findRecent($this->db, 200);
        }

        // Build distinct event types for the filter dropdown
        $eventTypes = [
            AuditLogger::LOGIN_SUCCESS,
            AuditLogger::LOGIN_FAILURE,
            AuditLogger::LOGOUT,
            AuditLogger::PASSWORD_CHANGE,
            AuditLogger::PASSWORD_RESET_REQUEST,
            AuditLogger::USER_CREATED,
            AuditLogger::USER_DELETED,
            AuditLogger::USER_ROLE_CHANGED,
            AuditLogger::DATA_EXPORT,
            AuditLogger::ADMIN_ACTION,
            AuditLogger::SETTINGS_CHANGED,
            AuditLogger::PROJECT_CREATED,
            AuditLogger::DOCUMENT_UPLOADED,
        ];

        $this->response->render('superadmin/audit-logs', [
            'user'        => $user,
            'logs'        => $logs,
            'event_types' => $eventTypes,
            'filter_type' => $filterType,
            'active_page' => 'superadmin',
        ], 'app');
    }

    /**
     * Export audit logs as CSV.
     */
    public function exportAuditLogs(): void
    {
        $user       = $this->auth->user();
        $filterType = $this->request->get('type', '') ?: null;
        $dateFrom   = $this->request->get('from', '') ?: null;
        $dateTo     = $this->request->get('to', '') ?: null;

        $logs = AuditLog::findFiltered($this->db, null, $filterType, $dateFrom, $dateTo);

        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::DATA_EXPORT,
            $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['type' => 'audit_logs', 'count' => count($logs)]
        );

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['Timestamp', 'Event', 'User', 'Email', 'IP Address', 'Details']);
        foreach ($logs as $log) {
            fputcsv($csv, [
                $log['created_at'],
                $log['event_type'],
                $log['full_name'] ?? 'System',
                $log['email'] ?? '',
                $log['ip_address'],
                $log['details_json'] ?? '',
            ]);
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        $this->response->download($content, 'audit-logs-' . date('Y-m-d') . '.csv', 'text/csv');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Count all rows in a table, optionally with a WHERE clause.
     *
     * @param string      $table Table name
     * @param string|null $where Optional WHERE clause (without the WHERE keyword)
     * @return int               Row count
     */
    private function countAll(string $table, ?string $where = null): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$table}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        $stmt = $this->db->query($sql);
        $row  = $stmt->fetch();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Fetch all active users across all organisations.
     *
     * @return array Array of user rows
     */
    private function getAllUsers(): array
    {
        $stmt = $this->db->query(
            "SELECT u.*, o.name AS org_name
             FROM users u
             LEFT JOIN organisations o ON o.id = u.org_id
             WHERE u.is_active = 1
             ORDER BY u.full_name ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Seed the default system persona panels and their members.
     *
     * Creates Executive and Product Management panels with predefined
     * role titles and prompt descriptions.
     */
    private function seedDefaultPersonas(): void
    {
        // Executive Panel
        $execId = PersonaPanel::create($this->db, [
            'panel_type' => 'executive',
            'name'       => 'Executive Panel',
        ]);

        $execMembers = [
            ['CEO',                           'You are a CEO focused on strategic vision, market positioning, and ROI'],
            ['CFO',                           'You are a CFO focused on financial viability, cost control, and risk management'],
            ['COO',                           'You are a COO focused on operational efficiency, resource allocation, and execution'],
            ['CMO',                           'You are a CMO focused on market fit, customer value, and competitive positioning'],
            ['Enterprise Business Strategist', 'You are a Business Strategist focused on long-term growth and competitive advantage'],
        ];

        foreach ($execMembers as [$role, $prompt]) {
            PersonaMember::create($this->db, [
                'panel_id'           => $execId,
                'role_title'         => $role,
                'prompt_description' => $prompt,
            ]);
        }

        // Product Management Panel
        $pmId = PersonaPanel::create($this->db, [
            'panel_type' => 'product_management',
            'name'       => 'Product Management Panel',
        ]);

        $pmMembers = [
            ['Agile Product Manager',  'You are a PM focused on backlog prioritisation, stakeholder alignment, and delivery velocity'],
            ['Product Owner',          'You are a PO focused on user value, acceptance criteria, and sprint goals'],
            ['Expert System Architect', 'You are an Architect focused on technical feasibility, scalability, and system design'],
            ['Senior Developer',       'You are a Senior Dev focused on implementation complexity, technical debt, and code quality'],
        ];

        foreach ($pmMembers as [$role, $prompt]) {
            PersonaMember::create($this->db, [
                'panel_id'           => $pmId,
                'role_title'         => $role,
                'prompt_description' => $prompt,
            ]);
        }
    }
}
