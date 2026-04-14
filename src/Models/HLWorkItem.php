<?php

/**
 * HLWorkItem Model
 *
 * Static data-access methods for the `hl_work_items` table.
 * Stores prioritised high-level work items generated from strategy
 * diagrams and OKR data. Multi-tenancy is enforced at the controller
 * level by verifying the project's org_id.
 *
 * Columns: id, project_id, diagram_id, priority_number, title, description,
 *          strategic_context, okr_title, okr_description, acceptance_criteria,
 *          kr_hypothesis, owner, estimated_sprints, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class HLWorkItem
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new work item and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, priority_number, title, description,
     *                       strategic_context, okr_title, okr_description, owner,
     *                       estimated_sprints, acceptance_criteria, kr_hypothesis,
     *                       diagram_id (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query("INSERT INTO hl_work_items
                (project_id, diagram_id, priority_number, title, description,
                 strategic_context, okr_title, okr_description, owner, estimated_sprints,
                 acceptance_criteria, kr_hypothesis, quality_score, quality_breakdown, status)
             VALUES
                (:project_id, :diagram_id, :priority_number, :title, :description,
                 :strategic_context, :okr_title, :okr_description, :owner, :estimated_sprints,
                 :acceptance_criteria, :kr_hypothesis, :quality_score, :quality_breakdown, :status)", [
                ':project_id'          => $data['project_id'],
                ':diagram_id'          => $data['diagram_id'] ?? null,
                ':priority_number'     => $data['priority_number'],
                ':title'               => $data['title'],
                ':description'         => $data['description'] ?? null,
                ':strategic_context'   => $data['strategic_context'] ?? null,
                ':okr_title'           => $data['okr_title'] ?? null,
                ':okr_description'     => $data['okr_description'] ?? null,
                ':owner'               => $data['owner'] ?? null,
                ':estimated_sprints'   => $data['estimated_sprints'] ?? 2,
                ':acceptance_criteria' => $data['acceptance_criteria'] ?? null,
                ':kr_hypothesis'       => $data['kr_hypothesis'] ?? null,
                ':quality_score'       => $data['quality_score'] ?? null,
                ':quality_breakdown'   => $data['quality_breakdown'] ?? null,
                ':status'              => $data['status'] ?? 'backlog',
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all work items for a project, ordered by priority ascending.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of work item rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT hw.*, sm.external_key AS jira_key, sm.external_url AS jira_url
             FROM hl_work_items hw
             LEFT JOIN sync_mappings sm ON sm.local_type = 'hl_work_item' AND sm.local_id = hw.id
             WHERE hw.project_id = :project_id
             ORDER BY hw.priority_number ASC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Find a single work item by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Work item primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query("SELECT * FROM hl_work_items WHERE id = :id LIMIT 1", [':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on a work item row by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   Work item primary key
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'priority_number', 'title', 'description', 'strategic_context',
        'okr_title', 'okr_description', 'acceptance_criteria', 'kr_hypothesis', 'quality_score', 'quality_breakdown',
        'quality_status', 'quality_scored_at', 'quality_attempts',
        'quality_last_attempt_at', 'quality_error',
        'owner', 'team_assigned', 'estimated_sprints',
        'rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort',
        'wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size',
        'final_score', 'requires_review', 'status', 'last_jira_sync_at',
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
        $db->query("UPDATE hl_work_items SET {$setClauses} WHERE id = :id", $bound);
    }

    /**
     * Batch-update priority numbers for multiple work items in a transaction.
     *
     * @param Database $db    Database instance
     * @param array    $items Array of arrays with keys: id, priority_number
     */
    public static function batchUpdatePriority(Database $db, array $items): void
    {
        $pdo = $db->getPdo();
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $db->query("UPDATE hl_work_items SET priority_number = :priority_number WHERE id = :id", [
                        ':priority_number' => $item['priority_number'],
                        ':id'              => $item['id'],
                    ]);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ===========================
    // SCORING
    // ===========================

    /**
     * Update RICE/WSJF score columns and final_score for a single work item.
     *
     * @param Database $db     Database instance
     * @param int      $id     Work item primary key
     * @param array    $scores Scoring columns to update (e.g. rice_reach, final_score)
     */
    public static function updateScores(Database $db, int $id, array $scores): void
    {
        // Only allow known scoring columns
        $allowed = [
            'rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort',
            'wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size',
            'final_score',
        ];
        $filtered = array_intersect_key($scores, array_flip($allowed));
        if (empty($filtered)) {
            return;
        }

        self::update($db, $id, $filtered);
    }

    /**
     * Return all work items for a project, ordered by final_score descending.
     *
     * Items with NULL or zero final_score appear last.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of work item rows as associative arrays
     */
    public static function findByProjectIdRankedByScore(Database $db, int $projectId): array
    {
        $stmt = $db->query("SELECT hw.*, sm.external_key AS jira_key, sm.external_url AS jira_url
             FROM hl_work_items hw
             LEFT JOIN sync_mappings sm ON sm.local_type = 'hl_work_item' AND sm.local_id = hw.id
             WHERE hw.project_id = :project_id
             ORDER BY COALESCE(hw.final_score, 0) DESC, hw.priority_number ASC", [':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Batch-update scores for multiple work items in a transaction.
     *
     * @param Database $db    Database instance
     * @param array    $items Array of arrays with keys: id, scores (assoc array)
     */
    public static function batchUpdateScores(Database $db, array $items): void
    {
        $pdo = $db->getPdo();
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                self::updateScores($db, (int) $item['id'], $item['scores']);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a single work item by ID.
     *
     * @param Database $db Database instance
     * @param int      $id Work item primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM hl_work_items WHERE id = :id", [':id' => $id]);
    }

    /**
     * Delete all work items for a given project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the deletion
     */
    public static function deleteByProjectId(Database $db, int $projectId): void
    {
        $db->query("DELETE FROM hl_work_items WHERE project_id = :project_id", [':project_id' => $projectId]);
    }

    // ===========================
    // QUALITY STATE HELPERS
    // ===========================

    /**
     * Enqueue a work item for async quality scoring by the background worker.
     * Resets attempts and clears any previous error.
     */
    public static function markQualityPending(Database $db, int $id): void
    {
        self::update($db, $id, [
            'quality_status'   => 'pending',
            'quality_attempts' => 0,
            'quality_error'    => null,
        ]);
    }

    /**
     * Record a successful quality score from the background worker.
     */
    public static function markQualityScored(Database $db, int $id, int $score, ?array $breakdown): void
    {
        self::update($db, $id, [
            'quality_score'     => $score,
            'quality_breakdown' => $breakdown !== null ? json_encode($breakdown) : null,
            'quality_status'    => 'scored',
            'quality_scored_at' => date('Y-m-d H:i:s'),
            'quality_error'     => null,
        ]);
    }

    /**
     * Record a failed scoring attempt from the background worker.
     *
     * @param string $error Short error key, e.g. 'schema:invest' or 'exc:RuntimeException'
     */
    public static function markQualityFailed(Database $db, int $id, int $attempts, string $error): void
    {
        self::update($db, $id, [
            'quality_status'          => 'failed',
            'quality_attempts'        => $attempts,
            'quality_last_attempt_at' => date('Y-m-d H:i:s'),
            'quality_error'           => mb_substr($error, 0, 500),
        ]);
    }
}
