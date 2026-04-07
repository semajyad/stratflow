<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * HTTP Request Wrapper
 *
 * Provides clean access to PHP superglobals ($_GET, $_POST, $_FILES,
 * $_SERVER) with typed helper methods.
 */
class Request
{
    /**
     * Return the HTTP method (GET, POST, PUT, DELETE, etc.).
     */
    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Return the request URI path without query string.
     */
    public function uri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $path ?: '/';
    }

    /**
     * Get a value from the query string ($_GET).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get a value from the POST body ($_POST).
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * Get an uploaded file descriptor from $_FILES.
     *
     * @return array|null File array (name, type, tmp_name, error, size) or null
     */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /**
     * Return the client IP address.
     */
    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Check if the request method is POST.
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Check if the request is an AJAX/XHR request.
     */
    public function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Return the raw request body.
     */
    public function body(): string
    {
        return file_get_contents('php://input');
    }
}
