<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Response;
use StratFlow\Core\Session;
use StratFlow\Models\PersonalAccessToken;

/**
 * ApiAuthMiddlewareTest
 *
 * Integration test — requires a real database. Tests all six critical
 * scenarios for the ApiAuthMiddleware PAT validation logic.
 *
 * Scenarios:
 * 1. Valid token — passes
 * 2. Missing Authorization header — 401
 * 3. Wrong prefix (not sf_pat_) — 401
 * 4. Revoked token — 401
 * 5. Expired token — 401
 * 6. Cross-org token (token belongs to org A, user is now in org B) — 401
 */
class ApiAuthMiddlewareTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - ApiAuthMW'");
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - ApiAuthMW']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'apiauth@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'ApiAuth User', 'user']
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

    private function runMiddleware(string $authHeader): bool
    {
        // Set the header in $_SERVER
        $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $session  = $this->createMock(Session::class);
        $auth     = new Auth($session, self::$db);
        $response = $this->createMock(Response::class);

        $middleware = new \StratFlow\Middleware\ApiAuthMiddleware();

        // Capture output buffering to prevent JSON from polluting test output
        ob_start();
        $result = $middleware->handle($auth, self::$db, $response);
        ob_end_clean();

        return $result;
    }

    // ===========================
    // SCENARIO 1: VALID
    // ===========================

    #[Test]
    public function testValidTokenPasses(): void
    {
        $gen     = PersonalAccessToken::generate();
        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'valid', $gen['raw'], $gen['prefix']);

        $result = $this->runMiddleware('Bearer ' . $gen['raw']);
        $this->assertTrue($result);
    }

    // ===========================
    // SCENARIO 2: MISSING HEADER
    // ===========================

    #[Test]
    public function testMissingAuthHeaderFails(): void
    {
        $this->expectOutputRegex('/unauthorized/');
        $result = $this->runMiddleware('');
        $this->assertFalse($result);
    }

    // ===========================
    // SCENARIO 3: WRONG PREFIX
    // ===========================

    #[Test]
    public function testWrongPrefixFails(): void
    {
        $this->expectOutputRegex('/unauthorized/');
        $result = $this->runMiddleware('Bearer ghp_somegithubtoken');
        $this->assertFalse($result);
    }

    // ===========================
    // SCENARIO 4: REVOKED
    // ===========================

    #[Test]
    public function testRevokedTokenFails(): void
    {
        $gen     = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'revoked', $gen['raw'], $gen['prefix']);
        PersonalAccessToken::revoke(self::$db, (int) $created['id'], self::$userId, self::$orgId);

        $this->expectOutputRegex('/unauthorized/');
        $result = $this->runMiddleware('Bearer ' . $gen['raw']);
        $this->assertFalse($result);
    }

    // ===========================
    // SCENARIO 5: EXPIRED
    // ===========================

    #[Test]
    public function testExpiredTokenFails(): void
    {
        $gen = PersonalAccessToken::generate();

        // Insert with a past expires_at directly
        self::$db->query(
            "INSERT INTO personal_access_tokens (user_id, org_id, name, token_hash, token_prefix, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                self::$userId, self::$orgId, 'expired',
                PersonalAccessToken::hash($gen['raw']), $gen['prefix'],
                date('Y-m-d H:i:s', time() - 3600),
            ]
        );

        $this->expectOutputRegex('/unauthorized/');
        $result = $this->runMiddleware('Bearer ' . $gen['raw']);
        $this->assertFalse($result);
    }

    // ===========================
    // SCENARIO 6: CROSS-ORG
    // ===========================

    #[Test]
    public function testCrossOrgTokenFails(): void
    {
        // Create a token for our user/org, but then change the token's org_id
        // to simulate the token belonging to a different org
        $gen     = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'cross', $gen['raw'], $gen['prefix']);

        // Corrupt: set org_id to a different value so user.org_id != token.org_id
        self::$db->query(
            "UPDATE personal_access_tokens SET org_id = 99999 WHERE id = ?",
            [$created['id']]
        );

        $this->expectOutputRegex('/unauthorized/');
        $result = $this->runMiddleware('Bearer ' . $gen['raw']);
        $this->assertFalse($result);
    }

    #[Test]
    public function testInactiveUserTokenFails(): void
    {
        $gen = PersonalAccessToken::generate();
        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'inactive', $gen['raw'], $gen['prefix']);
        self::$db->query("UPDATE users SET is_active = 0 WHERE id = ?", [self::$userId]);

        $this->expectOutputRegex('/unauthorized/');
        $result = $this->runMiddleware('Bearer ' . $gen['raw']);

        self::$db->query("UPDATE users SET is_active = 1 WHERE id = ?", [self::$userId]);
        $this->assertFalse($result);
    }
}
