<?php
/**
 * Route Definitions
 *
 * Returns a closure that registers all application routes on the Router.
 * Routes are grouped by public vs authenticated (middleware-protected).
 */

return function (\StratFlow\Core\Router $router) {
    // Public routes
    $router->add('GET', '/', 'PricingController@index');
    $router->add('GET', '/pricing', 'PricingController@index');

    // Checkout — CSRF required (form submission from pricing page)
    $router->add('POST', '/checkout', 'CheckoutController@create', ['csrf']);

    // Stripe webhook — NO CSRF (Stripe sends raw signed POST, not a browser form)
    $router->add('POST', '/webhook/stripe', 'WebhookController@handle');

    // Post-payment success page
    $router->add('GET', '/success', 'SuccessController@index');

    // Authentication
    $router->add('GET',  '/login',  'AuthController@showLogin');
    $router->add('POST', '/login',  'AuthController@login',  ['csrf']);
    $router->add('POST', '/logout', 'AuthController@logout', ['csrf', 'auth']);

    // Password reset flow
    $router->add('GET',  '/forgot-password',      'AuthController@showForgotPassword');
    $router->add('POST', '/forgot-password',      'AuthController@sendResetEmail',  ['csrf']);
    $router->add('GET',  '/set-password/{token}', 'AuthController@showSetPassword');
    $router->add('POST', '/set-password/{token}', 'AuthController@setPassword',     ['csrf']);

    // App — authenticated pages
    $router->add('GET',  '/app/home',     'HomeController@index',         ['auth']);
    $router->add('POST', '/app/projects', 'HomeController@createProject', ['auth', 'project_create', 'csrf']);

    // Document upload
    $router->add('GET',  '/app/upload',           'UploadController@index',           ['auth']);
    $router->add('POST', '/app/upload',           'UploadController@store',           ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/upload/summarise', 'UploadController@generateSummary', ['auth', 'workflow_write', 'csrf']);

    // Strategy diagram
    $router->add('GET',  '/app/diagram',          'DiagramController@index',    ['auth']);
    $router->add('POST', '/app/diagram/generate', 'DiagramController@generate', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/diagram/save',     'DiagramController@save',     ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/diagram/save-okr',      'DiagramController@saveOkr',     ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/diagram/save-all-okrs', 'DiagramController@saveAllOkrs', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/diagram/generate-okrs', 'DiagramController@generateOkrs', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/diagram/add-okr',       'DiagramController@addOkr',       ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/diagram/delete-okr',    'DiagramController@deleteOkr',    ['auth', 'workflow_write', 'csrf']);

    // Prioritisation — static routes MUST come before {id} routes
    $router->add('GET',  '/app/prioritisation',             'PrioritisationController@index',           ['auth']);
    $router->add('POST', '/app/prioritisation/framework',   'PrioritisationController@selectFramework', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/prioritisation/scores',      'PrioritisationController@saveScores',      ['auth', 'workflow_write']);
    $router->add('POST', '/app/prioritisation/rerank',      'PrioritisationController@rerank',          ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/prioritisation/ai-baseline', 'PrioritisationController@aiBaseline',      ['auth', 'workflow_write']);

    // Work items — static routes MUST come before {id} routes
    $router->add('GET',  '/app/work-items',                           'WorkItemController@index',               ['auth']);
    $router->add('POST', '/app/work-items/generate',                  'WorkItemController@generate',            ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/work-items/store',                     'WorkItemController@store',               ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/work-items/reorder',                   'WorkItemController@reorder',             ['auth', 'workflow_write']);
    $router->add('POST', '/app/work-items/regenerate-sizing',         'WorkItemController@regenerateSizing',    ['auth', 'workflow_write', 'csrf']);
    $router->add('GET',  '/app/work-items/export',                    'WorkItemController@export',              ['auth']);
    $router->add('POST', '/app/work-items/{id}/delete',               'WorkItemController@delete',             ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/work-items/{id}/close',                'WorkItemController@close',              ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/work-items/{id}/generate-description', 'WorkItemController@generateDescription', ['auth', 'workflow_write']);
    $router->add('POST', '/app/work-items/{id}/improve',              'WorkItemController@improve',             ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/work-items/{id}',                      'WorkItemController@update',              ['auth', 'workflow_write', 'csrf']);

    // Key Results — KR CRUD (static /delete route MUST come before {id} catch-all)
    $router->add('POST', '/app/key-results',             'KrController@store',  ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/key-results/{id}/delete', 'KrController@delete', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/key-results/{id}',        'KrController@update', ['auth', 'workflow_write', 'csrf']);

    // Risk modelling — static routes MUST come before {id} routes
    $router->add('GET',  '/app/risks',                'RiskController@index',              ['auth']);
    $router->add('POST', '/app/risks/generate',       'RiskController@generate',           ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/risks',                'RiskController@store',              ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/risks/{id}/delete',    'RiskController@delete',             ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/risks/{id}/close',     'RiskController@close',              ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/risks/{id}/roam',      'RiskController@setRoam',            ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/risks/{id}/mitigation', 'RiskController@generateMitigation', ['auth', 'workflow_write']);
    $router->add('POST', '/app/risks/{id}',           'RiskController@update',             ['auth', 'workflow_write', 'csrf']);

    // User stories — static routes MUST come before {id} routes
    $router->add('GET',  '/app/user-stories',                       'UserStoryController@index',           ['auth']);
    $router->add('POST', '/app/user-stories/generate',              'UserStoryController@generate',        ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/user-stories/store',                 'UserStoryController@store',           ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/user-stories/reorder',               'UserStoryController@reorder',         ['auth', 'workflow_write']);
    $router->add('POST', '/app/user-stories/regenerate-sizing',     'UserStoryController@regenerateSizing', ['auth', 'workflow_write', 'csrf']);
    $router->add('GET',  '/app/user-stories/export',                'UserStoryController@export',          ['auth']);
    $router->add('POST', '/app/user-stories/delete-all',            'UserStoryController@deleteAll',       ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}/delete',           'UserStoryController@delete',          ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}/close',            'UserStoryController@close',           ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}/suggest-size',     'UserStoryController@suggestSize',     ['auth', 'workflow_write']);
    $router->add('POST', '/app/user-stories/{id}/improve',          'UserStoryController@improve',         ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}',                  'UserStoryController@update',          ['auth', 'workflow_write', 'csrf']);

    // Sprint allocation — static routes MUST come before {id} routes
    $router->add('GET',  '/app/sprints',               'SprintController@index',         ['auth']);
    $router->add('GET',  '/app/sprints/jira-defaults', 'SprintController@jiraDefaults',  ['auth']);
    $router->add('POST', '/app/sprints/store',         'SprintController@store',         ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/sprints/assign',       'SprintController@assignStory',   ['auth', 'workflow_write']);
    $router->add('POST', '/app/sprints/unassign',     'SprintController@unassignStory', ['auth', 'workflow_write']);
    $router->add('POST', '/app/sprints/ai-allocate',    'SprintController@aiAllocate',    ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/sprints/auto-generate', 'SprintController@autoGenerate',  ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/sprints/auto-fill',     'SprintController@autoFill',      ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/sprints/{id}/delete',  'SprintController@delete',        ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/sprints/{id}',          'SprintController@update',        ['auth', 'workflow_write', 'csrf']);

    // Executive Dashboard — org-wide rollup (gated by has_executive_access flag or superadmin)
    $router->add('GET',  '/app/executive',               'ExecutiveController@dashboard',  ['auth', 'executive']);

    // Per-project OKR executive view
    $router->add('GET', '/app/projects/{id}/executive', 'ExecutiveController@projectDashboard', ['auth', 'executive']);

    // Traceability — read-only strategy-to-code chain view
    $router->add('GET',  '/app/traceability',            'TraceabilityController@index',   ['auth']);

    // Governance — drift detection and change control
    $router->add('GET',  '/app/governance',              'DriftController@dashboard',      ['auth']);
    $router->add('POST', '/app/governance/baseline',     'DriftController@createBaseline', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/governance/detect',       'DriftController@runDetection',   ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/governance/alerts/{id}',  'DriftController@acknowledgeAlert', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/governance/queue/{id}',   'DriftController@reviewChange',   ['auth', 'workflow_write', 'csrf']);

    // Sounding Board — AI persona evaluations
    $router->add('POST', '/app/sounding-board/evaluate',              'SoundingBoardController@evaluate', ['auth', 'workflow_write']);
    $router->add('GET',  '/app/sounding-board/results/{id}',          'SoundingBoardController@results',  ['auth']);
    $router->add('POST', '/app/sounding-board/results/{id}/respond',  'SoundingBoardController@respond',  ['auth', 'workflow_write']);
    $router->add('GET',  '/app/sounding-board/history',               'SoundingBoardController@history',  ['auth']);

    // Jira sync from workflow pages
    $router->add('POST', '/app/jira/sync',         'IntegrationController@contextualSync', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/jira/sync/preview',  'IntegrationController@syncPreview',    ['auth', 'workflow_write']);

    // Project GitHub repo subscription
    $router->add('GET',  '/app/projects/{id}/github/edit', 'ProjectGitHubController@edit', ['auth']);
    $router->add('POST', '/app/projects/{id}/github/save', 'ProjectGitHubController@save', ['auth', 'project_manage', 'csrf']);

    // Project management
    $router->add('POST', '/app/projects/{id}/edit',      'HomeController@editProject',   ['auth', 'project_manage', 'csrf']);
    $router->add('POST', '/app/projects/{id}/jira-link', 'HomeController@linkJira',      ['auth', 'project_manage', 'csrf']);
    $router->add('POST', '/app/projects/{id}/rename',    'HomeController@renameProject', ['auth', 'project_manage', 'csrf']);
    $router->add('POST', '/app/projects/{id}/delete',    'HomeController@deleteProject', ['auth', 'project_manage', 'csrf']);

    // Integrations (static routes BEFORE {id})
    $router->add('GET',  '/app/admin/integrations',                 'IntegrationController@index',            ['auth', 'admin']);
    $router->add('GET',  '/app/admin/integrations/jira/connect',    'IntegrationController@jiraConnect',      ['auth', 'admin']);
    $router->add('GET',  '/app/admin/integrations/jira/callback',   'IntegrationController@jiraCallback',     ['auth', 'admin']);
    $router->add('POST', '/app/admin/integrations/jira/disconnect', 'IntegrationController@jiraDisconnect',   ['auth', 'admin', 'csrf']);
    $router->add('GET',  '/app/admin/integrations/jira/configure',  'IntegrationController@jiraConfigure',    ['auth', 'admin']);
    $router->add('POST', '/app/admin/integrations/jira/configure',  'IntegrationController@jiraSaveConfigure',['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/integrations/jira/push',       'IntegrationController@jiraPush',         ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/integrations/jira/pull',       'IntegrationController@jiraPull',         ['auth', 'admin', 'csrf']);
    $router->add('GET',  '/app/admin/integrations/sync-log/export',  'IntegrationController@syncLogExport',    ['auth', 'admin']);
    $router->add('GET',  '/app/admin/integrations/sync-log',        'IntegrationController@syncLog',          ['auth', 'admin']);
    $router->add('POST', '/app/admin/integrations/jira/import-teams',    'IntegrationController@jiraImportTeams',    ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/integrations/jira/bulk-pull-status', 'IntegrationController@jiraBulkPullStatus', ['auth', 'admin', 'csrf']);
    $router->add('POST', '/webhook/integration/jira',               'IntegrationController@jiraWebhook');

    // Git webhooks — NO middleware (public, HMAC-verified in controller)
    $router->add('POST', '/webhook/git/github', 'GitWebhookController@receiveGitHub');
    $router->add('POST', '/webhook/git/gitlab', 'GitWebhookController@receiveGitLab');

    // GitHub App install flow — callback omits csrf (GitHub redirects back, state nonce is used instead)
    $router->add('GET',  '/app/admin/integrations/github/install',              'GitHubAppController@install',    ['auth', 'admin']);
    $router->add('GET',  '/app/admin/integrations/github/callback',             'GitHubAppController@callback',   ['auth', 'admin']);
    $router->add('POST', '/app/admin/integrations/github/{id}/disconnect',      'GitHubAppController@disconnect', ['auth', 'admin', 'csrf']);

    // Git links — manual CRUD (static routes BEFORE {id} routes)
    $router->add('GET',  '/app/git-links',            'GitLinkController@index',  ['auth']);
    $router->add('POST', '/app/git-links',            'GitLinkController@create', ['auth', 'workflow_write', 'csrf']);
    $router->add('POST', '/app/git-links/{id}/delete','GitLinkController@delete', ['auth', 'workflow_write', 'csrf']);

    // Git integrations — admin management
    $router->add('POST', '/app/admin/integrations/git/{provider}/connect',           'GitIntegrationController@connect',          ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/integrations/git/{provider}/disconnect',        'GitIntegrationController@disconnect',       ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/integrations/git/{provider}/regenerate-secret', 'GitIntegrationController@regenerateSecret', ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/integrations/git/{provider}/reveal-secret',     'GitIntegrationController@revealSecret',     ['auth', 'admin', 'csrf']);

    // Story quality rules — org-configurable AI quality constraints
    $router->add('GET',  '/app/admin/story-quality-rules',                    'StoryQualityController@index',  ['auth', 'admin']);
    $router->add('POST', '/app/admin/story-quality-rules',                    'StoryQualityController@store',  ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/story-quality-rules/{id}/delete',        'StoryQualityController@delete', ['auth', 'admin', 'csrf']);

    // Admin — static routes MUST come before {id} routes
    $router->add('GET',  '/app/admin',                       'AdminController@index',            ['auth', 'admin']);
    $router->add('GET',  '/app/admin/users',                 'AdminController@users',            ['auth', 'admin']);
    $router->add('POST', '/app/admin/users',                 'AdminController@createUser',       ['auth', 'admin', 'csrf']);
    $router->add('GET',  '/app/admin/teams',                 'AdminController@teams',            ['auth', 'admin']);
    $router->add('POST', '/app/admin/teams',                 'AdminController@createTeam',       ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/teams/add-member',      'AdminController@addTeamMember',    ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/teams/remove-member',   'AdminController@removeTeamMember', ['auth', 'admin', 'csrf']);
    $router->add('GET',  '/app/admin/audit-logs/export',     'AdminController@exportAuditLogs',  ['auth', 'admin']);
    $router->add('GET',  '/app/admin/audit-logs',            'AdminController@auditLogs',        ['auth', 'admin']);
    $router->add('GET',  '/app/admin/settings',              'AdminController@settings',         ['auth', 'admin']);
    $router->add('POST', '/app/admin/settings',              'AdminController@saveSettings',     ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/test-ai',               'AdminController@testAi',           ['auth', 'admin', 'csrf']);
    $router->add('GET',  '/app/admin/billing',                 'AdminController@billing',            ['auth', 'billing']);
    $router->add('GET',  '/app/admin/billing/portal',         'AdminController@billingPortal',      ['auth', 'billing']);
    $router->add('POST', '/app/admin/billing/portal',         'AdminController@billingPortal',      ['auth', 'billing', 'csrf']);
    $router->add('POST', '/app/admin/billing/contact',        'AdminController@saveBillingContact',  ['auth', 'billing', 'csrf']);
    $router->add('POST', '/app/admin/billing/seats/invoice',  'AdminController@purchaseSeatsInvoice', ['auth', 'billing', 'csrf']);
    $router->add('POST', '/app/admin/billing/seats/stripe',   'AdminController@purchaseSeatsStripe',  ['auth', 'billing', 'csrf']);

    // Xero OAuth — callback is GET with no CSRF (Xero redirects back)
    $router->add('GET',  '/app/admin/xero/connect',          'XeroController@connect',           ['auth', 'billing']);
    $router->add('GET',  '/app/admin/xero/callback',         'XeroController@callback',          ['auth', 'billing']);
    $router->add('POST', '/app/admin/xero/disconnect',       'XeroController@disconnect',        ['auth', 'billing', 'csrf']);

    // Invoices — Xero primary, Stripe fallback (static routes BEFORE {id})
    $router->add('GET',  '/app/admin/invoices',                          'XeroController@invoices',      ['auth', 'billing']);
    $router->add('POST', '/app/admin/invoices/create',                   'XeroController@createInvoice', ['auth', 'billing', 'csrf']);
    $router->add('POST', '/app/admin/invoices/sync',                     'XeroController@syncInvoices',  ['auth', 'billing', 'csrf']);
    $router->add('POST', '/app/admin/invoices/{id}/push-to-xero',        'XeroController@pushToXero',    ['auth', 'billing', 'csrf']);
    $router->add('POST', '/app/admin/users/{id}/delete',     'AdminController@deleteUser',       ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/users/{id}',            'AdminController@updateUser',       ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/teams/{id}/delete',     'AdminController@deleteTeam',       ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/teams/{id}',            'AdminController@updateTeam',       ['auth', 'admin', 'csrf']);
    $router->add('GET',  '/app/admin/invoices/{id}/download', 'AdminController@downloadInvoice', ['auth', 'admin']);

    // Superadmin — system-wide management (superadmin role only)
    $router->add('GET',  '/superadmin',                           'SuperadminController@index',           ['auth', 'superadmin']);
    $router->add('GET',  '/superadmin/organisations',              'SuperadminController@organisations',   ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/organisations/create',     'SuperadminController@createOrg',       ['auth', 'superadmin', 'csrf']);
    $router->add('GET',  '/superadmin/organisations/{id}/export', 'SuperadminController@exportOrg',      ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/organisations/{id}/jira',  'SuperadminController@toggleJira',      ['auth', 'superadmin', 'csrf']);
    $router->add('POST', '/superadmin/organisations/{id}',        'SuperadminController@updateOrg',      ['auth', 'superadmin', 'csrf']);
    $router->add('GET',  '/superadmin/defaults',                  'SuperadminController@defaults',        ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/defaults',                  'SuperadminController@saveDefaults',    ['auth', 'superadmin', 'csrf']);
    $router->add('POST', '/superadmin/defaults/test-ai',          'SuperadminController@testAiConnection', ['auth', 'superadmin', 'csrf']);
    $router->add('GET',  '/superadmin/personas',                  'SuperadminController@personas',        ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/personas',                  'SuperadminController@savePersona',     ['auth', 'superadmin', 'csrf']);
    $router->add('GET',  '/superadmin/audit-logs/export',          'SuperadminController@exportAuditLogs',  ['auth', 'superadmin']);
    $router->add('GET',  '/superadmin/audit-logs',                'SuperadminController@auditLogs',        ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/assign-superadmin',         'SuperadminController@assignSuperadmin', ['auth', 'superadmin', 'csrf']);

    // Developer tokens — Personal Access Tokens for API / MCP access (any authenticated user)
    $router->add('GET',  '/app/account/tokens',             'AccessTokenController@index',  ['auth']);
    $router->add('POST', '/app/account/tokens',             'AccessTokenController@create', ['auth', 'csrf']);
    $router->add('POST', '/app/account/tokens/{id}/revoke', 'AccessTokenController@revoke', ['auth', 'csrf']);
    $router->add('POST', '/app/account/team',               'AccessTokenController@saveTeam', ['auth', 'csrf']);

    // ====== JSON API — PAT-authenticated, no CSRF, no session ======
    // CSRF-exempt precedent: /webhook/stripe, /webhook/git/*
    $router->add('GET',  '/api/v1/me',                      'ApiStoriesController@me',           ['api_auth']);
    $router->add('POST', '/api/v1/me/team',                 'ApiStoriesController@setMyTeam',     ['api_auth']);
    $router->add('GET',  '/api/v1/stories/team',            'ApiStoriesController@teamStories',   ['api_auth']);
    $router->add('GET',  '/api/v1/stories',                 'ApiStoriesController@index',        ['api_auth']);
    $router->add('GET',  '/api/v1/stories/{id}',            'ApiStoriesController@show',         ['api_auth']);
    $router->add('POST', '/api/v1/stories/{id}/status',     'ApiStoriesController@updateStatus', ['api_auth']);
    $router->add('POST', '/api/v1/stories/{id}/assign',     'ApiStoriesController@assign',        ['api_auth']);
    $router->add('GET',  '/api/v1/projects',                'ApiProjectsController@index',       ['api_auth']);
};
