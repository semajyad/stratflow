<?php
declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class KeyResult
{
    /** @var string[] Columns safe for dynamic UPDATE */
    private const UPDATABLE_COLUMNS = [
        'title', 'metric_description', 'baseline_value', 'target_value',
        'current_value', 'unit', 'status', 'display_order',
        'jira_goal_id', 'jira_goal_url', 'ai_momentum',
    ];

    // ===========================
    // CREATE
    // ===========================

    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO key_results
                (org_id, hl_work_item_id, title, metric_description,
                 baseline_value, target_value, current_value, unit, status, display_order)
             VALUES
                (:org_id, :hl_work_item_id, :title, :metric_description,
                 :baseline_value, :target_value, :current_value, :unit, :status, :display_order)",
            [
                ':org_id'             => $data['org_id'],
                ':hl_work_item_id'    => $data['hl_work_item_id'],
                ':title'              => $data['title'],
                ':metric_description' => $data['metric_description'] ?? null,
                ':baseline_value'     => isset($data['baseline_value']) && $data['baseline_value'] !== '' ? (float) $data['baseline_value'] : null,
                ':target_value'       => isset($data['target_value'])   && $data['target_value']   !== '' ? (float) $data['target_value']   : null,
                ':current_value'      => isset($data['current_value'])  && $data['current_value']  !== '' ? (float) $data['current_value']  : null,
                ':unit'               => $data['unit'] ?? null,
                ':status'             => $data['status'] ?? 'not_started',
                ':display_order'      => $data['display_order'] ?? 0,
            ]
        );
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    public static function findByWorkItemId(Database $db, int $workItemId, int $orgId): array
    {
        return $db->query(
            "SELECT * FROM key_results
              WHERE hl_work_item_id = :wid AND org_id = :oid
              ORDER BY display_order ASC, id ASC",
            [':wid' => $workItemId, ':oid' => $orgId]
        )->fetchAll();
    }

    public static function findById(Database $db, int $id, int $orgId): ?array
    {
        $row = $db->query(
            "SELECT * FROM key_results WHERE id = :id AND org_id = :oid LIMIT 1",
            [':id' => $id, ':oid' => $orgId]
        )->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Load all KRs for OKR-bearing work items in a project, with work item context.
     * Used by the executive project dashboard.
     */
    public static function findByProjectOkrs(Database $db, int $projectId, int $orgId): array
    {
        return $db->query(
            "SELECT kr.*, hwi.title AS work_item_title, hwi.okr_title, hwi.id AS work_item_id,
                    hwi.priority_number, hwi.status AS work_item_status
               FROM key_results kr
               JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
               JOIN projects p        ON hwi.project_id = p.id AND p.org_id = :oid
              WHERE hwi.project_id = :pid
                AND kr.org_id = :oid
                AND hwi.okr_title IS NOT NULL
                AND hwi.okr_title != ''
              ORDER BY hwi.priority_number ASC, kr.display_order ASC",
            [':pid' => $projectId, ':oid' => $orgId]
        )->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    public static function update(Database $db, int $id, int $orgId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));
        if (empty($data)) {
            return;
        }
        $sets   = implode(', ', array_map(fn($col) => "`{$col}` = :{$col}", array_keys($data)));
        $params = array_combine(array_map(fn($k) => ":{$k}", array_keys($data)), array_values($data));
        $params[':id']  = $id;
        $params[':oid'] = $orgId;
        $db->query("UPDATE key_results SET {$sets} WHERE id = :id AND org_id = :oid", $params);
    }

    // ===========================
    // DELETE
    // ===========================

    public static function delete(Database $db, int $id, int $orgId): void
    {
        $db->query(
            "DELETE FROM key_results WHERE id = :id AND org_id = :oid",
            [':id' => $id, ':oid' => $orgId]
        );
    }
}
