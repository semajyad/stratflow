<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load test environment from .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__));
    $dotenv->load();
}

// Ensure DB_HOST is set via putenv for tests that read it directly
// CI sets DB_HOST=127.0.0.1, local Docker uses 'mysql'
$dbHost = $_ENV['DB_HOST'] ?? 'mysql';
putenv("DB_HOST=$dbHost");

/**
 * Get test database config.
 */
function getTestDbConfig(): array
{
    return [
        'host'     => getenv('DB_HOST') ?: 'mysql',
        'port'     => getenv('DB_PORT') ?: '3306',
        'database' => 'stratflow',
        'username' => 'stratflow',
        'password' => 'stratflow_secret',
    ];
}
