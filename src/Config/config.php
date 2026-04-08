<?php
/**
 * Application Configuration
 *
 * Loads environment variables from .env via phpdotenv and returns
 * a structured associative array of all configuration values.
 */

use Dotenv\Dotenv;

// Load .env file if it exists (not present on Railway — env vars set via dashboard)
$envPath = dirname(__DIR__, 2);
if (file_exists($envPath . '/.env')) {
    $dotenv = Dotenv::createImmutable($envPath);
    $dotenv->load();
}

// Parse DATABASE_URL if provided (Railway MySQL format: mysql://user:pass@host:port/dbname)
$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_DATABASE'] ?? 'stratflow',
    'username' => $_ENV['DB_USERNAME'] ?? 'stratflow',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
];

if (!empty($_ENV['DATABASE_URL'])) {
    $parsed = parse_url($_ENV['DATABASE_URL']);
    $dbConfig = [
        'host' => $parsed['host'] ?? 'localhost',
        'port' => (string)($parsed['port'] ?? '3306'),
        'database' => ltrim($parsed['path'] ?? '/stratflow', '/'),
        'username' => $parsed['user'] ?? 'root',
        'password' => $parsed['pass'] ?? '',
    ];
}

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'url' => $_ENV['APP_URL'] ?? 'http://localhost:8890',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],
    'db' => $dbConfig,
    'gemini' => [
        'api_key' => $_ENV['GEMINI_API_KEY'] ?? '',
        'model' => $_ENV['GEMINI_MODEL'] ?? 'gemini-2.5-flash',
    ],
    'stripe' => [
        'publishable_key' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
        'secret_key' => $_ENV['STRIPE_SECRET_KEY'] ?? '',
        'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '',
        'price_product' => $_ENV['STRIPE_PRICE_PRODUCT'] ?? '',
        'price_consultancy' => $_ENV['STRIPE_PRICE_CONSULTANCY'] ?? '',
        'price_user_pack' => $_ENV['STRIPE_PRICE_USER_PACK'] ?? '',
        'price_evaluation_board' => $_ENV['STRIPE_PRICE_EVAL_BOARD'] ?? '',
    ],
    'mail' => [
        'from_name'  => $_ENV['MAIL_FROM_NAME'] ?? 'StratFlow',
        'from_email' => $_ENV['MAIL_FROM_EMAIL'] ?? 'noreply@stratflow.app',
    ],
    'upload' => [
        'max_size' => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 52428800),
        'allowed_types' => ['text/plain', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'allowed_extensions' => ['txt', 'pdf', 'doc', 'docx'],
    ],
];
