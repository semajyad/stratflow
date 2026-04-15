<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Subscription;

/**
 * SubscriptionTest
 *
 * Unit tests for the Subscription model — all DB calls mocked.
 */
class SubscriptionTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null, ?array $fetchAllRows = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchAllRows ?? ($fetchRow ? [$fetchRow] : []));
        $stmt->method('rowCount')->willReturn($fetchRow ? 1 : 0);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('42');
        return $db;
    }

    private function subscriptionRow(): array
    {
        return [
            'id'                     => 42,
            'org_id'                 => 1,
            'stripe_subscription_id' => 'sub_1234567890',
            'plan_type'              => 'product',
            'status'                 => 'active',
            'started_at'             => '2024-01-15 10:30:00',
            'user_seat_limit'        => 10,
            'has_evaluation_board'   => true,
            'created_at'             => '2024-01-15 10:30:00',
            'updated_at'             => '2024-01-15 10:30:00',
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Subscription::create($db, [
            'org_id'                 => 1,
            'stripe_subscription_id' => 'sub_new',
            'plan_type'              => 'product',
            'status'                 => 'active',
        ]);
        $this->assertSame(42, $id);
    }

    #[Test]
    public function createUsesDefaultStatusAndStartedAt(): void
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

        Subscription::create($db, [
            'org_id'                 => 1,
            'stripe_subscription_id' => 'sub_test',
            'plan_type'              => 'product',
        ]);

        $this->assertSame('active', $capturedParams[':status']);
        $this->assertNotEmpty($capturedParams[':started_at']);
    }

    #[Test]
    public function createWithExplicitStatusOverridesDefault(): void
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

        Subscription::create($db, [
            'org_id'                 => 1,
            'stripe_subscription_id' => 'sub_test',
            'plan_type'              => 'product',
            'status'                 => 'past_due',
        ]);

        $this->assertSame('past_due', $capturedParams[':status']);
    }

    // ===========================
    // FIND BY ORG ID
    // ===========================

    #[Test]
    public function findByOrgIdReturnsMostRecentSubscription(): void
    {
        $db  = $this->makeDb($this->subscriptionRow());
        $row = Subscription::findByOrgId($db, 1);
        $this->assertIsArray($row);
        $this->assertSame(42, $row['id']);
        $this->assertSame('sub_1234567890', $row['stripe_subscription_id']);
    }

    #[Test]
    public function findByOrgIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Subscription::findByOrgId($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // FIND BY STRIPE ID
    // ===========================

    #[Test]
    public function findByStripeIdReturnsSubscription(): void
    {
        $db  = $this->makeDb($this->subscriptionRow());
        $row = Subscription::findByStripeId($db, 'sub_1234567890');
        $this->assertIsArray($row);
        $this->assertSame(42, $row['id']);
        $this->assertSame('sub_1234567890', $row['stripe_subscription_id']);
    }

    #[Test]
    public function findByStripeIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Subscription::findByStripeId($db, 'sub_invalid');
        $this->assertNull($row);
    }

    // ===========================
    // UPDATE STATUS
    // ===========================

    #[Test]
    public function updateStatusCallsQueryWithCorrectParameters(): void
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

        Subscription::updateStatus($db, 42, 'cancelled');

        $this->assertSame(42, $capturedParams[':id']);
        $this->assertSame('cancelled', $capturedParams[':status']);
    }

    #[Test]
    public function updateStatusAcceptsPastDueStatus(): void
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

        Subscription::updateStatus($db, 42, 'past_due');

        $this->assertSame('past_due', $capturedParams[':status']);
    }

    // ===========================
    // UPDATE SEAT LIMIT
    // ===========================

    #[Test]
    public function updateSeatLimitCallsQueryWithCorrectParameters(): void
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

        Subscription::updateSeatLimit($db, 1, 20);

        $this->assertSame(1, $capturedParams[':org_id']);
        $this->assertSame(20, $capturedParams[':limit']);
    }

    #[Test]
    public function updateSeatLimitWorksWithLargeNumbers(): void
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

        Subscription::updateSeatLimit($db, 1, 1000);

        $this->assertSame(1000, $capturedParams[':limit']);
    }

    // ===========================
    // GET SEAT LIMIT
    // ===========================

    #[Test]
    public function getSeatLimitReturnsStoredValue(): void
    {
        $db = $this->makeDb(['user_seat_limit' => 15]);
        $limit = Subscription::getSeatLimit($db, 1);
        $this->assertSame(15, $limit);
    }

    #[Test]
    public function getSeatLimitReturnsFallbackWhenNoActiveSubscription(): void
    {
        $db = $this->makeDb(null);
        $limit = Subscription::getSeatLimit($db, 999);
        $this->assertSame(5, $limit);
    }

    #[Test]
    public function getSeatLimitReturnsFallbackWhenColumnIsNull(): void
    {
        $db = $this->makeDb(['user_seat_limit' => null]);
        $limit = Subscription::getSeatLimit($db, 1);
        $this->assertSame(5, $limit);
    }

    #[Test]
    public function getSeatLimitReturnsIntegerType(): void
    {
        $db = $this->makeDb(['user_seat_limit' => '25']);
        $limit = Subscription::getSeatLimit($db, 1);
        $this->assertIsInt($limit);
        $this->assertSame(25, $limit);
    }

    // ===========================
    // HAS EVALUATION BOARD
    // ===========================

    #[Test]
    public function hasEvaluationBoardReturnsTrueWhenEnabled(): void
    {
        $db = $this->makeDb(['has_evaluation_board' => true]);
        $result = Subscription::hasEvaluationBoard($db, 1);
        $this->assertTrue($result);
    }

    #[Test]
    public function hasEvaluationBoardReturnsFalseWhenDisabled(): void
    {
        $db = $this->makeDb(['has_evaluation_board' => false]);
        $result = Subscription::hasEvaluationBoard($db, 1);
        $this->assertFalse($result);
    }

    #[Test]
    public function hasEvaluationBoardReturnsFalseWhenNoActiveSubscription(): void
    {
        $db = $this->makeDb(null);
        $result = Subscription::hasEvaluationBoard($db, 999);
        $this->assertFalse($result);
    }

    #[Test]
    public function hasEvaluationBoardCoercesTruthy(): void
    {
        $db = $this->makeDb(['has_evaluation_board' => 1]);
        $result = Subscription::hasEvaluationBoard($db, 1);
        $this->assertTrue($result);
    }

    #[Test]
    public function hasEvaluationBoardCoercesFalsy(): void
    {
        $db = $this->makeDb(['has_evaluation_board' => 0]);
        $result = Subscription::hasEvaluationBoard($db, 1);
        $this->assertFalse($result);
    }
}
