<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\BoardReview;

class BoardReviewTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null, string $lastId = '0'): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn($lastId);
        return $db;
    }

    private function reviewRow(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 7,
            'project_id'          => 1,
            'panel_id'            => 1,
            'board_type'          => 'executive',
            'evaluation_level'    => 'devils_advocate',
            'screen_context'      => 'summary',
            'content_snapshot'    => 'test content',
            'conversation_json'   => '[]',
            'recommendation_json' => '{"summary":"s","rationale":"r"}',
            'proposed_changes'    => '{}',
            'status'              => 'pending',
            'responded_by'        => null,
            'responded_at'        => null,
            'created_at'          => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===========================
    // create()
    // ===========================

    public function testCreateReturnsInsertedId(): void
    {
        $db = $this->makeDb(null, '42');
        $id = BoardReview::create($db, [
            'project_id'          => 1,
            'panel_id'            => 1,
            'board_type'          => 'executive',
            'evaluation_level'    => 'devils_advocate',
            'screen_context'      => 'summary',
            'content_snapshot'    => 'content',
            'conversation_json'   => '[]',
            'recommendation_json' => '{}',
            'proposed_changes'    => '{}',
        ]);
        $this->assertSame(42, $id);
    }

    // ===========================
    // findById()
    // ===========================

    public function testFindByIdReturnsRow(): void
    {
        $row = $this->reviewRow(['screen_context' => 'roadmap']);
        $db  = $this->makeDb($row);
        $result = BoardReview::findById($db, 7);
        $this->assertNotNull($result);
        $this->assertSame('roadmap', $result['screen_context']);
        $this->assertSame('pending', $result['status']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $db = $this->makeDb(null);
        $this->assertNull(BoardReview::findById($db, 999));
    }

    // ===========================
    // findByIdForUpdate()
    // ===========================

    public function testFindByIdForUpdateReturnsRow(): void
    {
        $row = $this->reviewRow();
        $db  = $this->makeDb($row);
        $result = BoardReview::findByIdForUpdate($db, 7);
        $this->assertNotNull($result);
        $this->assertSame('executive', $result['board_type']);
    }

    public function testFindByIdForUpdateReturnsNullWhenMissing(): void
    {
        $db = $this->makeDb(null);
        $this->assertNull(BoardReview::findByIdForUpdate($db, 999));
    }

    // ===========================
    // findByProjectId()
    // ===========================

    public function testFindByProjectIdReturnsRows(): void
    {
        $row = $this->reviewRow();
        $db  = $this->makeDb($row);
        $rows = BoardReview::findByProjectId($db, 1);
        $this->assertCount(1, $rows);
        $this->assertSame('executive', $rows[0]['board_type']);
    }

    public function testFindByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db = $this->makeDb(null);
        $this->assertSame([], BoardReview::findByProjectId($db, 99));
    }

    // ===========================
    // updateStatus()
    // ===========================

    public function testUpdateStatusCallsQuery(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())
           ->method('query')
           ->with($this->stringContains('UPDATE board_reviews'));
        $db->method('query')->willReturn($stmt);

        BoardReview::updateStatus($db, 7, 'accepted', 42);
    }
}
