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

// Static asset version: uses ASSET_VERSION env var (set at deploy) or falls back to git commit short hash.
// This prevents timestamp disclosure (ZAP-10096) while still busting caches on deploy.
$assetVersion = $_ENV['ASSET_VERSION']
    ?? (function () {
        $ref = dirname(__DIR__, 2) . '/.git/HEAD';
        if (!is_readable($ref)) { return '1'; }
        $head = trim(file_get_contents($ref));
        // Packed refs: HEAD points to a ref file
        if (str_starts_with($head, 'ref: ')) {
            $refFile = dirname(__DIR__, 2) . '/.git/' . substr($head, 5);
            $head = is_readable($refFile) ? trim(file_get_contents($refFile)) : '1';
        }
        return substr($head, 0, 8) ?: '1';
    })();

return [
    'app' => [
        'env'           => $_ENV['APP_ENV'] ?? 'production',
        'url'           => $_ENV['APP_URL'] ?? 'http://localhost:8890',
        'debug'         => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'asset_version' => $assetVersion,
    ],
    'db' => $dbConfig,
    'gemini' => [
        'api_key' => $_ENV['GEMINI_API_KEY'] ?? '',
        'model' => $_ENV['GEMINI_MODEL'] ?? 'gemini-3-flash-preview',
    ],
    'openai' => [
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
    ],
    'jira' => [
        'client_id'      => $_ENV['JIRA_CLIENT_ID'] ?? '',
        'client_secret'  => $_ENV['JIRA_CLIENT_SECRET'] ?? '',
        'redirect_uri'   => ($_ENV['APP_URL'] ?? '') . '/app/admin/integrations/jira/callback',
        'webhook_secret' => $_ENV['JIRA_WEBHOOK_SECRET'] ?? '',
    ],
    'xero' => [
        'client_id'     => $_ENV['XERO_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['XERO_CLIENT_SECRET'] ?? '',
        'redirect_uri'  => ($_ENV['APP_URL'] ?? '') . '/app/admin/xero/callback',
    ],
    'encryption' => [
        'token_key' => $_ENV['TOKEN_ENCRYPTION_KEY'] ?? '',
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
        'from_name'           => $_ENV['MAIL_FROM_NAME'] ?? 'StratFlow',
        'from_email'          => $_ENV['MAIL_FROM_EMAIL'] ?? '',
        'mailersend_api_key'  => $_ENV['MAILERSEND_API_KEY'] ?? '',
        'mailersend_from'     => $_ENV['MAILERSEND_FROM_EMAIL'] ?? '',
        'resend_api_key'      => $_ENV['RESEND_API_KEY'] ?? '',
        'smtp_host'           => $_ENV['MAIL_SMTP_HOST'] ?? '',
        'smtp_port'           => $_ENV['MAIL_SMTP_PORT'] ?? '465',
        'smtp_encryption'     => $_ENV['MAIL_SMTP_ENCRYPTION'] ?? 'auto',
        'smtp_user'           => $_ENV['MAIL_SMTP_USER'] ?? '',
        'smtp_pass'           => $_ENV['MAIL_SMTP_PASS'] ?? '',
    ],
    'upload' => [
        'max_size' => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 52428800),
        'allow_external_ai_processing' => filter_var($_ENV['ALLOW_EXTERNAL_AI_PROCESSING'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'allowed_types' => [
            'text/plain',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            // Video
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo',
            'video/webm',
            'video/x-matroska',
            // Audio
            'audio/mpeg',
            'audio/mp4',
            'audio/wav',
            'audio/ogg',
            'audio/aac',
        ],
        'allowed_extensions' => [
            'txt', 'pdf', 'doc', 'docx',
            'mp4', 'mov', 'avi', 'webm', 'mkv',
            'mp3', 'm4a', 'wav', 'ogg', 'aac',
        ],
    ],
];
