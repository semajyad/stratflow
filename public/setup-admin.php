<?php
/**
 * One-time admin user setup endpoint.
 * DELETE THIS FILE after use.
 *
 * Access: /setup-admin.php?key=stratflow-setup-2026
 */

// Security: require setup key
if (($_GET['key'] ?? '') !== 'stratflow-setup-2026') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/Config/config.php';

header('Content-Type: text/plain');

try {
    $db = new \StratFlow\Core\Database($config['db']);
    echo "DB connected.\n";

    // Create org
    $orgId = \StratFlow\Models\Organisation::create($db, [
        'name' => 'ThreePoints Solutions',
        'stripe_customer_id' => '',
        'is_active' => 1,
    ]);
    echo "Org created: ID $orgId\n";

    // Create subscription
    \StratFlow\Models\Subscription::create($db, [
        'org_id' => $orgId,
        'stripe_subscription_id' => 'prod_manual_setup',
        'plan_type' => 'consultancy',
        'status' => 'active',
        'started_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Subscription created.\n";

    // Create admin user
    $userId = \StratFlow\Models\User::create($db, [
        'org_id' => $orgId,
        'full_name' => 'James Day',
        'email' => 'james@threepointssolutions.com',
        'password_hash' => password_hash('StratFlow2026!', PASSWORD_BCRYPT),
        'role' => 'superadmin',
    ]);
    echo "User created: ID $userId\n";
    echo "\nLogin: james@threepointssolutions.com / StratFlow2026!\n";
    echo "\n** DELETE THIS FILE NOW: rm public/setup-admin.php **\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
