<?php
/**
 * Router for PHP Built-in Server
 *
 * Serves static files with correct MIME types, routes
 * everything else through index.php (front controller).
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$filePath = __DIR__ . $uri;

// Serve existing static files directly
if ($uri !== '/' && file_exists($filePath) && is_file($filePath)) {
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
    ];

    $contentType = $mimeTypes[$ext] ?? mime_content_type($filePath) ?: 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    return;
}

// Route everything else through the front controller
require __DIR__ . '/index.php';
