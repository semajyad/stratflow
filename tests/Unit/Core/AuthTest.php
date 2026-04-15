<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Session;

/**
 * AuthTest
 *
 * Covers the methods not exercised by AuthPrincipalTest:
 * logout, login, attempt (ok / bad-creds / mfa-required), isRateLimited,
 * recordFailedAttempt, and disableMfa.
 *
 * Skipped: attemptMfa and enableMfa — both call static TotpService and
 * SecretManager which cannot be mocked without refactoring the production
 * class.  Those paths are covered by integration tests.
 */
class AuthTest extends TestCase
{
    private function makeAuth(?Session $session = null, ?Database $db = null): Auth
    {
        return new Auth(
            $session ?? $this->createMock(Session::class),
            $db ?? $this->createMock(Database::class)
        );
    }

    private function makeStmt(mixed $row): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        return $stmt;
    }

    // ===========================
    // logout
    // ===========================

    #[Test]
    public function logoutDestroysSession(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects($this->once())->method('destroy');

        $auth = $this->makeAuth($session);
        $auth->logout();
    }

    // ===========================
    // login
    // ===========================

    #[Test]
    public function loginStoresExpectedFieldsInSession(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects($this->once())
            ->method('set')
            ->with('user', $this->callback(function (array $stored): bool {
                return $stored['id']     === 7
                    && $stored['org_id'] === 3
                    && $stored['name']   === 'Alice'
                    && $stored['email']  === 'alice@test.invalid'
                    && $stored['role']   === 'org_admin';
            }));

        $auth = $this->makeAuth($session);
        $auth->login([
            'id'                   => 7,
            'org_id'               => 3,
            'full_name'            => 'Alice',
            'email'                => 'alice@test.invalid',
            'role'                 => 'org_admin',
            'has_billing_access'   => 0,
            'has_executive_access' => 0,
            'is_project_admin'     => 0,
        ]);
    }

    #[Test]
    public function loginBoolCastsFlagFields(): void
    {
        $captured = null;
        $session = $this->createMock(Session::class);
        $session->method('set')->willReturnCallback(
            function (string $key, array $value) use (&$captured): void {
                $captured = $value;
            }
        );

        $auth = $this->makeAuth($session);
        $auth->login([
            'id'                   => 1,
            'org_id'               => 1,
            'full_name'            => 'Bob',
            'email'                => 'bob@test.invalid',
            'role'                 => 'user',
            'has_billing_access'   => 1,
            'has_executive_access' => 0,
            'is_project_admin'     => 1,
        ]);

        $this->assertTrue($captured['has_billing_access']);
        $this->assertFalse($captured['has_executive_access']);
        $this->assertTrue($captured['is_project_admin']);
    }

    // ===========================
    // attempt
    // ===========================

    #[Test]
    public function attemptReturnsFalseWhenUserNotFound(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt(false));

        $auth   = $this->makeAuth(null, $db);
        $result = $auth->attempt('nobody@test.invalid', 'pw');

        $this->assertFalse($result);
    }

    #[Test]
    public function attemptReturnsFalseOnBadPassword(): void
    {
        $hash = password_hash('correct', PASSWORD_BCRYPT);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt([
            'id'            => 1,
            'org_id'        => 1,
            'full_name'     => 'U',
            'email'         => 'u@test.invalid',
            'role'          => 'user',
            'password_hash' => $hash,
            'mfa_enabled'   => 0,
        ]));

        $auth   = $this->makeAuth(null, $db);
        $result = $auth->attempt('u@test.invalid', 'wrong');

        $this->assertFalse($result);
    }

    #[Test]
    public function attemptReturnsMfaRequiredWhenMfaEnabled(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt([
            'id'            => 5,
            'org_id'        => 2,
            'full_name'     => 'MFA User',
            'email'         => 'mfa@test.invalid',
            'role'          => 'user',
            'password_hash' => $hash,
            'mfa_enabled'   => 1,
        ]));

        $auth   = $this->makeAuth(null, $db);
        $result = $auth->attempt('mfa@test.invalid', 'secret');

        $this->assertSame('mfa_required', $result);

        // Clean up session pollution
        unset($_SESSION['_mfa_pending_user_id']);
    }

    #[Test]
    public function attemptReturnsOkAndCallsLoginOnSuccess(): void
    {
        $hash = password_hash('hunter2', PASSWORD_BCRYPT);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt([
            'id'                   => 10,
            'org_id'               => 4,
            'full_name'            => 'Carol',
            'email'                => 'carol@test.invalid',
            'role'                 => 'user',
            'password_hash'        => $hash,
            'mfa_enabled'          => 0,
            'has_billing_access'   => 0,
            'has_executive_access' => 0,
            'is_project_admin'     => 0,
        ]));

        $session = $this->createMock(Session::class);
        $session->expects($this->once())->method('set')->with('user', $this->isArray());

        $auth   = $this->makeAuth($session, $db);
        $result = $auth->attempt('carol@test.invalid', 'hunter2');

        $this->assertSame('ok', $result);
    }

    // ===========================
    // isRateLimited
    // ===========================

    #[Test]
    public function isRateLimitedReturnsTrueWhenAttemptCountAtThreshold(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt(['cnt' => 5]));

        $this->assertTrue($this->makeAuth(null, $db)->isRateLimited('1.2.3.4'));
    }

    #[Test]
    public function isRateLimitedReturnsFalseWhenBelowThreshold(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt(['cnt' => 4]));

        $this->assertFalse($this->makeAuth(null, $db)->isRateLimited('1.2.3.4'));
    }

    #[Test]
    public function isRateLimitedReturnsFalseWhenNoAttempts(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt(['cnt' => 0]));

        $this->assertFalse($this->makeAuth(null, $db)->isRateLimited('1.2.3.4'));
    }

    // ===========================
    // recordFailedAttempt
    // ===========================

    #[Test]
    public function recordFailedAttemptExecutesInsertQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('INSERT INTO login_attempts'));

        $this->makeAuth(null, $db)->recordFailedAttempt('5.6.7.8');
    }

    // ===========================
    // enableMfa
    // ===========================

    #[Test]
    public function enableMfaStoresSecretAndReturnsPlaintextRecoveryCodes(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE users'),
                $this->containsEqual(99)
            );

        $auth  = $this->makeAuth(null, $db);
        $codes = $auth->enableMfa(99, 'JBSWY3DPEHPK3PXP');

        // TotpService::generateRecoveryCodes(8) returns 8 plaintext codes
        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}(-[A-F0-9]{4})+$/', $codes[0]);
    }

    // ===========================
    // check — inactive user path (session+DB)
    // ===========================

    #[Test]
    public function checkDestroySessionAndReturnsFalseWhenUserInactive(): void
    {
        $sessionUser = [
            'id'     => 5,
            'org_id' => 2,
            'name'   => 'Inactive',
            'email'  => 'inactive@test.invalid',
            'role'   => 'user',
        ];

        $session = $this->createMock(Session::class);
        $session->method('get')->with('user')->willReturn($sessionUser);
        $session->expects($this->once())->method('destroy');

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt(false)); // DB says user not found/inactive

        $auth = $this->makeAuth($session, $db);
        $this->assertFalse($auth->check());
    }

    // ===========================
    // attemptMfa — partial paths (skipping TotpService verify paths)
    // ===========================

    #[Test]
    public function attemptMfaReturnsFalseWhenNoPendingSession(): void
    {
        unset($_SESSION['_mfa_pending_user_id']);
        $auth = $this->makeAuth();
        $this->assertFalse($auth->attemptMfa('123456'));
    }

    #[Test]
    public function attemptMfaReturnsFalseWhenUserNotFoundInDb(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 7;

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt(false));

        $auth = $this->makeAuth(null, $db);
        $this->assertFalse($auth->attemptMfa('000000'));

        unset($_SESSION['_mfa_pending_user_id']);
    }

    #[Test]
    public function attemptMfaReturnsFalseWhenSecretCannotBeDecrypted(): void
    {
        $_SESSION['_mfa_pending_user_id'] = 8;

        // mfa_secret = null → SecretManager::unprotectString receives null → returns null
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStmt([
            'id'                 => 8,
            'org_id'             => 1,
            'full_name'          => 'MFA User',
            'email'              => 'mfa@test.invalid',
            'role'               => 'user',
            'mfa_enabled'        => 1,
            'mfa_secret'         => null,
            'mfa_recovery_codes' => null,
        ]));

        $auth = $this->makeAuth(null, $db);
        $this->assertFalse($auth->attemptMfa('000000'));

        unset($_SESSION['_mfa_pending_user_id']);
    }

    // ===========================
    // disableMfa
    // ===========================

    #[Test]
    public function disableMfaExecutesUpdateQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE users'),
                $this->containsEqual(42)
            );

        $this->makeAuth(null, $db)->disableMfa(42);
    }
}
