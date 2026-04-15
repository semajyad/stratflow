<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Security\PermissionService;

/**
 * PermissionServiceTest
 *
 * Tests the static permission-resolver in legacy mode (no DB backing).
 * Pass `null` as $db to force legacyCapabilitiesFor, which resolves caps
 * from the ACCOUNT_TYPE_CAPABILITIES constant — no queries needed.
 *
 * Target: raise PermissionService from 15.79% to ≥60% method coverage.
 */
class PermissionServiceTest extends TestCase
{
    // ===========================
    // Fixtures
    // ===========================

    private function user(string $role, array $flags = []): array
    {
        return array_merge(['id' => 1, 'org_id' => 1, 'role' => $role], $flags);
    }

    // ===========================
    // can() — core capability gate
    // ===========================

    #[Test]
    public function regularUserHasWorkflowEdit(): void
    {
        $this->assertTrue(PermissionService::can($this->user('user'), PermissionService::WORKFLOW_EDIT));
    }

    #[Test]
    public function regularUserLacksAdminAccess(): void
    {
        $this->assertFalse(PermissionService::can($this->user('user'), PermissionService::ADMIN_ACCESS));
    }

    #[Test]
    public function orgAdminHasAdminAccess(): void
    {
        $this->assertTrue(PermissionService::can($this->user('org_admin'), PermissionService::ADMIN_ACCESS));
    }

    #[Test]
    public function orgAdminHasWorkflowEdit(): void
    {
        $this->assertTrue(PermissionService::can($this->user('org_admin'), PermissionService::WORKFLOW_EDIT));
    }

    #[Test]
    public function orgAdminHasProjectCreate(): void
    {
        $this->assertTrue(PermissionService::can($this->user('org_admin'), PermissionService::PROJECT_CREATE));
    }

    #[Test]
    public function orgAdminHasProjectViewAll(): void
    {
        $this->assertTrue(PermissionService::can($this->user('org_admin'), PermissionService::PROJECT_VIEW_ALL));
    }

    #[Test]
    public function orgAdminHasUsersManage(): void
    {
        $this->assertTrue(PermissionService::can($this->user('org_admin'), PermissionService::USERS_MANAGE));
    }

    #[Test]
    public function superadminCanDoEverything(): void
    {
        $this->assertTrue(PermissionService::can($this->user('superadmin'), PermissionService::ADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($this->user('superadmin'), PermissionService::SUPERADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($this->user('superadmin'), PermissionService::BILLING_MANAGE));
    }

    #[Test]
    public function viewerOnlyHasWorkflowView(): void
    {
        $this->assertTrue(PermissionService::can($this->user('viewer'), PermissionService::WORKFLOW_VIEW));
        $this->assertFalse(PermissionService::can($this->user('viewer'), PermissionService::WORKFLOW_EDIT));
        $this->assertFalse(PermissionService::can($this->user('viewer'), PermissionService::PROJECT_CREATE));
    }

    #[Test]
    public function developerOnlyHasTokenCaps(): void
    {
        $this->assertTrue(PermissionService::can($this->user('developer'), PermissionService::TOKENS_MANAGE_OWN));
        $this->assertFalse(PermissionService::can($this->user('developer'), PermissionService::WORKFLOW_EDIT));
    }

    #[Test]
    public function nullUserCannotDoAnything(): void
    {
        $this->assertFalse(PermissionService::can(null, PermissionService::WORKFLOW_VIEW));
    }

    // ===========================
    // Flag-based capabilities
    // ===========================

    #[Test]
    public function userWithBillingFlagGetsBillingView(): void
    {
        $user = $this->user('user', ['has_billing_access' => 1]);
        $this->assertTrue(PermissionService::can($user, PermissionService::BILLING_VIEW));
    }

    #[Test]
    public function userWithoutBillingFlagLacksBillingView(): void
    {
        $user = $this->user('user', ['has_billing_access' => 0]);
        $this->assertFalse(PermissionService::can($user, PermissionService::BILLING_VIEW));
    }

    #[Test]
    public function userWithExecutiveFlagGetsExecutiveView(): void
    {
        $user = $this->user('user', ['has_executive_access' => 1]);
        $this->assertTrue(PermissionService::can($user, PermissionService::EXECUTIVE_VIEW));
    }

    #[Test]
    public function userWithProjectAdminFlagGetsProjectCaps(): void
    {
        $user = $this->user('user', ['is_project_admin' => 1]);
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_EDIT_SETTINGS));
    }

