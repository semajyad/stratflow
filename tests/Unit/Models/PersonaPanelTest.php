<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PersonaPanel;

#[CoversClass(PersonaPanel::class)]
class PersonaPanelTest extends TestCase
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
    public function createWithOrgIdInsertsAndReturnsId(): void
    {
        $this->db->method('lastInsertId')->willReturn('11');
        $this->db->expects($this->once())->method('query');

        $data = ['org_id' => 1, 'panel_type' => 'devils_advocate', 'name' => 'Tech Panel'];

        $id = PersonaPanel::create($this->db, $data);

        $this->assertSame(11, $id);
    }

    #[Test]
    public function createWithoutOrgIdDefaultsToNull(): void
    {
        $this->db->method('lastInsertId')->willReturn('12');

        $data = ['panel_type' => 'red_teaming', 'name' => 'Default Panel'];

        $id = PersonaPanel::create($this->db, $data);

        $this->assertSame(12, $id);
    }

    #[Test]
    public function findByOrgIdReturnsRowsForOrg(): void
    {
        $rows = [['id' => 1, 'org_id' => 2], ['id' => 3, 'org_id' => 2]];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = PersonaPanel::findByOrgId($this->db, 2);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function findDefaultsReturnsSystemPanels(): void
    {
        $rows = [['id' => 5, 'org_id' => null, 'name' => 'System Panel']];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = PersonaPanel::findDefaults($this->db);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function findByIdReturnsRowWhenFound(): void
    {
        $row = ['id' => 9, 'name' => 'Dev Panel'];
        $this->stmt->method('fetch')->willReturn($row);

        $result = PersonaPanel::findById($this->db, 9);

        $this->assertSame($row, $result);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $this->stmt->method('fetch')->willReturn(false);

        $result = PersonaPanel::findById($this->db, 999);

        $this->assertNull($result);
    }

    #[Test]
    public function updateWithAllowedColumnCallsQuery(): void
    {
        $this->db->expects($this->once())->method('query');

        PersonaPanel::update($this->db, 1, ['name' => 'Renamed Panel']);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $this->db->expects($this->never())->method('query');

        PersonaPanel::update($this->db, 1, ['org_id' => 99]);
    }

    #[Test]
    public function deleteCallsQueryOnce(): void
    {
        $this->db->expects($this->once())->method('query');

        PersonaPanel::delete($this->db, 4);
    }
}
