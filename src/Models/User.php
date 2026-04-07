<?php
/**
 * User Model
 *
 * Static data-access methods for the `users` table.
 * All methods accept a Database instance and use prepared statements.
 *
 * Columns: id, org_id, full_name, email, password_hash, role, is_active, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class User
{
    /**
     * Find a user by their email address.
     *
     * @param Database $db    Database instance
     * @param string   $email Email address to search for
     * @return array|null     Row as associative array, or null if not found
     */
    public static function findByEmail(Database $db, string $email): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM users WHERE email = :email LIMIT 1",
            [':email' => $email]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a user by their primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM users WHERE id = :id LIMIT 1",
            [':id' => $id]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new user row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: org_id, full_name, email, password_hash, role
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO users (org_id, full_name, email, password_hash, role)
             VALUES (:org_id, :full_name, :email, :password_hash, :role)",
            [
                ':org_id'        => $data['org_id'],
                ':full_name'     => $data['full_name'],
                ':email'         => $data['email'],
                ':password_hash' => $data['password_hash'],
                ':role'          => $data['role'] ?? 'user',
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Update arbitrary columns on an existing user row.
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

        $bound = [];
        foreach ($data as $col => $val) {
            $bound[":{$col}"] = $val;
        }
        $bound[':id'] = $id;

        $db->query(
            "UPDATE users SET {$setClauses} WHERE id = :id",
            $bound
        );
    }
}
