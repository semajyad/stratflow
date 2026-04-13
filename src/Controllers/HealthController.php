<?php
/**
 * HealthController
 *
 * Returns a machine-readable health check response for uptime monitors and
 * container orchestrators. No authentication required — intentionally public.
 *
 * GET /healthz → JSON {status, build, db_ms}
 *
 * db_ms is the round-trip time in milliseconds for a trivial DB ping.
 * status is 'ok' when the DB is reachable, 'degraded' otherwise.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;

class HealthController
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

    public function index(): void
    {
        $dbMs   = null;
        $status = 'ok';

        try {
            $t0 = microtime(true);
            $this->db->query('SELECT 1');
            $dbMs = (int) round((microtime(true) - $t0) * 1000);
        } catch (\Throwable) {
            $status = 'degraded';
        }

        $payload = json_encode([
            'status' => $status,
            'build'  => ASSET_VERSION,
            'db_ms'  => $dbMs,
        ], JSON_UNESCAPED_SLASHES);

        \StratFlow\Core\Response::applySecurityHeaders();
        // Override no-store for the healthcheck endpoint — monitors need to
        // see fresh values, and no auth data is returned.
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status === 'ok' ? 200 : 503);
        echo $payload;
    }
}
