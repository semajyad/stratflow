<?php
/**
 * StratFlow Front Controller
 *
 * All requests are routed through this file by Nginx.
 * Bootstraps config, core services, registers routes, and dispatches.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

// === Error Display ===
if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// === Bootstrap Core Services ===
try {
    $db = new \StratFlow\Core\Database($config['db']);
} catch (\PDOException $e) {
    // If DB is unavailable, show a maintenance page (allows health checks to detect issues)
    if (!$config['app']['debug']) {
        http_response_code(503);
        echo '<h1>StratFlow is starting up...</h1><p>Please try again in a moment.</p>';
    } else {
        http_response_code(503);
        echo '<h1>Database connection failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
    exit;
}

$session = new \StratFlow\Core\Session();
$csrf = new \StratFlow\Core\CSRF($session);
$auth = new \StratFlow\Core\Auth($session, $db);
$request = new \StratFlow\Core\Request();
$response = new \StratFlow\Core\Response($csrf);

// === Register Routes ===
$router = new \StratFlow\Core\Router($request, $response, $auth, $csrf, $db, $config);

$routes = require __DIR__ . '/../src/Config/routes.php';
$routes($router);

// === Dispatch ===
$router->dispatch($request);
