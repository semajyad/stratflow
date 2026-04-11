<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Security\PermissionService;

class PermissionServiceTest extends TestCase
{
    #[Test]
    public function viewerIsReadOnly(): void
    {
        $viewer = ['role' => 'viewer'];

        $this->assertTrue(PermissionService::can($viewer, PermissionService::WORKFLOW_VIEW));
        $this->assertFalse(PermissionService::can($viewer, PermissionService::WORKFLOW_EDIT));
        $this->assertFalse(PermissionService::can($viewer, PermissionService::PROJECT_CREATE));
    }

    #[Test]
    public function userCanEditWorkflowButNotManageProjects(): void
    {
        $user = ['role' => 'user'];

        $this->assertTrue(PermissionService::can($user, PermissionService::WORKFLOW_EDIT));
        $this->assertFalse(PermissionService::can($user, PermissionService::PROJECT_EDIT_SETTINGS));
    }

    #[Test]
    public function projectManagerGetsProjectCapabilities(): void
    {
        $user = ['role' => 'project_manager'];

        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_CREATE));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_MANAGE_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_DELETE));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_VIEW_ALL));
    }

    #[Test]
    public function orgAdminGetsAdminAccess(): void
    {
        $user = ['role' => 'org_admin'];

        $this->assertTrue(PermissionService::can($user, PermissionService::ADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::USERS_MANAGE));
        $this->assertFalse(PermissionService::can($user, PermissionService::SUPERADMIN_ACCESS));
    }

    #[Test]
    public function developerOnlyGetsTokenAndApiAccess(): void
    {
        $user = ['role' => 'developer'];

        $this->assertTrue(PermissionService::can($user, PermissionService::TOKENS_MANAGE_OWN));
        $this->assertTrue(PermissionService::can($user, PermissionService::API_USE_OWN_TOKENS));
        $this->assertFalse(PermissionService::can($user, PermissionService::WORKFLOW_VIEW));
        $this->assertFalse(PermissionService::can($user, PermissionService::WORKFLOW_EDIT));
    }

    #[Test]
    public function flagsGrantAdditionalCapabilities(): void
    {
        $user = [
            'role' => 'viewer',
            'is_project_admin' => 1,
            'has_billing_access' => 1,
            'has_executive_access' => 1,
        ];

        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_CREATE));
        $this->assertTrue(PermissionService::can($user, PermissionService::BILLING_VIEW));
        $this->assertTrue(PermissionService::canViewExecutive($user));
    }

    #[Test]
    public function superadminGetsWildcardAccess(): void
    {
        $user = ['role' => 'superadmin'];

        $this->assertTrue(PermissionService::can($user, PermissionService::SUPERADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::ADMIN_ACCESS));
        $this->assertTrue(PermissionService::can($user, PermissionService::PROJECT_DELETE));
    }

    #[Test]
    public function assignableRolesDependOnActor(): void
    {
        $orgAdminRoles = PermissionService::assignableRolesFor(['role' => 'org_admin']);
        $superadminRoles = PermissionService::assignableRolesFor(['role' => 'superadmin']);

        $this->assertNotContains('developer', $orgAdminRoles);
        $this->assertContains('developer', $superadminRoles);
        $this->assertContains('superadmin', $superadminRoles);
    }
}
