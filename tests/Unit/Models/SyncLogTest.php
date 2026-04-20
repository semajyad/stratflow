<?php declare(strict_types=1);
namespace StratFlow\Tests\Unit\Models;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\SyncLog;
#[CoversClass(\StratFlow\Models\SyncLog::class)]
class SyncLogTest extends TestCase {
    private Database $db;
    private \PDOStatement $stmt;
    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(Database::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->db->method("query")->willReturn($this->stmt);
    }
    public function testCreate(): void {
        $data = ["integration_id" => 1, "direction" => "push", "action" => "create"];
        $this->db->method("lastInsertId")->willReturn("500");
        $this->db->expects($this->once())->method("query");
        $id = SyncLog::create($this->db, $data);
        $this->assertSame(500, $id);
    }
    public function testCreateDefault(): void {
        $this->db->method("lastInsertId")->willReturn("501");
        $this->db->expects($this->once())->method("query");
        SyncLog::create($this->db, ["integration_id" => 1, "direction" => "pull", "action" => "update"]);
    }
    public function testCreateOptional(): void {
        $this->db->method("lastInsertId")->willReturn("502");
        $this->db->expects($this->once())->method("query");
        SyncLog::create($this->db, ["integration_id" => 1, "direction" => "push", "action" => "delete"]);
    }
    public function testFindByIntegration(): void {
        $rows = [["id" => 1]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = SyncLog::findByIntegration($this->db, 1);
        $this->assertSame($rows, $result);
    }
    public function testFindByIntegrationLimit(): void {
        $rows = [["id" => 1]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = SyncLog::findByIntegration($this->db, 1, 20);
        $this->assertCount(1, $result);
    }
    public function testFindByIntegrationNone(): void {
        $this->stmt->method("fetchAll")->willReturn([]);
        $result = SyncLog::findByIntegration($this->db, 999);
        $this->assertSame([], $result);
    }
    public function testFindByIntegrationPaginated(): void {
        $db = $this->createMock(Database::class);
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method("fetch")->willReturn(["cnt" => 150]);
        $dataStmt = $this->createMock(\PDOStatement::class);
        $dataStmt->method("fetchAll")->willReturn([["id" => 1]]);
        $db->method("query")->willReturnOnConsecutiveCalls($countStmt, $dataStmt);
        $result = SyncLog::findByIntegrationPaginated($db, 1, 1, 50);
        $this->assertArrayHasKey("rows", $result);
        $this->assertArrayHasKey("total", $result);
        $this->assertSame(150, $result["total"]);
    }
    public function testFindByIntegrationPaginatedFilters(): void {
        $db = $this->createMock(Database::class);
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method("fetch")->willReturn(["cnt" => 50]);
        $dataStmt = $this->createMock(\PDOStatement::class);
        $dataStmt->method("fetchAll")->willReturn([["id" => 1]]);
        $db->method("query")->willReturnOnConsecutiveCalls($countStmt, $dataStmt);
        $result = SyncLog::findByIntegrationPaginated($db, 1, 1, 50, "push", "success");
        $this->assertSame(50, $result["total"]);
    }
    public function testCountByIntegration(): void {
        $this->stmt->method("fetch")->willReturn(["cnt" => 42]);
        $result = SyncLog::countByIntegration($this->db, 1);
        $this->assertSame(42, $result);
    }
    public function testFindAllByIntegration(): void {
        $rows = [["id" => 1]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = SyncLog::findAllByIntegration($this->db, 1);
        $this->assertSame($rows, $result);
    }
    public function testFindAllByIntegrationFilters(): void {
        $rows = [["id" => 1]];
        $this->stmt->method("fetchAll")->willReturn($rows);
        $result = SyncLog::findAllByIntegration($this->db, 1, "push", "success");
        $this->assertCount(1, $result);
    }
    public function testPaginatedByIntegrationHandlesFetchReturningFalse(): void {
        $countStmt = $this->createMock(\PDOStatement::class);
        $rowsStmt  = $this->createMock(\PDOStatement::class);
        $countStmt->method("fetch")->willReturn(false);
        $rowsStmt->method("fetchAll")->willReturn([]);
        $this->db->method("query")->willReturnOnConsecutiveCalls($countStmt, $rowsStmt);
        $result = SyncLog::paginatedByIntegration($this->db, 1);
        $this->assertSame(0, $result['total']);
    }
}
