<?php
/**
 * Router for PHP Built-in Server
 */

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
        'pdf' => 'application/pdf', 'json' => 'application/json',
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, max-age=86400');
    readfile($filePath);
    exit;
}

// Front controller
require $docRoot . '/index.php';
