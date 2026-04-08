<?php
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/Config/config.php';
$db = new \StratFlow\Core\Database($config['db']);

// Find org for ThreePoints Solutions
$stmt = $db->query("SELECT id FROM organisations WHERE name LIKE '%ThreePoints%' ORDER BY id DESC LIMIT 1");
$org = $stmt->fetch();
if (!$org) {
    echo "No ThreePoints org found\n";
    exit(1);
}
$orgId = (int)$org['id'];

// Check if martin exists
$existing = \StratFlow\Models\User::findByEmail($db, 'martin@threepointssolutions.com');
if ($existing) {
    \StratFlow\Models\User::update($db, (int)$existing['id'], [
        'password_hash' => password_hash('Martin@Strat2026!', PASSWORD_BCRYPT),
        'role' => 'superadmin',
        'is_active' => 1,
    ]);
    echo "Updated martin: ID {$existing['id']}\n";
} else {
    $userId = \StratFlow\Models\User::create($db, [
        'org_id' => $orgId,
        'full_name' => 'Martin',
        'email' => 'martin@threepointssolutions.com',
        'password_hash' => password_hash('Martin@Strat2026!', PASSWORD_BCRYPT),
        'role' => 'superadmin',
    ]);
    echo "Created martin: ID $userId\n";
}
echo "Login: martin@threepointssolutions.com / Martin@Strat2026!\n";
