<?php

/**
 * RiskItemLink Model
 *
 * Static data-access methods for the `risk_item_links` junction table.
 * Links risks to high-level work items (many-to-many).
 *
 * Columns: id, risk_id, work_item_id
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class RiskItemLink
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert multiple links between a risk and work items.
     *
     * Uses INSERT IGNORE to skip duplicates (unique constraint on risk_id + work_item_id).
     *
     * @param Database $db          Database instance
     * @param int      $riskId      Risk ID to link from
     * @param array    $workItemIds Array of work item IDs to link to
     */
    public static function createLinks(Database $db, int $riskId, array $workItemIds): void
    {
        if (empty($workItemIds)) {
            return;
        }

        foreach ($workItemIds as $workItemId) {
            $db->query("INSERT IGNORE INTO risk_item_links (risk_id, work_item_id)
                 VALUES (:risk_id, :work_item_id)", [
                    ':risk_id'      => $riskId,
                    ':work_item_id' => (int) $workItemId,
                ]);
        }
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all work item IDs linked to a given risk.
     *
     * @param Database $db     Database instance
     * @param int      $riskId Risk ID
     * @return array           Array of work_item_id integers
     */
    public static function findByRiskId(Database $db, int $riskId): array
    {
        $stmt = $db->query("SELECT work_item_id FROM risk_item_links WHERE risk_id = :risk_id", [':risk_id' => $riskId]);
        return array_column($stmt->fetchAll(), 'work_item_id');
    }

    /**
     * Return all risk IDs linked to a given work item.
     *
     * @param Database $db         Database instance
     * @param int      $workItemId Work item ID
     * @return array               Array of risk_id integers
     */
    public static function findByWorkItemId(Database $db, int $workItemId): array
    {
        $stmt = $db->query("SELECT risk_id FROM risk_item_links WHERE work_item_id = :work_item_id", [':work_item_id' => $workItemId]);
        return array_column($stmt->fetchAll(), 'risk_id');
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete all links for a given risk.
     *
     * @param Database $db     Database instance
     * @param int      $riskId Risk ID
     */
    public static function deleteByRiskId(Database $db, int $riskId): void
    {
        $db->query("DELETE FROM risk_item_links WHERE risk_id = :risk_id", [':risk_id' => $riskId]);
    }
}
