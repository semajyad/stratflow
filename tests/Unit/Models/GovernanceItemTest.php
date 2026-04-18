<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\GovernanceItem;

#[CoversClass(GovernanceItem::class)]
class GovernanceItemTest extends TestCase
{
    private Database $db;
    private \PDOStatement $stmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db   = $this->createMock(Database::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->db->method('query')->willReturn($this->stmt);
    }

    #[Test]
    public function createWithStringJsonInsertsAndReturnsId(): void
    {
        $this->db->method('lastInsertId')->willReturn('15');
        $this->db->expects($this->once())->method('query');

        $data = [
            'project_id'          => 1,
            'change_type'         => 'new_story',
            'proposed_change_json' => '{"title":"My Story"}',
        ];

        $id = GovernanceItem::create($this->db, $data);

        $this->assertSame(15, $id);
    }

    #[Test]
    public function createWithArrayJsonEncodesItBeforeInsert(): void
    {
        $this->db->method('lastInsertId')->willReturn('20');

        $data = [
            'project_id'          => 2,
            'change_type'         => 'scope_change',
            'proposed_change_json' => ['title' => 'Changed', 'description' => 'New scope'],
        ];

        $id = GovernanceItem::create($this->db, $data);

        $this->assertSame(20, $id);
    }

    #[Test]
    public function findPendingByProjectIdReturnsPendingRows(): void
    {
        $rows = [['id' => 1, 'status' => 'pending'], ['id' => 2, 'status' => 'pending']];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = GovernanceItem::findPendingByProjectId($this->db, 5);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function findByProjectIdReturnsAllRows(): void
    {
        $rows = [['id' => 1, 'status' => 'approved'], ['id' => 2, 'status' => 'pending']];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = GovernanceItem::findByProjectId($this->db, 5);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function findByIdReturnsRowWhenFound(): void
    {
        $row = ['id' => 7, 'change_type' => 'new_story'];
        $this->stmt->method('fetch')->willReturn($row);

        $result = GovernanceItem::findById($this->db, 7);

        $this->assertSame($row, $result);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $this->stmt->method('fetch')->willReturn(false);

        $result = GovernanceItem::findById($this->db, 999);

        $this->assertNull($result);
    }

    #[Test]
    public function updateStatusCallsQueryOnce(): void
    {
        $this->db->expects($this->once())->method('query');

        GovernanceItem::updateStatus($this->db, 1, 'approved', 42);
    }

    #[Test]
    public function updateStatusAcceptsNullReviewedBy(): void
    {
        $this->db->expects($this->once())->method('query');

        GovernanceItem::updateStatus($this->db, 1, 'rejected', null);
    }
}
