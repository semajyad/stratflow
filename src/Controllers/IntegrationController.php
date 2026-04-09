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
use StratFlow\Models\SyncLog;
use StratFlow\Models\SyncMapping;
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

        // Count synced items for Jira
        $jiraSyncCount = 0;
        if (isset($byProvider['jira'])) {
            $mappings = SyncMapping::findByIntegration($this->db, (int) $byProvider['jira']['id']);
            $jiraSyncCount = count($mappings);
        }

        $this->response->render('admin/integrations', [
            'user'            => $user,
            'integrations'    => $byProvider,
            'jira_sync_count' => $jiraSyncCount,
            'active_page'     => 'integrations',
            'flash_message'   => $_SESSION['flash_message'] ?? null,
            'flash_error'     => $_SESSION['flash_error']   ?? null,
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
            'board_id'           => (int) $this->request->post('board_id', 0),
        ];

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
            error_log('[JiraIntegration] Webhook registration failed: ' . $e->getMessage());
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
                ['provider' => 'jira']
            );
        }

        $_SESSION['flash_message'] = 'Jira Cloud disconnected.';
        $this->response->redirect('/app/admin/integrations');
    }

    // =========================================================================
    // SYNC: PUSH
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
    // SYNC LOG
    // =========================================================================

    /**
     * Render the sync log page showing recent sync operations.
     */
    public function syncLog(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        $logs = [];

        if ($integration) {
            $logs = SyncLog::findByIntegration($this->db, (int) $integration['id'], 50);
        }

        $this->response->render('admin/sync-log', [
            'user'          => $user,
            'logs'          => $logs,
            'integration'   => $integration,
            'active_page'   => 'integrations',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
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
                }

                SyncLog::create($this->db, [
                    'integration_id' => $integrationId,
                    'direction'      => 'pull',
                    'action'         => $action,
                    'local_type'     => $mapping['local_type'],
                    'local_id'       => (int) $mapping['local_id'],
                    'external_id'    => $issueKey,
                    'details_json'   => json_encode([
                        'webhook_event' => $event,
                        'fields_updated' => array_keys($updateData),
                    ]),
                    'status'         => 'success',
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[JiraWebhook] Error processing webhook: ' . $e->getMessage());
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

        $project = \StratFlow\Models\Project::findById($this->db, $projectId, $orgId);
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

            // Log item counts for debugging
            error_log("[JiraSync] Project {$projectId} ({$jiraKey}): syncType={$syncType}, results=" . json_encode($results));

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

            foreach ($results as $type => $counts) {
                if ($type === 'pull') {
                    $pullUpdated = $counts['updated'] ?? 0;
                    $totalErrors += $counts['errors'] ?? 0;
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
            if ($pullUpdated > 0)    $parts[] = "{$pullUpdated} pulled from Jira";
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
}
