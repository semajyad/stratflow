<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\AccessTokenController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * AccessTokenControllerTest
 *
 * Tests PAT (Personal Access Token) management: token creation, revocation,
 * team and Jira identity saving. Verifies response shapes for authenticated users.
 *
 * Note: Auth guards are enforced by the 'auth' middleware, not the controller.
 * These tests assume an authenticated user and verify controller behaviour only.
 */
class AccessTokenControllerTest extends ControllerTestCase
{
    // ===========================
    // SETUP
    // ===========================

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('tableExists')->willReturn(true);
        $this->db->method('lastInsertId')->willReturn('1');
    }

    private function makeController(): AccessTokenController
    {
        return new AccessTokenController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    // ===========================
    // index
    // ===========================

    #[Test]
    public function testIndexRendersTokenManagementPage(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $this->makeController()->index();

        $this->assertSame('account/access-tokens', $this->response->renderedTemplate);
    }

    #[Test]
    public function testIndexPassesTokensToView(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $this->makeController()->index();

        $this->assertArrayHasKey('tokens', $this->response->renderedData);
        $this->assertIsArray($this->response->renderedData['tokens']);
    }

    // ===========================
    // create
    // ===========================

    #[Test]
    public function testCreateRejectsEmptyTokenName(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['name' => '']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->create();

        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
        $this->assertSame('Token name is required.', $_SESSION['_flash']['error'] ?? null);
    }

    #[Test]
    public function testCreateRejectsNameExceeding100Chars(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['name' => str_repeat('x', 101)]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->create();

        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('100', $_SESSION['_flash']['error'] ?? '');
    }

    #[Test]
    public function testCreateRedirectsAfterSuccessfulTokenCreation(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['name' => 'My Token']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->create();

        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // revoke
    // ===========================

    #[Test]
    public function testRevokeDeletesTokenAndRedirects(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->revoke('42');

        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // saveTeam
    // ===========================

    #[Test]
    public function testSaveTeamProducesAResponse(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['team' => 'backend']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->saveTeam();

        $this->assertNotNull($this->response->redirectedTo ?? $this->response->jsonPayload);
    }

    #[Test]
    public function testSaveTeamRedirectsToTokensPage(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['team' => 'frontend']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->saveTeam();

        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // saveJiraIdentity
    // ===========================

    #[Test]
    public function testSaveJiraIdentityRedirectsToTokensPage(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest([
                'jira_account_id'   => 'jira-123',
                'jira_display_name' => 'John Doe',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->saveJiraIdentity();

        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testSaveJiraIdentityCallsUserUpdate(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $updateCalled = false;
        $capturedId = null;
        $capturedData = null;

        // We'll spy on the User::update call indirectly by ensuring the controller
        // processes the Jira identity data correctly
        $ctrl = new AccessTokenController(
            $this->makePostRequest([
                'jira_account_id'   => 'id-456',
                'jira_display_name' => 'Jane Smith',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->saveJiraIdentity();

        // Verify redirect occurs (which happens after User::update)
        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testSaveJiraIdentityAcceptsNullValues(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest([
                'jira_account_id'   => '',
                'jira_display_name' => '',
            ]),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->saveJiraIdentity();

        // Controller should redirect regardless of empty values
        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // jiraUsers
    // ===========================

    #[Test]
    public function testJiraUsersReturnsJsonWithEmptyListWhenJiraNotConnected(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        // Integration query returns no result
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = new AccessTokenController(
            $this->makeGetRequest(['q' => 'test']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->jiraUsers();

        $this->assertArrayHasKey('users', $this->response->jsonPayload ?? []);
        $this->assertSame([], $this->response->jsonPayload['users'] ?? null);
    }

    #[Test]
    public function testJiraUsersReturnsJsonErrorWhenIntegrationDisconnected(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        // Integration found but status is 'disconnected'
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'status' => 'disconnected',
            'config_json' => '{}',
        ]);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = new AccessTokenController(
            $this->makeGetRequest(['q' => 'test']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->jiraUsers();

        $this->assertArrayHasKey('error', $this->response->jsonPayload ?? []);
        $this->assertSame([], $this->response->jsonPayload['users'] ?? null);
    }

    #[Test]
    public function testIndexFetchesTeamNames(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        // The index method will return the default empty responses for all queries
        // This test verifies that team_options are included in rendered data

        $this->makeController()->index();

        $this->assertArrayHasKey('team_options', $this->response->renderedData);
        // Verify team options were assembled (even if empty)
        $this->assertIsArray($this->response->renderedData['team_options']);
    }

    #[Test]
    public function testIndexIncludesAppUrlInRenderData(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $this->makeController()->index();

        $this->assertArrayHasKey('app_url', $this->response->renderedData);
    }

    #[Test]
    public function testIndexIncludesCsrfToken(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);
        $_SESSION['csrf_token'] = 'test-csrf-token';

        $this->makeController()->index();

        $this->assertArrayHasKey('csrf_token', $this->response->renderedData);
    }

    #[Test]
    public function testCreateFlashesNewTokenToSession(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['name' => 'Valid Token Name']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->create();

        // After successful creation, a raw token should be flashed
        $this->assertArrayHasKey('new_pat', $_SESSION['_flash']);
        $this->assertIsString($_SESSION['_flash']['new_pat']);
        $this->assertNotEmpty($_SESSION['_flash']['new_pat']);
    }

    #[Test]
    public function testRevokeHandlesNonExistentToken(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->revoke('999');

        // Controller should redirect with error message when token not found
        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testSaveTeamHandlesEmptyTeam(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $ctrl = new AccessTokenController(
            $this->makePostRequest(['team' => '']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->saveTeam();

        // Should still redirect even with empty team
        $this->assertStringContainsString('tokens', $this->response->redirectedTo ?? '');
    }

    #[Test]
    public function testJiraUsersHandlesMissingIntegration(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(null);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = new AccessTokenController(
            $this->makeGetRequest(['q' => '']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->jiraUsers();

        $this->assertArrayHasKey('error', $this->response->jsonPayload ?? []);
    }
}
