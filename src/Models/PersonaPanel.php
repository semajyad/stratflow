<?php
/**
 * PersonaPanel Model
 *
 * Static data-access methods for the `persona_panels` table.
 * Panels may be system-wide defaults (org_id IS NULL) or org-specific overrides.
 *
 * Columns: id, org_id, panel_type, name, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class PersonaPanel
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new persona panel and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: panel_type, name, org_id (optional — omit for system defaults)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO persona_panels (org_id, panel_type, name)
             VALUES (:org_id, :panel_type, :name)",
            [
                ':org_id'     => $data['org_id'] ?? null,
                ':panel_type' => $data['panel_type'],
                ':name'       => $data['name'],
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all panels belonging to a specific organisation, newest first.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation primary key
     * @return array          Array of panel rows as associative arrays
     */
    public static function findByOrgId(Database $db, int $orgId): array
    {
        $stmt = $db->query(
            "SELECT * FROM persona_panels WHERE org_id = :org_id ORDER BY created_at DESC",
            [':org_id' => $orgId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Return all system-default panels (those with org_id IS NULL), newest first.
     *
     * @param Database $db Database instance
     * @return array       Array of panel rows as associative arrays
     */
    public static function findDefaults(Database $db): array
    {
        $stmt = $db->query(
            "SELECT * FROM persona_panels WHERE org_id IS NULL ORDER BY created_at DESC"
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single panel by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Panel primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM persona_panels WHERE id = :id LIMIT 1",
            [':id' => $id]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on an existing panel row.
     *
     * @param Database $db   Database instance
     * @param int      $id   Primary key of the panel to update
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'panel_type', 'name',
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

        $db->query("UPDATE persona_panels SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a panel by its primary key.
     *
     * CASCADE on persona_members and evaluation_results handles child cleanup.
     *
     * @param Database $db Database instance
     * @param int      $id Primary key of the panel to delete
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM persona_panels WHERE id = :id", [':id' => $id]);
    }
}