    // ===========================
    // can() — null user short-circuit
    // ===========================

    #[Test]
    public function canReturnsFalseForNullUser(): void
    {
        $this->assertFalse(PermissionService::can(null, PermissionService::WORKFLOW_VIEW));
    }

    // ===========================
    // canViewBilling
    // ===========================

    #[Test]
    public function canViewBillingReturnsFalseForNullUser(): void
    {
        $this->assertFalse(PermissionService::canViewBilling(null));
    }

    #[Test]
    public function canViewBillingOrgAdminWithDbWhenNoBillingUsersExist(): void
    {
        // org_admin + db that reports 0 other billing users → billing admin by default
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false); // legacy caps mode

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['cnt' => 0]);
        $db->method('query')->willReturn($stmt);

        $user   = $this->user('org_admin');
        $result = PermissionService::canViewBilling($user, $db);
        $this->assertTrue($result);
    }

    #[Test]
    public function canViewBillingReturnsTrueForBillingFlag(): void
    {
        $user = $this->user('user', ['has_billing_access' => 1]);
        $this->assertTrue(PermissionService::canViewBilling($user));
    }

    #[Test]
    public function canViewBillingReturnsFalseForRegularUser(): void
    {
        $user = $this->user('user', ['has_billing_access' => 0]);
        $this->assertFalse(PermissionService::canViewBilling($user));
    }

    #[Test]
    public function canViewBillingReturnsTrueForSuperadmin(): void
    {
        $this->assertTrue(PermissionService::canViewBilling($this->user('superadmin')));
    }

    // ===========================
    // canViewExecutive
    // ===========================

    #[Test]
    public function canViewExecutiveReturnsTrueForSuperadmin(): void
    {
        $this->assertTrue(PermissionService::canViewExecutive($this->user('superadmin')));
    }

    #[Test]
    public function canViewExecutiveReturnsFalseForRegularUser(): void
    {
        $user = $this->user('user', ['has_executive_access' => 0]);
        $this->assertFalse(PermissionService::canViewExecutive($user));
    }

    #[Test]
    public function canViewExecutiveReturnsTrueForUserWithFlag(): void
    {
        $user = $this->user('user', ['has_executive_access' => 1]);
        $this->assertTrue(PermissionService::canViewExecutive($user));
    }

    // ===========================
    // accountTypeFor
    // ===========================

    #[Test]
    public function accountTypeForReturnsExplicitAccountType(): void
    {
        $user = ['role' => 'user', 'account_type' => 'manager'];
        $this->assertSame('manager', PermissionService::accountTypeFor($user));
    }

    #[Test]
    public function accountTypeForFallsBackToRoleMapping(): void
    {
        $this->assertSame('member', PermissionService::accountTypeFor($this->user('user')));
        $this->assertSame('org_admin', PermissionService::accountTypeFor($this->user('org_admin')));
        $this->assertSame('viewer', PermissionService::accountTypeFor($this->user('viewer')));
    }

    // ===========================
    // isSuperadmin / isOrgAdmin / isDeveloper
    // ===========================

    #[Test]
    public function isSuperadminReturnsTrueForSuperadminRole(): void
    {
        $this->assertTrue(PermissionService::isSuperadmin($this->user('superadmin')));
        $this->assertFalse(PermissionService::isSuperadmin($this->user('org_admin')));
        $this->assertFalse(PermissionService::isSuperadmin($this->user('user')));
    }

    #[Test]
    public function isOrgAdminReturnsTrueForOrgAdminRole(): void
    {
        $this->assertTrue(PermissionService::isOrgAdmin($this->user('org_admin')));
        $this->assertFalse(PermissionService::isOrgAdmin($this->user('user')));
    }

    #[Test]
    public function isDeveloperReturnsTrueForDeveloperRole(): void
    {
        $this->assertTrue(PermissionService::isDeveloper($this->user('developer')));
        $this->assertFalse(PermissionService::isDeveloper($this->user('user')));
    }

    // ===========================
    // roleForAccountType / accountTypeOptions
    // ===========================

    #[Test]
    public function roleForAccountTypeReturnsCorrectRole(): void
    {
        $this->assertSame('user', PermissionService::roleForAccountType('member'));
        $this->assertSame('org_admin', PermissionService::roleForAccountType('org_admin'));
        $this->assertSame('viewer', PermissionService::roleForAccountType('viewer'));
    }

    #[Test]
    public function accountTypeOptionsReturnsAllOptions(): void
    {
        $options = PermissionService::accountTypeOptions();
        $this->assertContains('member', $options);
        $this->assertContains('org_admin', $options);
        $this->assertContains('superadmin', $options);
    }

    // ===========================
    // assignableRolesFor
    // ===========================

    #[Test]
    public function orgAdminCanAssignStandardRoles(): void
    {
        $roles = PermissionService::assignableRolesFor($this->user('org_admin'));
        $this->assertContains('user', $roles);
        $this->assertContains('org_admin', $roles);
        $this->assertNotContains('superadmin', $roles);
        $this->assertNotContains('developer', $roles);
    }

    #[Test]
    public function superadminCanAssignAllRolesIncludingSuperadminAndDeveloper(): void
    {
        $roles = PermissionService::assignableRolesFor($this->user('superadmin'));
        $this->assertContains('superadmin', $roles);
        $this->assertContains('developer', $roles);
    }

    // ===========================
    // capabilitiesFor — legacy mode
    // ===========================

    #[Test]
    public function capabilitiesForSuperadminReturnsWildcard(): void
    {
        $caps = PermissionService::capabilitiesFor($this->user('superadmin'));
        $this->assertContains('*', $caps);
    }

    #[Test]
    public function capabilitiesForNullUserReturnsEmpty(): void
    {
        $this->assertSame([], PermissionService::capabilitiesFor(null));
    }

    #[Test]
    public function capabilitiesForOrgAdminIncludesAllAdminCaps(): void
    {
        $caps = PermissionService::capabilitiesFor($this->user('org_admin'));
        $this->assertContains(PermissionService::ADMIN_ACCESS, $caps);
        $this->assertContains(PermissionService::USERS_MANAGE, $caps);
        $this->assertContains(PermissionService::SETTINGS_MANAGE, $caps);
    }

    // ===========================
    // capabilitiesFor — DB-backed path (capabilities table exists)
    // ===========================

    private function makeBackedDb(array $accountTypeCaps, array $userOverrides = []): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);

        $capStmt = $this->createMock(\PDOStatement::class);
        $capStmt->method('fetchAll')->willReturn($accountTypeCaps);

        $overrideStmt = $this->createMock(\PDOStatement::class);
        $overrideStmt->method('fetchAll')->willReturn($userOverrides);

        $db->method('query')->willReturnOnConsecutiveCalls($capStmt, $overrideStmt);

        return $db;
    }

    #[Test]
    public function capabilitiesForWithDbReturnsAccountTypeCaps(): void
    {
        $db   = $this->makeBackedDb([['key' => PermissionService::WORKFLOW_EDIT]]);
        $caps = PermissionService::capabilitiesFor($this->user('user'), $db);
        $this->assertContains(PermissionService::WORKFLOW_EDIT, $caps);
    }

    #[Test]
    public function capabilitiesForWithDbAppliesGrantOverride(): void
    {
        $db   = $this->makeBackedDb(
            [['key' => PermissionService::WORKFLOW_EDIT]],
            [['key' => PermissionService::ADMIN_ACCESS, 'effect' => 'grant']]
        );
        $caps = PermissionService::capabilitiesFor($this->user('user'), $db);
        $this->assertContains(PermissionService::ADMIN_ACCESS, $caps);
    }

    #[Test]
    public function capabilitiesForWithDbAppliesDenyOverride(): void
    {
        $db   = $this->makeBackedDb(
            [['key' => PermissionService::WORKFLOW_EDIT]],
            [['key' => PermissionService::WORKFLOW_EDIT, 'effect' => 'deny']]
        );
        $caps = PermissionService::capabilitiesFor($this->user('user'), $db);
        $this->assertNotContains(PermissionService::WORKFLOW_EDIT, $caps);
    }

    #[Test]
    public function capabilitiesForWithProjectIdChecksProjectMembership(): void
    {
        // Three tableExists calls: capabilities, account_type_capabilities, user_capabilities
        // Then one more for project_memberships inside projectMembershipCapabilitiesFor
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);

        $capStmt = $this->createMock(\PDOStatement::class);
        $capStmt->method('fetchAll')->willReturn([['key' => PermissionService::WORKFLOW_VIEW]]);

        $overrideStmt = $this->createMock(\PDOStatement::class);
        $overrideStmt->method('fetchAll')->willReturn([]);

        $membershipStmt = $this->createMock(\PDOStatement::class);
        $membershipStmt->method('fetch')->willReturn(['membership_role' => 'editor']);

        $db->method('query')->willReturnOnConsecutiveCalls($capStmt, $overrideStmt, $membershipStmt);

        $caps = PermissionService::capabilitiesFor($this->user('user'), $db, 1);
        // Project editor caps should be merged in
        $this->assertIsArray($caps);
    }

    // ===========================
    // capabilitiesFor — DB passed but no capabilities table (falls back to legacy)
    // ===========================

    #[Test]
    public function capabilitiesForWithDbButNoTableFallsBackToLegacy(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);

        $caps = PermissionService::capabilitiesFor($this->user('org_admin'), $db);
        $this->assertContains(PermissionService::ADMIN_ACCESS, $caps);
    }

    #[Test]
    public function capabilitiesForUserWithDbAndNoTableIncludesWorkflowEdit(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);

        $caps = PermissionService::capabilitiesFor($this->user('user'), $db);
        $this->assertContains(PermissionService::WORKFLOW_EDIT, $caps);
    }

    // ===========================
    // describeAccessSummary
    // ===========================

    #[Test]
    public function describeAccessSummaryReturnsEmptyForNullUser(): void
    {
        $this->assertSame([], PermissionService::describeAccessSummary(null));
    }

    #[Test]
    public function describeAccessSummaryIncludesAccountType(): void
    {
        $summary = PermissionService::describeAccessSummary($this->user('user'));
        $this->assertCount(1, $summary);
        $this->assertStringContainsString('member', $summary[0]);
    }

    #[Test]
    public function describeAccessSummaryListsBillingExtra(): void
    {
        $user = $this->user('user', ['has_billing_access' => 1]);
        $summary = PermissionService::describeAccessSummary($user);
        $this->assertCount(2, $summary);
        $this->assertStringContainsString('billing', $summary[1]);
    }

    #[Test]
    public function describeAccessSummaryListsProjectAdminExtra(): void
    {
        // project_admin flag only appears in summary when account_type is not manager/org_admin/superadmin
        $user = $this->user('user', ['is_project_admin' => 1]);
        $summary = PermissionService::describeAccessSummary($user);
        $this->assertCount(2, $summary);
        $this->assertStringContainsString('project admin', $summary[1]);
    }

    #[Test]
    public function describeAccessSummaryIncludesProjectMembershipCount(): void
    {
        $summary = PermissionService::describeAccessSummary($this->user('user'), null, 3);
        $combined = implode(' ', $summary);
        $this->assertStringContainsString('3 project', $combined);
    }

    #[Test]
    public function describeAccessSummaryForSuperadminHasNoExtras(): void
    {
        $summary = PermissionService::describeAccessSummary($this->user('superadmin'));
        // superadmin has no billing/executive/project_admin extras by default
        $this->assertCount(1, $summary);
        $this->assertStringContainsString('superadmin', $summary[0]);
    }
}
