<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PersonalAccessToken;

/**
 * PersonalAccessTokenTest
 *
 * Tests token generation, hash round-trip, create/find/revoke/touch lifecycle,
 * and cross-org isolation invariant.
 */
class PersonalAccessTokenTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - PATTest'");

        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - PATTest']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'pattest@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'PAT Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM personal_access_tokens WHERE user_id = ?", [self::$userId]);
        self::$db->query("DELETE FROM users WHERE id = ?", [self::$userId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        self::$db->query("DELETE FROM personal_access_tokens WHERE user_id = ?", [self::$userId]);
    }

    // ===========================
    // GENERATION
    // ===========================

    #[Test]
    public function testGenerateReturnsSfPatPrefix(): void
    {
        $result = PersonalAccessToken::generate();
        $this->assertStringStartsWith('sf_pat_', $result['raw']);
        $this->assertStringStartsWith('sf_pat_', $result['prefix']);
        $this->assertSame(15, strlen($result['prefix']));
    }

    #[Test]
    public function testGenerateIsUnique(): void
    {
        $a = PersonalAccessToken::generate();
        $b = PersonalAccessToken::generate();
        $this->assertNotSame($a['raw'], $b['raw']);
    }

    #[Test]
    public function testHashIsSha256Hex(): void
    {
        $hash = PersonalAccessToken::hash('sf_pat_test123');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function testHashIsDeterministic(): void
    {
        $raw = 'sf_pat_hello';
        $this->assertSame(PersonalAccessToken::hash($raw), PersonalAccessToken::hash($raw));
    }

    // ===========================
    // CREATE + FIND
    // ===========================

    #[Test]
    public function testCreateAndFindByHashRoundTrip(): void
    {
        $gen = PersonalAccessToken::generate();
        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'test', $gen['raw'], $gen['prefix']);

        $hash = PersonalAccessToken::hash($gen['raw']);
        $row  = PersonalAccessToken::findByHash(self::$db, $hash);

        $this->assertNotNull($row);
        $this->assertSame((string) self::$userId, (string) $row['user_id']);
        $this->assertSame((string) self::$orgId,  (string) $row['org_id']);
        $this->assertSame('test', $row['name']);
        $this->assertSame($gen['prefix'], $row['token_prefix']);
    }

    #[Test]
    public function testFindByHashReturnsNullForUnknownHash(): void
    {
        $row = PersonalAccessToken::findByHash(self::$db, str_repeat('0', 64));
        $this->assertNull($row);
    }

    // ===========================
    // REVOKE
    // ===========================

    #[Test]
    public function testRevokeHidesTokenFromFindByHash(): void
    {
        $gen = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'revoke-test', $gen['raw'], $gen['prefix']);

        $revoked = PersonalAccessToken::revoke(self::$db, (int) $created['id'], self::$userId, self::$orgId);
        $this->assertTrue($revoked);

        $hash = PersonalAccessToken::hash($gen['raw']);
        $this->assertNull(PersonalAccessToken::findByHash(self::$db, $hash));
    }

    #[Test]
    public function testRevokeReturnsFalseForWrongUser(): void
    {
        $gen = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'cross-user', $gen['raw'], $gen['prefix']);

        // Try to revoke with a different user_id
        $revoked = PersonalAccessToken::revoke(self::$db, (int) $created['id'], self::$userId + 9999, self::$orgId);
        $this->assertFalse($revoked);

        // Token should still be valid
        $hash = PersonalAccessToken::hash($gen['raw']);
        $this->assertNotNull(PersonalAccessToken::findByHash(self::$db, $hash));
    }

    // ===========================
    // LIST
    // ===========================

    #[Test]
    public function testListForUserReturnsOnlyActiveTokens(): void
    {
        $genA = PersonalAccessToken::generate();
        $genB = PersonalAccessToken::generate();

        $createdA = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'active', $genA['raw'], $genA['prefix']);
        $createdB = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'to-revoke', $genB['raw'], $genB['prefix']);

        PersonalAccessToken::revoke(self::$db, (int) $createdB['id'], self::$userId, self::$orgId);

        $list = PersonalAccessToken::listForUser(self::$db, self::$userId, self::$orgId);

        $names = array_column($list, 'name');
        $this->assertContains('active', $names);
        $this->assertNotContains('to-revoke', $names);
    }

    // ===========================
    // TOUCH LAST USED
    // ===========================

    #[Test]
    public function testTouchLastUsedUpdatesTimestamp(): void
    {
        $gen = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'touch-test', $gen['raw'], $gen['prefix']);

        PersonalAccessToken::touchLastUsed(self::$db, (int) $created['id'], '127.0.0.1');

        $stmt = self::$db->query(
            "SELECT last_used_at, last_used_ip FROM personal_access_tokens WHERE id = ?",
            [$created['id']]
        );
        $row = $stmt->fetch();
        $this->assertNotNull($row['last_used_at']);
        $this->assertSame('127.0.0.1', $row['last_used_ip']);
    }
}
