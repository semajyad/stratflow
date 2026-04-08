<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PasswordToken;

/**
 * PasswordTokenTest
 *
 * Tests CRUD and business logic for the `password_tokens` table.
 * setUp inserts a test org + user; tearDown removes all test data.
 */
class PasswordTokenTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - PasswordTokenTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - PasswordTokenTest'");

        // Insert a test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - PasswordTokenTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        // Insert a test user
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_pt@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Token Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM password_tokens WHERE user_id = ?", [self::$userId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Clean up tokens after each test so tests don't interfere
        self::$db->query("DELETE FROM password_tokens WHERE user_id = ?", [self::$userId]);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsTokenString(): void
    {
        $token = PasswordToken::create(self::$db, self::$userId, 'set_password');

        // bin2hex(random_bytes(32)) produces a 64-character hex string
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    // ===========================
    // FIND
    // ===========================

    #[Test]
    public function testFindByTokenReturnsValidToken(): void
    {
        $token = PasswordToken::create(self::$db, self::$userId, 'set_password');

        $row = PasswordToken::findByToken(self::$db, $token);

        $this->assertNotNull($row);
        $this->assertSame($token, $row['token']);
        $this->assertSame((string) self::$userId, (string) $row['user_id']);
    }

    #[Test]
    public function testFindByTokenReturnsNullForExpired(): void
    {
        // Insert an already-expired token directly
        self::$db->query(
            "INSERT INTO password_tokens (user_id, token, type, expires_at)
             VALUES (?, ?, ?, ?)",
            [self::$userId, 'expiredtoken' . str_repeat('a', 52), 'reset_password', date('Y-m-d H:i:s', time() - 3600)]
        );

        $row = PasswordToken::findByToken(self::$db, 'expiredtoken' . str_repeat('a', 52));

        $this->assertNull($row);
    }

    #[Test]
    public function testFindByTokenReturnsNullForUsed(): void
    {
        // Insert an already-used token directly
        $usedToken = 'usedtoken000' . str_repeat('b', 52);
        self::$db->query(
            "INSERT INTO password_tokens (user_id, token, type, expires_at, used_at)
             VALUES (?, ?, ?, ?, NOW())",
            [self::$userId, $usedToken, 'reset_password', date('Y-m-d H:i:s', time() + 86400)]
        );

        $row = PasswordToken::findByToken(self::$db, $usedToken);

        $this->assertNull($row);
    }

    // ===========================
    // MARK USED
    // ===========================

    #[Test]
    public function testMarkUsedSetsTimestamp(): void
    {
        $token = PasswordToken::create(self::$db, self::$userId, 'reset_password');
        $row   = PasswordToken::findByToken(self::$db, $token);
        $this->assertNotNull($row);

        PasswordToken::markUsed(self::$db, (int) $row['id']);

        // Token should no longer be findable (used_at is now set)
        $after = PasswordToken::findByToken(self::$db, $token);
        $this->assertNull($after);

        // Verify used_at column was populated in the DB
        $stmt = self::$db->query(
            "SELECT used_at FROM password_tokens WHERE id = ?",
            [$row['id']]
        );
        $stored = $stmt->fetch();
        $this->assertNotNull($stored['used_at']);
    }

    // ===========================
    // INVALIDATE FOR USER
    // ===========================

    #[Test]
    public function testInvalidateForUserMarksAllUsed(): void
    {
        // Insert two raw tokens (bypass create() to avoid auto-invalidation)
        $tokenA = str_repeat('a', 64);
        $tokenB = str_repeat('b', 64);
        $future = date('Y-m-d H:i:s', time() + 86400);

        self::$db->query(
            "INSERT INTO password_tokens (user_id, token, type, expires_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)",
            [self::$userId, $tokenA, 'set_password', $future,
             self::$userId, $tokenB, 'reset_password', $future]
        );

        PasswordToken::invalidateForUser(self::$db, self::$userId);

        $this->assertNull(PasswordToken::findByToken(self::$db, $tokenA));
        $this->assertNull(PasswordToken::findByToken(self::$db, $tokenB));
    }

    // ===========================
    // DELETE EXPIRED
    // ===========================

    #[Test]
    public function testDeleteExpiredRemovesOldTokens(): void
    {
        // Insert an expired token
        $expiredToken = str_repeat('e', 64);
        self::$db->query(
            "INSERT INTO password_tokens (user_id, token, type, expires_at)
             VALUES (?, ?, ?, ?)",
            [self::$userId, $expiredToken, 'set_password', date('Y-m-d H:i:s', time() - 3600)]
        );

        // Insert a valid token for comparison
        $validToken = PasswordToken::create(self::$db, self::$userId, 'set_password');

        PasswordToken::deleteExpired(self::$db);

        // Expired one should be gone from the DB
        $stmt = self::$db->query(
            "SELECT id FROM password_tokens WHERE token = ?",
            [$expiredToken]
        );
        $this->assertFalse($stmt->fetch());

        // Valid one should still be retrievable
        $this->assertNotNull(PasswordToken::findByToken(self::$db, $validToken));
    }
}
