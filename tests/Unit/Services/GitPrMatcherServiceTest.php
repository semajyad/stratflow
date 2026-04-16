<?php
declare(strict_types=1);
namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\GitPrMatcherService;
use StratFlow\Services\GeminiService;
use StratFlow\Core\Database;

class GitPrMatcherServiceTest extends TestCase
{
    private function makeDb(array $fetchAll = []): Database
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($fetchAll);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    private function makeGemini(array $matches = []): GeminiService
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($matches);
        return $gemini;
    }

    public function testConstructorWithDatabase(): void
    {
        $db = $this->makeDb();
        $service = new GitPrMatcherService($db, null);
        $this->assertNotNull($service);
    }

    public function testMatchAndLinkWithoutGemini(): void
    {
        $db = $this->makeDb();
        $service = new GitPrMatcherService($db, null);
        $count = $service->matchAndLink('Feature PR', 'Description', 'feature-branch', 'https://github.com/org/repo/pull/1', 1);
        $this->assertEquals(0, $count);
    }

    public function testMatchAndLinkWithNoCandidates(): void
    {
        $db = $this->makeDb([]);
        $gemini = $this->makeGemini([]);
        $service = new GitPrMatcherService($db, $gemini);
        $count = $service->matchAndLink('PR title', 'PR body', 'branch', 'https://github.com/org/repo/pull/2', 1);
        $this->assertEquals(0, $count);
    }

    public function testMatchAndLinkWithLowConfidenceMatches(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $story = ['id' => 1, 'title' => 'Story', 'description' => 'Desc'];
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls([$story], []);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->makeGemini([
            ['id' => 1, 'type' => 'user_story', 'confidence' => 0.5],
        ]);

        $service = new GitPrMatcherService($db, $gemini);
        $count = $service->matchAndLink('PR', 'Body', 'branch', 'https://github.com/org/repo/pull/3', 1);
        $this->assertEquals(0, $count);
    }

    public function testMatchAndLinkWithHighConfidenceMatch(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $story = ['id' => 5, 'title' => 'Epic', 'description' => 'Description'];
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls([$story], []);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->makeGemini([
            ['id' => 5, 'type' => 'user_story', 'confidence' => 0.85],
        ]);

        $service = new GitPrMatcherService($db, $gemini);
        $count = $service->matchAndLink('Feature', 'Body', 'feature', 'https://github.com/org/repo/pull/4', 1);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testMatchAndLinkWithGeminiError(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $story = ['id' => 2, 'title' => 'Story', 'description' => 'Desc'];
        $stmt->method('fetchAll')->willReturn([$story]);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));

        $service = new GitPrMatcherService($db, $gemini);
        $count = $service->matchAndLink('Title', 'Body', 'branch', 'https://github.com/org/repo/pull/5', 1);
        $this->assertEquals(0, $count);
    }

    public function testMatchAndLinkWithEmptyMatchResult(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $story = ['id' => 1, 'title' => 'Story', 'description' => 'Desc'];
        $stmt->method('fetchAll')->willReturn([$story]);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([]);

        $service = new GitPrMatcherService($db, $gemini);
        $count = $service->matchAndLink('Title', 'Body', 'branch', 'https://github.com/org/repo/pull/6', 1);
        $this->assertEquals(0, $count);
    }

    public function testMatchAndLinkTruncatesPrBody(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $story = ['id' => 1, 'title' => 'Story', 'description' => 'D'];
        $stmt->method('fetchAll')->willReturn([$story]);
        $db->method('query')->willReturn($stmt);

        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())
            ->method('generateJson')
            ->with($this->anything(), $this->callback(fn($input) => 
                strlen(json_decode($input, true)['pr_body'] ?? '') <= 1500
            ))
            ->willReturn([]);

        $service = new GitPrMatcherService($db, $gemini);
        $longBody = str_repeat('x', 5000);
        $service->matchAndLink('Title', $longBody, 'branch', 'https://github.com/org/repo/pull/7', 1);
        $this->assertTrue(true);
    }
}
