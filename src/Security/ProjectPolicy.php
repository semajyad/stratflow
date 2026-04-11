<?php

declare(strict_types=1);

namespace StratFlow\Security;

use StratFlow\Core\Database;

/**
 * Project-scoped permission policy.
 */
final class ProjectPolicy
{
    public static function canView(Database $db, ?array $user, array $project): bool
    {
        if (!$user) {
            return false;
        }

        if ((int) ($project['org_id'] ?? 0) !== (int) ($user['org_id'] ?? 0)) {
            return false;
        }

        if (PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL)) {
            return true;
        }

        if (($project['visibility'] ?? 'everyone') === 'everyone') {
            return true;
        }

        $stmt = $db->query(
            "SELECT 1 FROM project_members WHERE project_id = :pid AND user_id = :uid LIMIT 1",
            [':pid' => (int) $project['id'], ':uid' => (int) $user['id']]
        );

        return (bool) $stmt->fetch();
    }

    public static function canEditWorkflow(Database $db, ?array $user, array $project): bool
    {
        return PermissionService::can($user, PermissionService::WORKFLOW_EDIT)
            && self::canView($db, $user, $project);
    }

    public static function canManageProject(Database $db, ?array $user, array $project): bool
    {
        return self::canView($db, $user, $project)
            && (
                PermissionService::can($user, PermissionService::PROJECT_EDIT_SETTINGS)
                || PermissionService::can($user, PermissionService::PROJECT_MANAGE_ACCESS)
                || PermissionService::can($user, PermissionService::PROJECT_DELETE)
            );
    }
}
