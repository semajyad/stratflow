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
 * Unit tests for the PasswordToken model — all DB calls mocked.
 * Tests token creation, validation, lookup, and cleanup.
 */
class PasswordTokenTest extends TestCase
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

        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'lastInsertId'])
            ->getMock();
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('1');
        return $db;
    }

    private function tokenRow(): array
    {
        return [
            'id'         => 5,
            'user_id'    => 42,
            'token'      => hash('sha256', 'test_token_abc123'),
            'type'       => 'reset_password',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'used_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function expiredTokenRow(): array
    {
        $row            = $this->tokenRow();
        $row['expires_at'] = date('Y-m-d H:i:s', time() - 3600);
        return $row;
    }

    private function usedTokenRow(): array
    {
        $row          = $this->tokenRow();
        $row['used_at'] = date('Y-m-d H:i:s');
        return $row;
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createGeneratesTokenAndReturnsString(): void
    {
        $db = $this->makeDb();
        $token = PasswordToken::create($db, 42, 'reset_password');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
        // Verify it's valid hex
        $this->assertNotFalse(@hex2bin($token));
    }

    #[Test]
    public function createInvalidatesExistingTokensForUser(): void
    {
        $capturedQueries = [];
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($stmt, &$capturedQueries): \PDOStatement {
                $capturedQueries[] = ['sql' => $sql, 'params' => $params];
                return $stmt;
            }
        );

        PasswordToken::create($db, 42, 'reset_password');

        // First query should be the invalidate
        $this->assertCount(2, $capturedQueries);
        $this->assertStringContainsString('UPDATE password_tokens SET used_at = NOW()', $capturedQueries[0]['sql']);
        $this->assertSame(42, $capturedQueries[0]['params'][':user_id']);
    }

    #[Test]
    public function createInsertsTokenWithCorrectType(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($stmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'INSERT')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        PasswordToken::create($db, 42, 'set_password');

        $this->assertSame('set_password', $capturedParams[':type']);
    }

    #[Test]
    public function createSetsExpiresAtTo24HoursInFuture(): void
    {
        $beforeTime = time() + 86400;
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($stmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'INSERT')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        PasswordToken::create($db, 42, 'reset_password');

        $expiresAt = strtotime($capturedParams[':expires_at']);
        $this->assertGreaterThanOrEqual($beforeTime - 2, $expiresAt);
        $this->assertLessThanOrEqual($beforeTime + 2, $expiresAt);
    }

    #[Test]
    public function createInsertsHashedToken(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($stmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'INSERT')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        $token = PasswordToken::create($db, 42, 'reset_password');
        $expectedHash = hash('sha256', $token);

        $this->assertSame($expectedHash, $capturedParams[':token']);
    }

    // ===========================
    // FIND BY TOKEN
    // ===========================

    #[Test]
    public function findByTokenReturnsRowWhenValid(): void
    {
        $db = $this->makeDb($this->tokenRow());
        $row = PasswordToken::findByToken($db, 'test_token_abc123');

        $this->assertIsArray($row);
        $this->assertSame(5, $row['id']);
        $this->assertSame(42, $row['user_id']);
        $this->assertSame('reset_password', $row['type']);
    }

    #[Test]
    public function findByTokenReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDb(null);
        $row = PasswordToken::findByToken($db, 'nonexistent_token');

        $this->assertNull($row);
    }

    #[Test]
    public function findByTokenRejectsExpiredToken(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        $row = PasswordToken::findByToken($db, 'expired_token');

        $this->assertStringContainsString('expires_at > NOW()', $capturedSql);
        $this->assertNull($row);
    }

    #[Test]
    public function findByTokenRejectsAlreadyUsedToken(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        $row = PasswordToken::findByToken($db, 'used_token');

        $this->assertStringContainsString('used_at IS NULL', $capturedSql);
        $this->assertNull($row);
    }

    #[Test]
    public function findByTokenHashesTheToken(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        $token = 'my_plaintext_token_xyz';
        PasswordToken::findByToken($db, $token);

        $expectedHash = hash('sha256', $token);
        $this->assertSame($expectedHash, $capturedParams[':token']);
    }

    #[Test]
    public function findByTokenSupportsLegacyUnhashedTokens(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        $token = 'legacy_unhashed_token';
        PasswordToken::findByToken($db, $token);

        // Should check both hashed and legacy unhashed
        $this->assertSame(hash('sha256', $token), $capturedParams[':token']);
        $this->assertSame($token, $capturedParams[':legacy_token']);
    }

    // ===========================
    // MARK USED
    // ===========================

    #[Test]
    public function markUsedUpdatesTokenById(): void
    {
        $capturedParams = null;
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                $capturedParams = $params;
                return $stmt;
            }
        );

        PasswordToken::markUsed($db, 5);

        $this->assertStringContainsString('UPDATE password_tokens SET used_at = NOW()', $capturedSql);
        $this->assertSame(5, $capturedParams[':id']);
    }

    #[Test]
    public function markUsedReturnsVoid(): void
    {
        $db = $this->makeDb();
        $result = PasswordToken::markUsed($db, 5);

        $this->assertNull($result);
    }

    // ===========================
    // DELETE EXPIRED
    // ===========================

    #[Test]
    public function deleteExpiredCallsDeleteQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql) use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = str_contains($sql, 'DELETE FROM password_tokens');
                return $stmt;
            }
        );

        PasswordToken::deleteExpired($db);

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function deleteExpiredFiltersExpiredTokens(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        PasswordToken::deleteExpired($db);

        $this->assertStringContainsString('expires_at < NOW()', $capturedSql);
    }

    #[Test]
    public function deleteExpiredReturnsVoid(): void
    {
        $db = $this->makeDb();
        $result = PasswordToken::deleteExpired($db);

        $this->assertNull($result);
    }

    // ===========================
    // INVALIDATE FOR USER
    // ===========================

    #[Test]
    public function invalidateForUserUpdatesTokensForUser(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        PasswordToken::invalidateForUser($db, 42);

        $this->assertSame(42, $capturedParams[':user_id']);
    }

    #[Test]
    public function invalidateForUserMarksTokensAsUsed(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        PasswordToken::invalidateForUser($db, 42);

        $this->assertStringContainsString('UPDATE password_tokens SET used_at = NOW()', $capturedSql);
    }

    #[Test]
    public function invalidateForUserOnlyInvalidatesUnusedTokens(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        PasswordToken::invalidateForUser($db, 42);

        $this->assertStringContainsString('used_at IS NULL', $capturedSql);
    }

    #[Test]
    public function invalidateForUserReturnsVoid(): void
    {
        $db = $this->makeDb();
        $result = PasswordToken::invalidateForUser($db, 42);

        $this->assertNull($result);
    }

    // ===========================
    // INTEGRATION SCENARIOS
    // ===========================

    #[Test]
    public function creatingTokenInvalidatesPreviousToken(): void
    {
        // Simulates the workflow: create first token, then create second
        $callCount = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql) use ($stmt, &$callCount): \PDOStatement {
                if (str_contains($sql, 'UPDATE')) {
                    $callCount++;
                }
                return $stmt;
            }
        );

        // First token creation invalidates any existing (none in this case)
        PasswordToken::create($db, 42, 'reset_password');
        // Second token creation should invalidate the first
        PasswordToken::create($db, 42, 'reset_password');

        // Should have called UPDATE at least twice (once per create)
        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    #[Test]
    public function findByTokenWithExpiredTokenReturnsNull(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        $result = PasswordToken::findByToken($db, 'expired_token');

        $this->assertNull($result);
        $this->assertStringContainsString('expires_at > NOW()', $capturedSql);
        $this->assertStringContainsString('used_at IS NULL', $capturedSql);
    }

    #[Test]
    public function tokenWorkflow(): void
    {
        // Create token
        $db = $this->makeDb();
        $token = PasswordToken::create($db, 42, 'reset_password');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        // Find the token
        $row = $this->tokenRow();
        $row['token'] = hash('sha256', $token);
        $db = $this->makeDb($row);
        $found = PasswordToken::findByToken($db, $token);
        $this->assertNotNull($found);
        $this->assertSame(5, $found['id']);

        // Mark as used
        $db = $this->makeDb();
        PasswordToken::markUsed($db, 5);
        $this->assertTrue(true); // No exception = success

        // Cleanup expired
        $db = $this->makeDb();
        PasswordToken::deleteExpired($db);
        $this->assertTrue(true); // No exception = success
    }
}
