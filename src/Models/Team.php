<?php
/**
 * Team Model
 *
 * Static data-access methods for the `teams` table.
 * All methods accept a Database instance and use prepared statements.
 *
 * Columns: id, org_id, name, description, capacity, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class Team
{
    /**
     * Insert a new team row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: org_id, name, description, capacity
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO teams (org_id, name, description, capacity)
             VALUES (:org_id, :name, :description, :capacity)",
            [
                ':org_id'      => $data['org_id'],
                ':name'        => $data['name'],
                ':description' => $data['description'] ?? '',
                ':capacity'    => $data['capacity'] ?? 0,
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Find all teams belonging to an organisation.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation primary key
     * @return array          Array of team rows
     */
    public static function findByOrgId(Database $db, int $orgId): array
    {
        $stmt = $db->query(
            "SELECT t.*, COUNT(tm.user_id) AS member_count
             FROM teams t
             LEFT JOIN team_members tm ON tm.team_id = t.id
             WHERE t.org_id = :org_id
             GROUP BY t.id
             ORDER BY t.name ASC",
            [':org_id' => $orgId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a team by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM teams WHERE id = :id LIMIT 1",
            [':id' => $id]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Update arbitrary columns on an existing team row.
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
            "UPDATE teams SET {$setClauses} WHERE id = :id",
            $bound
        );
    }

    /**
     * Delete a team by its primary key.
     *
     * CASCADE on team_members handles member cleanup automatically.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the team to delete
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM teams WHERE id = :id", [':id' => $id]);
    }
}
