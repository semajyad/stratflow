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
    $router->add('POST', '/app/projects', 'HomeController@createProject', ['auth', 'csrf']);

    // Document upload
    $router->add('GET',  '/app/upload',           'UploadController@index',           ['auth']);
    $router->add('POST', '/app/upload',           'UploadController@store',           ['auth', 'csrf']);
    $router->add('POST', '/app/upload/summarise', 'UploadController@generateSummary', ['auth', 'csrf']);

    // Strategy diagram
    $router->add('GET',  '/app/diagram',          'DiagramController@index',    ['auth']);
    $router->add('POST', '/app/diagram/generate', 'DiagramController@generate', ['auth', 'csrf']);
    $router->add('POST', '/app/diagram/save',     'DiagramController@save',     ['auth', 'csrf']);
    $router->add('POST', '/app/diagram/save-okr',      'DiagramController@saveOkr',     ['auth', 'csrf']);
    $router->add('POST', '/app/diagram/save-all-okrs', 'DiagramController@saveAllOkrs', ['auth', 'csrf']);
    $router->add('POST', '/app/diagram/generate-okrs', 'DiagramController@generateOkrs', ['auth', 'csrf']);

    // Prioritisation — static routes MUST come before {id} routes
    $router->add('GET',  '/app/prioritisation',             'PrioritisationController@index',           ['auth']);
    $router->add('POST', '/app/prioritisation/framework',   'PrioritisationController@selectFramework', ['auth', 'csrf']);
    $router->add('POST', '/app/prioritisation/scores',      'PrioritisationController@saveScores',      ['auth']);
    $router->add('POST', '/app/prioritisation/rerank',      'PrioritisationController@rerank',          ['auth', 'csrf']);
    $router->add('POST', '/app/prioritisation/ai-baseline', 'PrioritisationController@aiBaseline',      ['auth']);

    // Work items — static routes MUST come before {id} routes
    $router->add('GET',  '/app/work-items',                           'WorkItemController@index',               ['auth']);
    $router->add('POST', '/app/work-items/generate',                  'WorkItemController@generate',            ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/store',                     'WorkItemController@store',               ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/reorder',                   'WorkItemController@reorder',             ['auth']);
    $router->add('POST', '/app/work-items/regenerate-sizing',         'WorkItemController@regenerateSizing',    ['auth', 'csrf']);
    $router->add('GET',  '/app/work-items/export',                    'WorkItemController@export',              ['auth']);
    $router->add('POST', '/app/work-items/{id}/delete',               'WorkItemController@delete',             ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/{id}/generate-description', 'WorkItemController@generateDescription', ['auth']);
    $router->add('POST', '/app/work-items/{id}',                      'WorkItemController@update',              ['auth', 'csrf']);

    // Risk modelling — static routes MUST come before {id} routes
    $router->add('GET',  '/app/risks',                'RiskController@index',              ['auth']);
    $router->add('POST', '/app/risks/generate',       'RiskController@generate',           ['auth', 'csrf']);
    $router->add('POST', '/app/risks',                'RiskController@store',              ['auth', 'csrf']);
    $router->add('POST', '/app/risks/{id}/delete',    'RiskController@delete',             ['auth', 'csrf']);
    $router->add('POST', '/app/risks/{id}/mitigation', 'RiskController@generateMitigation', ['auth']);
    $router->add('POST', '/app/risks/{id}',           'RiskController@update',             ['auth', 'csrf']);

    // User stories — static routes MUST come before {id} routes
    $router->add('GET',  '/app/user-stories',                       'UserStoryController@index',           ['auth']);
    $router->add('POST', '/app/user-stories/generate',              'UserStoryController@generate',        ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/store',                 'UserStoryController@store',           ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/reorder',               'UserStoryController@reorder',         ['auth']);
    $router->add('POST', '/app/user-stories/regenerate-sizing',     'UserStoryController@regenerateSizing', ['auth', 'csrf']);
    $router->add('GET',  '/app/user-stories/export',                'UserStoryController@export',          ['auth']);
    $router->add('POST', '/app/user-stories/{id}/delete',           'UserStoryController@delete',          ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}/suggest-size',     'UserStoryController@suggestSize',     ['auth']);
    $router->add('POST', '/app/user-stories/{id}',                  'UserStoryController@update',          ['auth', 'csrf']);

    // Sprint allocation — static routes MUST come before {id} routes
    $router->add('GET',  '/app/sprints',             'SprintController@index',         ['auth']);
    $router->add('POST', '/app/sprints/store',        'SprintController@store',         ['auth', 'csrf']);
    $router->add('POST', '/app/sprints/assign',       'SprintController@assignStory',   ['auth']);
    $router->add('POST', '/app/sprints/unassign',     'SprintController@unassignStory', ['auth']);
    $router->add('POST', '/app/sprints/ai-allocate',    'SprintController@aiAllocate',    ['auth', 'csrf']);
    $router->add('POST', '/app/sprints/auto-generate', 'SprintController@autoGenerate',  ['auth', 'csrf']);
    $router->add('POST', '/app/sprints/auto-fill',     'SprintController@autoFill',      ['auth', 'csrf']);
    $router->add('POST', '/app/sprints/{id}/delete',  'SprintController@delete',        ['auth', 'csrf']);
    $router->add('POST', '/app/sprints/{id}',          'SprintController@update',        ['auth', 'csrf']);

    // Governance — drift detection and change control
    $router->add('GET',  '/app/governance',              'DriftController@dashboard',      ['auth']);
    $router->add('POST', '/app/governance/baseline',     'DriftController@createBaseline', ['auth', 'csrf']);
    $router->add('POST', '/app/governance/detect',       'DriftController@runDetection',   ['auth', 'csrf']);
    $router->add('POST', '/app/governance/alerts/{id}',  'DriftController@acknowledgeAlert', ['auth', 'csrf']);
    $router->add('POST', '/app/governance/queue/{id}',   'DriftController@reviewChange',   ['auth', 'csrf']);

    // Sounding Board — AI persona evaluations
    $router->add('POST', '/app/sounding-board/evaluate',              'SoundingBoardController@evaluate', ['auth']);
    $router->add('GET',  '/app/sounding-board/results/{id}',          'SoundingBoardController@results',  ['auth']);
    $router->add('POST', '/app/sounding-board/results/{id}/respond',  'SoundingBoardController@respond',  ['auth']);
    $router->add('GET',  '/app/sounding-board/history',               'SoundingBoardController@history',  ['auth']);

    // Jira sync from workflow pages
    $router->add('POST', '/app/jira/sync',         'IntegrationController@contextualSync', ['auth', 'csrf']);
    $router->add('POST', '/app/jira/sync/preview',  'IntegrationController@syncPreview',    ['auth']);

    // Project management
    $router->add('POST', '/app/projects/{id}/jira-link', 'HomeController@linkJira', ['auth', 'csrf']);
    $router->add('POST', '/app/projects/{id}/rename',    'HomeController@renameProject', ['auth', 'csrf']);
    $router->add('POST', '/app/projects/{id}/delete',    'HomeController@deleteProject', ['auth', 'admin', 'csrf']);

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
    $router->add('GET',  '/app/admin/billing',               'AdminController@billing',          ['auth', 'billing']);
    $router->add('POST', '/app/admin/billing/portal',        'AdminController@billingPortal',    ['auth', 'billing', 'csrf']);
    $router->add('GET',  '/app/admin/invoices',              'AdminController@invoices',         ['auth', 'billing']);
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
    $router->add('GET',  '/superadmin/personas',                  'SuperadminController@personas',        ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/personas',                  'SuperadminController@savePersona',     ['auth', 'superadmin', 'csrf']);
    $router->add('GET',  '/superadmin/audit-logs/export',          'SuperadminController@exportAuditLogs',  ['auth', 'superadmin']);
    $router->add('GET',  '/superadmin/audit-logs',                'SuperadminController@auditLogs',        ['auth', 'superadmin']);
    $router->add('POST', '/superadmin/assign-superadmin',         'SuperadminController@assignSuperadmin', ['auth', 'superadmin', 'csrf']);
};
