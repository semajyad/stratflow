<?php
/**
 * Router for PHP Built-in Server
 *
 * Serves static files with correct MIME types, routes
 * everything else through index.php (front controller).
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// __DIR__ is the public/ directory since this file lives there
$docRoot = __DIR__;
$filePath = $docRoot . $uri;

// Serve existing static files directly
if ($uri !== '/' && is_file($filePath)) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'pdf'   => 'application/pdf',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'map'   => 'application/json',
    ];

    $contentType = $mimeTypes[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream');

    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, max-age=86400');
    readfile($filePath);
    exit;
}

// Route everything else through the front controller
require $docRoot . '/index.php';
