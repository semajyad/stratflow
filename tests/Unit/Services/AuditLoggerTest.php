<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Services\AuditLogger;

/**
 * AuditLoggerTest
 *
 * Tests tamper-evident hash chain computation, log insertion, chain verification,
 * and the fallback file write path — all with a mocked database.
 */
class AuditLoggerTest extends TestCase
{
    // ===========================
    // SETUP
    // ===========================

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['AUDIT_HMAC_KEY'] = 'test-hmac-key-for-unit-tests';
    }

    protected function tearDown(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY']);
        parent::tearDown();
    }

    private function makeDb(bool $tableExists = true, ?array $lastHash = null): Database
    {
        $hashStmt = $this->createMock(\PDOStatement::class);
        $hashStmt->method('fetch')
                 ->willReturn($lastHash ? ['row_hash' => $lastHash['row_hash']] : false);

        $insertStmt = $this->createMock(\PDOStatement::class);

        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn($tableExists);
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($hashStmt, $insertStmt): \PDOStatement {
                if (str_contains($sql, 'SELECT row_hash')) {
                    return $hashStmt;
                }
                return $insertStmt;
            }
        );

        return $db;
    }

    // ===========================
    // Event type constants
    // ===========================

    public function testEventTypeConstantsAreDefined(): void
    {
        $this->assertSame('login_success', AuditLogger::LOGIN_SUCCESS);
        $this->assertSame('login_failure', AuditLogger::LOGIN_FAILURE);
        $this->assertSame('logout', AuditLogger::LOGOUT);
        $this->assertSame('data_export', AuditLogger::DATA_EXPORT);
    }

    // ===========================
    // log() — insertion
    // ===========================

    public function testLogInsertsRowWhenTableExists(): void
    {
        $lastHashStmt = $this->createMock(\PDOStatement::class);
        $lastHashStmt->method('fetch')->willReturn(false);

        $insertStmt = $this->createMock(\PDOStatement::class);

        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->expects($this->atLeast(2))
           ->method('query')
           ->willReturnCallback(function (string $sql) use ($lastHashStmt, $insertStmt): \PDOStatement {
               if (str_contains($sql, 'SELECT row_hash')) {
                   return $lastHashStmt;
               }
               return $insertStmt;
           });

        AuditLogger::log($db, 1, AuditLogger::LOGIN_SUCCESS, '1.2.3.4', 'Mozilla/5.0', ['email' => 'x@y.com'], 1);
    }

    public function testLogWritesFallbackWhenTableMissing(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('query');

        // fallback file write — just verify no exception thrown
        AuditLogger::log($db, null, AuditLogger::LOGIN_FAILURE, '1.2.3.4', 'UA', []);
        $this->assertTrue(true);
    }

    public function testLogDoesNotThrowOnDbException(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('query')->willThrowException(new \RuntimeException('DB error'));

        // Must silently fall back
        AuditLogger::log($db, 1, AuditLogger::LOGOUT, '127.0.0.1', 'UA');
        $this->assertTrue(true);
    }

    public function testLogTruncatesLongIpAndUserAgent(): void
    {
        $longIp = str_repeat('1', 100);   // > 45 chars
        $longUa = str_repeat('A', 600);   // > 500 chars

        $capturedParams = null;

        $hashStmt = $this->createMock(\PDOStatement::class);
        $hashStmt->method('fetch')->willReturn(false);
        $insertStmt = $this->createMock(\PDOStatement::class);

        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($hashStmt, $insertStmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'INSERT INTO audit_logs')) {
                    $capturedParams = $params;
                    return $insertStmt;
                }
                return $hashStmt;
            }
        );

        AuditLogger::log($db, 1, AuditLogger::LOGIN_SUCCESS, $longIp, $longUa);

        $this->assertNotNull($capturedParams);
        $this->assertLessThanOrEqual(45, strlen($capturedParams['ip']));
        $this->assertLessThanOrEqual(500, strlen($capturedParams['ua']));
    }

    // ===========================
    // verifyChain()
    // ===========================

    public function testVerifyChainReturnsTrueForEmptyTable(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = AuditLogger::verifyChain($db);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['broken_at']);
        $this->assertSame(0, $result['total']);
    }

    public function testVerifyChainReturnsFalseOnDbException(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willThrowException(new \RuntimeException('DB down'));

        $result = AuditLogger::verifyChain($db);
        $this->assertFalse($result['ok']);
    }

    public function testVerifyChainDetectsTamperedRow(): void
    {
        $key = $_ENV['AUDIT_HMAC_KEY'];

        // Build two valid rows
        $entry1 = [
            'prev' => null, 'user_id' => 1, 'org_id' => 1,
            'event_type' => 'login_success', 'ip' => '127.0.0.1',
            'details' => '{}', 'resource_type' => null, 'resource_id' => null,
        ];
        $hash1 = hash_hmac('sha256', json_encode($entry1), $key);

        $entry2 = array_merge($entry1, ['prev' => $hash1, 'event_type' => 'logout']);
        $hash2  = hash_hmac('sha256', json_encode($entry2), $key);

        $rows = [
            [
                'id' => 1, 'user_id' => 1, 'org_id' => 1, 'event_type' => 'login_success',
                'ip_address' => '127.0.0.1', 'user_agent' => '', 'details_json' => '{}',
                'resource_type' => null, 'resource_id' => null,
                'prev_hash' => null, 'row_hash' => $hash1,
            ],
            [
                'id' => 2, 'user_id' => 1, 'org_id' => 1, 'event_type' => 'logout',
                'ip_address' => '127.0.0.1', 'user_agent' => '', 'details_json' => '{}',
                'resource_type' => null, 'resource_id' => null,
                'prev_hash' => $hash1, 'row_hash' => 'tampered-hash',  // Tampered!
            ],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = AuditLogger::verifyChain($db);
        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['broken_at']);
    }
}
