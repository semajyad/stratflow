<?php

/**
 * Organisation Model
 *
 * Static data-access methods for the `organisations` table.
 * All methods accept a Database instance and use prepared statements.
 *
 * Columns: id, name, stripe_customer_id, is_active, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;
use StratFlow\Core\SecretManager;

class Organisation
{
    private const SETTINGS_SECRET_PATHS = [
        'ai.api_key',
    ];
/**
     * Insert a new organisation row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: name, stripe_customer_id, is_active
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query("INSERT INTO organisations (name, stripe_customer_id, is_active)
             VALUES (:name, :stripe_customer_id, :is_active)", [
                ':name'               => $data['name'],
                ':stripe_customer_id' => $data['stripe_customer_id'],
                ':is_active'          => $data['is_active'] ?? 1,
            ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Find an organisation by its Stripe customer ID.
     *
     * @param Database $db               Database instance
     * @param string   $stripeCustomerId Stripe cus_xxx identifier
     * @return array|null                Row as associative array, or null if not found
     */
    public static function findByStripeCustomerId(Database $db, string $stripeCustomerId): ?array
    {
        $stmt = $db->query("SELECT * FROM organisations WHERE stripe_customer_id = :stripe_customer_id LIMIT 1", [':stripe_customer_id' => $stripeCustomerId]);
        $row = $stmt->fetch();
        return $row !== false ? self::hydrateRow($db, $row) : null;
    }

    /**
     * Find an organisation by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query("SELECT * FROM organisations WHERE id = :id LIMIT 1", [':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Return all organisations with user counts, newest first.
     *
     * @param Database $db Database instance
     * @return array       Array of organisation rows with a user_count column
     */
    public static function findAll(Database $db): array
    {
        $stmt = $db->query("SELECT o.*,
                    (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id AND u.is_active = 1) AS user_count
             FROM organisations o
             ORDER BY o.created_at DESC");
        return array_map(static fn(array $row): array => self::hydrateRow($db, $row), $stmt->fetchAll() ?: []);
    }

    /**
     * Suspend an organisation by setting is_active = 0.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the organisation
     */
    public static function suspend(Database $db, int $id): void
    {
        $db->query("UPDATE organisations SET is_active = 0 WHERE id = :id", [':id' => $id]);
    }

    /**
     * Enable an organisation by setting is_active = 1.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the organisation
     */
    public static function enable(Database $db, int $id): void
    {
        $db->query("UPDATE organisations SET is_active = 1 WHERE id = :id", [':id' => $id]);
    }

    /**
     * Delete an organisation by its primary key.
     *
     * CASCADE constraints handle child table cleanup (users, subscriptions, etc.).
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the organisation
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM organisations WHERE id = :id", [':id' => $id]);
    }

    /**
     * Export full organisation data as a nested array.
     *
     * Includes the org row plus all users, projects, and subscriptions.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the organisation
     * @return array|null  Nested data array, or null if org not found
     */
    public static function exportData(Database $db, int $id): ?array
    {
        $org = self::findById($db, $id);
        if (!$org) {
            return null;
        }

        // Users
        $stmt = $db->query("SELECT id, full_name, email, role, is_active, created_at FROM users WHERE org_id = :org_id", [':org_id' => $id]);
        $org['users'] = $stmt->fetchAll();
// Projects
        $stmt = $db->query("SELECT * FROM projects WHERE org_id = :org_id", [':org_id' => $id]);
        $org['projects'] = $stmt->fetchAll();
// Subscriptions
        $stmt = $db->query("SELECT * FROM subscriptions WHERE org_id = :org_id", [':org_id' => $id]);
        $org['subscriptions'] = $stmt->fetchAll();
        return $org;
    }

    /**
     * Update arbitrary columns on an existing organisation row.
     *
     * @param Database $db   Database instance
     * @param int      $id   Primary key of the row to update
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'name', 'stripe_customer_id', 'is_active', 'settings_json',
    ];
    public static function update(Database $db, int $id, array $data): void
    {
        // Filter to allowed columns only to prevent SQL injection via column names
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));
        if (empty($data)) {
            return;
        }

        if (array_key_exists('settings_json', $data) && is_string($data['settings_json']) && $data['settings_json'] !== '') {
            $decoded = json_decode($data['settings_json'], true);
            if (is_array($decoded)) {
                $data['settings_json'] = SecretManager::protectJson($decoded, self::SETTINGS_SECRET_PATHS);
            }
        }

        $setClauses = implode(', ', array_map(fn($col) => "`{$col}` = :{$col}", array_keys($data)));
        $bound = [];
        foreach ($data as $col => $val) {
            $bound[":{$col}"] = $val;
        }
        $bound[':id'] = $id;
        $db->query("UPDATE organisations SET {$setClauses} WHERE id = :id", $bound);
    }

    private static function hydrateRow(Database $db, array $row): array
    {
        $needsBackfill = SecretManager::isConfigured()
            && !empty($row['settings_json'])
            && is_string($row['settings_json'])
            && self::jsonNeedsProtection($row['settings_json'], self::SETTINGS_SECRET_PATHS);
        if (!empty($row['settings_json']) && is_string($row['settings_json'])) {
            $row['settings_json'] = SecretManager::unprotectJson($row['settings_json'], self::SETTINGS_SECRET_PATHS);
        }

        if ($needsBackfill && !empty($row['id'])) {
            self::update($db, (int) $row['id'], ['settings_json' => $row['settings_json']]);
        }

        return $row;
    }

    private static function jsonNeedsProtection(string $json, array $paths): bool
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return false;
        }

        foreach ($paths as $path) {
            $value = self::valueAtPath($payload, $path);
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private static function valueAtPath(array $payload, string $path): mixed
    {
        $segments = explode('.', $path);
        $node = $payload;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
