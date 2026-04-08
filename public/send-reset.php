<?php
if (($_GET['key'] ?? '') !== 'stratflow-provision-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/Config/config.php';
$db = new \StratFlow\Core\Database($config['db']);
header('Content-Type: text/plain');

$email = $_GET['email'] ?? '';
if (!$email) { echo "Pass ?email=xxx"; exit; }

$user = \StratFlow\Models\User::findByEmail($db, $email);
if (!$user) { echo "User not found: $email\n"; exit; }

// Create set_password token
$token = \StratFlow\Models\PasswordToken::create($db, (int)$user['id'], 'set_password');
$url = rtrim($config['app']['url'], '/') . '/set-password/' . $token;

echo "User: {$user['full_name']} ({$user['email']})\n";
echo "Token: $token\n";
echo "URL: $url\n\n";

// Send via Resend
$emailService = new \StratFlow\Services\EmailService($config);
echo "Resend API key: " . (empty($config['mail']['resend_api_key']) ? 'NOT SET' : substr($config['mail']['resend_api_key'], 0, 10) . '...') . "\n";
echo "From: {$config['mail']['from_email']}\n\n";

$sent = $emailService->sendWelcome($email, $user['full_name'], $url);
echo "Result: " . ($sent ? "EMAIL SENT!" : "FAILED - check logs") . "\n";
