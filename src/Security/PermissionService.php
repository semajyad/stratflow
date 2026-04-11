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

    private const ACCOUNT_TYPE_CAPABILITIES = [
        'viewer' => [
            self::WORKFLOW_VIEW,
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
        'member' => [
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
        'manager' => [
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
            self::PROJECT_CREATE,
            self::PROJECT_EDIT_SETTINGS,
            self::PROJECT_MANAGE_ACCESS,
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
        'superadmin' => ['*'],
        'developer' => [
            self::TOKENS_MANAGE_OWN,
            self::API_USE_OWN_TOKENS,
        ],
    ];

    private const ROLE_TO_ACCOUNT_TYPE = [
        'viewer' => 'viewer',
        'user' => 'member',
        'project_manager' => 'manager',
        'org_admin' => 'org_admin',
        'superadmin' => 'superadmin',
        'developer' => 'developer',
    ];

    private const ACCOUNT_TYPE_TO_ROLE = [
        'viewer' => 'viewer',
        'member' => 'user',
        'manager' => 'project_manager',
        'org_admin' => 'org_admin',
        'superadmin' => 'superadmin',
        'developer' => 'developer',
    ];

    private const PROJECT_MEMBERSHIP_CAPABILITIES = [
        'viewer' => [
            self::WORKFLOW_VIEW,
        ],
        'editor' => [
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
        ],
        'project_admin' => [
            self::WORKFLOW_VIEW,
            self::WORKFLOW_EDIT,
            self::PROJECT_EDIT_SETTINGS,
            self::PROJECT_MANAGE_ACCESS,
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
    public static function capabilitiesFor(?array $user, ?Database $db = null, ?int $projectId = null): array
    {
        if (!$user) {
            return [];
        }

        if (self::isSuperadmin($user)) {
            return ['*'];
        }

        if ($db === null || !self::supportsDatabaseBackedCapabilities($db)) {
            return self::legacyCapabilitiesFor($user, $projectId, $db);
        }

        $capabilities = self::databaseCapabilitiesFor($db, $user);
        $capabilities = self::applyFlagCapabilities($capabilities, $user);

        $capabilities = self::applyRoleCompatibilityCapabilities($capabilities, $user);

        if ($projectId !== null) {
            $capabilities = array_merge($capabilities, self::projectMembershipCapabilitiesFor($db, $user, $projectId));
        }

        return array_values(array_unique($capabilities));
    }

    public static function can(?array $user, string $capability, ?Database $db = null, ?int $projectId = null): bool
    {
        if (!$user) {
            return false;
        }

        $caps = self::capabilitiesFor($user, $db, $projectId);
        return in_array('*', $caps, true) || in_array($capability, $caps, true);
    }

    public static function canViewBilling(?array $user, ?Database $db = null): bool
    {
        if (!$user) {
            return false;
        }

        if (self::can($user, self::BILLING_VIEW, $db)) {
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

    public static function accountTypeFor(?array $user): string
    {
        $accountType = (string) ($user['account_type'] ?? '');
        if ($accountType !== '') {
            return $accountType;
        }

        $role = (string) ($user['role'] ?? 'user');
        return self::ROLE_TO_ACCOUNT_TYPE[$role] ?? 'member';
    }

    public static function roleForAccountType(string $accountType): string
    {
        return self::ACCOUNT_TYPE_TO_ROLE[$accountType] ?? 'user';
    }

    public static function accountTypeOptions(): array
    {
        return ['viewer', 'member', 'manager', 'org_admin', 'superadmin', 'developer'];
    }

    /**
     * @return string[]
     */
    public static function describeAccessSummary(?array $user, ?Database $db = null, ?int $projectMembershipCount = null): array
    {
        if (!$user) {
            return [];
        }

        $summary = [];
        $accountType = self::accountTypeFor($user);
        $summary[] = 'Account type: ' . str_replace('_', ' ', $accountType);

        $extras = [];
        if ((bool) ($user['is_project_admin'] ?? false) && !in_array($accountType, ['manager', 'org_admin', 'superadmin'], true)) {
            $extras[] = 'project admin flag';
        }
        if ((bool) ($user['has_billing_access'] ?? false)) {
            $extras[] = 'billing';
        }
        if ((bool) ($user['has_executive_access'] ?? false)) {
            $extras[] = 'executive';
        }

        if ($db !== null && self::supportsDatabaseBackedCapabilities($db) && isset($user['id'])) {
            [$grants, $denies] = self::userCapabilityOverrides($db, (int) $user['id']);
            if ($grants !== []) {
                $extras[] = 'grants: ' . implode(', ', $grants);
            }
            if ($denies !== []) {
                $extras[] = 'denies: ' . implode(', ', $denies);
            }
        }

        if ($projectMembershipCount !== null) {
            $extras[] = $projectMembershipCount . ' project membership' . ($projectMembershipCount === 1 ? '' : 's');
        }

        if ($extras !== []) {
            $summary[] = 'Extras: ' . implode(' | ', $extras);
        }

        return $summary;
    }

    public static function isSuperadmin(?array $user): bool
    {
        return self::accountTypeFor($user) === 'superadmin' || (string) ($user['role'] ?? '') === 'superadmin';
    }

    public static function isOrgAdmin(?array $user): bool
    {
        return self::accountTypeFor($user) === 'org_admin' || (string) ($user['role'] ?? '') === 'org_admin';
    }

    public static function isDeveloper(?array $user): bool
    {
        return self::accountTypeFor($user) === 'developer' || (string) ($user['role'] ?? '') === 'developer';
    }

    private static function supportsDatabaseBackedCapabilities(Database $db): bool
    {
        return $db->tableExists('capabilities')
            && $db->tableExists('account_type_capabilities')
            && $db->tableExists('user_capabilities');
    }

    /**
     * @return string[]
     */
    private static function legacyCapabilitiesFor(?array $user, ?int $projectId = null, ?Database $db = null): array
    {
        if (!$user) {
            return [];
        }

        $accountType = self::accountTypeFor($user);
        $capabilities = self::ACCOUNT_TYPE_CAPABILITIES[$accountType] ?? self::ACCOUNT_TYPE_CAPABILITIES['member'];
        $capabilities = self::applyFlagCapabilities($capabilities, $user);
        $capabilities = self::applyRoleCompatibilityCapabilities($capabilities, $user);

        if ($projectId !== null && $db !== null) {
            $capabilities = array_merge($capabilities, self::projectMembershipCapabilitiesFor($db, $user, $projectId));
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @return string[]
     */
    private static function databaseCapabilitiesFor(Database $db, array $user): array
    {
        $accountType = self::accountTypeFor($user);
        $capabilities = [];

        try {
            $rows = $db->query(
                "SELECT c.`key`
                 FROM account_type_capabilities atc
                 JOIN capabilities c ON c.id = atc.capability_id
                 WHERE atc.account_type = :account_type",
                [':account_type' => $accountType]
            )->fetchAll();

            $capabilities = array_column($rows, 'key');
        } catch (\Throwable) {
            $capabilities = self::ACCOUNT_TYPE_CAPABILITIES[$accountType] ?? self::ACCOUNT_TYPE_CAPABILITIES['member'];
        }

        if (!isset($user['id'])) {
            return array_values(array_unique($capabilities));
        }

        [$grants, $denies] = self::userCapabilityOverrides($db, (int) $user['id']);
        $capabilities = array_values(array_diff(array_merge($capabilities, $grants), $denies));

        return array_values(array_unique($capabilities));
    }

    /**
     * @return array{0:string[],1:string[]}
     */
    private static function userCapabilityOverrides(Database $db, int $userId): array
    {
        try {
            $rows = $db->query(
                "SELECT c.`key`, uc.effect
                 FROM user_capabilities uc
                 JOIN capabilities c ON c.id = uc.capability_id
                 WHERE uc.user_id = :user_id",
                [':user_id' => $userId]
            )->fetchAll();
        } catch (\Throwable) {
            return [[], []];
        }

        $grants = [];
        $denies = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            if (($row['effect'] ?? 'grant') === 'deny') {
                $denies[] = $key;
            } else {
                $grants[] = $key;
            }
        }

        return [array_values(array_unique($grants)), array_values(array_unique($denies))];
    }

    /**
     * @return string[]
     */
    private static function applyFlagCapabilities(array $capabilities, array $user): array
    {
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

        return $capabilities;
    }

    /**
     * Preserve legacy role behaviour while the UI and existing rows still
     * expose legacy role names alongside account_type.
     *
     * @return string[]
     */
    private static function applyRoleCompatibilityCapabilities(array $capabilities, array $user): array
    {
        if ((string) ($user['role'] ?? '') === 'project_manager') {
            $capabilities = array_merge($capabilities, [
                self::PROJECT_VIEW_ALL,
                self::PROJECT_DELETE,
            ]);
        }

        return $capabilities;
    }

    /**
     * @return string[]
     */
    private static function projectMembershipCapabilitiesFor(Database $db, array $user, int $projectId): array
    {
        if (!isset($user['id']) || $projectId <= 0) {
            return [];
        }

        try {
            if ($db->tableExists('project_memberships')) {
                $row = $db->query(
                    "SELECT membership_role
                     FROM project_memberships
                     WHERE project_id = :project_id AND user_id = :user_id
                     LIMIT 1",
                    [':project_id' => $projectId, ':user_id' => (int) $user['id']]
                )->fetch();

                $role = (string) ($row['membership_role'] ?? '');
                return self::PROJECT_MEMBERSHIP_CAPABILITIES[$role] ?? [];
            }

            if ($db->tableExists('project_members')) {
                $row = $db->query(
                    "SELECT 1
                     FROM project_members
                     WHERE project_id = :project_id AND user_id = :user_id
                     LIMIT 1",
                    [':project_id' => $projectId, ':user_id' => (int) $user['id']]
                )->fetch();

                return $row ? self::PROJECT_MEMBERSHIP_CAPABILITIES['editor'] : [];
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }
}
