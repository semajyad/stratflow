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
            "INSERT INTO users (
                org_id, full_name, email, password_hash, role,
                team, is_project_admin, has_billing_access, has_executive_access, password_changed_at
             ) VALUES (
                :org_id, :full_name, :email, :password_hash, :role,
                :team, :is_project_admin, :has_billing_access, :has_executive_access, :password_changed_at
             )",
            [
                ':org_id'        => $data['org_id'],
                ':full_name'     => $data['full_name'],
                ':email'         => $data['email'],
                ':password_hash' => $data['password_hash'],
                ':role'          => $data['role'] ?? 'user',
                ':team'          => $data['team'] ?? null,
                ':is_project_admin' => $data['is_project_admin'] ?? 0,
                ':has_billing_access' => $data['has_billing_access'] ?? 0,
                ':has_executive_access' => $data['has_executive_access'] ?? 0,
                ':password_changed_at' => $data['password_changed_at'] ?? null,
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
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'full_name', 'email', 'password_hash', 'role', 'is_active', 'team',
        'is_project_admin', 'has_billing_access', 'has_executive_access', 'password_changed_at',
    ];

    public static function update(Database $db, int $id, array $data): void
    {
        // Filter to allowed columns only to prevent SQL injection via column names
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));
        if (empty($data)) {
            return;
        }

        $setClauses = implode(
            ', ',
            array_map(fn($col) => "`{$col}` = :{$col}", array_keys($data))
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

    /**
     * Find all users belonging to an organisation.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation primary key
     * @return array          Array of user rows
     */
    public static function findByOrgId(Database $db, int $orgId): array
    {
        $stmt = $db->query(
            "SELECT * FROM users WHERE org_id = :org_id ORDER BY full_name ASC",
            [':org_id' => $orgId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Count active users belonging to an organisation.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation primary key
     * @return int            Number of active users
     */
    public static function countByOrgId(Database $db, int $orgId): int
    {
        $stmt = $db->query(
            "SELECT COUNT(*) AS cnt FROM users WHERE org_id = :org_id AND is_active = 1 AND role != 'developer'",
            [':org_id' => $orgId]
        );

        $row = $stmt->fetch();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Deactivate a user by setting is_active = 0.
     *
     * Preserves the row for foreign key integrity rather than deleting.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the user to deactivate
     */
    public static function deactivate(Database $db, int $id): void
    {
        $db->query(
            "UPDATE users SET is_active = 0 WHERE id = :id",
            [':id' => $id]
        );
    }
}
