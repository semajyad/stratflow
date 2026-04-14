<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Organisation;

/**
 * OrganisationTest
 *
 * Unit tests for the Organisation model — all DB calls mocked.
 */
class OrganisationTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('2');
        return $db;
    }

    private function orgRow(): array
    {
        return [
            'id'                 => 2,
            'name'               => 'Acme Corp',
            'stripe_customer_id' => 'cus_test123',
            'is_active'          => 1,
            'settings_json'      => null,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Organisation::create($db, [
            'name'               => 'Acme Corp',
            'stripe_customer_id' => 'cus_test123',
        ]);
        $this->assertSame(2, $id);
    }

    #[Test]
    public function createUsesDefaultIsActiveOne(): void
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

        Organisation::create($db, ['name' => 'Org', 'stripe_customer_id' => 'cus_x']);
        $this->assertSame(1, $capturedParams[':is_active']);
    }

    // ===========================
    // FIND BY STRIPE CUSTOMER ID
    // ===========================

    #[Test]
    public function findByStripeCustomerIdReturnsRowWhenFound(): void
    {
        $db  = $this->makeDb($this->orgRow());
        $row = Organisation::findByStripeCustomerId($db, 'cus_test123');
        $this->assertIsArray($row);
        $this->assertSame(2, $row['id']);
    }

    #[Test]
    public function findByStripeCustomerIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Organisation::findByStripeCustomerId($db, 'cus_notfound');
        $this->assertNull($row);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->orgRow());
        $row = Organisation::findById($db, 2);
        $this->assertIsArray($row);
        $this->assertSame(2, $row['id']);
        $this->assertSame('Acme Corp', $row['name']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Organisation::findById($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // FIND ALL
    // ===========================

    #[Test]
    public function findAllReturnsArray(): void
    {
        $db   = $this->makeDb($this->orgRow());
        $rows = Organisation::findAll($db);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Organisation::findAll($db);
        $this->assertSame([], $rows);
    }

    // ===========================
    // SUSPEND / ENABLE
    // ===========================

    #[Test]
    public function suspendCallsQueryWithCorrectId(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql, &$capturedParams): \PDOStatement {
                $capturedSql    = $sql;
                $capturedParams = $params;
                return $stmt;
            }
        );
        Organisation::suspend($db, 2);
        $this->assertStringContainsString('is_active = 0', $capturedSql);
        $this->assertSame(2, $capturedParams[':id']);
    }

    #[Test]
    public function enableCallsQueryWithCorrectId(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );
        Organisation::enable($db, 2);
        $this->assertStringContainsString('is_active = 1', $capturedSql);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function deleteCallsQueryWithCorrectId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        Organisation::delete($db, 2);
        $this->assertSame(2, $capturedParams[':id']);
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
        Organisation::update($db, 2, ['name' => 'Updated Corp']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Organisation::update($db, 2, ['created_at' => '2026-01-01']);
    }

    // ===========================
    // EXPORT DATA
    // ===========================

    #[Test]
    public function exportDataReturnsNullWhenOrgNotFound(): void
    {
        $db     = $this->makeDb(null);
        $result = Organisation::exportData($db, 999);
        $this->assertNull($result);
    }
}
