<?php
if (($_GET['key'] ?? '') !== 'stratflow-provision-2026') { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/Config/config.php';
$db = new \StratFlow\Core\Database($config['db']);
header('Content-Type: text/plain');

$users = [
    ['email' => 'martin@threepoints.co.nz', 'name' => 'Martin Rajcok'],
];

foreach ($users as $u) {
    $existing = \StratFlow\Models\User::findByEmail($db, $u['email']);
    if ($existing) { echo "SKIP: {$u['email']} already exists (ID {$existing['id']})\n"; continue; }
    
    // Find or create org
    $stmt = $db->query("SELECT id FROM organisations ORDER BY id DESC LIMIT 1");
    $org = $stmt->fetch();
    $orgId = $org ? (int)$org['id'] : \StratFlow\Models\Organisation::create($db, ['name' => 'ThreePoints', 'stripe_customer_id' => '', 'is_active' => 1]);
    
    $userId = \StratFlow\Models\User::create($db, [
        'org_id' => $orgId, 'full_name' => $u['name'], 'email' => $u['email'],
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'role' => 'org_admin',
    ]);
    
    $token = \StratFlow\Models\PasswordToken::create($db, $userId, 'set_password');
    $url = rtrim($config['app']['url'], '/') . '/set-password/' . $token;
    
    $emailService = new \StratFlow\Services\EmailService($config);
    $sent = $emailService->sendWelcome($u['email'], $u['name'], $url);
    echo "CREATED: {$u['email']} (ID $userId) - Email " . ($sent ? 'SENT' : 'FAILED') . "\n";
    echo "Set password: $url\n\n";
}
echo "Done.\n";
