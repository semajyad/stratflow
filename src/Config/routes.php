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
    $router->add('GET',  '/app/upload', 'UploadController@index', ['auth']);
    $router->add('POST', '/app/upload', 'UploadController@store', ['auth', 'csrf']);
};
