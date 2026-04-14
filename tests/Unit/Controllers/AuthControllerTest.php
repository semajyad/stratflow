<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\AuthController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

/**
 * AuthControllerTest
 *
 * Covers login, redirect-on-already-authenticated, rate-limiting, MFA redirect,
 * role-aware post-login landing, and logout paths.
 *
 * All HTTP output goes to FakeResponse (no real headers sent). Database and
 * Auth are mocked. AuditLogger is allowed to call the mocked DB silently.
 */
class AuthControllerTest extends ControllerTestCase
{
    // ===========================
    // SETUP
    // ===========================

    protected function setUp(): void
    {
        parent::setUp();
        // Session must be available (not started in test; access as array)
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        unset($_SESSION['login_error'], $_SESSION['flash_message'], $_SESSION['_intended_url']);

        // Allow AuditLogger to call DB without throwing
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('tableExists')->willReturn(true);
        $this->db->method('query')->willReturn($stmt);
    }

    private function makeController(FakeRequest $request): AuthController
    {
        return new AuthController($request, $this->response, $this->auth, $this->db, $this->config);
    }

    // ===========================
    // showLogin
    // ===========================

    public function testShowLoginRendersLoginTemplateWhenNotAuthenticated(): void
    {
        $this->auth->method('check')->willReturn(false);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showLogin();

        $this->assertSame('login', $this->response->renderedTemplate);
        $this->assertNull($this->response->redirectedTo);
    }

    public function testShowLoginRedirectsAuthenticatedUserToHome(): void
    {
        $this->auth->method('check')->willReturn(true);
        $this->auth->method('user')->willReturn(['id' => 1, 'role' => 'admin', 'org_id' => 1]);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showLogin();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testShowLoginRedirectsDeveloperToTokenManagement(): void
    {
        $this->auth->method('check')->willReturn(true);
        $this->auth->method('user')->willReturn(['id' => 1, 'role' => 'developer', 'org_id' => 1]);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showLogin();

        $this->assertSame('/app/account/tokens', $this->response->redirectedTo);
    }

    // ===========================
    // login — rate limited
    // ===========================

    public function testLoginRendersErrorWhenRateLimited(): void
    {
        $this->auth->method('isRateLimited')->willReturn(true);

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'pw']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('login', $this->response->renderedTemplate);
        $this->assertStringContainsString('Too many', $this->response->renderedData['error']);
        $this->assertNull($this->response->redirectedTo);
    }

    // ===========================
    // login — MFA required
    // ===========================

    public function testLoginRedirectsToMfaWhenMfaRequired(): void
    {
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn('mfa_required');

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'ValidPass1!']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('/login/mfa', $this->response->redirectedTo);
    }

    // ===========================
    // login — success
    // ===========================

    public function testLoginRedirectsToHomeOnSuccessForRegularUser(): void
    {
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn('ok');
        $this->auth->method('user')->willReturn([
            'id' => 1, 'role' => 'admin', 'org_id' => 1,
            'has_billing_access' => 0,
        ]);

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'ValidPass1!']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testLoginRedirectsToSuperadminForSuperadminRole(): void
    {
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn('ok');
        $this->auth->method('user')->willReturn([
            'id' => 1, 'role' => 'superadmin', 'org_id' => 1,
        ]);

        $request = $this->makePostRequest(['email' => 'super@test.invalid', 'password' => 'pass']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('/superadmin', $this->response->redirectedTo);
    }

    public function testLoginRedirectsDeveloperToTokenManagement(): void
    {
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn('ok');
        $this->auth->method('user')->willReturn([
            'id' => 2, 'role' => 'developer', 'org_id' => 1,
        ]);

        $request = $this->makePostRequest(['email' => 'dev@test.invalid', 'password' => 'pass']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('/app/account/tokens', $this->response->redirectedTo);
    }

    public function testLoginRedirectsToIntendedUrlWhenSetInSession(): void
    {
        $_SESSION['_intended_url'] = '/app/projects/5';

        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn('ok');
        $this->auth->method('user')->willReturn([
            'id' => 1, 'role' => 'admin', 'org_id' => 1,
        ]);

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'pass']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('/app/projects/5', $this->response->redirectedTo);
    }

    public function testLoginIgnoresExternalIntendedUrl(): void
    {
        $_SESSION['_intended_url'] = 'https://evil.com/steal';

        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn('ok');
        $this->auth->method('user')->willReturn([
            'id' => 1, 'role' => 'admin', 'org_id' => 1,
            'has_billing_access' => 0,
        ]);

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'pass']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        // Must NOT redirect to external URL
        $this->assertNotSame('https://evil.com/steal', $this->response->redirectedTo);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // login — failure
    // ===========================

    public function testLoginRendersErrorOnInvalidCredentials(): void
    {
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn(false);

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'wrong']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();

        $this->assertSame('login', $this->response->renderedTemplate);
        $this->assertStringContainsString('Invalid email or password', $this->response->renderedData['error']);
        $this->assertNull($this->response->redirectedTo);
    }

    public function testLoginRecordsFailedAttemptOnBadCredentials(): void
    {
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attempt')->willReturn(false);
        $this->auth->expects($this->once())->method('recordFailedAttempt');

        $request = $this->makePostRequest(['email' => 'x@y.com', 'password' => 'wrong']);
        $ctrl    = $this->makeController($request);
        $ctrl->login();
    }

    // ===========================
    // logout
    // ===========================

    public function testLogoutRedirectsToLogin(): void
    {
        $this->auth->method('user')->willReturn(['id' => 1, 'role' => 'admin', 'org_id' => 1, 'email' => 'admin@test.invalid']);

        $request = $this->makePostRequest();
        $ctrl    = $this->makeController($request);
        $ctrl->logout();

        $this->assertSame('/login', $this->response->redirectedTo);
    }
}
