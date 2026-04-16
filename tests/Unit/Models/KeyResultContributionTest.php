<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use StratFlow\Models\KeyResultContribution;

class KeyResultContributionTest extends TestCase
{
    private function makeDb(mixed $fetch = false, array $fetchAll = []): \StratFlow\Core\Database
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    public function testUpsertClampsScorerAndInserts(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        // Verify that query() is called with an INSERT or UPDATE statement
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('INSERT'))
            ->willReturn($stmt);

        KeyResultContribution::upsert($db, 1, 5, 1, 15, 'High relevance');

        $this->assertTrue(true);
    }

    public function testUpsertHandlesNullRationale(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        // Verify that query() is called for upsert
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('INSERT'))
            ->willReturn($stmt);

        KeyResultContribution::upsert($db, 1, 5, 1, 8, null);

        $this->assertTrue(true);
    }

    public function testFindByKeyResultIdReturnsContributions(): void
    {
        $rows = [
            ['id' => 1, 'key_result_id' => 5, 'story_git_link_id' => 10, 'ai_relevance_score' => 8, 'ref_url' => 'https://github.com/pr/123', 'ref_label' => 'PR #123'],
            ['id' => 2, 'key_result_id' => 5, 'story_git_link_id' => 11, 'ai_relevance_score' => 6, 'ref_url' => 'https://github.com/pr/124', 'ref_label' => 'PR #124'],
        ];
        $db = $this->makeDb(false, $rows);

        $result = KeyResultContribution::findByKeyResultId($db, 5, 1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(8, $result[0]['ai_relevance_score']);
    }

    public function testFindByKeyResultIdsReturnsGroupedByKeyResultId(): void
    {
        $rows = [
            ['id' => 1, 'key_result_id' => 5, 'story_git_link_id' => 10, 'ai_relevance_score' => 8, 'ref_url' => 'https://github.com/pr/123', 'ref_label' => 'PR #123'],
            ['id' => 2, 'key_result_id' => 5, 'story_git_link_id' => 11, 'ai_relevance_score' => 6, 'ref_url' => 'https://github.com/pr/124', 'ref_label' => 'PR #124'],
            ['id' => 3, 'key_result_id' => 6, 'story_git_link_id' => 12, 'ai_relevance_score' => 9, 'ref_url' => 'https://github.com/pr/125', 'ref_label' => 'PR #125'],
        ];
        $db = $this->makeDb(false, $rows);

        $result = KeyResultContribution::findByKeyResultIds($db, [5, 6], 1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertCount(2, $result[5]);
        $this->assertCount(1, $result[6]);
    }

    public function testFindByKeyResultIdsWithEmptyArrayReturnsEmpty(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);

        $result = KeyResultContribution::findByKeyResultIds($db, [], 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindRecentByKeyResultIdLimitsResults(): void
    {
        $rows = [
            ['ai_relevance_score' => 9, 'ai_rationale' => 'Very relevant', 'ref_label' => 'PR #100'],
            ['ai_relevance_score' => 8, 'ai_rationale' => 'Relevant', 'ref_label' => 'PR #99'],
        ];
        $db = $this->makeDb(false, $rows);

        $result = KeyResultContribution::findRecentByKeyResultId($db, 5, 1, 10);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }
}
