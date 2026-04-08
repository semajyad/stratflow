<?php
/**
 * PersonaMember Model
 *
 * Static data-access methods for the `persona_members` table.
 * Each member belongs to a panel and carries a role title plus an LLM prompt description.
 *
 * Columns: id, panel_id, role_title, prompt_description
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class PersonaMember
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new persona member and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: panel_id, role_title, prompt_description
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO persona_members (panel_id, role_title, prompt_description)
             VALUES (:panel_id, :role_title, :prompt_description)",
            [
                ':panel_id'           => $data['panel_id'],
                ':role_title'         => $data['role_title'],
                ':prompt_description' => $data['prompt_description'],
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all members belonging to a given panel, ordered by ID (insertion order).
     *
     * @param Database $db      Database instance
     * @param int      $panelId Parent panel primary key
     * @return array            Array of member rows as associative arrays
     */
    public static function findByPanelId(Database $db, int $panelId): array
    {
        $stmt = $db->query(
            "SELECT * FROM persona_members WHERE panel_id = :panel_id ORDER BY id ASC",
            [':panel_id' => $panelId]
        );

        return $stmt->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on an existing member row.
     *
     * @param Database $db   Database instance
     * @param int      $id   Primary key of the member to update
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'role_title', 'prompt_description',
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

        $db->query("UPDATE persona_members SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a member by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the member to delete
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM persona_members WHERE id = :id", [':id' => $id]);
    }
}
