<?php declare(strict_types=1);
namespace StratFlow\Tests\Unit\Models;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\SyncMapping;
#[CoversClass(\StratFlow\Models\SyncMapping::class)]
class SyncMappingTest extends TestCase {
    private Database $db;
    private \PDOStatement $stmt;
    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(Database::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->db->method("query")->willReturn($this->stmt);
    }
    public function testCreate(): void {
        $data = ["integration_id" => 1, "local_type" => "story", "local_id" => 42, "external_id" => "jira-123"];
        $this->db->method("lastInsertId")->willReturn("600");
        $this->db->expects($this->once())->method("query");
        $id = SyncMapping::create($this->db, $data);
        $this->assertSame(600, $id);
    }
    public function testCreateOptional(): void {
        $this->db->method("lastInsertId")->willReturn("601");
        $this->db->expects($this->once())->method("query");
        SyncMapping::create($this->db, ["integration_id" => 1, "local_type" => "story", "local_id" => 42, "external_id" => "j"]);
    }
    public function testFindByLocalItem(): void {
        $row = ["id" => 1, "external_id" => "jira-123"];
        $this->stmt->method("fetch")->willReturn($row);
        $result = SyncMapping::findByLocalItem($this->db, 1, "story", 42);
        $this->assertSame($row, $result);
    }
    public function testFindByLocalItemNull(): void {
        $this->stmt->method("fetch")->willReturn(false);
        $result = SyncMapping::findByLocalItem($this->db, 1, "story", 999);
        $this->assertNull($result);
    }
    public function testFindByExternalKey(): void {
        $row = ["id" => 1, "external_key" => "PROJ-123"];
        $this->stmt->method("fetch")->willReturn($row);
        $result = SyncMapping::findByExternalKey($this->db, 1, "PROJ-123");
        $this->assertSame($row, $result);
    }
    public function testFindByExternalKeyNull(): void {
        $this->stmt->method("fetch")->willReturn(false);
        $result = SyncMapping::findByExternalKey($this->db, 1, "UNKNOWN");
        $this->assertNull($result);
    }
    public function testFindByExternalId(): void {
        $row = ["id" => 1, "external_id" => "jira-123"];
        $this->stmt->method("fetch")->willReturn($row);
        $result = SyncMapping::findByExternalId($this->db, 1, "jira-123");
        $this->assertSame($row, $result);
    }
    public function testFindByExternalIdNull(): void {
        $this->stmt->method("fetch")->willReturn(false);
        $result = SyncMapping::findByExternalId($this->db, 1, "unknown");
        $this->assertNull($result);
    }
    public function testFindByIntegration(): void {
        $rows = [["id" => 1], ["id" => 2]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = SyncMapping::findByIntegration($this->db, 1);
        $this->assertSame($rows, $result);
    }
    public function testFindByIntegrationNone(): void {
        $this->stmt->method("fetchAll")->willReturn([]);
        $result = SyncMapping::findByIntegration($this->db, 999);
        $this->assertSame([], $result);
    }
    public function testUpdateHash(): void {
        $this->db->expects($this->once())->method("query");
        SyncMapping::update($this->db, 1, ["sync_hash" => "new"]);
    }
    public function testUpdateExternal(): void {
        $this->db->expects($this->once())->method("query");
        SyncMapping::update($this->db, 1, ["external_key" => "PROJ"]);
    }
    public function testUpdateMultiple(): void {
        $this->db->expects($this->once())->method("query");
        SyncMapping::update($this->db, 1, ["sync_hash" => "n", "external_url" => "u"]);
    }
    public function testUpdateIgnores(): void {
        $this->db->expects($this->once())->method("query");
        SyncMapping::update($this->db, 1, ["sync_hash" => "n", "local_type" => "s"]);
    }
    public function testUpdateEmpty(): void {
        $this->db->expects($this->never())->method("query");
        SyncMapping::update($this->db, 1, ["local_type" => "story"]);
    }
    public function testDeleteById(): void {
        $this->db->expects($this->once())->method("query");
        SyncMapping::delete($this->db, 1);
    }
    public function testDeleteByIntegration(): void {
        $this->db->expects($this->once())->method("query");
        SyncMapping::deleteByIntegration($this->db, 1);
    }
}
