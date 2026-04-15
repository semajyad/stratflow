<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Core\SecretManager;
use StratFlow\Models\Integration;

/**
 * IntegrationTest
 *
 * Unit tests for the Integration model — all DB calls mocked.
 * Covers encryption, decryption, CRUD operations, and token management.
 */
class IntegrationTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('10');
        return $db;
    }

    private function integrationRow(): array
    {
        return [
            'id'                => 10,
            'org_id'            => 1,
            'provider'          => 'jira',
            'display_name'      => 'Jira Cloud',
            'cloud_id'          => 'abc123',
            'access_token'      => 'token_encrypted',
            'refresh_token'     => 'refresh_encrypted',
            'token_expires_at'  => '2025-12-31 23:59:59',
            'site_url'          => 'https://example.atlassian.net',
            'config_json'       => '{}',
            'status'            => 'active',
            'last_sync_at'      => '2025-04-14 12:00:00',
            'error_message'     => null,
            'error_count'       => 0,
            'created_at'        => '2025-01-01 00:00:00',
            'updated_at'        => '2025-04-14 12:00:00',
            'installation_id'   => null,
            'account_login'     => null,
            'token_iv'          => null,
            'token_tag'         => null,
        ];
    }

    // ===========================
    // ENCRYPT / DECRYPT
    // ===========================

    #[Test]
    public function encryptTokenReturnsArray(): void
    {
        $result = Integration::encryptToken('test_token_value');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ciphertext', $result);
        $this->assertArrayHasKey('iv', $result);
        $this->assertArrayHasKey('tag', $result);
    }

    #[Test]
    public function encryptTokenWithEmptyStringReturnsCiphertext(): void
    {
        $result = Integration::encryptToken('');
        $this->assertIsArray($result);
        // encryptToken on empty string may still encrypt
        $this->assertArrayHasKey('ciphertext', $result);
    }

    #[Test]
    public function decryptTokenReturnsCiphertext(): void
    {
        $ciphertext = 'some_ciphertext';
        $result = Integration::decryptToken($ciphertext, null, null);
        $this->assertIsString($result);
        // When no encryption configured, falls back to plaintext
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function decryptTokenWithJsonDecodedValue(): void
    {
        $protected = Integration::encryptToken('token123');
        $decrypted = Integration::decryptToken($protected['ciphertext'], $protected['iv'], $protected['tag']);
        // Should return plaintext token or fallback
        $this->assertIsString($decrypted);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Integration::create($db, [
            'org_id'       => 1,
            'provider'     => 'jira',
            'display_name' => 'Jira Cloud',
            'cloud_id'     => 'abc123',
        ]);
        $this->assertSame(10, $id);
    }

    #[Test]
    public function createWithAccessTokenCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('5');

        Integration::create($db, [
            'org_id'       => 1,
            'provider'     => 'github',
            'access_token' => 'ghp_abc123',
        ]);

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function createWithDefaultStatusDisconnected(): void
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

        Integration::create($db, [
            'org_id'   => 1,
            'provider' => 'azure',
        ]);

        // Status should default to 'disconnected'
        $this->assertSame('disconnected', $capturedParams[':status']);
    }

    // ===========================
    // FIND BY ORG AND PROVIDER
    // ===========================

    #[Test]
    public function findByOrgAndProviderReturnsMappedRow(): void
    {
        $db  = $this->makeDb($this->integrationRow());
        $row = Integration::findByOrgAndProvider($db, 1, 'jira');
        $this->assertIsArray($row);
        $this->assertSame(10, $row['id']);
        $this->assertSame('jira', $row['provider']);
    }

    #[Test]
    public function findByOrgAndProviderReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Integration::findByOrgAndProvider($db, 1, 'nonexistent');
        $this->assertNull($row);
    }

    #[Test]
    public function findByOrgAndProviderPassesCorrectParams(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Integration::findByOrgAndProvider($db, 5, 'github');

        $this->assertSame(5, $capturedParams[':org_id']);
        $this->assertSame('github', $capturedParams[':provider']);
    }

    // ===========================
    // FIND BY ORG
    // ===========================

    #[Test]
    public function findByOrgReturnsArray(): void
    {
        $row1 = $this->integrationRow();
        $row1['id'] = 1;
        $row2 = $this->integrationRow();
        $row2['id'] = 2;
        $row2['provider'] = 'github';

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([$row1, $row2]);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $rows = Integration::findByOrg($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
    }

    #[Test]
    public function findByOrgReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Integration::findByOrg($db, 99);
        $this->assertSame([], $rows);
    }

    #[Test]
    public function findByOrgPassesOrgIdParameter(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Integration::findByOrg($db, 7);

        $this->assertSame(7, $capturedParams[':org_id']);
    }

    // ===========================
    // FIND ACTIVE BY INSTALLATION ID
    // ===========================

    #[Test]
    public function findActiveByInstallationIdReturnsMappedRow(): void
    {
        $row = $this->integrationRow();
        $row['provider'] = 'github';
        $row['installation_id'] = 456;
        $row['status'] = 'active';

        $db = $this->makeDb($row);
        $result = Integration::findActiveByInstallationId($db, 456);

        $this->assertIsArray($result);
        $this->assertSame(456, $result['installation_id']);
    }

    #[Test]
    public function findActiveByInstallationIdReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDb(null);
        $result = Integration::findActiveByInstallationId($db, 999);
        $this->assertNull($result);
    }

    #[Test]
    public function findActiveByInstallationIdPassesCorrectParams(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Integration::findActiveByInstallationId($db, 789);

        $this->assertSame(789, $capturedParams[':installation_id']);
    }

    // ===========================
    // FIND ACTIVE GITHUB BY ORG
    // ===========================

    #[Test]
    public function findActiveGithubByOrgReturnsArray(): void
    {
        $row = $this->integrationRow();
        $row['provider'] = 'github';
        $row['status'] = 'active';
        $row['repo_count'] = 5;

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([$row]);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $rows = Integration::findActiveGithubByOrg($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function findActiveGithubByOrgReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Integration::findActiveGithubByOrg($db, 99);
        $this->assertSame([], $rows);
    }

    #[Test]
    public function findActiveGithubByOrgPassesOrgIdParameter(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Integration::findActiveGithubByOrg($db, 3);

        $this->assertSame(3, $capturedParams[':org_id']);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRow(): void
    {
        $db  = $this->makeDb($this->integrationRow());
        $row = Integration::findById($db, 10);
        $this->assertIsArray($row);
        $this->assertSame(10, $row['id']);
        $this->assertSame('jira', $row['provider']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Integration::findById($db, 999);
        $this->assertNull($row);
    }

    #[Test]
    public function findByIdPassesCorrectIdParameter(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Integration::findById($db, 42);

        $this->assertSame(42, $capturedParams[':id']);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function updateWithAllowedColumnCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );

        Integration::update($db, 10, ['display_name' => 'Updated Name']);

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Integration::update($db, 10, ['org_id' => 99]); // not in UPDATABLE_COLUMNS
    }

    #[Test]
    public function updateWithEmptyDataSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Integration::update($db, 10, []);
    }

    #[Test]
    public function updateFiltersDisallowedColumns(): void
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

        Integration::update($db, 10, [
            'display_name' => 'New Name',
            'org_id'       => 99, // disallowed
            'status'       => 'active',
        ]);

        // org_id should not be in params
        $this->assertArrayNotHasKey(':org_id', $capturedParams ?? []);
    }

    // ===========================
    // UPDATE TOKENS
    // ===========================

    #[Test]
    public function updateTokensCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );

        Integration::updateTokens($db, 10, 'new_access', 'new_refresh', '2025-12-31 23:59:59');

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateTokensPassesCorrectParams(): void
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

        Integration::updateTokens($db, 42, 'access123', 'refresh123', '2026-01-01 00:00:00');

        $this->assertSame(42, $capturedParams[':id']);
        $this->assertSame('2026-01-01 00:00:00', $capturedParams[':expires_at']);
        // Access and refresh tokens should be encrypted
        $this->assertNotEmpty($capturedParams[':access_token']);
        $this->assertNotEmpty($capturedParams[':refresh_token']);
    }

    // ===========================
    // RECORD ERROR
    // ===========================

    #[Test]
    public function recordErrorCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );

        Integration::recordError($db, 10, 'Connection timeout');

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function recordErrorPassesCorrectParams(): void
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

        Integration::recordError($db, 15, 'Auth failed');

        $this->assertSame(15, $capturedParams[':id']);
        $this->assertSame('Auth failed', $capturedParams[':message']);
    }

    // ===========================
    // CLEAR ERROR
    // ===========================

    #[Test]
    public function clearErrorCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );

        Integration::clearError($db, 10);

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function clearErrorPassesCorrectId(): void
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

        Integration::clearError($db, 25);

        $this->assertSame(25, $capturedParams[':id']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function deleteCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );

        Integration::delete($db, 10);

        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function deletePassesCorrectId(): void
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

        Integration::delete($db, 50);

        $this->assertSame(50, $capturedParams[':id']);
    }
}
