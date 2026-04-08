<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PasswordToken;

/**
 * PasswordResetFlowTest
 *
 * Integration tests covering the full password-reset token lifecycle:
 * create, find, expiry, usage, invalidation, and password change.
 *
 * Creates a real test user in MySQL and cleans up after each test.
 */
class PasswordResetFlowTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private Database $db;
    private int $orgId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        $this->db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - PasswordResetFlowTest'"
        );
        $this->db->query("DELETE FROM organisations WHERE name = 'Test Org - PasswordResetFlowTest'");

        // Create test org + user
        $this->db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - PasswordResetFlowTest']);
        $this->orgId = (int) $this->db->lastInsertId();

        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [$this->orgId, 'reset_flow@test.invalid', password_hash('OldPassword123!', PASSWORD_DEFAULT), 'Reset Flow User', 'user']
        );
        $this->userId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM password_tokens WHERE user_id = ?", [$this->userId]);
        $this->db->query("DELETE FROM users WHERE org_id = ?", [$this->orgId]);
        $this->db->query("DELETE FROM organisations WHERE id = ?", [$this->orgId]);
    }

    // ===========================
    // TOKEN CREATION AND RETRIEVAL
    // ===========================

    #[Test]
    public function testCreateTokenAndFindIt(): void
    {
        $token = PasswordToken::create($this->db, $this->userId, 'reset_password');

        $row = PasswordToken::findByToken($this->db, $token);

        $this->assertNotNull($row);
        $this->assertSame($token, $row['token']);
        $this->assertSame((string) $this->userId, (string) $row['user_id']);
        $this->assertSame('reset_password', $row['type']);
    }

    // ===========================
    // EXPIRY ENFORCEMENT
    // ===========================

    #[Test]
    public function testExpiredTokenNotFound(): void
    {
        // Insert a token that has already expired
        $expiredToken = str_repeat('f', 64);
        $this->db->query(
            "INSERT INTO password_tokens (user_id, token, type, expires_at)
             VALUES (?, ?, ?, ?)",
            [$this->userId, $expiredToken, 'reset_password', date('Y-m-d H:i:s', time() - 7200)]
        );

        $row = PasswordToken::findByToken($this->db, $expiredToken);

        $this->assertNull($row);
    }

    // ===========================
    // USED TOKEN
    // ===========================

    #[Test]
    public function testUsedTokenNotFound(): void
    {
        $token = PasswordToken::create($this->db, $this->userId, 'reset_password');
        $row   = PasswordToken::findByToken($this->db, $token);

        PasswordToken::markUsed($this->db, (int) $row['id']);

        $this->assertNull(PasswordToken::findByToken($this->db, $token));
    }

    #[Test]
    public function testTokenCanOnlyBeUsedOnce(): void
    {
        $token = PasswordToken::create($this->db, $this->userId, 'reset_password');
        $row   = PasswordToken::findByToken($this->db, $token);

        // First use — valid
        $this->assertNotNull($row);
        PasswordToken::markUsed($this->db, (int) $row['id']);

        // Second lookup — should be null
        $this->assertNull(PasswordToken::findByToken($this->db, $token));
    }

    // ===========================
    // INVALIDATION
    // ===========================

    #[Test]
    public function testInvalidateInvalidatesPreviousTokens(): void
    {
        // Create multiple tokens by inserting directly (bypassing auto-invalidation in create())
        $future = date('Y-m-d H:i:s', time() + 86400);
        $tokenA = str_repeat('1', 64);
        $tokenB = str_repeat('2', 64);

        $this->db->query(
            "INSERT INTO password_tokens (user_id, token, type, expires_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)",
            [$this->userId, $tokenA, 'reset_password', $future,
             $this->userId, $tokenB, 'reset_password', $future]
        );

        PasswordToken::invalidateForUser($this->db, $this->userId);

        $this->assertNull(PasswordToken::findByToken($this->db, $tokenA));
        $this->assertNull(PasswordToken::findByToken($this->db, $tokenB));
    }

    // ===========================
    // PASSWORD CHANGE SIMULATION
    // ===========================

    #[Test]
    public function testSetPasswordChangesHash(): void
    {
        $newPassword = 'NewSecurePassword456!';
        $token       = PasswordToken::create($this->db, $this->userId, 'reset_password');
        $row         = PasswordToken::findByToken($this->db, $token);

        $this->assertNotNull($row, 'Token must be valid before resetting password');

        // Simulate the controller: update the user's password hash and mark token used
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->query(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$newHash, $this->userId]
        );
        PasswordToken::markUsed($this->db, (int) $row['id']);

        // Verify the new hash works
        $userRow = $this->db->query(
            "SELECT password_hash FROM users WHERE id = ?",
            [$this->userId]
        )->fetch();

        $this->assertTrue(password_verify($newPassword, $userRow['password_hash']));

        // Verify the token is now consumed
        $this->assertNull(PasswordToken::findByToken($this->db, $token));
    }
}
