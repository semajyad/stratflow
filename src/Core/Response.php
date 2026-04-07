<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * HTTP Response Helper
 *
 * Renders PHP templates within layouts, sends JSON responses,
 * redirects, and file downloads. Adds security headers to all output.
 */
class Response
{
    private CSRF $csrf;

    public function __construct(CSRF $csrf)
    {
        $this->csrf = $csrf;
    }

    /**
     * Render a template within a layout.
     *
     * Template path is relative to templates/ (e.g. 'pages/pricing').
     * Data is extracted into template scope. CSRF token is injected automatically.
     *
     * @param string $template Template path (without .php extension)
     * @param array  $data     Variables to pass to the template
     * @param string $layout   Layout name ('public' or 'app')
     */
    public function render(string $template, array $data = [], string $layout = 'public'): void
    {
        $this->setSecurityHeaders();

        $data['csrf_token'] = $this->csrf->getToken();
        extract($data);

        ob_start();
        require __DIR__ . '/../../templates/' . $template . '.php';
        $content = ob_get_clean();

        require __DIR__ . '/../../templates/layouts/' . $layout . '.php';
    }

    /**
     * Send a JSON response with appropriate headers.
     *
     * @param array $data   Data to encode as JSON
     * @param int   $status HTTP status code (default 200)
     */
    public function json(array $data, int $status = 200): void
    {
        $this->setSecurityHeaders();
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Send a redirect response.
     *
     * @param string $url Absolute or relative URL
     */
    public function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Send a file download response.
     *
     * @param string $content     File content
     * @param string $filename    Suggested download filename
     * @param string $contentType MIME type
     */
    public function download(string $content, string $filename, string $contentType): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }

    /**
     * Set standard security headers.
     */
    private function setSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
