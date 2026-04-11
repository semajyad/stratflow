<?php

declare(strict_types=1);

namespace StratFlow\Security;

use StratFlow\Core\Database;
use StratFlow\Models\Project;

/**
 * Project-scoped permission policy.
 */
final class ProjectPolicy
{
    public static function findViewableProject(Database $db, ?array $user, int $projectId): ?array
    {
        if (!$user) {
            return null;
        }

        $project = Project::findById($db, $projectId, (int) ($user['org_id'] ?? 0));
        if ($project === null || !self::canView($db, $user, $project)) {
            return null;
        }

        return $project;
    }

    public static function findEditableProject(Database $db, ?array $user, int $projectId): ?array
    {
        if (!$user) {
            return null;
        }

        $project = Project::findById($db, $projectId, (int) ($user['org_id'] ?? 0));
        if ($project === null || !self::canEditWorkflow($db, $user, $project)) {
            return null;
        }

        return $project;
    }

    public static function findManageableProject(Database $db, ?array $user, int $projectId): ?array
    {
        if (!$user) {
            return null;
        }

        $project = Project::findById($db, $projectId, (int) ($user['org_id'] ?? 0));
        if ($project === null || !self::canManageProject($db, $user, $project)) {
            return null;
        }

        return $project;
    }

    public static function canView(Database $db, ?array $user, array $project): bool
    {
        if (!$user) {
            return false;
        }

        if ((int) ($project['org_id'] ?? 0) !== (int) ($user['org_id'] ?? 0)) {
            return false;
        }

        if (PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL, $db, (int) $project['id'])) {
            return true;
        }

        if (($project['visibility'] ?? 'everyone') === 'everyone') {
            return PermissionService::can($user, PermissionService::WORKFLOW_VIEW, $db);
        }

        return self::hasProjectMembership($db, $user, (int) $project['id'])
            && PermissionService::can($user, PermissionService::WORKFLOW_VIEW, $db, (int) $project['id']);
    }

    public static function canEditWorkflow(Database $db, ?array $user, array $project): bool
    {
        return self::canView($db, $user, $project)
            && PermissionService::can($user, PermissionService::WORKFLOW_EDIT, $db, (int) $project['id']);
    }

    public static function canManageProject(Database $db, ?array $user, array $project): bool
    {
        $projectId = (int) $project['id'];

        $canManage = PermissionService::can($user, PermissionService::PROJECT_EDIT_SETTINGS, $db, $projectId)
            || PermissionService::can($user, PermissionService::PROJECT_MANAGE_ACCESS, $db, $projectId)
            || PermissionService::can($user, PermissionService::PROJECT_DELETE, $db, $projectId);

        return $canManage && (
            self::canView($db, $user, $project)
            || PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL, $db, $projectId)
            || (bool) ($user['is_project_admin'] ?? false)
        );
    }

    private static function hasProjectMembership(Database $db, array $user, int $projectId): bool
    {
        $table = $db->tableExists('project_memberships') ? 'project_memberships' : 'project_members';

        $stmt = $db->query(
            "SELECT 1 FROM {$table} WHERE project_id = :pid AND user_id = :uid LIMIT 1",
            [':pid' => $projectId, ':uid' => (int) $user['id']]
        );

        return (bool) $stmt->fetch();
    }
}
