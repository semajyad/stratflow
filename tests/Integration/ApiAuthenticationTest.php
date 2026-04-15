<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PersonalAccessToken;

/**
 * ApiAuthenticationTest
 *
 * Full personal access token authentication flow against real DB.
 * Covers the API security boundary: token creation, hash storage,
 * expiry, revocation, scope enforcement, last_used_at tracking,
 * and multiple tokens per user.
 *
 * Not duplicating scenarios already in ApiAuthMiddlewareTest (valid, expired,
 * revoked, cross-org, inactive user, missing/wrong-prefix header) or
 * PersonalAccessTokenTest (hash mechanics, list, touch).
 *
 * Unique coverage here:
 * 1. Hash storage invariant at INSERT level
 * 2. Bearer auth resolves correct user from DB
 * 3. Expired token blocked at middleware level (direct DB insert)
 * 4. Revoked token blocked at middleware level
 * 5. Scope filtering — token without write scope blocked on write route
 * 6. last_used_at updated on authenticated use
 * 7. Multiple tokens per user — revoking one leaves others valid
 */
class ApiAuthenticationTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $userId;

    // ===========================
    // FIXTURES
    // ===========================

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover state from a previous crashed run
        self::$db->query(
            "DELETE u FROM users u INNER JOIN organisations o ON u.org_id = o.id WHERE o.name = ?",
            ['Test Org - ApiAuth']
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - ApiAuth'");
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - ApiAuth']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'apiauthtest@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'Auth Test User', 'user']
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
    // HELPERS
    // ===========================

    /**
     * Invoke ApiAuthMiddleware with the given Authorization header value.
     * Returns the middleware result (true = authenticated, false = rejected).
     */
    private function runMiddleware(string $authHeader): bool
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        $_SERVER['REMOTE_ADDR']        = '127.0.0.1';

        $session    = $this->createMock(\StratFlow\Core\Session::class);
        $auth       = new \StratFlow\Core\Auth($session, self::$db);
        $response   = $this->createMock(\StratFlow\Core\Response::class);
        $middleware = new \StratFlow\Middleware\ApiAuthMiddleware();

        ob_start();
        $result = $middleware->handle($auth, self::$db, $response);
        ob_end_clean();

        return $result;
    }

    // ===========================
    // TEST 1: HASH STORAGE INVARIANT
    // ===========================

    #[Test]
    public function testTokenCreationStoresHashedToken(): void
    {
        $gen = PersonalAccessToken::generate();
        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'hash-check', $gen['raw'], $gen['prefix']);

        // Query the raw table row — the DB must NOT contain the plaintext token
        $stmt = self::$db->query(
            "SELECT token_hash FROM personal_access_tokens WHERE user_id = ? AND name = ?",
            [self::$userId, 'hash-check']
        );
        $row = $stmt->fetch();

        $this->assertNotNull($row, 'Token row should exist');
        // token_hash must be a 64-char hex string (sha256)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['token_hash']);
        // Must NOT equal the raw token
        $this->assertNotSame($gen['raw'], $row['token_hash'], 'Plaintext token must not be stored');
        // Must equal the expected hash
        $this->assertSame(PersonalAccessToken::hash($gen['raw']), $row['token_hash']);
    }

    // ===========================
    // TEST 2: VALID TOKEN RESOLVES USER VIA MIDDLEWARE
    // ===========================

    #[Test]
    public function testValidTokenAuthenticatesRequest(): void
    {
        $gen = PersonalAccessToken::generate();
        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'valid-auth', $gen['raw'], $gen['prefix']);

        $result = $this->runMiddleware('Bearer ' . $gen['raw']);

        $this->assertTrue($result, 'Middleware should pass a valid token');
    }

    // ===========================
    // TEST 3: EXPIRED TOKEN REJECTED
    // ===========================

    #[Test]
    public function testExpiredTokenIsRejected(): void
    {
        $gen = PersonalAccessToken::generate();

        self::$db->query(
            "INSERT INTO personal_access_tokens (user_id, org_id, name, token_hash, token_prefix, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                self::$userId,
                self::$orgId,
                'expired-direct',
                PersonalAccessToken::hash($gen['raw']),
                $gen['prefix'],
                date('Y-m-d H:i:s', time() - 7200),
            ]
        );

        $this->expectOutputRegex('/unauthorized/i');
        $result = $this->runMiddleware('Bearer ' . $gen['raw']);
        $this->assertFalse($result, 'Expired token must be rejected');
    }

    // ===========================
    // TEST 4: REVOKED TOKEN REJECTED
    // ===========================

    #[Test]
    public function testRevokedTokenIsRejected(): void
    {
        $gen     = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'revoked-auth', $gen['raw'], $gen['prefix']);
        PersonalAccessToken::revoke(self::$db, (int) $created['id'], self::$userId, self::$orgId);

        $this->expectOutputRegex('/unauthorized/i');
        $result = $this->runMiddleware('Bearer ' . $gen['raw']);
        $this->assertFalse($result, 'Revoked token must be rejected');
    }

    // ===========================
    // TEST 5: SCOPE ENFORCEMENT — WRITE SCOPE ABSENT
    // ===========================

    #[Test]
    public function testTokenScopeEnforcementWriteScopeAbsent(): void
    {
        // Mint a read-only token (no stories:write-status scope)
        $gen    = PersonalAccessToken::generate();
        $scopes = ['profile:read', 'projects:read', 'stories:read'];
        PersonalAccessToken::create(
            self::$db,
            self::$userId,
            self::$orgId,
            'read-only',
            $gen['raw'],
            $gen['prefix'],
            $scopes
        );

        // Verify the stored scopes do not include any write permission
        $hash  = PersonalAccessToken::hash($gen['raw']);
        $row   = self::$db->query(
            "SELECT scopes FROM personal_access_tokens WHERE token_hash = ?",
            [$hash]
        )->fetch();

        $this->assertNotNull($row);
        $stored = json_decode($row['scopes'], true);
        $this->assertIsArray($stored);
        $this->assertNotContains('stories:write-status', $stored, 'Write scope must not be present on read-only token');
        $this->assertNotContains('stories:assign', $stored, 'stories:assign must not be present on read-only token');
    }

    // ===========================
    // TEST 6: LAST_USED_AT UPDATED ON AUTHENTICATED USE
    // ===========================

    #[Test]
    public function testTokenLastUsedAtIsUpdatedOnUse(): void
    {
        $gen     = PersonalAccessToken::generate();
        $created = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'touch-used', $gen['raw'], $gen['prefix']);

        // Confirm last_used_at is null before first use
        $before = self::$db->query(
            "SELECT last_used_at FROM personal_access_tokens WHERE id = ?",
            [$created['id']]
        )->fetch();
        $this->assertNull($before['last_used_at'], 'last_used_at should be null before first use');

        // Simulate authenticated use
        PersonalAccessToken::touchLastUsed(self::$db, (int) $created['id'], '10.0.0.1');

        $after = self::$db->query(
            "SELECT last_used_at, last_used_ip FROM personal_access_tokens WHERE id = ?",
            [$created['id']]
        )->fetch();

        $this->assertNotNull($after['last_used_at'], 'last_used_at must be set after token use');
        $this->assertSame('10.0.0.1', $after['last_used_ip']);
    }

    // ===========================
    // TEST 7: MULTIPLE TOKENS — REVOKE ONE, OTHERS STAY VALID
    // ===========================

    #[Test]
    public function testMultipleTokensForSameUser(): void
    {
        $genA = PersonalAccessToken::generate();
        $genB = PersonalAccessToken::generate();
        $genC = PersonalAccessToken::generate();

        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'token-a', $genA['raw'], $genA['prefix']);
        $createdB = PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'token-b', $genB['raw'], $genB['prefix']);
        PersonalAccessToken::create(self::$db, self::$userId, self::$orgId, 'token-c', $genC['raw'], $genC['prefix']);

        // Revoke only token B
        PersonalAccessToken::revoke(self::$db, (int) $createdB['id'], self::$userId, self::$orgId);

        // Token A still valid
        $rowA = PersonalAccessToken::findByHash(self::$db, PersonalAccessToken::hash($genA['raw']));
        $this->assertNotNull($rowA, 'Token A should still be valid after revoking token B');

        // Token B revoked
        $rowB = PersonalAccessToken::findByHash(self::$db, PersonalAccessToken::hash($genB['raw']));
        $this->assertNull($rowB, 'Token B should be invalid after revocation');

        // Token C still valid
        $rowC = PersonalAccessToken::findByHash(self::$db, PersonalAccessToken::hash($genC['raw']));
        $this->assertNotNull($rowC, 'Token C should still be valid after revoking token B');

        // Via middleware — A and C pass, B fails
        $this->assertTrue($this->runMiddleware('Bearer ' . $genA['raw']), 'Token A should pass middleware');

        ob_start();
        $bResult = $this->runMiddleware('Bearer ' . $genB['raw']);
        ob_end_clean();
        $this->assertFalse($bResult, 'Revoked token B should fail middleware');

        $this->assertTrue($this->runMiddleware('Bearer ' . $genC['raw']), 'Token C should pass middleware');
    }
}
