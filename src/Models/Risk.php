<?php
/**
 * Risk Model
 *
 * Static data-access methods for the `risks` table.
 * Stores project risks with likelihood/impact scores, AI-generated
 * mitigation strategies, and links to high-level work items.
 *
 * Columns: id, project_id, title, description, likelihood, impact,
 *          mitigation, priority, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class Risk
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new risk and return its ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, title, description, likelihood, impact,
     *                       mitigation (optional), priority (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO risks
                (project_id, title, description, likelihood, impact, mitigation, priority)
             VALUES
                (:project_id, :title, :description, :likelihood, :impact, :mitigation, :priority)",
            [
                ':project_id'  => $data['project_id'],
                ':title'       => $data['title'],
                ':description' => $data['description'] ?? null,
                ':likelihood'  => $data['likelihood'] ?? 3,
                ':impact'      => $data['impact'] ?? 3,
                ':mitigation'  => $data['mitigation'] ?? null,
                ':priority'    => $data['priority'] ?? null,
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all risks for a project, ordered by priority descending (RPN).
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of risk rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT * FROM risks
             WHERE project_id = :project_id
             ORDER BY (likelihood * impact) DESC, created_at DESC",
            [':project_id' => $projectId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single risk by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Risk primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM risks WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on a risk row by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   Risk primary key
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

        $db->query("UPDATE risks SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a single risk by ID. CASCADE deletes associated risk_item_links.
     *
     * @param Database $db Database instance
     * @param int      $id Risk primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM risks WHERE id = :id", [':id' => $id]);
    }

    /**
     * Delete all risks for a given project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the deletion
     */
    public static function deleteByProjectId(Database $db, int $projectId): void
    {
        $db->query(
            "DELETE FROM risks WHERE project_id = :project_id",
            [':project_id' => $projectId]
        );
    }
}
