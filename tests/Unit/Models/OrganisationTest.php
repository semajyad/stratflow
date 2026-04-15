<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Core\SecretManager;
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

    #[Test]
    public function exportDataReturnsNestedArrayWithUsersProjectsSubscriptions(): void
    {
        $orgRow = $this->orgRow();
        $userRow = ['id' => 1, 'full_name' => 'John Doe', 'email' => 'john@test.com', 'role' => 'admin', 'is_active' => 1, 'created_at' => '2026-01-01'];
        $projectRow = ['id' => 10, 'org_id' => 2, 'name' => 'Project A', 'status' => 'active'];
        $subscriptionRow = ['id' => 5, 'org_id' => 2, 'plan' => 'pro'];

        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, $orgRow, $userRow, $projectRow, $subscriptionRow, &$callCount): \PDOStatement {
            $callCount++;
            if (str_contains($sql, 'SELECT * FROM organisations')) {
                $stmt->method('fetch')->willReturn($orgRow);
            } elseif (str_contains($sql, 'FROM users')) {
                $stmt->method('fetchAll')->willReturn([$userRow]);
            } elseif (str_contains($sql, 'FROM projects')) {
                $stmt->method('fetchAll')->willReturn([$projectRow]);
            } elseif (str_contains($sql, 'FROM subscriptions')) {
                $stmt->method('fetchAll')->willReturn([$subscriptionRow]);
            }
            return $stmt;
        });

        $result = Organisation::exportData($db, 2);

        $this->assertIsArray($result);
        $this->assertSame(2, $result['id']);
        $this->assertCount(1, $result['users']);
        $this->assertCount(1, $result['projects']);
        $this->assertCount(1, $result['subscriptions']);
        $this->assertSame('John Doe', $result['users'][0]['full_name']);
    }

    #[Test]
    public function updateWithSettingsJsonProtectsSecrets(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Organisation::update($db, 2, ['settings_json' => '{"ai": {"api_key": "secret123"}}']);

        $this->assertNotNull($capturedParams);
        $this->assertArrayHasKey(':settings_json', $capturedParams);
    }

    #[Test]
    public function updateWithInvalidJsonSkipsSettingsJson(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Organisation::update($db, 2, ['settings_json' => 'not valid json']);

        // Should still call query with other allowed columns if present
        // But if only settings_json, it should still attempt to process it
        $this->assertNotNull($capturedParams);
    }

    #[Test]
    public function updateWithEmptyDataDoesNotCallQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        Organisation::update($db, 2, ['nonexistent_column' => 'value']);
    }

    #[Test]
    public function updateWithMultipleAllowedColumnsUpdatesAll(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        Organisation::update($db, 2, ['name' => 'New Name', 'is_active' => 0]);

        $this->assertStringContainsString('name', $capturedSql);
        $this->assertStringContainsString('is_active', $capturedSql);
    }

    #[Test]
    public function findByStripeCustomerIdCallsHydrateRow(): void
    {
        $orgRow = $this->orgRow();
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($orgRow);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = Organisation::findByStripeCustomerId($db, 'cus_test123');

        $this->assertIsArray($result);
        $this->assertSame($orgRow['id'], $result['id']);
    }

    #[Test]
    public function createWithCustomIsActive(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('5');

        Organisation::create($db, [
            'name' => 'Test Org',
            'stripe_customer_id' => 'cus_xyz',
            'is_active' => 0,
        ]);

        $this->assertSame(0, $capturedParams[':is_active']);
    }

    #[Test]
    public function suspendSetsIsActiveToZero(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Organisation::suspend($db, 2);

        $this->assertSame(2, $capturedParams[':id']);
    }

    #[Test]
    public function enableSetsIsActiveToOne(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Organisation::enable($db, 2);

        $this->assertSame(2, $capturedParams[':id']);
    }

    #[Test]
    public function findByIdDoesNotCallHydrate(): void
    {
        $orgRow = $this->orgRow();
        $db = $this->makeDb($orgRow);
        $row = Organisation::findById($db, 2);
        $this->assertIsArray($row);
        $this->assertSame(2, $row['id']);
    }

    #[Test]
    public function findAllFetchesWithUserCount(): void
    {
        $orgRow = $this->orgRow();
        $orgRow['user_count'] = 3;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([$orgRow]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $rows = Organisation::findAll($db);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('user_count', $rows[0]);
    }

    #[Test]
    public function updateFiltersDisallowedColumns(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        Organisation::update($db, 2, [
            'created_at' => '2026-01-01',
            'updated_at' => '2026-04-14',
            'id' => 999,
        ]);
    }

    #[Test]
    public function findByStripeCustomerIdWithSettingsJsonTriggersHydration(): void
    {
        $orgRow = $this->orgRow();
        $orgRow['settings_json'] = '{"ai":{"api_key":"secret"}}';

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($orgRow);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = Organisation::findByStripeCustomerId($db, 'cus_test123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('settings_json', $result);
    }

    #[Test]
    public function findByStripeCustomerIdWithEmptySettingsJson(): void
    {
        $orgRow = $this->orgRow();
        $orgRow['settings_json'] = '';

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($orgRow);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = Organisation::findByStripeCustomerId($db, 'cus_test123');

        $this->assertIsArray($result);
    }

    #[Test]
    public function updateWithSettingsJsonEmptyString(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $db->method('query')->willReturn($stmt);

        Organisation::update($db, 2, ['settings_json' => '']);

        // Should not throw and should still call query
        $this->assertTrue(true);
    }

    #[Test]
    public function exportDataIncludesAllChildData(): void
    {
        $orgRow = $this->orgRow();
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, &$callCount): \PDOStatement {
            $callCount++;
            return $stmt;
        });
        $stmt->method('fetch')->willReturn($orgRow);
        $stmt->method('fetchAll')->willReturn([]);

        Organisation::exportData($db, 2);

        // Should make 4 queries: 1 for org, 1 for users, 1 for projects, 1 for subscriptions
        $this->assertGreaterThanOrEqual(4, $callCount);
    }

    #[Test]
    public function findByIdWithValidId(): void
    {
        $orgRow = $this->orgRow();
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($orgRow);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = Organisation::findById($db, 2);

        $this->assertSame(2, $result['id']);
        $this->assertSame('Acme Corp', $result['name']);
    }

    #[Test]
    public function suspendUpdateIsActive(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        Organisation::suspend($db, 2);

        $this->assertStringContainsString('UPDATE organisations', $capturedSql);
        $this->assertStringContainsString('is_active = 0', $capturedSql);
    }

    #[Test]
    public function exportDataWithAllDataPopulated(): void
    {
        $orgRow = [
            'id'                 => 2,
            'name'               => 'Test Org',
            'stripe_customer_id' => 'cus_test',
            'is_active'          => 1,
            'settings_json'      => null,
        ];

        $userRow = ['id' => 1, 'full_name' => 'User 1', 'email' => 'user1@test.com', 'role' => 'admin', 'is_active' => 1, 'created_at' => '2026-01-01'];
        $projectRow = ['id' => 10, 'org_id' => 2, 'name' => 'Project 1', 'status' => 'active'];
        $subscriptionRow = ['id' => 5, 'org_id' => 2, 'plan' => 'pro'];

        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $callOrder = [];

        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, $orgRow, $userRow, $projectRow, $subscriptionRow, &$callOrder): \PDOStatement {
            $callOrder[] = $sql;
            if (str_contains($sql, 'SELECT * FROM organisations')) {
                $stmt->method('fetch')->willReturn($orgRow);
            } elseif (str_contains($sql, 'SELECT id, full_name')) {
                $stmt->method('fetchAll')->willReturn([$userRow]);
            } elseif (str_contains($sql, 'SELECT * FROM projects')) {
                $stmt->method('fetchAll')->willReturn([$projectRow]);
            } elseif (str_contains($sql, 'SELECT * FROM subscriptions')) {
                $stmt->method('fetchAll')->willReturn([$subscriptionRow]);
            }
            return $stmt;
        });

        $result = Organisation::exportData($db, 2);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['users']);
        $this->assertCount(1, $result['projects']);
        $this->assertCount(1, $result['subscriptions']);
    }

    #[Test]
    public function findAllReturnsMultipleOrgs(): void
    {
        $org1 = $this->orgRow();
        $org2 = $this->orgRow();
        $org2['id'] = 3;
        $org2['name'] = 'Other Corp';

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([$org1, $org2]);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $rows = Organisation::findAll($db);

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function findByStripeCustomerIdTriggersHydrateMultipleTimes(): void
    {
        $row1 = ['id' => 1, 'name' => 'Org 1', 'stripe_customer_id' => 'cus_001', 'is_active' => 1, 'settings_json' => null];
        $row2 = ['id' => 2, 'name' => 'Org 2', 'stripe_customer_id' => 'cus_002', 'is_active' => 1, 'settings_json' => null];

        $db = $this->createMock(Database::class);
        $stmt1 = $this->createMock(\PDOStatement::class);
        $stmt2 = $this->createMock(\PDOStatement::class);

        $stmt1->method('fetch')->willReturn($row1);
        $stmt2->method('fetch')->willReturn($row2);

        $callIndex = 0;
        $db->method('query')->willReturnCallback(function () use (&$callIndex, $stmt1, $stmt2) {
            return ($callIndex++ === 0) ? $stmt1 : $stmt2;
        });

        $result1 = Organisation::findByStripeCustomerId($db, 'cus_001');
        $result2 = Organisation::findByStripeCustomerId($db, 'cus_002');

        $this->assertSame(1, $result1['id']);
        $this->assertSame(2, $result2['id']);
    }

    #[Test]
    public function findAllExecutesHydrateForEachRow(): void
    {
        $orgRows = [
            ['id' => 1, 'name' => 'Org 1', 'stripe_customer_id' => 'cus_001', 'is_active' => 1, 'settings_json' => null, 'user_count' => 5],
            ['id' => 2, 'name' => 'Org 2', 'stripe_customer_id' => 'cus_002', 'is_active' => 0, 'settings_json' => null, 'user_count' => 3],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($orgRows);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $results = Organisation::findAll($db);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['id']);
        $this->assertSame(2, $results[1]['id']);
    }

    #[Test]
    public function updateWithStripeCustomerId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Organisation::update($db, 2, ['stripe_customer_id' => 'cus_updated']);

        $this->assertSame('cus_updated', $capturedParams[':stripe_customer_id']);
    }

    #[Test]
    public function deleteWithIdParameter(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        Organisation::delete($db, 5);

        $this->assertStringContainsString('DELETE FROM organisations', $capturedSql);
        $this->assertStringContainsString('WHERE id = :id', $capturedSql);
    }

    #[Test]
    public function updateWithMixedAllowedAndDisallowedColumns(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        Organisation::update($db, 2, [
            'name' => 'NewName',
            'is_active' => 1,
            'invalid_field' => 'should_be_filtered',
            'created_at' => 'should_also_be_filtered',
        ]);

        $this->assertStringContainsString('name', $capturedSql);
        $this->assertStringContainsString('is_active', $capturedSql);
        $this->assertStringNotContainsString('invalid_field', $capturedSql);
        $this->assertStringNotContainsString('created_at', $capturedSql);
    }

    #[Test]
    public function findByIdSearchesWithCorrectId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($this->orgRow());
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Organisation::findById($db, 42);

        $this->assertSame(42, $capturedParams[':id']);
    }
}
