<?php

/**
 * StoryQualityConfig Model
 *
 * DAO for the `story_quality_config` table.
 * Stores per-org splitting patterns and mandatory conditions used to
 * inject quality constraints into AI story/epic generation prompts.
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class StoryQualityConfig
{
    /** Default splitting patterns seeded for every new org. */
    private const DEFAULT_PATTERNS = [
        'SPIDR',
        'Happy/Unhappy Path',
        'User Role',
        'Performance Tier',
        'CRUD Operations',
    ];
// ===========================
    // READ
    // ===========================

    public static function findByOrgId(Database $db, int $orgId): array
    {
        $stmt = $db->query("SELECT * FROM story_quality_config
              WHERE org_id = :org_id AND is_active = 1
              ORDER BY rule_type ASC, display_order ASC, id ASC", [':org_id' => $orgId]);
        return $stmt->fetchAll();
    }

    // ===========================
    // CREATE
    // ===========================

    public static function create(Database $db, array $data): int
    {
        $db->query("INSERT INTO story_quality_config (org_id, rule_type, label, is_default, display_order)
             VALUES (:org_id, :rule_type, :label, 0,
                     (SELECT COALESCE(MAX(s.display_order), 0) + 1
                      FROM story_quality_config s
                      WHERE s.org_id = :org_id2 AND s.rule_type = :rule_type2))", [
                ':org_id'     => $data['org_id'],
                ':rule_type'  => $data['rule_type'],
                ':label'      => $data['label'],
                ':org_id2'    => $data['org_id'],
                ':rule_type2' => $data['rule_type'],
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // DELETE
    // ===========================

    public static function delete(Database $db, int $id, int $orgId): void
    {
        $db->query("DELETE FROM story_quality_config
              WHERE id = :id AND org_id = :org_id AND is_default = 0", [':id' => $id, ':org_id' => $orgId]);
    }

    // ===========================
    // SEED
    // ===========================

    public static function seedDefaults(Database $db, int $orgId): void
    {
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM story_quality_config
              WHERE org_id = :org_id AND is_default = 1 AND rule_type = 'splitting_pattern'", [':org_id' => $orgId]);
        $cnt = (int) ($stmt->fetch()['cnt'] ?? 0);
        if ($cnt > 0) {
            return;
        }

        foreach (self::DEFAULT_PATTERNS as $order => $label) {
            $db->query("INSERT INTO story_quality_config (org_id, rule_type, label, is_default, display_order)
                 VALUES (:org_id, 'splitting_pattern', :label, 1, :ord)", [':org_id' => $orgId, ':label' => $label, ':ord' => $order + 1]);
        }
    }

    // ===========================
    // HELPERS
    // ===========================

    public static function buildPromptBlock(Database $db, int $orgId): string
    {
        $rows = self::findByOrgId($db, $orgId);
        if (empty($rows)) {
            return '';
        }

        $patterns   = array_filter($rows, fn($r) => $r['rule_type'] === 'splitting_pattern');
        $conditions = array_filter($rows, fn($r) => $r['rule_type'] === 'mandatory_condition');
        $patternLabels = implode(', ', array_column(array_values($patterns), 'label'));
        $block  = "\n--- ORG QUALITY RULES ---\n";
        $block .= "Splitting patterns available: {$patternLabels}\n";
        if (!empty($conditions)) {
            $block .= "Mandatory conditions:\n";
            foreach ($conditions as $c) {
                $block .= "  - " . $c['label'] . "\n";
            }
        }

        $block .= "-------------------------\n";
        return $block;
    }
}
