<?php declare(strict_types=1);
namespace StratFlow\Tests\Unit\Models;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Document;
#[CoversClass(\StratFlow\Models\Document::class)]
class DocumentTest extends TestCase {
    private Database $db;
    private \PDOStatement $stmt;
    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(Database::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->db->method("query")->willReturn($this->stmt);
    }
    public function testCreateInserts(): void {
        $data = ["project_id" => 1, "filename" => "doc.pdf", "original_name" => "My.pdf", "mime_type" => "app", "file_size" => 1024, "uploaded_by" => 42];
        $this->db->method("lastInsertId")->willReturn("123");
        $this->db->expects($this->once())->method("query");
        $id = Document::create($this->db, $data);
        $this->assertSame(123, $id);
    }
    public function testCreateOptional(): void {
        $data = ["project_id" => 1, "filename" => "doc.txt", "original_name" => "O.txt", "mime_type" => "text", "file_size" => 512, "uploaded_by" => 5];
        $this->db->method("lastInsertId")->willReturn("456");
        $this->db->expects($this->once())->method("query");
        $id = Document::create($this->db, $data);
        $this->assertSame(456, $id);
    }
    public function testFindByProjectId(): void {
        $rows = [["id" => 1], ["id" => 2]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = Document::findByProjectId($this->db, 1);
        $this->assertSame($rows, $result);
    }
    public function testFindByProjectIdNone(): void {
        $this->stmt->method("fetchAll")->willReturn([]);
        $result = Document::findByProjectId($this->db, 999);
        $this->assertSame([], $result);
    }
    public function testFindById(): void {
        $row = ["id" => 1];
        $this->stmt->method("fetch")->willReturn($row);
        $result = Document::findById($this->db, 1);
        $this->assertSame($row, $result);
    }
    public function testFindByIdNull(): void {
        $this->stmt->method("fetch")->willReturn(false);
        $result = Document::findById($this->db, 999);
        $this->assertNull($result);
    }
    public function testUpdateText(): void {
        $this->db->expects($this->once())->method("query");
        Document::update($this->db, 1, ["extracted_text" => "New"]);
    }
    public function testUpdateSummary(): void {
        $this->db->expects($this->once())->method("query");
        Document::update($this->db, 1, ["ai_summary" => "Summary"]);
    }
    public function testUpdateEmpty(): void {
        $this->db->expects($this->never())->method("query");
        Document::update($this->db, 1, ["project_id" => 999]);
    }
    public function testDeleteById(): void {
        $this->db->expects($this->once())->method("query");
        Document::delete($this->db, 1);
    }
}
