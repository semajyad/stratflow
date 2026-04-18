<?php

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class BoardReview
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new board review row and return its ID.
     *
     * @param  Database $db   Database instance
     * @param  array    $data Keys: project_id, panel_id, board_type, evaluation_level,
     *                        screen_context, content_snapshot, conversation_json,
     *                        recommendation_json, proposed_changes
     * @return int            Inserted row ID
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO board_reviews
                (project_id, panel_id, board_type, evaluation_level, screen_context,
                 content_snapshot, conversation_json, recommendation_json, proposed_changes, status)
             VALUES
                (:project_id, :panel_id, :board_type, :evaluation_level, :screen_context,
                 :content_snapshot, :conversation_json, :recommendation_json, :proposed_changes, 'pending')",
            [
                ':project_id'          => $data['project_id'],
                ':panel_id'            => $data['panel_id'],
                ':board_type'          => $data['board_type'],
                ':evaluation_level'    => $data['evaluation_level'],
                ':screen_context'      => $data['screen_context'],
                ':content_snapshot'    => $data['content_snapshot'],
                ':conversation_json'   => $data['conversation_json'],
                ':recommendation_json' => $data['recommendation_json'],
                ':proposed_changes'    => $data['proposed_changes'],
            ]
        );
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Find a single board review by primary key.
     *
     * @param  Database  $db Database instance
     * @param  int       $id Row primary key
     * @return array|null    Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM board_reviews WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a single board review with a FOR UPDATE lock (use inside a transaction).
     *
     * Prevents TOCTOU races when two concurrent accept requests check status simultaneously.
     *
     * @param  Database  $db Database instance
     * @param  int       $id Row primary key
     * @return array|null    Row as associative array, or null if not found
     */
    public static function findByIdForUpdate(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM board_reviews WHERE id = :id LIMIT 1 FOR UPDATE",
            [':id' => $id]
        );
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Return all board reviews for a project, newest first.
     *
     * @param  Database $db        Database instance
     * @param  int      $projectId Project primary key
     * @return array               Array of rows
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT * FROM board_reviews WHERE project_id = :project_id ORDER BY created_at DESC",
            [':project_id' => $projectId]
        );
        return $stmt->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Set the status and record who responded and when.
     *
     * @param Database  $db          Database instance
     * @param int       $id          Row primary key
     * @param string    $status      'accepted' or 'rejected'
     * @param int|null  $respondedBy User ID of the person responding
     */
    public static function updateStatus(Database $db, int $id, string $status, ?int $respondedBy = null): void
    {
        $db->query(
            "UPDATE board_reviews
             SET status = :status, responded_by = :responded_by, responded_at = NOW()
             WHERE id = :id",
            [
                ':status'       => $status,
                ':responded_by' => $respondedBy,
                ':id'           => $id,
            ]
        );
    }
}
