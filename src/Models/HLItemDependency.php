<?php

/**
 * HLItemDependency Model
 *
 * Static data-access methods for the `hl_item_dependencies` table.
 * Records which work items must be completed before another can begin.
 * A row (item_id, depends_on_id) means "item_id depends on depends_on_id".
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class HLItemDependency
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a single dependency record and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: item_id, depends_on_id, dependency_type ('hard'|'soft')
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query("INSERT INTO hl_item_dependencies (item_id, depends_on_id, dependency_type)
             VALUES (:item_id, :depends_on_id, :dependency_type)
             ON DUPLICATE KEY UPDATE dependency_type = VALUES(dependency_type)", [
                ':item_id'         => $data['item_id'],
                ':depends_on_id'   => $data['depends_on_id'],
                ':dependency_type' => $data['dependency_type'] ?? 'hard',
            ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Create a batch of dependencies for a single item, replacing existing ones.
     *
     * Deletes all current dependencies for the item first, then inserts the
     * new set. Skips any depends_on_id that equals item_id (self-reference).
     *
     * @param Database $db           Database instance
     * @param int      $itemId       The item that has these dependencies
     * @param array    $dependsOnIds Array of work item IDs this item depends on
     */
    public static function createBatch(Database $db, int $itemId, array $dependsOnIds): void
    {
        self::deleteByItemId($db, $itemId);
        foreach ($dependsOnIds as $dependsOnId) {
            $dependsOnId = (int) $dependsOnId;
        // Skip self-references
            if ($dependsOnId === $itemId || $dependsOnId === 0) {
                continue;
            }

            self::create($db, [
                'item_id'         => $itemId,
                'depends_on_id'   => $dependsOnId,
                'dependency_type' => 'hard',
            ]);
        }
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all dependencies for a given item — items that must be done first.
     *
     * Each row includes the title of the blocking work item for display.
     *
     * @param Database $db     Database instance
     * @param int      $itemId Work item primary key
     * @return array           Array of dependency rows with depends_on title
     */
    public static function findByItemId(Database $db, int $itemId): array
    {
        $stmt = $db->query("SELECT d.*, w.title AS depends_on_title, w.priority_number AS depends_on_priority
             FROM hl_item_dependencies d
             JOIN hl_work_items w ON w.id = d.depends_on_id
             WHERE d.item_id = :item_id
             ORDER BY w.priority_number ASC", [':item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    /**
     * Return all items that depend on a given item — its downstream consumers.
     *
     * Each row includes the title of the dependent work item for display.
     *
     * @param Database $db     Database instance
     * @param int      $itemId Work item primary key (the blocking item)
     * @return array           Array of dependency rows with dependent item title
     */
    public static function findDependentsOf(Database $db, int $itemId): array
    {
        $stmt = $db->query("SELECT d.*, w.title AS dependent_title, w.priority_number AS dependent_priority
             FROM hl_item_dependencies d
             JOIN hl_work_items w ON w.id = d.item_id
             WHERE d.depends_on_id = :item_id
             ORDER BY w.priority_number ASC", [':item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete all dependency rows where the given item is the dependent.
     *
     * Used before creating a fresh batch of dependencies for an item.
     *
     * @param Database $db     Database instance
     * @param int      $itemId Work item primary key
     */
    public static function deleteByItemId(Database $db, int $itemId): void
    {
        $db->query("DELETE FROM hl_item_dependencies WHERE item_id = :item_id", [':item_id' => $itemId]);
    }
}
