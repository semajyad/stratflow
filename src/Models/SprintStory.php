<?php

/**
 * SprintStory Model
 *
 * Static data-access methods for the `sprint_stories` junction table.
 * Links user stories to sprints for allocation and capacity tracking.
 *
 * Columns: id, sprint_id, user_story_id, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class SprintStory
{
    // ===========================
    // ASSIGN / UNASSIGN
    // ===========================

    /**
     * Assign a user story to a sprint. Ignores if already assigned.
     *
     * @param Database $db          Database instance
     * @param int      $sprintId    Sprint to assign into
     * @param int      $userStoryId User story to assign
     */
    public static function assign(Database $db, int $sprintId, int $userStoryId): void
    {
        $db->query("INSERT IGNORE INTO sprint_stories (sprint_id, user_story_id)
             VALUES (:sprint_id, :user_story_id)", [
                ':sprint_id'     => $sprintId,
                ':user_story_id' => $userStoryId,
            ]);
    }

    /**
     * Remove a user story from a sprint (return to backlog).
     *
     * @param Database $db          Database instance
     * @param int      $sprintId    Sprint to unassign from
     * @param int      $userStoryId User story to unassign
     */
    public static function unassign(Database $db, int $sprintId, int $userStoryId): void
    {
        $db->query("DELETE FROM sprint_stories
             WHERE sprint_id = :sprint_id AND user_story_id = :user_story_id", [
                ':sprint_id'     => $sprintId,
                ':user_story_id' => $userStoryId,
            ]);
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all user stories assigned to a sprint, with story data via JOIN.
     *
     * @param Database $db       Database instance
     * @param int      $sprintId Sprint ID
     * @return array             Array of user story rows
     */
    public static function findBySprintId(Database $db, int $sprintId): array
    {
        $stmt = $db->query("SELECT us.*, hw.title AS parent_title
             FROM sprint_stories ss
             JOIN user_stories us ON ss.user_story_id = us.id
             LEFT JOIN hl_work_items hw ON us.parent_hl_item_id = hw.id
             WHERE ss.sprint_id = :sprint_id
             ORDER BY us.priority_number ASC", [':sprint_id' => $sprintId]);
        return $stmt->fetchAll();
    }

    /**
     * Find which sprint a story is assigned to, if any.
     *
     * @param Database $db      Database instance
     * @param int      $storyId User story ID
     * @return array|null       Sprint row, or null if unallocated
     */
    public static function findSprintForStory(Database $db, int $storyId): ?array
    {
        $stmt = $db->query("SELECT s.*
             FROM sprint_stories ss
             JOIN sprints s ON ss.sprint_id = s.id
             WHERE ss.user_story_id = :story_id
             LIMIT 1", [':story_id' => $storyId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Delete all sprint_stories entries for a sprint.
     *
     * @param Database $db       Database instance
     * @param int      $sprintId Sprint ID
     */
    public static function deleteBySprintId(Database $db, int $sprintId): void
    {
        $db->query("DELETE FROM sprint_stories WHERE sprint_id = :sprint_id", [':sprint_id' => $sprintId]);
    }

    /**
     * Get total story points allocated to a sprint.
     *
     * @param Database $db       Database instance
     * @param int      $sprintId Sprint ID
     * @return int               Sum of story sizes in the sprint
     */
    public static function getSprintLoad(Database $db, int $sprintId): int
    {
        $stmt = $db->query("SELECT COALESCE(SUM(us.size), 0) AS total_load
             FROM sprint_stories ss
             JOIN user_stories us ON ss.user_story_id = us.id
             WHERE ss.sprint_id = :sprint_id", [':sprint_id' => $sprintId]);
        $row = $stmt->fetch();
        return (int) ($row['total_load'] ?? 0);
    }
}
