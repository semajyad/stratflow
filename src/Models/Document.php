<?php
/**
 * Document Model
 *
 * Static data-access methods for the `documents` table.
 * All queries are scoped by project_id. Multi-tenancy is enforced
 * at the controller level by verifying the project's org_id.
 *
 * Columns: id, project_id, filename, original_name, mime_type,
 *          file_size, extracted_text, ai_summary, uploaded_by, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class Document
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new document row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, filename, original_name, mime_type,
     *                       file_size, uploaded_by, extracted_text (optional), ai_summary (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO documents
                (project_id, filename, original_name, mime_type, file_size, extracted_text, ai_summary, uploaded_by)
             VALUES
                (:project_id, :filename, :original_name, :mime_type, :file_size, :extracted_text, :ai_summary, :uploaded_by)",
            [
                ':project_id'    => $data['project_id'],
                ':filename'      => $data['filename'],
                ':original_name' => $data['original_name'],
                ':mime_type'     => $data['mime_type'],
                ':file_size'     => $data['file_size'],
                ':extracted_text' => $data['extracted_text'] ?? null,
                ':ai_summary'    => $data['ai_summary'] ?? null,
                ':uploaded_by'   => $data['uploaded_by'],
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all documents for a given project, newest first.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project ID to scope the query
     * @return array              Array of document rows as associative arrays
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT * FROM documents WHERE project_id = :project_id ORDER BY created_at DESC",
            [':project_id' => $projectId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single document by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Document primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM documents WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on a document row by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   Document primary key
     * @param array    $data Columns to update as key => value pairs
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'extracted_text', 'ai_summary',
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

        $db->query("UPDATE documents SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a document by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Document primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM documents WHERE id = :id", [':id' => $id]);
    }
}
