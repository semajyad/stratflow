<?php
/**
 * Secret Rotation Script
 *
 * Re-encrypts all SecretManager-protected values from the current (possibly
 * legacy v1) envelope to the latest key ID in TOKEN_ENCRYPTION_KEYS.
 *
 * Usage:
 *   php bin/rotate_secrets.php [--dry-run]
 *
 * Flags:
 *   --dry-run  Show what would be rotated without writing changes
 *
 * Prerequisites:
 *   TOKEN_ENCRYPTION_KEYS must include the old key id AND the new key id.
 *   After rotation completes, you may remove the old key id.
 *
 * Tables and columns rotated:
 *   - integrations.access_token  (JSON-encoded envelope stored as TEXT)
 *   - integrations.refresh_token (JSON-encoded envelope stored as TEXT)
 *
 * Add additional tables/columns in the $targets array below.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

$dryRun = in_array('--dry-run', $argv, true);

// ===========================
// BOOTSTRAP
// ===========================

try {
    $db = new \StratFlow\Core\Database($config['db']);
} catch (\Throwable $e) {
    fwrite(STDERR, '[rotate_secrets] DB connection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!\StratFlow\Core\SecretManager::isConfigured()) {
    fwrite(STDERR, '[rotate_secrets] TOKEN_ENCRYPTION_KEYS not configured — nothing to do.' . PHP_EOL);
    exit(0);
}

// ===========================
// TARGETS
// ===========================

/**
 * Each entry: [table, id_column, encrypted_column]
 * The encrypted_column stores a JSON-serialised SecretManager envelope (or
 * plaintext if not yet encrypted).
 */
$targets = [
    ['integrations', 'id', 'access_token'],
    ['integrations', 'id', 'refresh_token'],
];

// ===========================
// ROTATION LOOP
// ===========================

$pdo        = $db->getPdo();
$totalRows  = 0;
$rotated    = 0;
$skipped    = 0;

foreach ($targets as [$table, $idCol, $col]) {
    $stmt = $pdo->query("SELECT {$idCol}, {$col} FROM `{$table}` WHERE {$col} IS NOT NULL AND {$col} != ''");
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $totalRows++;
        $raw = $row[$col];

        // Decode the stored JSON envelope (if it looks like JSON)
        $decoded = @json_decode($raw, true);
        $value   = is_array($decoded) ? $decoded : $raw;

        $rotated_value = \StratFlow\Core\SecretManager::rotate($value);

        if ($rotated_value === $value) {
            $skipped++;
            continue; // Already on the current key or plaintext
        }

        $newRaw = is_array($rotated_value)
            ? json_encode($rotated_value, JSON_UNESCAPED_SLASHES)
            : $rotated_value;

        if ($dryRun) {
            echo "[dry-run] Would rotate {$table}.{$col} id={$row[$idCol]}" . PHP_EOL;
        } else {
            $upd = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$idCol}` = ?");
            $upd->execute([$newRaw, $row[$idCol]]);
        }

        $rotated++;
    }
}

$mode = $dryRun ? ' (dry-run)' : '';
echo "[rotate_secrets] total={$totalRows} rotated={$rotated} skipped={$skipped}{$mode}" . PHP_EOL;
exit(0);
