<?php

/**
 * Sprint Model
 *
 * Static data-access methods for the `sprints` table.
 * Stores sprint definitions with date ranges and team capacity,
 * used for allocating user stories into time-boxed iterations.
 *
 * Columns: id, project_id, name, start_date, end_date, team_capacity,
 *          created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class Sprint
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new sprint and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, name, start_date, end_date, team_capacity
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query("INSERT INTO sprints (project_id, name, start_date, end_date, team_capacity, team_id)
             VALUES (:project_id, :name, :start_date, :end_date, :team_capacity, :team_id)", [
                ':project_id'    => $data['project_id'],
                ':name'          => $data['name'],
                ':start_date'    => $data['start_date'] ?? null,
                ':end_date'      => $data['end_date'] ?? null,
                ':team_capacity' => $data['team_capacity'] ?? null,
                ':team_id'       => $data['team_id'] ?? null,
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all sprints for a project, ordered by start_date ascending.
     * Includes story count and total size via subquery.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of sprint rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT s.*,
                    t.name AS team_name,
                    COALESCE(sub.story_count, 0) AS story_count,
                    COALESCE(sub.total_size, 0)  AS total_size
             FROM sprints s
             LEFT JOIN teams t ON s.team_id = t.id
             LEFT JOIN (
                 SELECT ss.sprint_id,
                        COUNT(*)          AS story_count,
                        SUM(COALESCE(us.size, 0)) AS total_size
                 FROM sprint_stories ss
                 JOIN user_stories us ON ss.user_story_id = us.id
                 GROUP BY ss.sprint_id
             ) sub ON s.id = sub.sprint_id
             WHERE s.project_id = :project_id
             ORDER BY t.name ASC, s.start_date ASC, s.id ASC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Find a single sprint by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Sprint primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query("SELECT * FROM sprints WHERE id = :id LIMIT 1", [':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on a sprint by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   Sprint primary key
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'name', 'start_date', 'end_date', 'team_capacity', 'team_id',
    ];
    public static function update(Database $db, int $id, array $data): void
    {
        // Filter to allowed columns only to prevent SQL injection via column names
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));
        if (empty($data)) {
            return;
        }

        $setClauses = implode(', ', array_map(fn($col) => "`{$col}` = :{$col}", array_keys($data)));
        $bound = [];
        foreach ($data as $col => $val) {
            $bound[":{$col}"] = $val;
        }
        $bound[':id'] = $id;
        $db->query("UPDATE sprints SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a sprint by ID. CASCADE on sprint_stories returns stories to backlog.
     *
     * @param Database $db Database instance
     * @param int      $id Sprint primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM sprints WHERE id = :id", [':id' => $id]);
    }
}
