<?php
/**
 * SyncMapping Model
 *
 * Static data-access methods for the `sync_mappings` table.
 * Tracks the link between local StratFlow items (work items,
 * user stories, sprints) and their external counterparts in
 * Jira, Azure DevOps, etc.
 *
 * Columns: id, integration_id, local_type, local_id, external_id,
 *          external_key, external_url, sync_hash, last_synced_at, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class SyncMapping
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new sync mapping and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: integration_id, local_type, local_id,
     *                       external_id, external_key, external_url, sync_hash
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO sync_mappings
                (integration_id, local_type, local_id, external_id,
                 external_key, external_url, sync_hash, last_synced_at)
             VALUES
                (:integration_id, :local_type, :local_id, :external_id,
                 :external_key, :external_url, :sync_hash, NOW())",
            [
                ':integration_id' => $data['integration_id'],
                ':local_type'     => $data['local_type'],
                ':local_id'       => $data['local_id'],
                ':external_id'    => $data['external_id'],
                ':external_key'   => $data['external_key'] ?? null,
                ':external_url'   => $data['external_url'] ?? null,
                ':sync_hash'      => $data['sync_hash'] ?? null,
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Find a mapping by local item.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     * @param string   $localType     Local type (hl_work_item, user_story, sprint)
     * @param int      $localId       Local item primary key
     * @return array|null             Row or null if not found
     */
    public static function findByLocalItem(Database $db, int $integrationId, string $localType, int $localId): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM sync_mappings
             WHERE integration_id = :integration_id
               AND local_type = :local_type
               AND local_id = :local_id
             LIMIT 1",
            [
                ':integration_id' => $integrationId,
                ':local_type'     => $localType,
                ':local_id'       => $localId,
            ]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Find a mapping by its external key (e.g. Jira issue key like "PROJ-42").
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     * @param string   $externalKey   External issue key
     * @return array|null             Row or null if not found
     */
    public static function findByExternalKey(Database $db, int $integrationId, string $externalKey): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM sync_mappings
             WHERE integration_id = :integration_id
               AND external_key = :external_key
             LIMIT 1",
            [
                ':integration_id' => $integrationId,
                ':external_key'   => $externalKey,
            ]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Find a mapping by its external ID.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     * @param string   $externalId    External system ID
     * @return array|null             Row or null if not found
     */
    public static function findByExternalId(Database $db, int $integrationId, string $externalId): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM sync_mappings
             WHERE integration_id = :integration_id
               AND external_id = :external_id
             LIMIT 1",
            [
                ':integration_id' => $integrationId,
                ':external_id'    => $externalId,
            ]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Return all mappings for an integration.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     * @return array                  Array of mapping rows
     */
    public static function findByIntegration(Database $db, int $integrationId): array
    {
        $stmt = $db->query(
            "SELECT * FROM sync_mappings
             WHERE integration_id = :integration_id
             ORDER BY created_at ASC",
            [':integration_id' => $integrationId]
        );

        return $stmt->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update a sync mapping by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   Mapping primary key
     * @param array    $data Columns to update (sync_hash, last_synced_at, external_key, external_url)
     */
    public static function update(Database $db, int $id, array $data): void
    {
        $allowed = ['sync_hash', 'last_synced_at', 'external_key', 'external_url', 'external_id'];
        $data = array_intersect_key($data, array_flip($allowed));
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

        $db->query("UPDATE sync_mappings SET {$setClauses} WHERE id = :id", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a single mapping by ID.
     *
     * @param Database $db Database instance
     * @param int      $id Mapping primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM sync_mappings WHERE id = :id", [':id' => $id]);
    }

    /**
     * Delete all mappings for an integration.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration ID
     */
    public static function deleteByIntegration(Database $db, int $integrationId): void
    {
        $db->query(
            "DELETE FROM sync_mappings WHERE integration_id = :integration_id",
            [':integration_id' => $integrationId]
        );
    }
}
