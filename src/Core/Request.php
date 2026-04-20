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

    /** True when the request expects a JSON response (AJAX or Content-Type: application/json). */
    public static function expectsJson(): bool
    {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            return true;
        }
        $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        return str_starts_with($ct, 'application/json');
    }

    /**
     * Return the raw request body.
     */
    public function body(): string
    {
        return file_get_contents('php://input');
    }

    /**
     * Decode the request body as JSON and return an associative array.
     *
     * Used by API controllers for PAT-authenticated endpoints where the
     * body is application/json rather than form-encoded.
     *
     * Returns an empty array when the body is empty or not valid JSON.
     */
    public function json(): array
    {
        $body = $this->body();
        if ($body === '' || $body === false) {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Return the value of an HTTP request header.
     *
     * Header names are normalised to HTTP_UPPER_CASE keys in $_SERVER.
     * e.g. header('Authorization') reads $_SERVER['HTTP_AUTHORIZATION'].
     */
    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? '';
    }
}
