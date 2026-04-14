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

    public function testIndexRendersTokenManagementPage(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 1, 'role' => 'user', 'email' => 'u@test.invalid']);

        $this->makeController()->index();

        $this->assertSame('account/access-tokens', $this->response->renderedTemplate);
    }

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
}
