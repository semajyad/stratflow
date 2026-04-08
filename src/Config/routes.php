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
    $router->add('POST', '/app/diagram/save-okr', 'DiagramController@saveOkr', ['auth', 'csrf']);

    // Prioritisation — static routes MUST come before {id} routes
    $router->add('GET',  '/app/prioritisation',             'PrioritisationController@index',           ['auth']);
    $router->add('POST', '/app/prioritisation/framework',   'PrioritisationController@selectFramework', ['auth', 'csrf']);
    $router->add('POST', '/app/prioritisation/scores',      'PrioritisationController@saveScores',      ['auth']);
    $router->add('POST', '/app/prioritisation/rerank',      'PrioritisationController@rerank',          ['auth', 'csrf']);
    $router->add('POST', '/app/prioritisation/ai-baseline', 'PrioritisationController@aiBaseline',      ['auth']);

    // Work items — static routes MUST come before {id} routes
    $router->add('GET',  '/app/work-items',                        'WorkItemController@index',               ['auth']);
    $router->add('POST', '/app/work-items/generate',               'WorkItemController@generate',            ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/reorder',                'WorkItemController@reorder',             ['auth']);
    $router->add('GET',  '/app/work-items/export',                 'WorkItemController@export',              ['auth']);
    $router->add('POST', '/app/work-items/{id}',                   'WorkItemController@update',              ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/{id}/delete',            'WorkItemController@delete',              ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/{id}/generate-description', 'WorkItemController@generateDescription', ['auth']);

    // Risk modelling — static routes MUST come before {id} routes
    $router->add('GET',  '/app/risks',                'RiskController@index',              ['auth']);
    $router->add('POST', '/app/risks/generate',       'RiskController@generate',           ['auth', 'csrf']);
    $router->add('POST', '/app/risks',                'RiskController@store',              ['auth', 'csrf']);
    $router->add('POST', '/app/risks/{id}',           'RiskController@update',             ['auth', 'csrf']);
    $router->add('POST', '/app/risks/{id}/delete',    'RiskController@delete',             ['auth', 'csrf']);
    $router->add('POST', '/app/risks/{id}/mitigation', 'RiskController@generateMitigation', ['auth']);

    // User stories — static routes MUST come before {id} routes
    $router->add('GET',  '/app/user-stories',                  'UserStoryController@index',       ['auth']);
    $router->add('POST', '/app/user-stories/generate',         'UserStoryController@generate',    ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/store',            'UserStoryController@store',       ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/reorder',          'UserStoryController@reorder',     ['auth']);
    $router->add('GET',  '/app/user-stories/export',           'UserStoryController@export',      ['auth']);
    $router->add('POST', '/app/user-stories/{id}',             'UserStoryController@update',      ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}/delete',      'UserStoryController@delete',      ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}/suggest-size', 'UserStoryController@suggestSize', ['auth']);
};
