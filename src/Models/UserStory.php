<?php
/**
 * UserStory Model
 *
 * Static data-access methods for the `user_stories` table.
 * Stores user stories decomposed from high-level work items,
 * with size estimates, team assignments, and dependency tracking.
 *
 * Columns: id, project_id, parent_hl_item_id, priority_number, title,
 *          description, parent_link, team_assigned, size, blocked_by,
 *          created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class UserStory
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new user story and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, parent_hl_item_id, priority_number,
     *                       title, description, parent_link, team_assigned, size, blocked_by
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO user_stories
                (project_id, parent_hl_item_id, priority_number, title, description,
                 parent_link, team_assigned, size, blocked_by, status)
             VALUES
                (:project_id, :parent_hl_item_id, :priority_number, :title, :description,
                 :parent_link, :team_assigned, :size, :blocked_by, :status)",
            [
                ':project_id'        => $data['project_id'],
                ':parent_hl_item_id' => $data['parent_hl_item_id'] ?? null,
                ':priority_number'   => $data['priority_number'],
                ':title'             => $data['title'],
                ':description'       => $data['description'] ?? null,
                ':parent_link'       => $data['parent_link'] ?? null,
                ':team_assigned'     => $data['team_assigned'] ?? null,
                ':size'              => $data['size'] ?? null,
                ':blocked_by'        => $data['blocked_by'] ?? null,
                ':status'            => $data['status'] ?? 'backlog',
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all user stories for a project, ordered by priority ascending.
     * Includes parent HL item title via LEFT JOIN.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of story rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT us.*, hw.title AS parent_title,
                    sm.external_key AS jira_key, sm.external_url AS jira_url
             FROM user_stories us
             LEFT JOIN hl_work_items hw ON us.parent_hl_item_id = hw.id
             LEFT JOIN sync_mappings sm ON sm.local_type = 'user_story' AND sm.local_id = us.id
             WHERE us.project_id = :project_id
             ORDER BY us.priority_number ASC",
            [':project_id' => $projectId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Return all user stories for a given parent HL work item.
     *
     * @param Database $db       Database instance
     * @param int      $parentId Parent HL work item ID
     * @return array             Array of story rows as associative arrays
     */
    public static function findByParentId(Database $db, int $parentId): array
    {
        $stmt = $db->query(
            "SELECT us.*, hw.title AS parent_title
             FROM user_stories us
             LEFT JOIN hl_work_items hw ON us.parent_hl_item_id = hw.id
             WHERE us.parent_hl_item_id = :parent_id
             ORDER BY us.priority_number ASC",
            [':parent_id' => $parentId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single user story by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id User story primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT us.*, hw.title AS parent_title
             FROM user_stories us
             LEFT JOIN hl_work_items hw ON us.parent_hl_item_id = hw.id
             WHERE us.id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Return user stories NOT allocated to any sprint.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of unallocated story rows
     */
    public static function findUnallocated(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT us.*, hw.title AS parent_title
             FROM user_stories us
             LEFT JOIN hl_work_items hw ON us.parent_hl_item_id = hw.id
             LEFT JOIN sprint_stories ss ON us.id = ss.user_story_id
             WHERE us.project_id = :project_id
               AND ss.id IS NULL
             ORDER BY us.priority_number ASC",
            [':project_id' => $projectId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Count user stories for a project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return int                Number of stories
     */
    public static function countByProjectId(Database $db, int $projectId): int
    {
        $stmt = $db->query(
            "SELECT COUNT(*) AS cnt FROM user_stories WHERE project_id = :project_id",
            [':project_id' => $projectId]
        );
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on a user story by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   User story primary key
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'priority_number', 'title', 'description', 'parent_hl_item_id',
        'parent_link', 'team_assigned', 'size', 'blocked_by', 'requires_review', 'status',
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

        $db->query("UPDATE user_stories SET {$setClauses} WHERE id = :id", $bound);
    }

    /**
     * Batch-update priority numbers for multiple user stories in a transaction.
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
                $db->query(
                    "UPDATE user_stories SET priority_number = :priority_number WHERE id = :id",
                    [
                        ':priority_number' => $item['priority_number'],
                        ':id'              => $item['id'],
                    ]
                );
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
     * Delete a single user story by ID.
     *
     * @param Database $db Database instance
     * @param int      $id User story primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM user_stories WHERE id = :id", [':id' => $id]);
    }

    /**
     * Delete all user stories for a given project.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the deletion
     */
    public static function deleteByProjectId(Database $db, int $projectId): void
    {
        $db->query(
            "DELETE FROM user_stories WHERE project_id = :project_id",
            [':project_id' => $projectId]
        );
    }
}
