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

    /** Per-request CSP nonce — generated once on first call to getNonce(). */
    private static string $nonce = '';

    /**
     * Return the per-request CSP nonce, generating it on first call.
     * Use this to set nonce= on any inline <script> or <style> block.
     */
    public static function getNonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

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
        self::applySecurityHeaders($layout);

        $data['csrf_token'] = $this->csrf->getToken();

        // Inject project list for sidebar project switcher (app layout only)
        // Uses access-filtered query so restricted projects are hidden from non-members.
        if ($layout === 'app' && !isset($data['all_projects']) && isset($data['user'])) {
            try {
                $orgId         = (int) ($data['user']['org_id'] ?? 0);
                $userId        = (int) ($data['user']['id']    ?? 0);
                $role          = (string) ($data['user']['role'] ?? 'user');
                $isProjectAdmin = (bool) ($data['user']['is_project_admin'] ?? false);
                if ($orgId > 0) {
                    $data['all_projects'] = \StratFlow\Models\Project::findAccessibleByOrgId(
                        \StratFlow\Core\Database::getInstance(),
                        $orgId,
                        $userId,
                        $role,
                        $isProjectAdmin
                    );
                }
            } catch (\Throwable $e) {
                $data['all_projects'] = [];
            }
        }

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
        self::applySecurityHeaders('app');
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
        self::applySecurityHeaders('app');
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
        self::applySecurityHeaders('app');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }

    /**
     * Set comprehensive security headers.
     *
     * Covers OWASP A05 (Security Misconfiguration), HIPAA transport
     * security, PCI-DSS strong cryptography, and SOC 2 access controls.
     */
    public static function applySecurityHeaders(string $profile = 'app'): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Origin-Agent-Cluster: ?1');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        // Always send HSTS when APP_URL is https (covers Railway's TLS-terminating proxy
        // where isSecureTransport() may miss the first request in a session).
        $appUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
        if (self::isSecureTransport() || str_starts_with($appUrl, 'https://')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        header('Content-Security-Policy: ' . self::buildContentSecurityPolicy($profile, self::getNonce()));
        header('Cross-Origin-Embedder-Policy: require-corp');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }

    public static function applyStaticAssetHeaders(string $contentType, int $contentLength, int $maxAge = 86400): void
    {
        header_remove('X-Powered-By');
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $contentLength);
        header('Cache-Control: public, max-age=' . $maxAge);
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Origin-Agent-Cluster: ?1');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Content-Security-Policy: ' . self::buildContentSecurityPolicy('app', self::getNonce()));
        header('Cross-Origin-Embedder-Policy: require-corp');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // Always send HSTS when APP_URL is https (covers Railway's TLS-terminating proxy
        // where isSecureTransport() may miss the first request in a session).
        $appUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
        if (self::isSecureTransport() || str_starts_with($appUrl, 'https://')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    private static function isSecureTransport(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }

        if ((string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443') {
            return true;
        }

        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cfVisitor !== '' && str_contains($cfVisitor, '"scheme":"https"')) {
            return true;
        }

        $appUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
        return str_starts_with($appUrl, 'https://');
    }

    public static function buildContentSecurityPolicy(string $profile, string $nonce): string
    {
        $n = "'nonce-{$nonce}'";

        if ($profile === 'public') {
            return "default-src 'self'; script-src 'self' {$n}; style-src 'self' {$n}; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; media-src 'self'; frame-src https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://checkout.stripe.com";
        }

        // style-src keeps 'unsafe-inline' until all style= HTML attributes are migrated to classes.
        return "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net {$n}; style-src 'self' 'unsafe-inline' {$n}; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; media-src 'self'; frame-src https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://checkout.stripe.com";
    }
}
