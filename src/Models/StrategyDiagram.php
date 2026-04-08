<?php
/**
 * StrategyDiagram Model
 *
 * Static data-access methods for the `strategy_diagrams` table.
 * Stores versioned Mermaid.js diagram code per project. Multi-tenancy
 * is enforced at the controller level by verifying the project's org_id.
 *
 * Columns: id, project_id, mermaid_code, version, created_by, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class StrategyDiagram
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new strategy diagram row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, mermaid_code, created_by, version (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO strategy_diagrams
                (project_id, mermaid_code, version, created_by)
             VALUES
                (:project_id, :mermaid_code, :version, :created_by)",
            [
                ':project_id'   => $data['project_id'],
                ':mermaid_code' => $data['mermaid_code'],
                ':version'      => $data['version'] ?? 1,
                ':created_by'   => $data['created_by'],
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return the latest diagram for a project (highest version).
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array|null         Row as associative array, or null if none exists
     */
    public static function findByProjectId(Database $db, int $projectId): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM strategy_diagrams
             WHERE project_id = :project_id
             ORDER BY version DESC
             LIMIT 1",
            [':project_id' => $projectId]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Find a single diagram by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Diagram primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM strategy_diagrams WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update a diagram row by ID, incrementing the version number.
     *
     * @param Database $db   Database instance
     * @param int      $id   Diagram primary key
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'mermaid_code',
    ];

    public static function update(Database $db, int $id, array $data): void
    {
        // Filter to allowed columns only to prevent SQL injection via column names
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));

        $setClauses = ['version = version + 1'];
        $bound      = [];
        foreach ($data as $col => $val) {
            $setClauses[] = "`{$col}` = :{$col}";
            $bound[":{$col}"] = $val;
        }

        $bound[':id'] = $id;
        $setStr = implode(', ', $setClauses);

        $db->query("UPDATE strategy_diagrams SET {$setStr} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete all diagrams for a given project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the deletion
     */
    public static function deleteByProjectId(Database $db, int $projectId): void
    {
        $db->query(
            "DELETE FROM strategy_diagrams WHERE project_id = :project_id",
            [':project_id' => $projectId]
        );
    }
}
