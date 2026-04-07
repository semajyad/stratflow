<?php
/**
 * Application Configuration
 *
 * Loads environment variables from .env via phpdotenv and returns
 * a structured associative array of all configuration values.
 */

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->load();

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'url' => $_ENV['APP_URL'] ?? 'http://localhost:8890',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'database' => $_ENV['DB_DATABASE'] ?? 'stratflow',
        'username' => $_ENV['DB_USERNAME'] ?? 'stratflow',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
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
    ],
    'upload' => [
        'max_size' => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 52428800),
        'allowed_types' => ['text/plain', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'allowed_extensions' => ['txt', 'pdf', 'doc', 'docx'],
    ],
];
