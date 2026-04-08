<?php
/**
 * EvaluationResult Model
 *
 * Static data-access methods for the `evaluation_results` table.
 * Stores the structured JSON output from sounding-board evaluations, linked to a
 * project and the panel that performed the evaluation.
 *
 * Columns: id, project_id, panel_id, evaluation_level, screen_context,
 *          results_json, status, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class EvaluationResult
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new evaluation result row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: project_id, panel_id, evaluation_level,
     *                       screen_context, results_json, status (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO evaluation_results
                (project_id, panel_id, evaluation_level, screen_context, results_json, status)
             VALUES
                (:project_id, :panel_id, :evaluation_level, :screen_context, :results_json, :status)",
            [
                ':project_id'       => $data['project_id'],
                ':panel_id'         => $data['panel_id'],
                ':evaluation_level' => $data['evaluation_level'],
                ':screen_context'   => $data['screen_context'],
                ':results_json'     => $data['results_json'],
                ':status'           => $data['status'] ?? 'pending',
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all evaluation results for a project, newest first.
     *
     * @param Database $db       Database instance
     * @param int      $projectId Project primary key
     * @return array             Array of result rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT * FROM evaluation_results
             WHERE project_id = :project_id
             ORDER BY created_at DESC",
            [':project_id' => $projectId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single evaluation result by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Result primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM evaluation_results WHERE id = :id LIMIT 1",
            [':id' => $id]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Return all evaluation results for a project scoped to a specific screen context,
     * newest first.
     *
     * @param Database $db           Database instance
     * @param int      $projectId    Project primary key
     * @param string   $screenContext Screen identifier (e.g. 'prioritisation', 'strategy')
     * @return array                 Array of result rows as associative arrays
     */
    public static function findByProjectAndScreen(Database $db, int $projectId, string $screenContext): array
    {
        $stmt = $db->query(
            "SELECT * FROM evaluation_results
             WHERE project_id = :project_id
               AND screen_context = :screen_context
             ORDER BY created_at DESC",
            [
                ':project_id'     => $projectId,
                ':screen_context' => $screenContext,
            ]
        );

        return $stmt->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update the status field of an evaluation result.
     *
     * @param Database $db     Database instance
     * @param int      $id     Primary key of the row to update
     * @param string   $status New status value: 'pending', 'accepted', 'rejected', or 'partial'
     */
    public static function updateStatus(Database $db, int $id, string $status): void
    {
        $db->query(
            "UPDATE evaluation_results SET status = :status WHERE id = :id",
            [
                ':status' => $status,
                ':id'     => $id,
            ]
        );
    }
}
