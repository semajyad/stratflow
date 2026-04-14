<?php

/**
 * ProjectRepoLink Model
 *
 * DAO for the `project_repo_links` table.
 *
 * Many-to-many: stratflow projects <-> integration_repos.
 * A project can subscribe to repos from multiple GitHub accounts.
 * A repo can serve multiple projects independently.
 *
 * Columns: id, project_id, integration_repo_id, org_id, created_at, created_by
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class ProjectRepoLink
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new project-repo link.
     *
     * Returns 0 if the row already exists (ON DUPLICATE KEY with no update
     * means lastInsertId returns 0 on a no-op).
     *
     * @param Database $db                 Database instance
     * @param int      $projectId          Project primary key
     * @param int      $integrationRepoId  integration_repos primary key
     * @param int      $orgId             Organisation ID
     * @param int|null $createdBy         User ID who created the link (nullable)
     * @return int                         New row ID, or 0 on duplicate
     */
    public static function create(Database $db, int $projectId, int $integrationRepoId, int $orgId, ?int $createdBy = null): int
    {
        $db->query("INSERT IGNORE INTO project_repo_links
                (project_id, integration_repo_id, org_id, created_by)
             VALUES
                (:project_id, :integration_repo_id, :org_id, :created_by)", [
                ':project_id'           => $projectId,
                ':integration_repo_id'  => $integrationRepoId,
                ':org_id'               => $orgId,
                ':created_by'           => $createdBy,
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all integration_repo IDs currently linked to a project.
     *
     * @param Database $db       Database instance
     * @param int      $projectId Project primary key
     * @param int      $orgId    Organisation ID (tenancy check)
     * @return int[]             Array of integration_repo_id values
     */
    public static function findRepoIdsByProject(Database $db, int $projectId, int $orgId): array
    {
        $stmt = $db->query("SELECT integration_repo_id FROM project_repo_links
             WHERE project_id = :project_id AND org_id = :org_id", [':project_id' => $projectId, ':org_id' => $orgId]);
        return array_column($stmt->fetchAll(), 'integration_repo_id');
    }

    /**
     * Return all project IDs that have linked a given integration_repo_id.
     *
     * @param Database $db                Database instance
     * @param int      $integrationRepoId integration_repos primary key
     * @param int      $orgId            Organisation ID
     * @return int[]                      Array of project_id values
     */
    public static function findProjectIdsByRepo(Database $db, int $integrationRepoId, int $orgId): array
    {
        $stmt = $db->query("SELECT project_id FROM project_repo_links
             WHERE integration_repo_id = :integration_repo_id AND org_id = :org_id", [':integration_repo_id' => $integrationRepoId, ':org_id' => $orgId]);
        return array_column($stmt->fetchAll(), 'project_id');
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a specific project-repo link.
     *
     * @param Database $db                Database instance
     * @param int      $projectId         Project primary key
     * @param int      $integrationRepoId integration_repos primary key
     * @param int      $orgId            Organisation ID (tenancy check)
     */
    public static function delete(Database $db, int $projectId, int $integrationRepoId, int $orgId): void
    {
        $db->query("DELETE FROM project_repo_links
             WHERE project_id = :project_id
               AND integration_repo_id = :integration_repo_id
               AND org_id = :org_id", [
                ':project_id'           => $projectId,
                ':integration_repo_id'  => $integrationRepoId,
                ':org_id'               => $orgId,
            ]);
    }

    /**
     * Delete all repo links for a project (e.g. when a project is deleted).
     *
     * This is handled by the FK CASCADE on projects.id, but provided for
     * explicit use in controller-level resets.
     *
     * @param Database $db       Database instance
     * @param int      $projectId Project primary key
     * @param int      $orgId    Organisation ID (tenancy check)
     */
    public static function deleteAllForProject(Database $db, int $projectId, int $orgId): void
    {
        $db->query("DELETE FROM project_repo_links
             WHERE project_id = :project_id AND org_id = :org_id", [':project_id' => $projectId, ':org_id' => $orgId]);
    }
}
