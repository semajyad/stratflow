<?php
/**
 * Router for PHP Built-in Server
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

header_remove('X-Powered-By');
$isSecureRequest = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
    || (string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"')
);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docRoot = __DIR__;
$filePath = $docRoot . $uri;
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));


// Serve setup scripts directly (bypasses front controller)

// Serve static files (not PHP files)
if ($uri !== '/' && $ext !== '' && $ext !== 'php' && is_file($filePath)) {
    $mimeTypes = [
        'css' => 'text/css', 'js' => 'application/javascript',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'pdf' => 'application/pdf', 'json' => 'application/json', 'webp' => 'image/webp',
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
    http_response_code(200);
    \StratFlow\Core\Response::applyStaticAssetHeaders($contentType, (int) filesize($filePath));
    readfile($filePath);
    exit;
}

// Front controller
if ($isSecureRequest) {
    $_SERVER['HTTPS'] = 'on';
}
require $docRoot . '/index.php';
