<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\EvaluationResult;

#[CoversClass(EvaluationResult::class)]
class EvaluationResultTest extends TestCase
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
    public function createInsertsAndReturnsId(): void
    {
        $this->db->method('lastInsertId')->willReturn('42');
        $this->db->expects($this->once())->method('query');

        $data = [
            'project_id'       => 1,
            'panel_id'         => 2,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'summary',
            'results_json'     => '{"key":"value"}',
        ];

        $id = EvaluationResult::create($this->db, $data);

        $this->assertSame(42, $id);
    }

    #[Test]
    public function createUsesDefaultStatusWhenOmitted(): void
    {
        $this->db->method('lastInsertId')->willReturn('7');

        $data = [
            'project_id'       => 3,
            'panel_id'         => 4,
            'evaluation_level' => 'red_teaming',
            'screen_context'   => 'roadmap',
            'results_json'     => '{}',
        ];

        $id = EvaluationResult::create($this->db, $data);

        $this->assertSame(7, $id);
    }

    #[Test]
    public function findByProjectIdReturnsFetchAllResult(): void
    {
        $rows = [['id' => 1, 'project_id' => 5], ['id' => 2, 'project_id' => 5]];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = EvaluationResult::findByProjectId($this->db, 5);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function findByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $this->stmt->method('fetchAll')->willReturn([]);

        $result = EvaluationResult::findByProjectId($this->db, 999);

        $this->assertSame([], $result);
    }

    #[Test]
    public function findByIdReturnsRowWhenFound(): void
    {
        $row = ['id' => 10, 'project_id' => 1];
        $this->stmt->method('fetch')->willReturn($row);

        $result = EvaluationResult::findById($this->db, 10);

        $this->assertSame($row, $result);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $this->stmt->method('fetch')->willReturn(false);

        $result = EvaluationResult::findById($this->db, 999);

        $this->assertNull($result);
    }

    #[Test]
    public function findByProjectAndScreenReturnsFetchAllResult(): void
    {
        $rows = [['id' => 3, 'screen_context' => 'summary']];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = EvaluationResult::findByProjectAndScreen($this->db, 1, 'summary');

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function updateStatusCallsQuery(): void
    {
        $this->db->expects($this->once())->method('query');

        EvaluationResult::updateStatus($this->db, 1, 'accepted');
    }
}
