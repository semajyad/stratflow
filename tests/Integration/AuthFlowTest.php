<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Session;

/**
 * AuthFlowTest
 *
 * Integration test covering the full login cycle:
 * - correct credentials succeed
 * - wrong password fails
 * - non-existent user fails
 * - IP-based rate limiting engages after 5 failures
 *
 * Creates a real test user in MySQL and cleans up after each test.
 */
class AuthFlowTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private Database $db;
    private int $orgId;
    private int $userId;
    private Auth $auth;

    private const TEST_EMAIL    = 'auth_integration_test@test.invalid';
    private const TEST_PASSWORD = 'CorrectPassword123!';
    private const TEST_IP       = '10.0.0.99';

    protected function setUp(): void
    {
        $this->db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        $this->db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - AuthFlowTest'"
        );
        $this->db->query("DELETE FROM organisations WHERE name = 'Test Org - AuthFlowTest'");

        // Create a test organisation
        $this->db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - AuthFlowTest']);
        $this->orgId = (int) $this->db->lastInsertId();

        // Create a test user with a known password
        $hash = password_hash(self::TEST_PASSWORD, PASSWORD_DEFAULT);
        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [$this->orgId, self::TEST_EMAIL, $hash, 'Auth Test User', 'user']
        );
        $this->userId = (int) $this->db->lastInsertId();

        // Build Auth with a mocked Session (no real PHP session needed)
        $session    = $this->makeInMemorySession();
        $this->auth = new Auth($session, $this->db);
    }

    protected function tearDown(): void
    {
        // Remove rate-limit records for test IP
        $this->db->query("DELETE FROM login_attempts WHERE ip_address = ?", [self::TEST_IP]);

        // Delete in FK-safe order: users FK on organisations is RESTRICT
        $this->db->query("DELETE FROM users WHERE org_id = ?", [$this->orgId]);
        $this->db->query("DELETE FROM organisations WHERE id = ?", [$this->orgId]);
    }

    // ===========================
    // SESSION HELPER
    // ===========================

    /**
     * Build a mock Session that stores values in memory instead of using PHP sessions.
     */
    private function makeInMemorySession(): Session
    {
        $store = [];

        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set', 'has', 'destroy'])
            ->getMock();

        $session->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$store): void {
                $store[$key] = $value;
            });

        $session->method('get')
            ->willReturnCallback(function (string $key, mixed $default = null) use (&$store): mixed {
                return $store[$key] ?? $default;
            });

        $session->method('has')
            ->willReturnCallback(function (string $key) use (&$store): bool {
                return isset($store[$key]);
            });

        $session->method('destroy')
            ->willReturnCallback(function () use (&$store): void {
                $store = [];
            });

        return $session;
    }

    // ===========================
    // LOGIN — HAPPY PATH
    // ===========================

    #[Test]
    public function testCorrectCredentialsReturnsTrue(): void
    {
        $result = $this->auth->attempt(self::TEST_EMAIL, self::TEST_PASSWORD);
        $this->assertTrue($result);
    }

    #[Test]
    public function testAfterSuccessfulLoginUserIsAuthenticated(): void
    {
        $this->auth->attempt(self::TEST_EMAIL, self::TEST_PASSWORD);
        $this->assertTrue($this->auth->check());
    }

    #[Test]
    public function testAuthenticatedUserHasCorrectEmail(): void
    {
        $this->auth->attempt(self::TEST_EMAIL, self::TEST_PASSWORD);
        $user = $this->auth->user();

        $this->assertSame(self::TEST_EMAIL, $user['email']);
    }

    // ===========================
    // LOGIN — FAILURE CASES
    // ===========================

    #[Test]
    public function testWrongPasswordReturnsFalse(): void
    {
        $result = $this->auth->attempt(self::TEST_EMAIL, 'WrongPassword!');
        $this->assertFalse($result);
    }

    #[Test]
    public function testNonExistentUserReturnsFalse(): void
    {
        $result = $this->auth->attempt('nobody@test.invalid', 'anything');
        $this->assertFalse($result);
    }

    #[Test]
    public function testInactiveUserCannotLogin(): void
    {
        $this->db->query("UPDATE users SET is_active = 0 WHERE id = ?", [$this->userId]);

        $result = $this->auth->attempt(self::TEST_EMAIL, self::TEST_PASSWORD);

        $this->assertFalse($result);
    }

    #[Test]
    public function testFailedLoginDoesNotSetSession(): void
    {
        $this->auth->attempt(self::TEST_EMAIL, 'WrongPassword!');
        $this->assertFalse($this->auth->check());
    }

    // ===========================
    // RATE LIMITING
    // ===========================

    #[Test]
    public function testFreshIpIsNotRateLimited(): void
    {
        $this->assertFalse($this->auth->isRateLimited(self::TEST_IP));
    }

    #[Test]
    public function testIpIsRateLimitedAfterFiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->recordFailedAttempt(self::TEST_IP);
        }

        $this->assertTrue($this->auth->isRateLimited(self::TEST_IP));
    }

    #[Test]
    public function testFourFailuresDoNotTriggerRateLimit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->auth->recordFailedAttempt(self::TEST_IP);
        }

        $this->assertFalse($this->auth->isRateLimited(self::TEST_IP));
    }

    // ===========================
    // LOGOUT
    // ===========================

    #[Test]
    public function testLogoutClearsAuthState(): void
    {
        $this->auth->attempt(self::TEST_EMAIL, self::TEST_PASSWORD);
        $this->assertTrue($this->auth->check());

        $this->auth->logout();
        $this->assertFalse($this->auth->check());
    }
}
