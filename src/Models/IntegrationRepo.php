<?php

/**
 * IntegrationRepo Model
 *
 * DAO for the `integration_repos` table.
 *
 * Stores repos visible to a GitHub App installation.
 * One row per repo per installation. Acts as:
 *  (a) an allowlist — only repos in this table are accepted by the webhook
 *  (b) the pool from which per-project repo pickers are populated
 *
 * Columns: id, integration_id, org_id, repo_github_id, repo_full_name, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class IntegrationRepo
{
    // ===========================
    // CREATE / UPSERT
    // ===========================

    /**
     * Upsert a single repo for an integration.
     *
     * ON DUPLICATE KEY UPDATE handles idempotent re-syncing when the install
     * callback runs again or when an installation_repositories.added event fires.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration row ID
     * @param int      $orgId         Organisation ID
     * @param int      $repoGithubId  GitHub repository ID
     * @param string   $repoFullName  Full repo name (e.g. "acme/hello-world")
     * @return int                    ID of the affected row
     */
    public static function upsert(Database $db, int $integrationId, int $orgId, int $repoGithubId, string $repoFullName): int
    {
        $db->query("INSERT INTO integration_repos
                (integration_id, org_id, repo_github_id, repo_full_name)
             VALUES
                (:integration_id, :org_id, :repo_github_id, :repo_full_name)
             ON DUPLICATE KEY UPDATE
                repo_full_name = VALUES(repo_full_name)", [
                ':integration_id' => $integrationId,
                ':org_id'         => $orgId,
                ':repo_github_id' => $repoGithubId,
                ':repo_full_name' => $repoFullName,
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Find a single integration_repos row by its primary key, scoped to an org.
     *
     * @param Database $db    Database instance
     * @param int      $id    integration_repos primary key
     * @param int      $orgId Organisation ID (tenancy check)
     * @return array|null     Row or null
     */
    public static function findByIdForOrg(Database $db, int $id, int $orgId): ?array
    {
        $stmt = $db->query("SELECT * FROM integration_repos WHERE id = :id AND org_id = :org_id LIMIT 1", [':id' => $id, ':org_id' => $orgId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a repo by GitHub repo ID within a specific integration.
     *
     * Used by the webhook handler to verify a repo is on the allowlist.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration row ID
     * @param int      $repoGithubId  GitHub repository ID
     * @return array|null             Row or null if not on the allowlist
     */
    public static function findByIntegrationAndGithubId(Database $db, int $integrationId, int $repoGithubId): ?array
    {
        $stmt = $db->query("SELECT * FROM integration_repos
             WHERE integration_id = :integration_id AND repo_github_id = :repo_github_id
             LIMIT 1", [':integration_id' => $integrationId, ':repo_github_id' => $repoGithubId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Return all repos for a given integration.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration row ID
     * @return array                  Array of rows
     */
    public static function findByIntegration(Database $db, int $integrationId): array
    {
        $stmt = $db->query("SELECT * FROM integration_repos
             WHERE integration_id = :integration_id
             ORDER BY repo_full_name ASC", [':integration_id' => $integrationId]);
        return $stmt->fetchAll();
    }

    /**
     * Return all repos across all active GitHub integrations for an org.
     *
     * Used to populate the per-project repo picker, grouped by account_login
     * on the PHP/template side.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID
     * @return array          Rows with additional integration.account_login column
     */
    public static function findAllForOrg(Database $db, int $orgId): array
    {
        $stmt = $db->query("SELECT ir.*, i.account_login, i.id AS integration_id_col
             FROM integration_repos ir
             JOIN integrations i ON i.id = ir.integration_id
             WHERE ir.org_id = :org_id
               AND i.provider = 'github'
               AND i.status   = 'active'
             ORDER BY i.account_login ASC, ir.repo_full_name ASC", [':org_id' => $orgId]);
        return $stmt->fetchAll();
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a repo from the allowlist when a GitHub App installation_repositories
     * removed event fires.
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration row ID
     * @param int      $repoGithubId  GitHub repository ID
     */
    public static function deleteByIntegrationAndGithubId(Database $db, int $integrationId, int $repoGithubId): void
    {
        $db->query("DELETE FROM integration_repos
             WHERE integration_id = :integration_id AND repo_github_id = :repo_github_id", [':integration_id' => $integrationId, ':repo_github_id' => $repoGithubId]);
    }

    /**
     * Delete all repos for an integration (e.g. on disconnect/revoke).
     *
     * @param Database $db            Database instance
     * @param int      $integrationId Integration row ID
     */
    public static function deleteByIntegration(Database $db, int $integrationId): void
    {
        $db->query("DELETE FROM integration_repos WHERE integration_id = :integration_id", [':integration_id' => $integrationId]);
    }
}
