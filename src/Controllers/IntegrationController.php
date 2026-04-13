<?php
/**
 * IntegrationController
 *
 * Handles the Jira Cloud (and future Azure DevOps) integration lifecycle:
 * OAuth connect/disconnect, configuration, bidirectional sync (push/pull),
 * webhook ingestion, and sync log viewing.
 *
 * All routes require 'auth' + 'admin' middleware except the webhook endpoint.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Integration;
use StratFlow\Models\IntegrationRepo;
use StratFlow\Models\SyncLog;
use StratFlow\Models\SyncMapping;
use StratFlow\Models\SystemSettings;
use StratFlow\Services\AuditLogger;
use StratFlow\Services\JiraService;
use StratFlow\Services\JiraSyncService;

class IntegrationController
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
    // INTEGRATION HUB
    // =========================================================================

    /**
     * Render the Integration Hub page.
     *
     * Lists all configured integrations for the organisation with
     * status badges, sync counts, and action buttons.
     */
    public function index(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integrations = Integration::findByOrg($this->db, $orgId);

        // Index by provider for easy template access
        $byProvider = [];
        foreach ($integrations as $integ) {
            $byProvider[$integ['provider']] = $integ;
        }

        // Build sync health stats for Jira
        $jiraSyncCount = 0;
        $syncHealth = ['epics' => 0, 'stories' => 0, 'risks' => 0, 'sprints' => 0, 'total' => 0, 'recent_errors' => 0];
        if (isset($byProvider['jira'])) {
            $integId = (int) $byProvider['jira']['id'];
            $mappings = SyncMapping::findByIntegration($this->db, $integId);
            $jiraSyncCount = count($mappings);

            foreach ($mappings as $m) {
                match ($m['local_type']) {
                    'hl_work_item' => $syncHealth['epics']++,
                    'user_story'   => $syncHealth['stories']++,
                    'risk'         => $syncHealth['risks']++,
                    'sprint'       => $syncHealth['sprints']++,
                    default        => null,
                };
            }
            $syncHealth['total'] = $jiraSyncCount;

            // Count recent errors (last 24h)
            $recentLogs = SyncLog::findByIntegration($this->db, $integId, 50);
            foreach ($recentLogs as $log) {
                if ($log['status'] === 'error' && strtotime($log['created_at']) > time() - 86400) {
                    $syncHealth['recent_errors']++;
                }
            }
        }

        // GitHub App installations (multiple per org are allowed)
        $githubInstalls = Integration::findActiveGithubByOrg($this->db, $orgId);

        // Attach repo names to each install for the hover tooltip
        foreach ($githubInstalls as &$install) {
            $repos = IntegrationRepo::findByIntegration($this->db, (int) $install['id']);
            $install['repo_names'] = array_column($repos, 'repo_full_name');
        }
        unset($install);

        $githubAppSlug = $_ENV['GITHUB_APP_SLUG'] ?? '';

        $this->response->render('admin/integrations', [
            'user'             => $user,
            'integrations'     => $byProvider,
            'jira_sync_count'  => $jiraSyncCount,
            'sync_health'      => $syncHealth,
            'github_installs'  => $githubInstalls,
            'github_app_slug'  => $githubAppSlug,
            'settings'         => SystemSettings::get($this->db),
            'active_page'      => 'integrations',
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    // =========================================================================
    // JIRA OAUTH CONNECT
    // =========================================================================

    /**
     * Initiate Jira OAuth flow.
     *
     * Generates a CSRF state token, stores it in session, and redirects
     * the user to Atlassian's authorization page.
     */
    public function jiraConnect(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['jira_oauth_state'] = $state;

        $jira = new JiraService($this->config['jira'] ?? []);
        $url = $jira->getAuthorizationUrl($state);

        $this->response->redirect($url);
    }

    /**
     * Handle Jira OAuth callback.
     *
     * Verifies state, exchanges code for tokens, fetches accessible
     * resources, and creates/updates the Integration record.
     */
    public function jiraCallback(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        // Verify state
        $state = $_GET['state'] ?? '';
        $expectedState = $_SESSION['jira_oauth_state'] ?? '';
        unset($_SESSION['jira_oauth_state']);

        if ($state === '' || $state !== $expectedState) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try connecting again.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $code = $_GET['code'] ?? '';
        if ($code === '') {
            $_SESSION['flash_error'] = 'No authorization code received from Jira.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        try {
            $jira = new JiraService($this->config['jira'] ?? []);

            // Exchange code for tokens
            $tokens = $jira->exchangeCode($code);
            $accessToken  = $tokens['access_token'];
            $refreshToken = $tokens['refresh_token'] ?? '';
            $expiresIn    = $tokens['expires_in'] ?? 3600;
            $expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

            // Get accessible resources (cloud sites)
            $resources = $jira->getAccessibleResources($accessToken);

            if (empty($resources)) {
                $_SESSION['flash_error'] = 'No Jira sites found for this account.';
                $this->response->redirect('/app/admin/integrations');
                return;
            }

            // Use the first accessible resource
            $resource = $resources[0];
            $cloudId  = $resource['id'];
            $siteUrl  = $resource['url'] ?? '';
            $siteName = $resource['name'] ?? 'Jira Cloud';

            // Create or update integration record
            $existing = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

            if ($existing) {
                Integration::update($this->db, (int) $existing['id'], [
                    'display_name'     => $siteName,
                    'cloud_id'         => $cloudId,
                    'access_token'     => $accessToken,
                    'refresh_token'    => $refreshToken,
                    'token_expires_at' => $expiresAt,
                    'site_url'         => $siteUrl,
                    'status'           => 'active',
                    'error_message'    => null,
                    'error_count'      => 0,
                ]);
            } else {
                Integration::create($this->db, [
                    'org_id'           => $orgId,
                    'provider'         => 'jira',
                    'display_name'     => $siteName,
                    'cloud_id'         => $cloudId,
                    'access_token'     => $accessToken,
                    'refresh_token'    => $refreshToken,
                    'token_expires_at' => $expiresAt,
                    'site_url'         => $siteUrl,
                    'status'           => 'active',
                ]);
            }

            AuditLogger::log(
                $this->db,
                (int) $user['id'],
                AuditLogger::INTEGRATION_CONNECTED,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                ['provider' => 'jira', 'site' => $siteName]
            );

            $_SESSION['flash_message'] = 'Jira Cloud connected successfully to ' . $siteName . '.';
            $this->response->redirect('/app/admin/integrations/jira/configure');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to connect Jira: ' . $e->getMessage();
            $this->response->redirect('/app/admin/integrations');
        }
    }

    // =========================================================================
    // JIRA CONFIGURATION
    // =========================================================================

    /**
     * Render the Jira configuration page.
     *
     * Shows project selection dropdown populated from the Jira API.
     */
    public function jiraConfigure(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration || $integration['status'] === 'disconnected') {
            $_SESSION['flash_error'] = 'Jira is not connected. Please connect first.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $projects = [];
        $error = null;

        $jiraFields   = [];
        $jiraIssueTypes = [];
        $jiraBoards   = [];

        try {
            $jira = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $projects = $jira->getProjects();

            // Load fields and issue types for mapping configuration
            try {
                $jiraFields = $jira->getFields();
                // Filter to custom fields + key standard fields
                $jiraFields = array_filter($jiraFields, function ($f) {
                    return ($f['custom'] ?? false)
                        || in_array($f['id'], ['summary', 'description', 'priority', 'labels', 'assignee', 'duedate']);
                });
                usort($jiraFields, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            } catch (\Throwable $e) { /* non-critical */ }

            // Load issue types for the selected project
            $currentConfig = json_decode($integration['config_json'] ?? '{}', true) ?: [];
            $selectedProject = $currentConfig['project_key'] ?? '';
            if ($selectedProject) {
                try {
                    $jiraIssueTypes = $jira->getIssueTypes($selectedProject);
                } catch (\Throwable $e) { /* non-critical */ }
                try {
                    $boardsResult = $jira->getBoards($selectedProject);
                    $jiraBoards = $boardsResult['values'] ?? [];
                } catch (\Throwable $e) { /* non-critical */ }
            }
        } catch (\Throwable $e) {
            $error = 'Could not load Jira projects: ' . $e->getMessage();
        }

        $currentConfig = json_decode($integration['config_json'] ?? '{}', true) ?: [];

        $this->response->render('admin/jira-configure', [
            'user'             => $user,
            'integration'      => $integration,
            'jira_projects'    => $projects,
            'jira_fields'      => $jiraFields,
            'jira_issue_types' => $jiraIssueTypes,
            'jira_boards'      => $jiraBoards,
            'current_config'   => $currentConfig,
            'error'            => $error,
            'active_page'      => 'integrations',
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save Jira configuration (selected project, board).
     */
    public function jiraSaveConfigure(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration) {
            $_SESSION['flash_error'] = 'Jira integration not found.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $projectKey = trim((string) $this->request->post('jira_project_key', ''));

        if ($projectKey === '') {
            $_SESSION['flash_error'] = 'Please select a Jira project.';
            $this->response->redirect('/app/admin/integrations/jira/configure');
            return;
        }

        $currentConfig = json_decode($integration['config_json'] ?? '{}', true) ?: [];
        $currentConfig['project_key'] = $projectKey;

        // Save field mappings
        $currentConfig['field_mapping'] = [
            'epic_type'          => trim((string) $this->request->post('epic_type', 'Epic')),
            'story_type'         => trim((string) $this->request->post('story_type', 'Story')),
            'risk_type'          => trim((string) $this->request->post('risk_type', 'Risk')),
            'epic_name_field'    => trim((string) $this->request->post('epic_name_field', 'customfield_10011')),
            'story_points_field' => trim((string) $this->request->post('story_points_field', 'customfield_10016')),
            'team_field'         => trim((string) $this->request->post('team_field', 'customfield_10001')),
            'board_id'           => (int) $this->request->post('board_id', 0),
            'priority_ranges'    => [
                'highest' => (int) $this->request->post('priority_highest', 2),
                'high'    => (int) $this->request->post('priority_high', 4),
                'medium'  => (int) $this->request->post('priority_medium', 6),
                'low'     => (int) $this->request->post('priority_low', 8),
            ],
        ];

        // Save additional custom field mappings
        $rawMappings = $this->request->post('custom_mappings', []);
        $validFields = ['title', 'description', 'owner', 'status', 'priority_number',
                        'estimated_sprints', 'strategic_context', 'size', 'blocked_by'];
        $validDirections = ['push', 'pull', 'both'];
        $customMappings = [];

        if (is_array($rawMappings)) {
            foreach ($rawMappings as $raw) {
                $sf   = trim((string) ($raw['stratflow_field'] ?? ''));
                $jf   = trim((string) ($raw['jira_field'] ?? ''));
                $dir  = trim((string) ($raw['direction'] ?? 'both'));

                if ($sf !== '' && $jf !== '' && in_array($sf, $validFields, true) && in_array($dir, $validDirections, true)) {
                    $customMappings[] = [
                        'stratflow_field' => $sf,
                        'jira_field'      => $jf,
                        'direction'       => $dir,
                    ];
                }
            }
        }

        $currentConfig['field_mapping']['custom_mappings'] = $customMappings;

        Integration::update($this->db, (int) $integration['id'], [
            'config_json' => json_encode($currentConfig),
        ]);

        // Attempt to register webhook
        try {
            $jira = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $appUrl = rtrim($this->config['app']['url'] ?? '', '/');
            $webhookUrl = $appUrl . '/webhook/integration/jira';

            $webhookResult = $jira->registerWebhook(
                'project = ' . $projectKey . ' AND labels = stratflow',
                ['jira:issue_updated', 'jira:issue_deleted'],
                $webhookUrl
            );

            // Store webhook IDs for refresh
            $webhookIds = [];
            foreach ($webhookResult['webhookRegistrationResult'] ?? [] as $reg) {
                if (!empty($reg['createdWebhookId'])) {
                    $webhookIds[] = $reg['createdWebhookId'];
                }
            }

            if (!empty($webhookIds)) {
                $currentConfig['webhook_ids'] = $webhookIds;
                Integration::update($this->db, (int) $integration['id'], [
                    'config_json' => json_encode($currentConfig),
                ]);
            }
        } catch (\Throwable $e) {
            // Webhook registration is best-effort; don't block configuration
            \StratFlow\Services\Logger::warn('[JiraIntegration] Webhook registration failed: ' . $e->getMessage());
        }

        $_SESSION['flash_message'] = 'Jira configuration saved. Project: ' . $projectKey;
        $this->response->redirect('/app/admin/integrations');
    }

    // =========================================================================
    // JIRA DISCONNECT
    // =========================================================================

    /**
     * Disconnect Jira integration.
     *
     * Sets status to 'disconnected' and clears tokens.
     */
    public function jiraDisconnect(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if ($integration) {
            // Revoke OAuth token at Atlassian before clearing locally
            if (!empty($integration['access_token'])) {
                try {
                    $ch = curl_init('https://auth.atlassian.com/oauth/revoke');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query([
                            'client_id'  => $this->config['jira']['client_id'] ?? '',
                            'token'      => $integration['access_token'],
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                        CURLOPT_TIMEOUT    => 10,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                } catch (\Throwable $e) {
                    \StratFlow\Services\Logger::warn('[JiraDisconnect] Token revocation failed: ' . $e->getMessage());
                }
            }

            Integration::update($this->db, (int) $integration['id'], [
                'status'        => 'disconnected',
                'access_token'  => null,
                'refresh_token' => null,
                'error_message' => null,
                'error_count'   => 0,
            ]);

            AuditLogger::log(
                $this->db,
                (int) $user['id'],
                AuditLogger::INTEGRATION_DISCONNECTED,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                ['provider' => 'jira', 'token_revoked' => true]
            );
        }

        $_SESSION['flash_message'] = 'Jira Cloud disconnected.';
        $this->response->redirect('/app/admin/integrations');
    }

    // =========================================================================
    // SYNC: PUSH
    // =========================================================================

    /**
     * Search Jira users — JSON endpoint for admin autocomplete.
     *
     * Returns users assignable to the configured project (or all users if no
     * project is configured). Admin-only.
     *
     * GET /app/admin/integrations/jira/users?q=...&project_key=...
     */
    public function jiraSearchUsers(): void
    {
        header('Content-Type: application/json');

        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration || $integration['status'] === 'disconnected') {
            echo json_encode(['users' => [], 'error' => 'Jira not connected']);
            exit;
        }

        $q          = trim((string) $this->request->get('q', ''));
        $projectKey = trim((string) $this->request->get('project_key', ''));

        if ($projectKey === '') {
            $cfg        = json_decode($integration['config_json'] ?? '{}', true) ?: [];
            $projectKey = $cfg['project_key'] ?? '';
        }

        try {
            $jira  = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $users = $projectKey !== ''
                ? $jira->getAssignableUsers($projectKey, $q)
                : $jira->searchUsers($q ?: 'a');

            $mapped = array_map(fn($u) => [
                'accountId'   => $u['accountId'] ?? '',
                'displayName' => $u['displayName'] ?? '',
                'email'       => $u['emailAddress'] ?? '',
                'avatar'      => $u['avatarUrls']['24x24'] ?? '',
            ], $users);

            echo json_encode(['users' => array_values($mapped)]);
        } catch (\Throwable $e) {
            echo json_encode(['users' => [], 'error' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================

    /**
     * Push StratFlow items to Jira.
     *
     * Pushes all work items as Epics and user stories as Stories
     * to the configured Jira project.
     */
    public function jiraPush(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration || $integration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Jira integration is not active.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $projectId = (int) $this->request->post('project_id', '0');
        if ($projectId === 0) {
            $_SESSION['flash_error'] = 'Please select a StratFlow project to sync.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $config = json_decode($integration['config_json'] ?? '{}', true) ?: [];
        $jiraProjectKey = $config['project_key'] ?? '';

        if ($jiraProjectKey === '') {
            $_SESSION['flash_error'] = 'No Jira project configured. Please configure the integration first.';
            $this->response->redirect('/app/admin/integrations/jira/configure');
            return;
        }

        try {
            $jira = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $sync = new JiraSyncService($this->db, $jira, $integration);

            $wiResult = $sync->pushWorkItems($projectId, $jiraProjectKey);
            $usResult = $sync->pushUserStories($projectId, $jiraProjectKey);
            $rkResult = $sync->pushRisks($projectId, $jiraProjectKey);

            Integration::update($this->db, (int) $integration['id'], [
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);

            $totalCreated = $wiResult['created'] + $usResult['created'] + $rkResult['created'];
            $totalUpdated = $wiResult['updated'] + $usResult['updated'] + $rkResult['updated'];
            $totalSkipped = ($wiResult['skipped'] ?? 0) + ($usResult['skipped'] ?? 0) + ($rkResult['skipped'] ?? 0);
            $totalErrors  = $wiResult['errors']  + $usResult['errors'] + $rkResult['errors'];

            AuditLogger::log(
                $this->db,
                (int) $user['id'],
                AuditLogger::INTEGRATION_SYNC,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                [
                    'provider'  => 'jira',
                    'direction' => 'push',
                    'created'   => $totalCreated,
                    'updated'   => $totalUpdated,
                    'skipped'   => $totalSkipped,
                    'errors'    => $totalErrors,
                ]
            );

            $parts = [];
            if ($totalCreated > 0) $parts[] = "{$totalCreated} created";
            if ($totalUpdated > 0) $parts[] = "{$totalUpdated} updated";
            if ($totalSkipped > 0) $parts[] = "{$totalSkipped} already in sync";
            if ($totalErrors > 0)  $parts[] = "{$totalErrors} errors";
            $_SESSION['flash_message'] = 'Push complete: ' . (empty($parts) ? 'no items to push.' : implode(', ', $parts) . '.');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Push failed: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/integrations');
    }

    // =========================================================================
    // SYNC: PULL
    // =========================================================================

    /**
     * Pull changes from Jira into StratFlow.
     */
    public function jiraPull(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration || $integration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Jira integration is not active.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $projectId = (int) $this->request->post('project_id', '0');
        if ($projectId === 0) {
            $_SESSION['flash_error'] = 'Please select a StratFlow project to sync.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $config = json_decode($integration['config_json'] ?? '{}', true) ?: [];
        $jiraProjectKey = $config['project_key'] ?? '';

        if ($jiraProjectKey === '') {
            $_SESSION['flash_error'] = 'No Jira project configured. Please configure the integration first.';
            $this->response->redirect('/app/admin/integrations/jira/configure');
            return;
        }

        try {
            $jira = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $sync = new JiraSyncService($this->db, $jira, $integration);

            $result = $sync->pullChanges($projectId, $jiraProjectKey);

            Integration::update($this->db, (int) $integration['id'], [
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);

            AuditLogger::log(
                $this->db,
                (int) $user['id'],
                AuditLogger::INTEGRATION_SYNC,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                [
                    'provider'  => 'jira',
                    'direction' => 'pull',
                    'updated'   => $result['updated'],
                    'errors'    => $result['errors'],
                ]
            );

            $_SESSION['flash_message'] = "Pull complete: {$result['updated']} updated, {$result['errors']} errors.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Pull failed: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/integrations');
    }

    // =========================================================================
    // JIRA BULK STATUS PULL
    // =========================================================================

    /**
     * Pull the latest Jira status for every mapped item in the org's integration.
     *
     * Loads all sync_mappings for the active Jira integration, collects their
     * external keys, calls JiraSyncService::pullStatusBulk, and redirects back
     * to the sync log page with a flash message reporting how many items changed.
     */
    public function jiraBulkPullStatus(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration || $integration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Jira integration is not active.';
            $this->response->redirect('/app/admin/integrations/sync-log');
            return;
        }

        $integrationId = (int) $integration['id'];
        $mappings = SyncMapping::findByIntegration($this->db, $integrationId);

        // Collect external keys for all hl_work_item and user_story mappings
        $issueKeys = [];
        foreach ($mappings as $m) {
            if (in_array($m['local_type'], ['hl_work_item', 'user_story'], true) && !empty($m['external_key'])) {
                $issueKeys[] = $m['external_key'];
            }
        }

        if (empty($issueKeys)) {
            $_SESSION['flash_message'] = 'No mapped items found to pull status for.';
            $this->response->redirect('/app/admin/integrations/sync-log');
            return;
        }

        try {
            $jira    = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $sync    = new JiraSyncService($this->db, $jira, $integration);
            $updated = $sync->pullStatusBulk($issueKeys);

            $_SESSION['flash_message'] = "Pulled status for " . count($issueKeys) . " items — {$updated} updated.";
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[BulkPullStatus] ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Bulk status pull failed: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/integrations/sync-log');
    }

    // =========================================================================
    // SYNC LOG
    // =========================================================================

    /**
     * Render the sync log page with pagination and filters.
     *
     * Accepts query params: page (int), direction (push|pull), status (success|error).
     */
    public function syncLog(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        // Parse filter / pagination query params
        $page      = max(1, (int) $this->request->get('page', 1));
        $perPage   = 50;
        $direction = $this->request->get('direction');
        $status    = $this->request->get('status');

        // Normalise filters — only accept known values
        $direction = in_array($direction, ['push', 'pull'], true) ? $direction : null;
        $status    = in_array($status, ['success', 'error'], true) ? $status : null;

        $logs       = [];
        $total      = 0;
        $totalPages = 1;

        if ($integration) {
            $result     = SyncLog::findByIntegrationPaginated(
                $this->db,
                (int) $integration['id'],
                $page,
                $perPage,
                $direction,
                $status
            );
            $logs       = $result['rows'];
            $total      = $result['total'];
            $totalPages = max(1, (int) ceil($total / $perPage));

            // Clamp page to valid range
            if ($page > $totalPages) {
                $page = $totalPages;
            }
        }

        $this->response->render('admin/sync-log', [
            'user'          => $user,
            'logs'          => $logs,
            'integration'   => $integration,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'total'         => $total,
            'direction'     => $direction,
            'status'        => $status,
            'active_page'   => 'integrations',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Export all sync log entries as CSV.
     *
     * Streams a CSV download with columns: timestamp, direction, action,
     * type, local_id, external_id, status, details. Respects active
     * direction/status filters from query params.
     */
    public function syncLogExport(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');

        if (!$integration) {
            $this->response->redirect('/app/admin/integrations/sync-log');
            return;
        }

        // Respect active filters
        $direction = $this->request->get('direction');
        $status    = $this->request->get('status');
        $direction = in_array($direction, ['push', 'pull'], true) ? $direction : null;
        $status    = in_array($status, ['success', 'error'], true) ? $status : null;

        $logs = SyncLog::findAllByIntegration(
            $this->db,
            (int) $integration['id'],
            $direction,
            $status
        );

        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::DATA_EXPORT, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'type'       => 'sync_log_export',
            'row_count'  => count($logs),
            'filters'    => ['direction' => $direction, 'status' => $status],
        ]);

        // Build CSV
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Timestamp', 'Direction', 'Action', 'Type', 'Local ID', 'External ID', 'Status', 'Details']);

        foreach ($logs as $log) {
            $details = json_decode($log['details_json'] ?? '{}', true) ?: [];
            $detailStr = '';
            if (!empty($details['error'])) {
                $detailStr = $details['error'];
            } elseif (!empty($details['title'])) {
                $detailStr = $details['title'];
            } elseif (!empty($details['reason'])) {
                $detailStr = $details['reason'];
            }

            fputcsv($handle, [
                $log['created_at'] ?? '',
                $log['direction'] ?? '',
                $log['action'] ?? '',
                $log['local_type'] ?? '',
                $log['local_id'] ?? '',
                $log['external_id'] ?? '',
                $log['status'] ?? '',
                $detailStr,
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $filename = 'sync_log_export_' . date('Y-m-d_His') . '.csv';
        $this->response->download($content, $filename, 'text/csv');
    }

    // =========================================================================
    // WEBHOOK
    // =========================================================================

    /**
     * Handle incoming Jira webhook events.
     *
     * No auth middleware or CSRF (external POST from Atlassian).
     * Returns 200 JSON on success to acknowledge receipt.
     */
    public function jiraWebhook(): void
    {
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody)) {
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            return;
        }

        // Validate webhook origin
        $signature = $_SERVER['HTTP_X_ATLASSIAN_SIGNATURE'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
        $webhookSecret = $this->config['jira']['webhook_secret'] ?? '';
        if ($webhookSecret !== '') {
            // HMAC signature validation
            $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
            $providedHash = str_replace('sha256=', '', $signature);
            if (!hash_equals($expected, $providedHash)) {
                \StratFlow\Services\Logger::warn('[JiraWebhook] Invalid HMAC signature — rejecting');
                http_response_code(401);
                echo json_encode(['error' => 'Invalid signature']);
                return;
            }
        } else {
            // No secret: verify basic Jira payload structure to prevent trivial spoofing
            $testPayload = json_decode($rawBody, true);
            if (!$testPayload || !isset($testPayload['webhookEvent']) || !isset($testPayload['issue'])) {
                \StratFlow\Services\Logger::warn('[JiraWebhook] Malformed payload rejected');
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payload']);
                return;
            }
        }

        $payload = json_decode($rawBody, true);
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        // Extract issue key and find the integration
        $issueKey = $payload['issue']['key'] ?? null;
        $event    = $payload['webhookEvent'] ?? 'unknown';

        if (!$issueKey) {
            http_response_code(200);
            echo json_encode(['status' => 'ignored', 'reason' => 'no issue key']);
            return;
        }

        // Find integration by looking up the sync mapping for this external key
        // We need to search across all integrations since we don't have org context
        try {
            $stmt = $this->db->query(
                "SELECT sm.*, i.id AS integration_id, i.org_id, i.config_json
                 FROM sync_mappings sm
                 JOIN integrations i ON sm.integration_id = i.id
                 WHERE sm.external_key = :key
                 LIMIT 1",
                [':key' => $issueKey]
            );
            $mapping = $stmt->fetch();

            if ($mapping) {
                $integrationId = (int) $mapping['integration_id'];

                AuditLogger::log(
                    $this->db,
                    null,
                    AuditLogger::INTEGRATION_WEBHOOK,
                    $this->request->ip(),
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    [
                        'provider' => 'jira',
                        'event'    => $event,
                        'issue'    => $issueKey,
                    ]
                );

                // Actually pull the changed fields from the webhook payload
                $issueFields = $payload['issue']['fields'] ?? [];
                $newTitle = $issueFields['summary'] ?? null;
                $action = 'update';
                $updateData = [];

                if ($event === 'jira:issue_deleted') {
                    $action = 'delete';
                } else {
                    if ($newTitle !== null) {
                        $updateData['title'] = $newTitle;
                    }
                    if (!empty($issueFields['description'])) {
                        $jiraService = new \StratFlow\Services\JiraService($this->config['jira'] ?? [], $mapping, $this->db);
                        $updateData['description'] = $jiraService->adfToText($issueFields['description']);
                    }

                    if (!empty($updateData)) {
                        if ($mapping['local_type'] === 'hl_work_item') {
                            \StratFlow\Models\HLWorkItem::update($this->db, (int) $mapping['local_id'], $updateData);
                        } elseif ($mapping['local_type'] === 'user_story') {
                            \StratFlow\Models\UserStory::update($this->db, (int) $mapping['local_id'], $updateData);
                        }
                    }

                    // Pull status — prefer the changelog status entry if present,
                    // otherwise fall back to the full issue fields.
                    $changelogItems  = $payload['changelog']['items'] ?? [];
                    $statusChangelog = null;
                    foreach ($changelogItems as $cl) {
                        if (($cl['field'] ?? '') === 'status') {
                            $statusChangelog = $cl;
                            break;
                        }
                    }

                    $integration = \StratFlow\Models\Integration::findById($this->db, $integrationId);
                    if ($integration) {
                        $jiraSvc = new \StratFlow\Services\JiraService($this->config['jira'] ?? [], $integration, $this->db);
                        $syncSvc = new \StratFlow\Services\JiraSyncService($this->db, $jiraSvc, $integration);

                        if ($statusChangelog !== null) {
                            // Changelog gives us the new status name directly — build a
                            // minimal issue payload so pullStatus can do the mapping.
                            $syntheticIssue = [
                                'fields' => [
                                    'status' => ['name' => $statusChangelog['toString'] ?? ''],
                                ],
                            ];
                            try {
                                $syncSvc->pullStatus($issueKey, $syntheticIssue);
                            } catch (\Throwable $statusEx) {
                                \StratFlow\Services\Logger::warn('[JiraWebhook] Status pull failed for ' . $issueKey . ': ' . $statusEx->getMessage());
                            }
                        } elseif (
                            in_array($event, ['jira:issue_updated', 'jira:issue_created'], true)
                            && !empty($issueFields['status'])
                        ) {
                            // No changelog — use whatever status is in the full issue payload
                            try {
                                $syncSvc->pullStatus($issueKey, $payload['issue']);
                            } catch (\Throwable $statusEx) {
                                \StratFlow\Services\Logger::warn('[JiraWebhook] Status pull failed for ' . $issueKey . ': ' . $statusEx->getMessage());
                            }
                        }
                    }
                }

                SyncLog::create($this->db, [
                    'integration_id' => $integrationId,
                    'direction'      => 'pull',
                    'action'         => $action,
                    'local_type'     => $mapping['local_type'],
                    'local_id'       => (int) $mapping['local_id'],
                    'external_id'    => $issueKey,
                    'details_json'   => json_encode([
                        'webhook_event'  => $event,
                        'fields_updated' => array_keys($updateData),
                    ]),
                    'status'         => 'success',
                ]);
            }
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[JiraWebhook] Error processing webhook: ' . $e->getMessage());
        }

        http_response_code(200);
        echo json_encode(['status' => 'ok']);
    }

    /**
     * Contextual sync from workflow pages (work items or user stories).
     * Uses the project's linked Jira project key.
     */
    public function contextualSync(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);
        $syncType  = (string) $this->request->post('sync_type', 'all');

        $project = \StratFlow\Security\ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if (!$project || empty($project['jira_project_key'])) {
            $_SESSION['flash_error'] = 'Project has no Jira link. Set one on the Home page.';
            $this->response->redirect($_SERVER['HTTP_REFERER'] ?? '/app/home');
            return;
        }

        $integration = \StratFlow\Models\Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        if (!$integration || $integration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Jira is not connected. Go to Administration → Integrations.';
            $this->response->redirect($_SERVER['HTTP_REFERER'] ?? '/app/home');
            return;
        }

        // Sync lock: prevent concurrent syncs
        $lastSync = $integration['last_sync_at'] ?? null;
        if ($lastSync && (time() - strtotime($lastSync)) < 10) {
            $_SESSION['flash_error'] = 'Sync already in progress. Please wait a moment.';
            $this->response->redirect($_SERVER['HTTP_REFERER'] ?? '/app/home');
            return;
        }

        try {
            $jiraService = new \StratFlow\Services\JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $syncService = new \StratFlow\Services\JiraSyncService($this->db, $jiraService, $integration);

            $results = [];
            $jiraKey = $project['jira_project_key'];

            // Push local changes to Jira
            if ($syncType === 'work_items' || $syncType === 'all') {
                $results['push_work_items'] = $syncService->pushWorkItems($projectId, $jiraKey);
            }
            if ($syncType === 'user_stories' || $syncType === 'all') {
                $results['push_user_stories'] = $syncService->pushUserStories($projectId, $jiraKey);
            }
            if ($syncType === 'risks' || $syncType === 'all') {
                $results['push_risks'] = $syncService->pushRisks($projectId, $jiraKey);
            }
            if ($syncType === 'sprints' || $syncType === 'all') {
                // Get board ID: from integration config, project record, or auto-detect
                $intConfig = json_decode($integration['config_json'] ?? '{}', true) ?: [];
                $boardId = (int) ($intConfig['field_mapping']['board_id'] ?? 0)
                        ?: (int) ($project['jira_board_id'] ?? 0);
                if ($boardId === 0) {
                    try {
                        $boards = $jiraService->getBoards($jiraKey);
                        $boardId = (int) ($boards['values'][0]['id'] ?? 0);
                    } catch (\Throwable $e) {
                        $boardId = 1;
                    }
                }
                if ($boardId > 0) {
                    $results['push_sprints'] = $syncService->pushSprints($projectId, $jiraKey, $boardId);
                }
            }

            // Push OKRs to Atlassian Goals (always, regardless of sync_type)
            $goalsCreated = 0;
            if ($syncType === 'all' || $syncType === 'work_items') {
                try {
                    $goalsResult = $syncService->pushOkrsToGoals($projectId);
                    $goalsCreated = $goalsResult['created'] ?? 0;
                } catch (\Throwable $e) {
                    \StratFlow\Services\Logger::warn("[JiraSync] Goals push failed (non-critical): " . $e->getMessage());
                }
            }

            // Pull Jira changes back to StratFlow
            $pullResult = $syncService->pullChanges($projectId, $jiraKey);
            $results['pull'] = $pullResult;

            \StratFlow\Models\Integration::update($this->db, (int) $integration['id'], [
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);

            // Build a clear summary message
            $totalCreated   = 0;
            $totalUpdated   = 0;
            $totalSkipped   = 0;
            $totalAllocated = 0;
            $totalErrors    = 0;
            $pullUpdated    = 0;

            $pullCreated    = 0;
            $totalConflicts = 0;

            foreach ($results as $type => $counts) {
                if ($type === 'pull') {
                    $pullCreated    = $counts['created'] ?? 0;
                    $pullUpdated    = $counts['updated'] ?? 0;
                    $totalConflicts = $counts['conflicts'] ?? 0;
                    $totalErrors   += $counts['errors'] ?? 0;
                } else {
                    $totalCreated   += $counts['created'] ?? 0;
                    $totalUpdated   += $counts['updated'] ?? 0;
                    $totalSkipped   += $counts['skipped'] ?? 0;
                    $totalAllocated += $counts['allocated'] ?? 0;
                    $totalErrors    += $counts['errors'] ?? 0;
                }
            }

            $parts = [];
            if ($totalCreated > 0)   $parts[] = "{$totalCreated} pushed to Jira";
            if ($totalUpdated > 0)   $parts[] = "{$totalUpdated} updated in Jira";
            if ($totalAllocated > 0) $parts[] = "{$totalAllocated} stories allocated to sprints";
            if ($goalsCreated > 0)   $parts[] = "{$goalsCreated} OKRs synced to Goals";
            if ($pullCreated > 0)    $parts[] = "{$pullCreated} imported from Jira";
            if ($pullUpdated > 0)    $parts[] = "{$pullUpdated} pulled from Jira";
            if ($totalConflicts > 0) $parts[] = "{$totalConflicts} conflicts (review required)";
            if ($totalSkipped > 0)   $parts[] = "{$totalSkipped} already in sync";
            if ($totalErrors > 0)    $parts[] = "{$totalErrors} errors";

            if (empty($parts)) {
                $_SESSION['flash_message'] = 'Jira sync complete — no items to sync.';
            } elseif ($totalSkipped > 0 && $totalCreated === 0 && $totalUpdated === 0 && $pullUpdated === 0) {
                $_SESSION['flash_message'] = "Jira sync complete — all {$totalSkipped} items already in sync.";
            } else {
                $_SESSION['flash_message'] = 'Jira sync complete: ' . implode(', ', $parts) . '.';
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Jira sync failed: ' . $e->getMessage();
        }

        $this->response->redirect($_SERVER['HTTP_REFERER'] ?? '/app/home');
    }

    // =========================================================================
    // JIRA TEAM IMPORT
    // =========================================================================

    /**
     * Import teams from Jira.
     *
     * In Jira, teams typically map to boards — each board represents
     * a team's workspace. This imports all boards from the configured
     * Jira project as StratFlow teams, plus any team names found in
     * the Team custom field on existing issues.
     */

    /**
     * Dry-run preview: returns JSON showing what WOULD be synced.
     */
    public function syncPreview(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = \StratFlow\Security\ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if (!$project || empty($project['jira_project_key'])) {
            $this->response->json(['error' => 'No Jira link'], 400);
            return;
        }

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        if (!$integration || $integration['status'] !== 'active') {
            $this->response->json(['error' => 'Jira not connected'], 400);
            return;
        }

        try {
            $jira = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $sync = new JiraSyncService($this->db, $jira, $integration);
            $preview = $sync->dryRunPreview($projectId, $project['jira_project_key']);
            $this->response->json($preview);
        } catch (\Throwable $e) {
            $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    public function jiraImportTeams(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        if (!$integration || $integration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Jira integration is not active.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        $config = json_decode($integration['config_json'] ?? '{}', true) ?: [];
        $projectKey = $config['project_key'] ?? '';
        if (!$projectKey) {
            $_SESSION['flash_error'] = 'No Jira project configured. Go to Integrations → Configure.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        try {
            $jira = new JiraService($this->config['jira'] ?? [], $integration, $this->db);
            $created = 0;
            $skipped = 0;

            // Load existing teams for dedup
            $existingTeams = \StratFlow\Models\Team::findByOrgId($this->db, $orgId);
            $existingNames = array_map(fn($t) => strtolower($t['name']), $existingTeams);

            // 1. Import boards as teams (board = team in Jira)
            $boardCount = 0;
            $boards = [];
            try {
                $boardsResult = $jira->getBoards($projectKey);
                $boards = $boardsResult['values'] ?? [];
            } catch (\Throwable $e) {
                // Board API may fail on team-managed projects or missing scopes.
                // Fallback: create one team from the project itself.
                \StratFlow\Services\Logger::warn('[JiraTeamImport] Board API failed, using project fallback: ' . $e->getMessage());
                $boards = [['id' => 1, 'name' => $projectKey . ' Team', 'type' => 'project']];
            }
            $boardCount = count($boards);

            try {
                foreach ($boards as $board) {
                    $teamName = $board['name'] ?? 'Board ' . $board['id'];
                    $boardId  = (int) $board['id'];

                    // Check if a team already has this board_id linked
                    $existsByBoardId = false;
                    foreach ($existingTeams as $et) {
                        if ((int) ($et['jira_board_id'] ?? 0) === $boardId) {
                            $existsByBoardId = true;
                            $skipped++;
                            break;
                        }
                    }
                    if ($existsByBoardId) continue;

                    // Check by name match
                    if (in_array(strtolower($teamName), $existingNames)) {
                        // Link existing team to this board
                        foreach ($existingTeams as $et) {
                            if (strtolower($et['name']) === strtolower($teamName)) {
                                \StratFlow\Models\Team::update($this->db, (int) $et['id'], [
                                    'jira_board_id' => $boardId,
                                ]);
                                break;
                            }
                        }
                        $skipped++;
                        continue;
                    }

                    \StratFlow\Models\Team::create($this->db, [
                        'org_id'        => $orgId,
                        'name'          => $teamName,
                        'description'   => "Jira board (ID: {$boardId}, type: " . ($board['type'] ?? 'unknown') . ")",
                        'capacity'      => 0,
                        'jira_board_id' => $boardId,
                    ]);
                    $existingNames[] = strtolower($teamName);
                    $existingTeams = \StratFlow\Models\Team::findByOrgId($this->db, $orgId);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errMsg = $e->getMessage();
                \StratFlow\Services\Logger::warn('[JiraTeamImport] Board import failed: ' . $errMsg);
                if (str_contains($errMsg, '401') || str_contains($errMsg, 'Unauthorized') || str_contains($errMsg, 'scope')) {
                    $_SESSION['flash_error'] = 'Jira board access denied. Please disconnect and reconnect Jira in Integrations to grant updated permissions (board scope required).';
                } else {
                    $_SESSION['flash_error'] = 'Board import failed: ' . $errMsg;
                }
                $this->response->redirect('/app/admin/teams');
                return;
            }

            // 2. Discover team names from the Team custom field on issues
            $teamField = $config['field_mapping']['team_field'] ?? '';
            if ($teamField) {
                try {
                    $result = $jira->searchIssues(
                        "project = {$projectKey} AND {$teamField} IS NOT EMPTY",
                        [$teamField],
                        100
                    );

                    foreach ($result['issues'] ?? [] as $issue) {
                        $val = $issue['fields'][$teamField] ?? null;
                        $name = null;
                        if (is_string($val) && $val !== '') {
                            $name = $val;
                        } elseif (is_array($val)) {
                            $name = $val['value'] ?? $val['name'] ?? null;
                        }

                        if ($name && !in_array(strtolower($name), $existingNames)) {
                            \StratFlow\Models\Team::create($this->db, [
                                'org_id'      => $orgId,
                                'name'        => $name,
                                'description' => 'Discovered from Jira Team field',
                                'capacity'    => 0,
                            ]);
                            $existingNames[] = strtolower($name);
                            $created++;
                        }
                    }
                } catch (\Throwable $e) {
                    // Non-critical
                }
            }

            $msg = "Jira import: found {$boardCount} board(s). ";
            if ($created > 0) $msg .= "{$created} team(s) created. ";
            if ($skipped > 0) $msg .= "{$skipped} already linked. ";
            if ($created === 0 && $skipped === 0 && $boardCount === 0) $msg .= "No boards found in Jira project {$projectKey}.";
            elseif ($created === 0 && $boardCount > 0) $msg .= "All boards already have matching teams.";
            $_SESSION['flash_message'] = trim($msg);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to import teams: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/teams');
    }
}
