<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GeminiService;
use StratFlow\Services\StoryQualityScorer;

class StoryQualityScorerTest extends TestCase
{
    private function makeValidBreakdown(int $overall = 73): array
    {
        return [
            'overall'             => $overall,
            'invest'              => ['score' => 15, 'max' => 20, 'issues' => []],
            'acceptance_criteria' => ['score' => 18, 'max' => 20, 'issues' => []],
            'value'               => ['score' => 10, 'max' => 20, 'issues' => ['Outcome is vague']],
            'kr_linkage'          => ['score' => 15, 'max' => 20, 'issues' => []],
            'smart'               => ['score' =>  8, 'max' => 10, 'issues' => []],
            'splitting'           => ['score' =>  7, 'max' => 10, 'issues' => []],
        ];
    }

    #[Test]
    public function scoreWorkItemReturnsScoreAndBreakdownOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($this->makeValidBreakdown(73));

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreWorkItem(
            ['title' => 'Test item', 'description' => 'desc', 'acceptance_criteria' => 'Given x when y then z'],
            ''
        );

        $this->assertSame(73, $result['score']);
        $this->assertIsArray($result['breakdown']);
        $this->assertArrayHasKey('invest', $result['breakdown']);
        $this->assertArrayNotHasKey('overall', $result['breakdown']);
    }

    #[Test]
    public function scoreStoryReturnsScoreAndBreakdownOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($this->makeValidBreakdown(81));

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreStory(
            ['title' => 'As a user I want X so that Y increases by 5%', 'acceptance_criteria' => 'Given...'],
            ''
        );

        $this->assertSame(81, $result['score']);
        $this->assertIsArray($result['breakdown']);
    }

    #[Test]
    public function returnsNullScoreWhenGeminiThrows(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreWorkItem(['title' => 'Test'], '');

        $this->assertNull($result['score']);
        $this->assertNull($result['breakdown']);
    }

    #[Test]
    public function returnsNullScoreWhenDimensionKeyMissing(): void
    {
        $incomplete = $this->makeValidBreakdown(73);
        unset($incomplete['kr_linkage']); // missing a required dimension

        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($incomplete);

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreWorkItem(['title' => 'Test'], '');

        $this->assertNull($result['score']);
        $this->assertNull($result['breakdown']);
    }

    #[Test]
    public function overallKeyIsRemovedFromBreakdown(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($this->makeValidBreakdown(73));

        $scorer  = new StoryQualityScorer($gemini);
        $result  = $scorer->scoreWorkItem(['title' => 'Test'], '');

        $this->assertArrayNotHasKey('overall', $result['breakdown']);
        $this->assertSame(6, count($result['breakdown']));
    }
}
