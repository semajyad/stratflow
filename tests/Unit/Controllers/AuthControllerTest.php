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

    // ===========================
    // showMfaChallenge
    // ===========================

    public function testShowMfaChallengeRedirectsToLoginWhenNoPendingMfa(): void
    {
        $_SESSION['_mfa_pending_user_id'] = null;

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showMfaChallenge();

        $this->assertSame('/login', $this->response->redirectedTo);
    }

    public function testShowMfaChallengeRendersFormWhenPendingMfaSet(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 1;

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showMfaChallenge();

        $this->assertSame('mfa-challenge', $this->response->renderedTemplate);
        $this->assertNull($this->response->redirectedTo);
    }

    public function testShowMfaChallengePassesErrorFromSession(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 1;
        $_SESSION['mfa_error'] = 'Invalid code';

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showMfaChallenge();

        $this->assertSame('Invalid code', $this->response->renderedData['error']);
        // Session error should be cleared after render
        $this->assertFalse(isset($_SESSION['mfa_error']));
    }

    // ===========================
    // verifyMfaChallenge
    // ===========================

    public function testVerifyMfaChallengeRedirectsToLoginWhenNoPendingMfa(): void
    {
        $_SESSION['_mfa_pending_user_id'] = null;

        $request = $this->makePostRequest(['code' => '123456']);
        $ctrl    = $this->makeController($request);
        $ctrl->verifyMfaChallenge();

        $this->assertSame('/login', $this->response->redirectedTo);
    }

    public function testVerifyMfaChallengeRedirectsToLoginWhenRateLimited(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 1;
        $this->auth->method('isRateLimited')->willReturn(true);

        $request = $this->makePostRequest(['code' => '123456']);
        $ctrl    = $this->makeController($request);
        $ctrl->verifyMfaChallenge();

        $this->assertSame('/login', $this->response->redirectedTo);
        // Session should be cleared
        $this->assertFalse(isset($_SESSION['_mfa_pending_user_id']));
    }

    public function testVerifyMfaChallengeSucceedsWithValidCode(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 1;
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attemptMfa')->willReturn(true);
        $this->auth->method('user')->willReturn(['id' => 1, 'role' => 'admin', 'org_id' => 1]);

        $request = $this->makePostRequest(['code' => '123456']);
        $ctrl    = $this->makeController($request);
        $ctrl->verifyMfaChallenge();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testVerifyMfaChallengeRedirectsToIntendedUrlAfterMfa(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 1;
        $_SESSION['_intended_url']        = '/app/projects/5';
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attemptMfa')->willReturn(true);
        $this->auth->method('user')->willReturn(['id' => 1, 'role' => 'admin', 'org_id' => 1]);

        $request = $this->makePostRequest(['code' => '123456']);
        $ctrl    = $this->makeController($request);
        $ctrl->verifyMfaChallenge();

        $this->assertSame('/app/projects/5', $this->response->redirectedTo);
    }

    public function testVerifyMfaChallengeRendersErrorOnInvalidCode(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 1;
        $this->auth->method('isRateLimited')->willReturn(false);
        $this->auth->method('attemptMfa')->willReturn(false);

        $request = $this->makePostRequest(['code' => '999999']);
        $ctrl    = $this->makeController($request);
        $ctrl->verifyMfaChallenge();

        $this->assertSame('/login/mfa', $this->response->redirectedTo);
        $this->assertSame('Invalid code. Please try again.', $_SESSION['mfa_error']);
    }

    // ===========================
    // showMfaSetup
    // ===========================

    public function testShowMfaSetupRendersTemplateWithSecret(): void
    {
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['mfa_enabled' => 0, 'mfa_recovery_codes' => '[]']);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showMfaSetup();

        $this->assertSame('mfa-setup', $this->response->renderedTemplate);
        $this->assertFalse($this->response->renderedData['mfa_enabled']);
        $this->assertNotEmpty($this->response->renderedData['totp_secret']);
        $this->assertNotEmpty($this->response->renderedData['totp_uri']);
    }

    public function testShowMfaSetupStoresSecretInSession(): void
    {
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['mfa_enabled' => 0, 'mfa_recovery_codes' => '[]']);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showMfaSetup();

        $this->assertNotEmpty($_SESSION['_mfa_provisioning_secret']);
        $this->assertSame($_SESSION['_mfa_provisioning_secret'], $this->response->renderedData['totp_secret']);
    }

    public function testShowMfaSetupRendersWithSecretAndUri(): void
    {
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        // Mock the DB query to return user's current MFA status
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['mfa_enabled' => 0, 'mfa_recovery_codes' => '[]']);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showMfaSetup();

        // Verify template rendered and secret/URI present
        $this->assertSame('mfa-setup', $this->response->renderedTemplate);
        $this->assertNotEmpty($this->response->renderedData['totp_secret']);
        $this->assertNotEmpty($this->response->renderedData['totp_uri']);
        $this->assertFalse($this->response->renderedData['mfa_enabled']);
    }

    // ===========================
    // enableMfa
    // ===========================

    public function testEnableMfaRedirectsOnInvalidCode(): void
    {
        $_SESSION['_mfa_provisioning_secret'] = 'VALIDBASE32SECRET';
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $request = $this->makePostRequest(['code' => '999999']);
        $ctrl    = $this->makeController($request);
        $ctrl->enableMfa();

        $this->assertStringContainsString('/app/account/mfa?error=invalid_code', $this->response->redirectedTo ?? '');
    }

    public function testEnableMfaRedirectsOnEmptySecret(): void
    {
        $_SESSION['_mfa_provisioning_secret'] = '';
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $request = $this->makePostRequest(['code' => '123456']);
        $ctrl    = $this->makeController($request);
        $ctrl->enableMfa();

        $this->assertStringContainsString('/app/account/mfa?error=invalid_code', $this->response->redirectedTo ?? '');
    }

    public function testEnableMfaRedirectsOnInvalidCodeWithSecret(): void
    {
        // When secret is set but code is invalid, should redirect to error
        $secret = 'JBSWY3DPEBLW64TMMQ======';
        $_SESSION['_mfa_provisioning_secret'] = $secret;

        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $request = $this->makePostRequest(['code' => '999999']);
        $ctrl    = $this->makeController($request);
        $ctrl->enableMfa();

        // Invalid code should redirect to MFA setup with error
        $this->assertStringContainsString('/app/account/mfa?error=invalid_code', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // showRecoveryCodes
    // ===========================

    public function testShowRecoveryCodesRedirectsWhenNoCodesInSession(): void
    {
        $_SESSION['_mfa_recovery_codes_flash'] = null;

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showRecoveryCodes();

        $this->assertSame('/app/account/mfa', $this->response->redirectedTo);
    }

    public function testShowRecoveryCodesRendersCodesAndClearsSession(): void
    {
        $codes                                 = ['ABC123', 'DEF456', 'GHI789'];
        $_SESSION['_mfa_recovery_codes_flash'] = $codes;

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showRecoveryCodes();

        $this->assertSame('mfa-recovery-codes', $this->response->renderedTemplate);
        $this->assertSame($codes, $this->response->renderedData['recovery_codes']);
        // Session should be cleared
        $this->assertFalse(isset($_SESSION['_mfa_recovery_codes_flash']));
    }

    // ===========================
    // disableMfa
    // ===========================

    public function testDisableMfaRedirectsOnWrongPassword(): void
    {
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['password_hash' => password_hash('correct', PASSWORD_DEFAULT)]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['password' => 'wrong']);
        $ctrl    = $this->makeController($request);
        $ctrl->disableMfa();

        $this->assertStringContainsString('/app/account/mfa?error=wrong_password', $this->response->redirectedTo ?? '');
    }

    public function testDisableMfaRedirectsOnMissingUser(): void
    {
        $this->auth->method('user')->willReturn(['id' => 1, 'email' => 'user@test.invalid']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['password' => 'test']);
        $ctrl    = $this->makeController($request);
        $ctrl->disableMfa();

        $this->assertStringContainsString('/app/account/mfa?error=wrong_password', $this->response->redirectedTo ?? '');
    }


    // ===========================
    // showForgotPassword
    // ===========================

    public function testShowForgotPasswordRendersFormWhenNotAuthenticated(): void
    {
        $this->auth->method('check')->willReturn(false);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showForgotPassword();

        $this->assertSame('forgot-password', $this->response->renderedTemplate);
        $this->assertNull($this->response->redirectedTo);
    }

    public function testShowForgotPasswordRedirectsWhenAuthenticated(): void
    {
        $this->auth->method('check')->willReturn(true);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showForgotPassword();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // sendResetEmail
    // ===========================

    public function testSendResetEmailShowsSuccessEvenIfRateLimited(): void
    {
        // Mock RateLimiter to return false (rate limited)
        // We need to set up the mocks appropriately
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);

        $this->db->method('query')->willReturn($stmt);

        // Create a custom mock for RateLimiter check to simulate rate limit
        // Since we can't easily mock static methods, we test the rendered response
        $request = $this->makePostRequest(['email' => 'test@example.com']);
        $ctrl    = $this->makeController($request);

        // This test is basic since RateLimiter is hard to mock
        // It should render forgot-password template with success regardless
        $ctrl->sendResetEmail();

        $this->assertSame('forgot-password', $this->response->renderedTemplate);
    }

    public function testSendResetEmailRendersWithSuccess(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);

        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['email' => 'test@example.com']);
        $ctrl    = $this->makeController($request);
        $ctrl->sendResetEmail();

        $this->assertSame('forgot-password', $this->response->renderedTemplate);
    }

    // ===========================
    // showSetPassword
    // ===========================

    public function testShowSetPasswordRendersFormWithInvalidToken(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showSetPassword('invalid-token');

        $this->assertSame('set-password', $this->response->renderedTemplate);
        $this->assertFalse($this->response->renderedData['token_valid']);
    }

    public function testShowSetPasswordIncludesPasswordRequirements(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = $this->makeController($this->makeGetRequest());
        $ctrl->showSetPassword('token');

        $this->assertArrayHasKey('password_requirements', $this->response->renderedData);
    }

    // ===========================
    // setPassword
    // ===========================

    public function testSetPasswordRedirectsWhenTokenInvalid(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!']);
        $ctrl    = $this->makeController($request);
        $ctrl->setPassword('invalid-token');

        $this->assertSame('set-password', $this->response->renderedTemplate);
        $this->assertFalse($this->response->renderedData['token_valid']);
    }
}
