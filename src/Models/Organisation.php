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

class Organisation
{
    /**
     * Insert a new organisation row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: name, stripe_customer_id, is_active
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO organisations (name, stripe_customer_id, is_active)
             VALUES (:name, :stripe_customer_id, :is_active)",
            [
                ':name'               => $data['name'],
                ':stripe_customer_id' => $data['stripe_customer_id'],
                ':is_active'          => $data['is_active'] ?? 1,
            ]
        );

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
        $stmt = $db->query(
            "SELECT * FROM organisations WHERE stripe_customer_id = :stripe_customer_id LIMIT 1",
            [':stripe_customer_id' => $stripeCustomerId]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
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
        $stmt = $db->query(
            "SELECT * FROM organisations WHERE id = :id LIMIT 1",
            [':id' => $id]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Update arbitrary columns on an existing organisation row.
     *
     * @param Database $db   Database instance
     * @param int      $id   Primary key of the row to update
     * @param array    $data Columns to update as key => value pairs
     */
    public static function update(Database $db, int $id, array $data): void
    {
        $setClauses = implode(
            ', ',
            array_map(fn($col) => "{$col} = :{$col}", array_keys($data))
        );

        $params = $data;
        $params[':id'] = $id;

        // Rebind param keys to include colon prefix for PDO named placeholders
        $bound = [];
        foreach ($data as $col => $val) {
            $bound[":{$col}"] = $val;
        }
        $bound[':id'] = $id;

        $db->query(
            "UPDATE organisations SET {$setClauses} WHERE id = :id",
            $bound
        );
    }
}
