#!/usr/bin/env php
<?php
/**
 * Admin User Creation Script
 *
 * Creates an organisation admin user for production setup.
 * Accepts CLI args or prompts interactively via readline().
 *
 * Usage:
 *   docker compose exec php php scripts/create-admin.php
 *   docker compose exec php php scripts/create-admin.php --email=x --password=x --name="Full Name" --org="Org Name"
 */

declare(strict_types=1);

// === BOOTSTRAP ===

require_once __DIR__ . '/../vendor/autoload.php';

use StratFlow\Core\Database;
use StratFlow\Models\Organisation;
use StratFlow\Models\User;

$config = require __DIR__ . '/../src/Config/config.php';
$db = new Database($config['db']);

// === ARG PARSING ===

/**
 * Parse --key=value style CLI arguments into an associative array.
 *
 * @param array $argv Raw argv array from PHP
 * @return array      Map of argument name => value (without leading dashes)
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([^=]+)=(.+)$/s', $arg, $matches)) {
            $args[$matches[1]] = $matches[2];
        }
    }
    return $args;
}

// === INPUT HELPERS ===

/**
 * Prompt the user for input via readline, re-prompting if the value is empty.
 *
 * @param string $prompt  Text to display before the cursor
 * @param bool   $secret  If true, suppress terminal echo for password input
 * @return string         Non-empty trimmed user input
 */
function prompt(string $prompt, bool $secret = false): string
{
    while (true) {
        if ($secret && PHP_OS_FAMILY !== 'Windows') {
            system('stty -echo');
        }

        $value = readline($prompt);

        if ($secret && PHP_OS_FAMILY !== 'Windows') {
            system('stty echo');
            echo PHP_EOL;
        }

        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
        echo "  Input cannot be empty. Please try again.\n";
    }
}

// === VALIDATION ===

/**
 * Validate an email address using PHP's built-in filter.
 *
 * @param string $email Email address to validate
 * @return bool         True if valid
 */
function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate that a password meets the minimum length requirement.
 *
 * @param string $password Password to validate
 * @return bool            True if at least 8 characters
 */
function validatePassword(string $password): bool
{
    return strlen($password) >= 8;
}

// === ORGANISATION HELPERS ===

/**
 * Find an organisation by exact name (case-insensitive).
 *
 * @param Database $db   Database instance
 * @param string   $name Organisation name to search for
 * @return array|null    Row as associative array, or null if not found
 */
function findOrgByName(Database $db, string $name): ?array
{
    $stmt = $db->query(
        "SELECT * FROM organisations WHERE LOWER(name) = LOWER(:name) LIMIT 1",
        [':name' => $name]
    );
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

// === MAIN LOGIC ===

$args = parseArgs($argv);
$interactive = empty($args);

echo "\n=== StratFlow Admin User Creation ===\n\n";

// Collect: organisation name
if (isset($args['org'])) {
    $orgName = trim($args['org']);
    if ($orgName === '') {
        fwrite(STDERR, "Error: --org value cannot be empty.\n");
        exit(1);
    }
} else {
    $orgName = prompt("Organisation name: ");
}

// Collect: email
if (isset($args['email'])) {
    $email = trim($args['email']);
} else {
    $email = prompt("Email address: ");
}

if (!validateEmail($email)) {
    fwrite(STDERR, "Error: '{$email}' is not a valid email address.\n");
    exit(1);
}

// Collect: password
if (isset($args['password'])) {
    $password = $args['password'];
} else {
    $password = prompt("Password (min 8 chars): ", secret: true);
}

if (!validatePassword($password)) {
    fwrite(STDERR, "Error: Password must be at least 8 characters.\n");
    exit(1);
}

// Collect: full name
if (isset($args['name'])) {
    $fullName = trim($args['name']);
    if ($fullName === '') {
        fwrite(STDERR, "Error: --name value cannot be empty.\n");
        exit(1);
    }
} else {
    $fullName = prompt("Full name: ");
}

// === DB OPERATIONS ===

// Resolve or create organisation
$org = findOrgByName($db, $orgName);

if ($org !== null) {
    $orgId = (int) $org['id'];
    echo "  Organisation '{$orgName}' already exists (id={$orgId}). Using existing.\n";
} else {
    $orgId = Organisation::create($db, [
        'name'               => $orgName,
        'stripe_customer_id' => '',
        'is_active'          => 1,
    ]);
    echo "  Organisation '{$orgName}' created (id={$orgId}).\n";
}

// Check for duplicate email
if (User::findByEmail($db, $email) !== null) {
    fwrite(STDERR, "Error: A user with email '{$email}' already exists.\n");
    exit(1);
}

// Create admin user
$userId = User::create($db, [
    'org_id'        => $orgId,
    'full_name'     => $fullName,
    'email'         => $email,
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role'          => 'org_admin',
]);

echo "\nAdmin user created successfully!\n";
echo "  ID:    {$userId}\n";
echo "  Name:  {$fullName}\n";
echo "  Email: {$email}\n";
echo "  Role:  org_admin\n";
echo "  Org:   {$orgName} (id={$orgId})\n\n";

exit(0);
