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

$token = \StratFlow\Models\PasswordToken::create($db, (int)$user['id'], 'set_password');
$url = rtrim($config['app']['url'], '/') . '/set-password/' . $token;

echo "User: {$user['full_name']} ({$user['email']})\n";
echo "URL: $url\n\n";

$smtpUser = $config['mail']['smtp_user'] ?? '';
$smtpPass = $config['mail']['smtp_pass'] ?? '';
echo "SMTP user: " . ($smtpUser ? substr($smtpUser, 0, 5) . '...' : 'NOT SET') . "\n";
echo "SMTP pass: " . ($smtpPass ? 'SET (' . strlen($smtpPass) . ' chars)' : 'NOT SET') . "\n\n";

echo "Testing ssl://smtp.gmail.com:465...\n";
$socket = @fsockopen('ssl://smtp.gmail.com', 465, $errno, $errstr, 15);
if (!$socket) {
    echo "SOCKET FAILED: $errstr ($errno)\n";
} else {
    echo "SOCKET OK\n";
    $greeting = fgets($socket, 512);
    echo "Greeting: $greeting\n";
    fclose($socket);
}

echo "\n--- Full email send ---\n";
$emailService = new \StratFlow\Services\EmailService($config);
try {
    $sent = $emailService->sendWelcome($email, $user['full_name'], $url);
    echo "Result: " . ($sent ? "EMAIL SENT!" : "FAILED") . "\n";
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
