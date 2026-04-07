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
};
