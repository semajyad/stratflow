<?php

declare(strict_types=1);

namespace StratFlow\Security;

use StratFlow\Core\Database;

/**
 * Central permission resolver for StratFlow.
 *
 * This is intentionally lightweight: it keeps the existing schema
 * (role + flags + project visibility) but exposes capability-style checks
 * so routes, controllers, and templates stop hard-coding role comparisons.
 */
final class PermissionService
{
    public const ADMIN_ACCESS          = 'admin.access';
    public const WORKFLOW_VIEW         = 'workflow.view';
    public const WORKFLOW_EDIT         = 'workflow.edit';
    public const PROJECT_CREATE        = 'project.create';
    public const PROJECT_VIEW_ALL      = 'project.view_all';
    public const PROJECT_EDIT_SETTINGS = 'project.edit_settings';
    public const PROJECT_MANAGE_ACCESS = 'project.manage_access';
    public const PROJECT_DELETE        = 'project.delete';
    public const USERS_MANAGE          = 'users.manage';
    public const TEAMS_MANAGE          = 'teams.manage';
    public const SETTINGS_MANAGE       = 'settings.manage';
    public const INTEGRATIONS_MANAGE   = 'integrations.manage';
    public const AUDIT_LOGS_VIEW       = 'audit_logs.view';
    public const BILLING_VIEW          = 'billing.view';
    public const BILLING_MANAGE        = 'billing.manage';
    public const EXECUTIVE_VIEW        = 'executive.view';
    public const TOKENS_MANAGE_OWN     = 'tokens.manage_own';
    public const API_USE_OWN_TOKENS    = 'api.use_own_tokens';
    public const SUPERADMIN_ACCESS     = 'superadmin.access';

    private const ROLE_CAPABILITIES = [
        'viewer' => [
            self::WORKFLOW_VIEW,
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
        'user' => [
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
        'project_manager' => [
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
            self::PROJECT_CREATE,
            self::PROJECT_VIEW_ALL,
            self::PROJECT_EDIT_SETTINGS,
            self::PROJECT_MANAGE_ACCESS,
            self::PROJECT_DELETE,
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
        'org_admin' => [
            self::ADMIN_ACCESS,
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
            self::PROJECT_CREATE,
            self::PROJECT_VIEW_ALL,
            self::PROJECT_EDIT_SETTINGS,
            self::PROJECT_MANAGE_ACCESS,
            self::PROJECT_DELETE,
            self::USERS_MANAGE,
            self::TEAMS_MANAGE,
            self::SETTINGS_MANAGE,
            self::INTEGRATIONS_MANAGE,
            self::AUDIT_LOGS_VIEW,
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
        'developer' => [
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
    ];

    /**
     * Roles that may be assigned by an org admin.
     *
     * Superadmin can also assign superadmin and developer.
     *
     * @return string[]
     */
    public static function assignableRolesFor(array $actor): array
    {
        $roles = ['viewer', 'user', 'project_manager', 'org_admin'];

        if (self::isSuperadmin($actor)) {
            $roles[] = 'superadmin';
            $roles[] = 'developer';
        }

        return $roles;
    }

    /**
     * @return string[]
     */
    public static function capabilitiesFor(?array $user): array
    {
        if (!$user) {
            return [];
        }

        if (self::isSuperadmin($user)) {
            return ['*'];
        }

        $role = (string) ($user['role'] ?? 'user');
        $capabilities = self::ROLE_CAPABILITIES[$role] ?? self::ROLE_CAPABILITIES['user'];

        if ((bool) ($user['is_project_admin'] ?? false)) {
            $capabilities = array_merge($capabilities, [
                self::PROJECT_CREATE,
                self::PROJECT_VIEW_ALL,
                self::PROJECT_EDIT_SETTINGS,
                self::PROJECT_MANAGE_ACCESS,
                self::PROJECT_DELETE,
            ]);
        }

        if ((bool) ($user['has_billing_access'] ?? false)) {
            $capabilities = array_merge($capabilities, [
                self::BILLING_VIEW,
                self::BILLING_MANAGE,
            ]);
        }

        if ((bool) ($user['has_executive_access'] ?? false)) {
            $capabilities[] = self::EXECUTIVE_VIEW;
        }

        return array_values(array_unique($capabilities));
    }

    public static function can(?array $user, string $capability): bool
    {
        if (!$user) {
            return false;
        }

        $caps = self::capabilitiesFor($user);
        return in_array('*', $caps, true) || in_array($capability, $caps, true);
    }

    public static function canViewBilling(?array $user, ?Database $db = null): bool
    {
        if (!$user) {
            return false;
        }

        if (self::can($user, self::BILLING_VIEW)) {
            return true;
        }

        if (!self::isOrgAdmin($user) || $db === null) {
            return false;
        }

        try {
            $stmt = $db->query(
                "SELECT COUNT(*) AS cnt FROM users WHERE org_id = :oid AND has_billing_access = 1 AND id != :uid",
                [':oid' => (int) $user['org_id'], ':uid' => (int) $user['id']]
            );
            $row = $stmt->fetch();
            return (int) ($row['cnt'] ?? 0) === 0;
        } catch (\Throwable) {
            // Preserve current app behaviour: if the dedicated-billing check
            // fails, do not block the org admin from reaching billing.
            return true;
        }
    }

    public static function canViewExecutive(?array $user): bool
    {
        return self::can($user, self::EXECUTIVE_VIEW);
    }

    public static function isSuperadmin(?array $user): bool
    {
        return (string) ($user['role'] ?? '') === 'superadmin';
    }

    public static function isOrgAdmin(?array $user): bool
    {
        return (string) ($user['role'] ?? '') === 'org_admin';
    }

    public static function isDeveloper(?array $user): bool
    {
        return (string) ($user['role'] ?? '') === 'developer';
    }
}
