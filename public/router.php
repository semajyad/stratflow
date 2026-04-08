<?php
/**
 * Router for PHP Built-in Server
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docRoot = __DIR__;
$filePath = $docRoot . $uri;
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

// Debug endpoint
if ($uri === '/_debug') {
    header('Content-Type: text/plain');
    echo "URI: $uri\n";
    echo "DocRoot (__DIR__): $docRoot\n";
    echo "CWD: " . getcwd() . "\n";
    echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n\n";

    $testPath = $docRoot . '/assets/css/app.css';
    echo "Test path: $testPath\n";
    echo "file_exists: " . (file_exists($testPath) ? 'YES' : 'NO') . "\n";
    echo "is_file: " . (is_file($testPath) ? 'YES' : 'NO') . "\n\n";

    echo "Listing $docRoot:\n";
    foreach (scandir($docRoot) as $f) echo "  $f\n";
    echo "\nListing $docRoot/assets:\n";
    if (is_dir("$docRoot/assets")) {
        foreach (scandir("$docRoot/assets") as $f) echo "  $f\n";
    } else {
        echo "  NOT A DIRECTORY\n";
    }
    exit;
}

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
