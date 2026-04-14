<?php

/**
 * Project Model
 *
 * Static data-access methods for the `projects` table.
 * All queries are scoped by org_id to enforce multi-tenancy isolation.
 *
 * Columns: id, org_id, name, status, created_by, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;
use StratFlow\Security\PermissionService;

class Project
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new project and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: org_id, name, created_by, status (optional)
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query("INSERT INTO projects (org_id, name, status, created_by)
             VALUES (:org_id, :name, :status, :created_by)", [
                ':org_id'     => $data['org_id'],
                ':name'       => $data['name'],
                ':status'     => $data['status'] ?? 'draft',
                ':created_by' => $data['created_by'],
            ]);
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Return all projects belonging to an organisation, newest first.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID to scope the query
     * @return array          Array of project rows as associative arrays
     */
    public static function findByOrgId(Database $db, int $orgId): array
    {
        $stmt = $db->query("SELECT * FROM projects WHERE org_id = :org_id ORDER BY created_at DESC", [':org_id' => $orgId]);
        return $stmt->fetchAll();
    }

    /**
     * Return projects accessible to a specific user within an organisation.
     *
     * Uses the central PermissionService principal so project visibility stays
     * aligned with account_type/capability resolution rather than reconstructing
     * access from legacy role fields.
     *
     * @param Database $db   Database instance
     * @param array    $user Authenticated principal/session user
     * @return array         Array of project rows
     */
    public static function findAccessibleByOrgId(Database $db, array $user): array
    {
        $orgId = (int) ($user['org_id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);
        if ($orgId <= 0 || $userId <= 0) {
            return [];
        }

        if (PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL, $db)) {
            return self::findByOrgId($db, $orgId);
        }

        $membershipTable = $db->tableExists('project_memberships') ? 'project_memberships' : 'project_members';
        $canViewOrgWide = PermissionService::can($user, PermissionService::WORKFLOW_VIEW, $db);
        $visibilityPredicate = $canViewOrgWide
            ? "p.visibility = 'everyone' OR EXISTS (
                   SELECT 1 FROM {$membershipTable} pm
                   WHERE pm.project_id = p.id AND pm.user_id = :user_id
               )"
            : "EXISTS (
                   SELECT 1 FROM {$membershipTable} pm
                   WHERE pm.project_id = p.id AND pm.user_id = :user_id
               )";
        $stmt = $db->query("SELECT p.* FROM projects p
             WHERE p.org_id = :org_id
               AND ({$visibilityPredicate})
             ORDER BY p.created_at DESC", [':org_id' => $orgId, ':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    // ===========================
    // MEMBER MANAGEMENT
    // ===========================

    /**
     * Replace the full member list for a restricted project.
     *
     * Deletes all existing members then inserts the new set.
     * Pass an empty array to remove all members.
     *
     * @param int   $projectId Project ID
     * @param int[] $userIds   User IDs to grant access
     */
    public static function setMembers(Database $db, int $projectId, array $members): void
    {
        $normalised = self::normaliseMembershipPayload($members);
        if ($db->tableExists('project_memberships')) {
            $db->query("DELETE FROM project_memberships WHERE project_id = :pid", [':pid' => $projectId]);
            foreach ($normalised as $member) {
                $db->query("INSERT INTO project_memberships (project_id, user_id, membership_role)
                     VALUES (:pid, :uid, :membership_role)", [
                        ':pid' => $projectId,
                        ':uid' => $member['user_id'],
                        ':membership_role' => $member['membership_role'],
                    ]);
            }
        }

        if ($db->tableExists('project_members')) {
            $db->query("DELETE FROM project_members WHERE project_id = :pid", [':pid' => $projectId]);
            foreach ($normalised as $member) {
                $db->query("INSERT IGNORE INTO project_members (project_id, user_id) VALUES (:pid, :uid)", [':pid' => $projectId, ':uid' => $member['user_id']]);
            }
        }
    }

    /**
     * Return the user IDs currently in a project's member list.
     *
     * @return int[]
     */
    public static function getMemberIds(Database $db, int $projectId): array
    {
        $rows = self::getMemberships($db, $projectId);
        return array_column($rows, 'user_id');
    }

    /**
     * Return project memberships keyed by membership role when available.
     *
     * @return array<int, array{user_id:int,membership_role:string}>
     */
    public static function getMemberships(Database $db, int $projectId): array
    {
        if ($db->tableExists('project_memberships')) {
            $rows = $db->query("SELECT user_id, membership_role
                 FROM project_memberships
                 WHERE project_id = :pid
                 ORDER BY user_id ASC", [':pid' => $projectId])->fetchAll();
            return array_map(fn(array $row): array => [
                    'user_id' => (int) $row['user_id'],
                    'membership_role' => (string) ($row['membership_role'] ?? 'viewer'),
                ], $rows);
        }

        $rows = $db->query("SELECT user_id FROM project_members WHERE project_id = :pid", [':pid' => $projectId])->fetchAll();
        return array_map(fn(array $row): array => [
                'user_id' => (int) $row['user_id'],
                'membership_role' => 'editor',
            ], $rows);
    }

    /**
     * Find a single project by ID, scoped to an org for security.
     *
     * @param Database $db    Database instance
     * @param int      $id    Project primary key
     * @param int|null $orgId Organisation ID for scoping (omit only for internal use)
     * @return array|null     Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id, ?int $orgId = null): ?array
    {
        $sql    = "SELECT * FROM projects WHERE id = :id";
        $params = [':id' => $id];
        if ($orgId !== null) {
            $sql           .= " AND org_id = :org_id";
            $params[':org_id'] = $orgId;
        }

        $stmt = $db->query($sql . " LIMIT 1", $params);
        $row  = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on a project row, scoped to org_id.
     *
     * @param Database $db    Database instance
     * @param int      $id    Project primary key
     * @param array    $data  Columns to update as key => value pairs
     * @param int|null $orgId Organisation ID for scoping (recommended)
     */
    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'name', 'status', 'selected_framework', 'jira_project_key', 'jira_board_id', 'visibility',
    ];
    public static function update(Database $db, int $id, array $data, ?int $orgId = null): void
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
        $where = "id = :id";
        if ($orgId !== null) {
            $where             .= " AND org_id = :org_id";
            $bound[':org_id']   = $orgId;
        }

        $db->query("UPDATE projects SET {$setClauses} WHERE {$where}", $bound);
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a project by ID, scoped to org_id.
     *
     * @param Database $db    Database instance
     * @param int      $id    Project primary key
     * @param int|null $orgId Organisation ID for scoping (recommended)
     */
    public static function delete(Database $db, int $id, ?int $orgId = null): void
    {
        $sql    = "DELETE FROM projects WHERE id = :id";
        $params = [':id' => $id];
        if ($orgId !== null) {
            $sql           .= " AND org_id = :org_id";
            $params[':org_id'] = $orgId;
        }

        $db->query($sql, $params);
    }

    /**
     * @param array<int, int|array{user_id?:mixed,membership_role?:mixed}> $members
     * @return array<int, array{user_id:int,membership_role:string}>
     */
    private static function normaliseMembershipPayload(array $members): array
    {
        $normalised = [];
        foreach ($members as $key => $member) {
            if (is_array($member)) {
                $userId = (int) ($member['user_id'] ?? $key);
                $role = (string) ($member['membership_role'] ?? 'editor');
            } else {
                $userId = (int) $member;
                $role = 'editor';
            }

            if ($userId <= 0) {
                continue;
            }

            if (!in_array($role, ['viewer', 'editor', 'project_admin'], true)) {
                $role = 'editor';
            }

            $normalised[$userId] = [
                'user_id' => $userId,
                'membership_role' => $role,
            ];
        }

        return array_values($normalised);
    }
}
