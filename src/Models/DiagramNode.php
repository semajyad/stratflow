<?php

/**
 * DiagramNode Model
 *
 * Static data-access methods for the `diagram_nodes` table.
 * Each node corresponds to a parsed element from a Mermaid.js diagram,
 * with optional OKR (Objective & Key Result) fields.
 *
 * Columns: id, diagram_id, node_key, label, okr_title, okr_description
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class DiagramNode
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Batch-insert multiple nodes for a diagram.
     *
     * @param Database $db        Database instance
     * @param int      $diagramId Parent diagram ID
     * @param array    $nodes     Array of arrays, each with keys: node_key, label
     */
    public static function createBatch(Database $db, int $diagramId, array $nodes): void
    {
        foreach ($nodes as $node) {
            $db->query("INSERT INTO diagram_nodes (diagram_id, node_key, label)
                 VALUES (:diagram_id, :node_key, :label)", [
                    ':diagram_id' => $diagramId,
                    ':node_key'   => $node['node_key'],
                    ':label'      => $node['label'],
                ]);
        }
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all nodes belonging to a diagram.
     *
     * @param Database $db        Database instance
     * @param int      $diagramId Diagram ID to scope the query
     * @return array              Array of node rows as associative arrays
     */
    public static function findByDiagramId(Database $db, int $diagramId): array
    {
        $stmt = $db->query("SELECT * FROM diagram_nodes WHERE diagram_id = :diagram_id ORDER BY id ASC", [':diagram_id' => $diagramId]);
        return $stmt->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update a single node's fields (typically OKR data).
     *
     * @param Database $db   Database instance
     * @param int      $id   Node primary key
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'label', 'okr_title', 'okr_description',
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
        $db->query("UPDATE diagram_nodes SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete all nodes belonging to a diagram.
     *
     * @param Database $db        Database instance
     * @param int      $diagramId Diagram ID to scope the deletion
     */
    public static function deleteByDiagramId(Database $db, int $diagramId): void
    {
        $db->query("DELETE FROM diagram_nodes WHERE diagram_id = :diagram_id", [':diagram_id' => $diagramId]);
    }

    /**
     * Delete a single node by ID.
     *
     * @param Database $db Database instance
     * @param int      $id Node primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM diagram_nodes WHERE id = :id", [':id' => $id]);
    }
}
