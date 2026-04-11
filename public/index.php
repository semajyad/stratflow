<?php
/**
 * StratFlow Front Controller
 *
 * All requests are routed through this file by Nginx.
 * Bootstraps config, core services, registers routes, and dispatches.
 *
 * Production error handling: never exposes stack traces, SQL errors,
 * or file paths. Logs all errors to PHP error_log and shows generic
 * error pages (403, 404, 500) with no technical details.
 */

declare(strict_types=1);

// Enable gzip compression
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

header_remove('X-Powered-By');
@ini_set('expose_php', '0');

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

// === Error Handling ===
if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    // Register a shutdown function to catch fatal errors in production
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            error_log(sprintf(
                '[StratFlow] Fatal error: %s in %s on line %d',
                $error['message'],
                $error['file'],
                $error['line']
            ));

            if (!headers_sent()) {
                \StratFlow\Core\Response::applySecurityHeaders();
                http_response_code(500);
                include __DIR__ . '/../templates/errors/500.php';
            }
        }
    });

    // Set exception handler for uncaught exceptions in production
    set_exception_handler(function (\Throwable $e) {
        error_log(sprintf(
            '[StratFlow] Uncaught %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if (!headers_sent()) {
            \StratFlow\Core\Response::applySecurityHeaders();
            http_response_code(500);
            include __DIR__ . '/../templates/errors/500.php';
        }
        exit(1);
    });
}

// === Bootstrap Core Services ===
try {
    $db = new \StratFlow\Core\Database($config['db']);
} catch (\Throwable $e) {
    // Catch any connection failure (PDOException, Exception, or driver-level errors
    // such as caching_sha2_password auth failures that may not surface as PDOException).
    error_log('[StratFlow] Database connection failed: ' . $e->getMessage());
    \StratFlow\Core\Response::applySecurityHeaders();
    http_response_code(503);
    if ($config['app']['debug']) {
        echo '<h1>Database connection failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    } else {
        include __DIR__ . '/../templates/errors/500.php';
    }
    exit;
}

$session = new \StratFlow\Core\Session(86400, $db->getPdo());
$csrf = new \StratFlow\Core\CSRF($session);
$auth = new \StratFlow\Core\Auth($session, $db);
$request = new \StratFlow\Core\Request();
$response = new \StratFlow\Core\Response($csrf);

// === Merge org-level AI settings into config (if user is logged in) ===
// Allows admins to override the platform default Gemini model and API key
// via Organisation Settings → AI. Falls back to platform defaults when empty.
if (!empty($_SESSION['user']['org_id'])) {
    try {
        $orgRow = $db->query(
            "SELECT settings_json FROM organisations WHERE id = :id LIMIT 1",
            [':id' => (int) $_SESSION['user']['org_id']]
        )->fetch();
        if ($orgRow && !empty($orgRow['settings_json'])) {
            $orgSettings = json_decode($orgRow['settings_json'], true) ?? [];
            if (!empty($orgSettings['ai']['model'])) {
                $config['gemini']['model'] = $orgSettings['ai']['model'];
            }
            if (!empty($orgSettings['ai']['api_key'])) {
                $config['gemini']['api_key'] = $orgSettings['ai']['api_key'];
            }
        }
    } catch (\Throwable) {
        // Non-fatal: fall back to platform defaults
    }
}

// === Register Routes ===
$router = new \StratFlow\Core\Router($request, $response, $auth, $csrf, $db, $config);

$routes = require __DIR__ . '/../src/Config/routes.php';
$routes($router);

// === Dispatch ===
$router->dispatch($request);
