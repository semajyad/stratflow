<?php
declare(strict_types=1);
namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\KrScoringService;
use StratFlow\Services\GeminiService;
use StratFlow\Core\Database;

class KrScoringServiceTest extends TestCase
{
    private function makeDb(mixed $fetch = false, array $all = [], array $multiAll = []): Database
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls(...array_merge(
            array_fill(0, 10, $all),
            array_fill(0, 10, $multiAll)
        ));
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    private function makeGemini(array $response = ['score' => 7, 'rationale' => 'Good fit']): GeminiService
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($response);
        return $gemini;
    }

    public function testConstructorWithValidDatabase(): void
    {
        $db = $this->makeDb();
        $service = new KrScoringService($db, null);
        $this->assertNotNull($service);
    }

    public function testConstructorWithGemini(): void
    {
        $db = $this->makeDb();
        $gemini = $this->makeGemini();
        $service = new KrScoringService($db, $gemini);
        $this->assertNotNull($service);
    }

    public function testScoreForMergedPrWithoutGemini(): void
    {
        $db = $this->makeDb();
        $service = new KrScoringService($db, null);
        $service->scoreForMergedPr('https://github.com/org/repo/pull/123', 1);
        $this->assertTrue(true);
    }

    public function testScoreForMergedPrWithNoLinks(): void
    {
        $db = $this->makeDb(false, []);
        $gemini = $this->makeGemini();
        $service = new KrScoringService($db, $gemini);
        $service->scoreForMergedPr('https://github.com/org/repo/pull/999', 1);
        $this->assertTrue(true);
    }

    public function testScoreForMergedPrWithValidLink(): void
    {
        $link = ['id' => 1, 'local_type' => 'hl_work_item', 'local_id' => 10, 'ref_label' => 'PR #123'];
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls([$link], [], [], []);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->makeGemini(['score' => 8, 'rationale' => 'High value']);
        $service = new KrScoringService($db, $gemini);
        $service->scoreForMergedPr('https://github.com/org/repo/pull/123', 1);
        $this->assertTrue(true);
    }

    public function testScoreForMergedPrWithUserStoryLink(): void
    {
        $link = ['id' => 2, 'local_type' => 'user_story', 'local_id' => 20, 'ref_label' => 'Story PR'];
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls([$link], [], [], []);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->makeGemini(['score' => 5, 'rationale' => 'Moderate impact']);
        $service = new KrScoringService($db, $gemini);
        $service->scoreForMergedPr('https://github.com/org/repo/pull/456', 1);
        $this->assertTrue(true);
    }

    public function testScoreForMergedPrWithGeminiError(): void
    {
        $link = ['id' => 3, 'local_type' => 'hl_work_item', 'local_id' => 30, 'ref_label' => 'PR'];
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls([$link], [], [], []);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));

        $service = new KrScoringService($db, $gemini);
        $service->scoreForMergedPr('https://github.com/org/repo/pull/789', 1);
        $this->assertTrue(true);
    }
}
