<?php declare(strict_types=1);
namespace StratFlow\Tests\Unit\Models;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\DiagramNode;
#[CoversClass(\StratFlow\Models\DiagramNode::class)]
class DiagramNodeTest extends TestCase {
    private Database $db;
    private \PDOStatement $stmt;
    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(Database::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->db->method("query")->willReturn($this->stmt);
    }
    public function testCreateBatch(): void {
        $this->db->expects($this->exactly(2))->method("query");
        DiagramNode::createBatch($this->db, 1, [["node_key" => "n1", "label" => "Start"], ["node_key" => "n2", "label" => "End"]]);
    }
    public function testCreateBatchEmpty(): void {
        $this->db->expects($this->never())->method("query");
        DiagramNode::createBatch($this->db, 1, []);
    }
    public function testFindByDiagramId(): void {
        $rows = [["id" => 1]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = DiagramNode::findByDiagramId($this->db, 1);
        $this->assertSame($rows, $result);
    }
    public function testFindByDiagramIdNone(): void {
        $this->stmt->method("fetchAll")->willReturn([]);
        $result = DiagramNode::findByDiagramId($this->db, 999);
        $this->assertSame([], $result);
    }
    public function testUpdateLabel(): void {
        $this->db->expects($this->once())->method("query");
        DiagramNode::update($this->db, 1, ["label" => "New"]);
    }
    public function testUpdateOkr(): void {
        $this->db->expects($this->once())->method("query");
        DiagramNode::update($this->db, 1, ["okr_title" => "Obj", "okr_description" => "Desc"]);
    }
    public function testUpdateIgnoresNonUpdatable(): void {
        $this->db->expects($this->once())->method("query");
        DiagramNode::update($this->db, 1, ["label" => "Valid", "diagram_id" => 999]);
    }
    public function testUpdateEmpty(): void {
        $this->db->expects($this->never())->method("query");
        DiagramNode::update($this->db, 1, ["diagram_id" => 999]);
    }
    public function testDeleteByDiagramId(): void {
        $this->db->expects($this->once())->method("query");
        DiagramNode::deleteByDiagramId($this->db, 1);
    }
    public function testDeleteById(): void {
        $this->db->expects($this->once())->method("query");
        DiagramNode::delete($this->db, 1);
    }
}
