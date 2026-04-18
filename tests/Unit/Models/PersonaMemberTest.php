<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PersonaMember;

#[CoversClass(PersonaMember::class)]
class PersonaMemberTest extends TestCase
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
        $this->db->method('lastInsertId')->willReturn('8');
        $this->db->expects($this->once())->method('query');

        $data = [
            'panel_id'           => 1,
            'role_title'         => 'CEO',
            'prompt_description' => 'Focused on ROI',
        ];

        $id = PersonaMember::create($this->db, $data);

        $this->assertSame(8, $id);
    }

    #[Test]
    public function findByPanelIdReturnsMembersForPanel(): void
    {
        $rows = [
            ['id' => 1, 'panel_id' => 3, 'role_title' => 'CEO'],
            ['id' => 2, 'panel_id' => 3, 'role_title' => 'CTO'],
        ];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = PersonaMember::findByPanelId($this->db, 3);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function findByPanelIdReturnsEmptyArrayWhenNone(): void
    {
        $this->stmt->method('fetchAll')->willReturn([]);

        $result = PersonaMember::findByPanelId($this->db, 999);

        $this->assertSame([], $result);
    }

    #[Test]
    public function updateWithAllowedColumnsCallsQuery(): void
    {
        $this->db->expects($this->once())->method('query');

        PersonaMember::update($this->db, 1, ['role_title' => 'VP Engineering']);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $this->db->expects($this->never())->method('query');

        PersonaMember::update($this->db, 1, ['panel_id' => 99]);
    }

    #[Test]
    public function updateWithEmptyDataSkipsQuery(): void
    {
        $this->db->expects($this->never())->method('query');

        PersonaMember::update($this->db, 1, []);
    }

    #[Test]
    public function deleteCallsQueryOnce(): void
    {
        $this->db->expects($this->once())->method('query');

        PersonaMember::delete($this->db, 5);
    }
}
