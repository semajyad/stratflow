<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\StrategyDiagram;

#[AllowMockObjectsWithoutExpectations]
final class StrategyDiagramTest extends TestCase
{
    private function makeStatement(mixed $fetch = false): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        return $stmt;
    }

    #[Test]
    public function createInsertsDiagramAndReturnsId(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $this->makeStatement();
            });
        $db->method('lastInsertId')->willReturn('42');

        $id = StrategyDiagram::create($db, [
            'project_id' => 7,
            'mermaid_code' => 'graph TD; A-->B;',
            'created_by' => 3,
        ]);

        $this->assertSame(42, $id);
        $this->assertSame(7, $capturedParams[':project_id']);
        $this->assertSame('graph TD; A-->B;', $capturedParams[':mermaid_code']);
        $this->assertSame(1, $capturedParams[':version']);
        $this->assertSame(3, $capturedParams[':created_by']);
    }

    #[Test]
    public function findByProjectIdReturnsLatestRowOrNull(): void
    {
        $row = ['id' => 5, 'project_id' => 7, 'version' => 2];
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($this->makeStatement($row));

        $this->assertSame($row, StrategyDiagram::findByProjectId($db, 7));

        $emptyDb = $this->createMock(Database::class);
        $emptyDb->method('query')->willReturn($this->makeStatement(false));
        $this->assertNull(StrategyDiagram::findByProjectId($emptyDb, 99));
    }

    #[Test]
    public function updateFiltersToAllowedColumnsAndIncrementsVersion(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): \PDOStatement {
                $capturedSql = $sql;
                $capturedParams = $params;
                return $this->makeStatement();
            });

        StrategyDiagram::update($db, 5, [
            'mermaid_code' => 'graph LR; A-->B;',
            'project_id' => 999,
        ]);

        $this->assertStringContainsString('version = version + 1', $capturedSql);
        $this->assertStringContainsString('`mermaid_code` = :mermaid_code', $capturedSql);
        $this->assertArrayNotHasKey(':project_id', $capturedParams);
        $this->assertSame('graph LR; A-->B;', $capturedParams[':mermaid_code']);
        $this->assertSame(5, $capturedParams[':id']);
    }

    #[Test]
    public function deleteByProjectIdScopesDeletionToProject(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $this->makeStatement();
            });

        StrategyDiagram::deleteByProjectId($db, 7);

        $this->assertSame(7, $capturedParams[':project_id']);
    }
}
