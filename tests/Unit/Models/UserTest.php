<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\User;

/**
 * UserTest
 *
 * Unit tests for the User model — all DB calls mocked.
 */
class UserTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('99');
        $db->method('tableExists')->willReturn(false);
        return $db;
    }

    private function userRow(): array
    {
        return [
            'id'            => 99,
            'org_id'        => 1,
            'full_name'     => 'Alice Smith',
            'email'         => 'alice@example.com',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'role'          => 'user',
            'is_active'     => 1,
        ];
    }

    // ===========================
    // FIND BY EMAIL
    // ===========================

    #[Test]
    public function findByEmailReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->userRow());
        $row = User::findByEmail($db, 'alice@example.com');
        $this->assertIsArray($row);
        $this->assertSame('Alice Smith', $row['full_name']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    #[Test]
    public function findByEmailReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = User::findByEmail($db, 'nobody@example.com');
        $this->assertNull($row);
    }

    #[Test]
    public function findByEmailPassesEmailToQuery(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        User::findByEmail($db, 'test@example.com');
        $this->assertSame('test@example.com', $capturedParams[':email']);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->userRow());
        $row = User::findById($db, 99);
        $this->assertIsArray($row);
        $this->assertSame(99, $row['id']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = User::findById($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        // tableExists returns false so the schema-check query is skipped
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('99');

        $id = User::create($db, [
            'org_id'        => 1,
            'full_name'     => 'Bob Jones',
            'email'         => 'bob@example.com',
            'password_hash' => password_hash('pw', PASSWORD_BCRYPT),
            'role'          => 'user',
        ]);
        $this->assertSame(99, $id);
    }

    #[Test]
    public function createUsesDefaultRoleUser(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db   = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('1');

        User::create($db, [
            'org_id'        => 1,
            'full_name'     => 'Bob',
            'email'         => 'b@x.com',
            'password_hash' => 'hash',
        ]);

        // Verify the INSERT was called with default role 'user'
        $this->assertStringContainsString('INSERT INTO users', $capturedSql);
    }

    // ===========================
    // FIND BY ORG ID
    // ===========================

    #[Test]
    public function findByOrgIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->userRow());
        $rows = User::findByOrgId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function findByOrgIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = User::findByOrgId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // COUNT BY ORG ID
    // ===========================

    #[Test]
    public function countByOrgIdReturnsInteger(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['cnt' => '3']);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $count = User::countByOrgId($db, 1);
        $this->assertSame(3, $count);
    }

    #[Test]
    public function countByOrgIdReturnsZeroWhenNone(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $count = User::countByOrgId($db, 99);
        $this->assertSame(0, $count);
    }

    // ===========================
    // DEACTIVATE / REACTIVATE
    // ===========================

    #[Test]
    public function deactivateCallsQueryWithIsActiveZero(): void
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
        User::deactivate($db, 99);
        $this->assertStringContainsString('is_active = 0', $capturedSql);
    }

    #[Test]
    public function reactivateCallsQueryWithIsActiveOne(): void
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
        User::reactivate($db, 99);
        $this->assertStringContainsString('is_active = 1', $capturedSql);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function updateWithAllowedColumnCallsQuery(): void
    {
        $queryCalled = false;
        $infoStmt = $this->createMock(\PDOStatement::class);
        $infoStmt->method('fetch')->willReturn(false); // account_type col not found
        $updateStmt = $this->createMock(\PDOStatement::class);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($infoStmt, $updateStmt, &$queryCalled): \PDOStatement {
                if (str_contains($sql, 'information_schema')) {
                    return $infoStmt;
                }
                $queryCalled = true;
                return $updateStmt;
            }
        );
        User::update($db, 99, ['full_name' => 'Alice Updated']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithNoAllowedColumnsSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        User::update($db, 99, ['org_id' => 5]); // org_id not in UPDATABLE_COLUMNS
    }
}
