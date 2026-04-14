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

    /** Default workflow persona definitions with pipeline stage and prompt. */
    private const DEFAULT_WORKFLOW_PERSONAS = [
        'agile_product_manager' => [
            'title'    => 'Agile Product Manager',
            'stage'    => 'Translate Mermaid strategy diagram and OKRs to the prioritised list of high-level work items',
            'prompt'   => 'You are an Agile Product Manager. Your role is to translate strategic goals and OKRs into a prioritised backlog of high-level work items that deliver measurable business value.',
        ],
        'technical_project_manager' => [
            'title'    => 'Technical Project Manager',
            'stage'    => 'Generate a description of high-level work items',
            'prompt'   => 'You are a Technical Project Manager. Your role is to create clear, actionable descriptions for high-level work items that capture scope, dependencies, and technical requirements.',
        ],
        'expert_system_architect' => [
            'title'    => 'Expert System Architect',
            'stage'    => 'Convert summary (from text) or analyse video (from file) into structured output',
            'prompt'   => 'You are an Expert System Architect. Your role is to analyse uploaded content and produce structured technical summaries that capture architecture, components, and integration points.',
        ],
        'enterprise_risk_manager' => [
            'title'    => 'Enterprise Risk Manager',
            'stage'    => 'Generate risks, read the risk, and write the mitigation strategy',
            'prompt'   => 'You are an Enterprise Risk Manager. Your role is to identify strategic and operational risks, assess their likelihood and impact, and recommend specific mitigation strategies.',
        ],
        'experienced_agile_product_owner' => [
            'title'    => 'Experienced Agile Product Owner',
            'stage'    => 'Decompose High Level Items into user stories',
            'prompt'   => 'You are an Experienced Agile Product Owner. Your role is to decompose high-level work items into well-defined user stories that follow INVEST criteria with clear acceptance criteria.',
        ],
        'enterprise_business_strategist' => [
            'title'    => 'Enterprise Business Strategist',
            'stage'    => 'Analyse the provided file to a 3-paragraph summary',
            'prompt'   => 'You are an Enterprise Business Strategist. Your role is to analyse strategic documents and produce concise executive summaries that highlight key themes, opportunities, and recommended actions.',
        ],
    ];
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
     * List all users across all organisations.
     */
    public function users(): void
    {
        $user = $this->auth->user();
        $stmt = $this->db->query("SELECT u.*, o.name AS org_name
             FROM users u
             LEFT JOIN organisations o ON o.id = u.org_id
             ORDER BY u.created_at DESC");
        $allUsers = $stmt->fetchAll();
        $this->response->render('superadmin/users', [
            'user'        => $user,
            'all_users'   => $allUsers,
            'active_page' => 'superadmin',
        ], 'app');
    }

    /**
     * List all subscriptions across all organisations.
     */
    public function subscriptions(): void
    {
        $user = $this->auth->user();
        $stmt = $this->db->query("SELECT s.*, o.name AS org_name
             FROM subscriptions s
             LEFT JOIN organisations o ON o.id = s.org_id
             ORDER BY s.id DESC");
        $allSubscriptions = $stmt->fetchAll();
        $this->response->render('superadmin/subscriptions', [
            'user'              => $user,
            'all_subscriptions' => $allSubscriptions,
            'active_page'       => 'superadmin',
        ], 'app');
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
        $billingPeriod   = (int) $this->request->post('billing_period_months', 1) === 12 ? 12 : 1;
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
            $this->db->query("UPDATE subscriptions
                 SET billing_method = :bmethod, billing_period_months = :bperiod,
                     price_per_seat_cents = :price, next_invoice_date = :next_inv
                 WHERE org_id = :org ORDER BY id DESC LIMIT 1", [
                    ':bmethod' => $billingMethod === '' ? 'stripe' : 'invoice',
                    ':bperiod' => $billingPeriod,
                    ':price'   => $pricePerSeat,
                    ':next_inv' => $nextInvoiceDate,
                    ':org'     => $orgId,
                ]);
        }

        $user = $this->auth->user();
        AuditLogger::log(
            $this->db,
            (int) $user['id'],
            AuditLogger::ADMIN_ACTION,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'org_created', 'org_id' => $orgId, 'org_name' => $name]
        );
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
        AuditLogger::log(
            $this->db,
            (int) $user['id'],
            AuditLogger::ADMIN_ACTION,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'jira_toggle', 'org_id' => $orgId, 'jira_action' => $action]
        );
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
                $billingPeriod = (int) $this->request->post('billing_period_months', 1) === 12 ? 12 : 1;
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
                    $this->db->query("UPDATE subscriptions
                         SET plan_type = :plan, user_seat_limit = :seats, stripe_subscription_id = :sub_id,
                             billing_method = :bmethod, billing_period_months = :bperiod,
                             price_per_seat_cents = :price, next_invoice_date = :next_inv
                         WHERE org_id = :org
                         ORDER BY id DESC LIMIT 1", [
                    ':plan'     => $newPlanType,
                    ':seats'    => $newSeats,
                    ':sub_id'   => $newSubId,
                    ':bmethod'  => $billingMethod,
                    ':bperiod'  => $billingPeriod,
                    ':price'    => $pricePerSeat,
                    ':next_inv' => $nextInvoice,
                    ':org'      => $orgId,
                    ]);
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
            'ai_model'               => trim((string) $this->request->post('ai_model', 'gemini-3-flash-preview')),
            'default_seat_limit'            => max(1, (int) $this->request->post('default_seat_limit', 5)),
            'default_price_per_seat_cents'  => max(0, (int) round((float) $this->request->post('default_price_per_seat', 0) * 100)),
            'default_plan_type'             => $this->request->post('default_plan_type', 'product'),
            'default_billing_method'        => $this->request->post('default_billing_method', 'invoiced'),
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
            // Billing rates — convert dollar input to cents (Monthly + Annual only)
            'billing_currency'             => strtoupper(trim((string) $this->request->post('billing_currency', 'NZD'))),
            'billing_rate_monthly_cents'   => max(0, (int) round((float) $this->request->post('billing_rate_monthly', 0) * 100)),
            'billing_rate_annual_cents'    => max(0, (int) round((float) $this->request->post('billing_rate_annual', 0) * 100)),
        ];
        SystemSettings::save($this->db, $data);

        AuditLogger::log(
            $this->db,
            (int) $user['id'],
            AuditLogger::ADMIN_ACTION,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'system_defaults_updated']
        );
        $_SESSION['flash_message'] = 'App-wide defaults saved.';
        $this->response->redirect('/superadmin/defaults');
    }

    /**
     * Test the configured AI provider/model with a minimal prompt.
     *
     * Returns JSON: {success, provider, model, latency_ms, snippet?, error?}
     */
    public function testAiConnection(): void
    {
        $provider = trim((string) $this->request->post('provider', 'google'));
        $model    = trim((string) $this->request->post('model', ''));
        if ($model === '') {
            $this->response->json(['success' => false, 'error' => 'Model identifier is required.']);
            return;
        }

        $start = microtime(true);
        try {
            $snippet = match ($provider) {
                'google'    => $this->testGemini($model),
                'openai'    => $this->testOpenAi($model),
                'anthropic' => $this->testAnthropic($model),
                default     => throw new \RuntimeException("Unknown provider: {$provider}"),
            };
            $this->response->json([
                'success'     => true,
                'provider'    => $provider,
                'model'       => $model,
                'latency_ms'  => (int) round((microtime(true) - $start) * 1000),
                'snippet'     => mb_substr($snippet, 0, 120),
            ]);
        } catch (\Throwable $e) {
            $this->response->json([
                'success'    => false,
                'provider'   => $provider,
                'model'      => $model,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function testGemini(string $model): string
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $body = json_encode([
            'contents' => [['parts' => [['text' => 'Reply with just the word: OK']]]],
            'generationConfig' => ['maxOutputTokens' => 10],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('cURL error: ' . $err);
        }
        $data = json_decode($raw, true) ?? [];
        if ($code !== 200) {
            throw new \RuntimeException($data['error']['message'] ?? "HTTP {$code}");
        }
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '(no text)';
    }

    private function testOpenAi(string $model): string
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not set.');
        }

        $body = json_encode([
            'model'       => $model,
            'messages'    => [['role' => 'user', 'content' => 'Reply with just the word: OK']],
            'max_tokens'  => 10,
        ]);
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('cURL error: ' . $err);
        }
        $data = json_decode($raw, true) ?? [];
        if ($code !== 200) {
            throw new \RuntimeException($data['error']['message'] ?? "HTTP {$code}");
        }
        return $data['choices'][0]['message']['content'] ?? '(no text)';
    }

    private function testAnthropic(string $model): string
    {
        $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        $body = json_encode([
            'model'      => $model,
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Reply with just the word: OK']],
        ]);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('cURL error: ' . $err);
        }
        $data = json_decode($raw, true) ?? [];
        if ($code !== 200) {
            throw new \RuntimeException($data['error']['message'] ?? "HTTP {$code}");
        }
        return $data['content'][0]['text'] ?? '(no text)';
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

        // Load workflow personas from system settings
        $settings = SystemSettings::get($this->db);
        $workflowPersonas = self::DEFAULT_WORKFLOW_PERSONAS;
        if (!empty($settings['workflow_personas_json'])) {
            $stored = json_decode($settings['workflow_personas_json'], true);
            if (is_array($stored) && !empty($stored)) {
                $workflowPersonas = $stored;
            }
        }

        // Load evaluation levels (critical review prompts)
        $defaultLevels = \StratFlow\Services\Prompts\PersonaPrompt::EVALUATION_LEVELS;
        $evaluationLevels = $defaultLevels;
        if (!empty($settings['evaluation_levels_json'])) {
            $storedLevels = json_decode($settings['evaluation_levels_json'], true);
            if (is_array($storedLevels) && !empty($storedLevels)) {
                $evaluationLevels = array_merge($defaultLevels, $storedLevels);
            }
        }

        $this->response->render('superadmin/personas', [
            'user'               => $user,
            'panels'             => $panels,
            'panel_members'      => $panelMembers,
            'workflow_personas'  => $workflowPersonas,
            'evaluation_levels'  => $evaluationLevels,
            'active_page'        => 'superadmin',
            'flash_message'      => $_SESSION['flash_message'] ?? null,
            'flash_error'        => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save updated prompt descriptions for default persona members
     * and workflow persona prompts.
     *
     * Expects POST data keyed as member_{id} for sounding panel members
     * and workflow_{key} for workflow persona prompts.
     */
    public function savePersona(): void
    {
        $post = $_POST;
// Save sounding panel member prompts
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

        // Save workflow persona prompts to system_settings
        $workflowPersonas = self::DEFAULT_WORKFLOW_PERSONAS;
        $settings = SystemSettings::get($this->db);
        if (!empty($settings['workflow_personas_json'])) {
            $stored = json_decode($settings['workflow_personas_json'], true);
            if (is_array($stored) && !empty($stored)) {
                $workflowPersonas = $stored;
            }
        }

        $workflowUpdated = false;
        foreach ($post as $key => $value) {
            if (strpos($key, 'workflow_') === 0) {
                $wfKey = str_replace('workflow_', '', $key);
                if (isset($workflowPersonas[$wfKey])) {
                    $workflowPersonas[$wfKey]['prompt'] = trim((string) $value);
                    $workflowUpdated = true;
                }
            }
        }

        if ($workflowUpdated) {
            SystemSettings::save($this->db, [
                'workflow_personas_json' => json_encode($workflowPersonas),
            ]);
        }

        // Save evaluation level prompts to system_settings
        $defaultLevels = \StratFlow\Services\Prompts\PersonaPrompt::EVALUATION_LEVELS;
        $evaluationLevels = $defaultLevels;
        if (!empty($settings['evaluation_levels_json'])) {
            $storedLevels = json_decode($settings['evaluation_levels_json'], true);
            if (is_array($storedLevels) && !empty($storedLevels)) {
                $evaluationLevels = array_merge($defaultLevels, $storedLevels);
            }
        }

        $levelsUpdated = false;
        foreach ($post as $key => $value) {
            if (strpos($key, 'level_') === 0) {
                $lvlKey = str_replace('level_', '', $key);
                if (isset($evaluationLevels[$lvlKey])) {
                    $evaluationLevels[$lvlKey] = trim((string) $value);
                    $levelsUpdated = true;
                }
            }
        }

        if ($levelsUpdated) {
            SystemSettings::save($this->db, [
                'evaluation_levels_json' => json_encode($evaluationLevels),
            ]);
        }

        $_SESSION['flash_message'] = 'Default personas and levels updated successfully.';
        $this->response->redirect('/superadmin/personas');
    }

    /**
     * Run a critical review evaluation for a sounding panel (superadmin preview).
     *
     * Expects JSON body: panel_id, evaluation_level, content.
     * Returns JSON with per-persona response results.
     */
    public function evaluatePersona(): void
    {
        $body = json_decode($this->request->body(), true);
        if (!$body) {
            $this->response->json(['status' => 'error', 'message' => 'Invalid JSON body'], 400);
            return;
        }

        $panelId         = (int) ($body['panel_id'] ?? 0);
        $evaluationLevel = $body['evaluation_level'] ?? 'devils_advocate';
        $content         = $body['content'] ?? '';
        if (empty($content)) {
            $this->response->json(['status' => 'error', 'message' => 'No content provided'], 400);
            return;
        }

        // Validate evaluation level
        $validLevels = ['devils_advocate', 'red_teaming', 'gordon_ramsay'];
        if (!in_array($evaluationLevel, $validLevels, true)) {
            $evaluationLevel = 'devils_advocate';
        }

        // Load panel members
        $members = PersonaMember::findByPanelId($this->db, $panelId);
        if (empty($members)) {
            $this->response->json(['status' => 'error', 'message' => 'No members found for this panel'], 404);
            return;
        }

        // Load custom levels if any
        $settings = SystemSettings::get($this->db);
        $customLevels = null;
        if (!empty($settings['evaluation_levels_json'])) {
            $customLevels = json_decode($settings['evaluation_levels_json'], true);
        }

        $gemini  = new \StratFlow\Services\GeminiService($this->config);
        $results = [];
        foreach ($members as $member) {
            $prompt = \StratFlow\Services\Prompts\PersonaPrompt::buildPrompt($member['role_title'], $member['prompt_description'] ?? '', $evaluationLevel, $content, $customLevels);
            try {
                $response = $gemini->generate($prompt, '');
                $results[] = [
                    'role_title' => $member['role_title'],
                    'response'   => $response,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                'role_title' => $member['role_title'],
                'response'   => 'Error: ' . $e->getMessage(),
                ];
            }
        }

        $this->response->json(['status' => 'ok', 'results' => $results]);
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

        AuditLogger::log(
            $this->db,
            (int) $user['id'],
            AuditLogger::DATA_EXPORT,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
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
        $stmt = $this->db->query("SELECT u.*, o.name AS org_name
             FROM users u
             LEFT JOIN organisations o ON o.id = u.org_id
             WHERE u.is_active = 1
             ORDER BY u.full_name ASC");
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
        // Ensure review_scope column exists (inline migration for Railway)
        try {
            $this->db->query("ALTER TABLE persona_panels ADD COLUMN review_scope VARCHAR(50) DEFAULT NULL AFTER name");
        } catch (\Throwable) {
        // Column already exists — ignore
        }

        // Executive Panel
        $execId = PersonaPanel::create($this->db, [
            'panel_type'   => 'executive',
            'name'         => 'Executive Panel',
            'review_scope' => 'strategy_okrs',
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
            'panel_type'   => 'product_management',
            'name'         => 'Product Management Panel',
            'review_scope' => 'hl_items_stories',
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

        // Seed workflow persona defaults into system_settings
        $settings = SystemSettings::get($this->db);
        if (empty($settings['workflow_personas_json'])) {
            SystemSettings::save($this->db, [
                'workflow_personas_json' => json_encode(self::DEFAULT_WORKFLOW_PERSONAS),
            ]);
        }
    }
}
