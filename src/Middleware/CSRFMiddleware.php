<?php

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\CSRF;
use StratFlow\Core\Request;
use StratFlow\Core\Response;

/**
 * CSRF Protection Middleware
 *
 * Validates the _csrf_token field on POST, PUT, and DELETE requests.
 * Returns 403 if the token is missing or invalid.
 */
class CSRFMiddleware
{
    /**
     * Validate CSRF token on state-changing requests.
     *
     * @return bool True if validation passed (or not required), false on failure
     */
    public function handle(Request $request, CSRF $csrf, Response $response): bool
    {
        $method = $request->method();

        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            $token = $request->post('_csrf_token', '');

            if (!$csrf->validateToken($token)) {
                Response::applySecurityHeaders('app');
                http_response_code(403);
                echo '<h1>403 - Invalid CSRF Token</h1>';
                return false;
            }
        }

        return true;
    }
}
