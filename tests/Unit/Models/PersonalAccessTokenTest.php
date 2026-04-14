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
 * Unit tests for the PersonalAccessToken model — all DB calls mocked.
 */
class PersonalAccessTokenTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);
        $stmt->method('rowCount')->willReturn($fetchRow ? 1 : 0);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('77');
        return $db;
    }

    // ===========================
    // GENERATE
    // ===========================

    #[Test]
    public function generateReturnsRawTokenWithCorrectPrefix(): void
    {
        $result = PersonalAccessToken::generate();
        $this->assertArrayHasKey('raw', $result);
        $this->assertArrayHasKey('prefix', $result);
        $this->assertStringStartsWith('sf_pat_', $result['raw']);
    }

    #[Test]
    public function generateRawTokenHasCorrectLength(): void
    {
        $result = PersonalAccessToken::generate();
        // sf_pat_ (7) + 43 base64url chars = 50 chars
        $this->assertGreaterThanOrEqual(50, strlen($result['raw']));
    }

    #[Test]
    public function generatePrefixIsFifteenChars(): void
    {
        $result = PersonalAccessToken::generate();
        $this->assertSame(15, strlen($result['prefix']));
    }

    #[Test]
    public function generateReturnsDifferentTokensEachCall(): void
    {
        $a = PersonalAccessToken::generate();
        $b = PersonalAccessToken::generate();
        $this->assertNotSame($a['raw'], $b['raw']);
    }

    // ===========================
    // HASH
    // ===========================

    #[Test]
    public function hashReturnsSha256HexDigest(): void
    {
        $hash = PersonalAccessToken::hash('sf_pat_test');
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function hashIsDeterministic(): void
    {
        $raw = 'sf_pat_deterministic_test';
        $this->assertSame(PersonalAccessToken::hash($raw), PersonalAccessToken::hash($raw));
    }

    // ===========================
    // DEFAULT SCOPES
    // ===========================

    #[Test]
    public function defaultScopesConstantContainsExpectedScopes(): void
    {
        $this->assertContains('profile:read', PersonalAccessToken::DEFAULT_SCOPES);
        $this->assertContains('projects:read', PersonalAccessToken::DEFAULT_SCOPES);
        $this->assertContains('stories:read', PersonalAccessToken::DEFAULT_SCOPES);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsArrayWithRawToken(): void
    {
        $db     = $this->makeDb();
        [$raw, $prefix] = array_values(PersonalAccessToken::generate());
        $result = PersonalAccessToken::create($db, 1, 1, 'My Token', $raw, $prefix);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('raw', $result);
        $this->assertSame($raw, $result['raw']);
    }

    #[Test]
    public function createStoresTokenPrefixButNotRaw(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('1');

        ['raw' => $raw, 'prefix' => $prefix] = PersonalAccessToken::generate();
        PersonalAccessToken::create($db, 1, 1, 'Test', $raw, $prefix);

        // token_hash (not the raw) is stored
        $this->assertArrayHasKey(':token_hash', $capturedParams);
        $this->assertSame(PersonalAccessToken::hash($raw), $capturedParams[':token_hash']);
        $this->assertArrayHasKey(':token_prefix', $capturedParams);
        $this->assertSame($prefix, $capturedParams[':token_prefix']);
    }

    #[Test]
    public function createUsesDefaultScopesWhenNoneProvided(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('1');

        ['raw' => $raw, 'prefix' => $prefix] = PersonalAccessToken::generate();
        PersonalAccessToken::create($db, 1, 1, 'Test', $raw, $prefix);

        $storedScopes = json_decode($capturedParams[':scopes'], true);
        $this->assertContains('profile:read', $storedScopes);
    }

    // ===========================
    // FIND BY HASH
    // ===========================

    #[Test]
    public function findByHashReturnsMappedRowWhenFound(): void
    {
        $tokenRow = ['id' => 77, 'user_id' => 1, 'org_id' => 1, 'name' => 'My Token', 'token_prefix' => 'sf_pat_abcdef'];
        $db       = $this->makeDb($tokenRow);
        $row      = PersonalAccessToken::findByHash($db, 'abc123hash');
        $this->assertIsArray($row);
        $this->assertSame(77, $row['id']);
    }

    #[Test]
    public function findByHashReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = PersonalAccessToken::findByHash($db, 'nosuchhash');
        $this->assertNull($row);
    }

    // ===========================
    // LIST FOR USER
    // ===========================

    #[Test]
    public function listForUserReturnsArray(): void
    {
        $tokenRow = ['id' => 77, 'name' => 'My Token', 'token_prefix' => 'sf_pat_xx'];
        $db       = $this->makeDb($tokenRow);
        $rows     = PersonalAccessToken::listForUser($db, 1, 1);
        $this->assertIsArray($rows);
    }

    #[Test]
    public function listForUserReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = PersonalAccessToken::listForUser($db, 1, 1);
        $this->assertSame([], $rows);
    }

    // ===========================
    // REVOKE
    // ===========================

    #[Test]
    public function revokeReturnsTrueWhenRowUpdated(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = PersonalAccessToken::revoke($db, 77, 1, 1);
        $this->assertTrue($result);
    }

    #[Test]
    public function revokeReturnsFalseWhenNoRowUpdated(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = PersonalAccessToken::revoke($db, 999, 1, 1);
        $this->assertFalse($result);
    }

    // ===========================
    // TOUCH LAST USED
    // ===========================

    #[Test]
    public function touchLastUsedSilentlySwallowsExceptions(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willThrowException(new \RuntimeException('DB error'));

        // Must not throw
        PersonalAccessToken::touchLastUsed($db, 1, '127.0.0.1');
        $this->assertTrue(true);
    }
}
