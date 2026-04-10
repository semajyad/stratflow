<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GeminiService;
use StratFlow\Services\StoryImprovementService;

class StoryImprovementServiceTest extends TestCase
{
    private function makeBreakdownWithIssues(): array
    {
        return [
            'invest'              => ['score' => 12, 'max' => 20, 'issues' => ['Not independent']],
            'acceptance_criteria' => ['score' => 10, 'max' => 20, 'issues' => ['No Given/When/Then format']],
            'value'               => ['score' => 8,  'max' => 20, 'issues' => ['Vague outcome — no numbers']],
            'kr_linkage'          => ['score' => 8,  'max' => 20, 'issues' => ['No KR referenced']],
            'smart'               => ['score' => 5,  'max' => 10, 'issues' => ['Not time-bound']],
            'splitting'           => ['score' => 5,  'max' => 10, 'issues' => ['No pattern named']],
        ];
    }

    private function makeBreakdownAtThreshold(): array
    {
        // All dimensions exactly at 80% — should NOT trigger improvement
        return [
            'invest'              => ['score' => 16, 'max' => 20, 'issues' => []],
            'acceptance_criteria' => ['score' => 16, 'max' => 20, 'issues' => []],
            'value'               => ['score' => 16, 'max' => 20, 'issues' => []],
            'kr_linkage'          => ['score' => 16, 'max' => 20, 'issues' => []],
            'smart'               => ['score' => 8,  'max' => 10, 'issues' => []],
            'splitting'           => ['score' => 8,  'max' => 10, 'issues' => []],
        ];
    }

    #[Test]
    public function improveWorkItemReturnsImprovedFieldsOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'acceptance_criteria' => "Given the user is logged in\nWhen they submit the form\nThen they see a confirmation",
            'kr_hypothesis'       => 'Contributes +15% toward KR: Increase conversion rate to 5% by Q3',
            'description'         => 'Improved scope description addressing the issues.',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item', 'description' => 'Old description'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayHasKey('acceptance_criteria', $result);
        $this->assertArrayHasKey('kr_hypothesis', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertIsString($result['description']);
    }

    #[Test]
    public function improveStoryReturnsImprovedFieldsOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'description' => 'As a product manager I want dashboards so that conversion increases by 5% by Q3',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveStory(
            ['title' => 'As a user I want x so that y'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayHasKey('description', $result);
        $this->assertIsString($result['description']);
    }

    #[Test]
    public function returnsEmptyArrayWhenAllDimensionsAtThreshold(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateJson');

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownAtThreshold(),
            ''
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayWhenGeminiThrows(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function stripsUnknownFieldsFromGeminiResponse(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'title'               => 'This should be stripped — PM owns the title',
            'description'         => 'Valid improved description',
            'acceptance_criteria' => 'Given x when y then z',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('acceptance_criteria', $result);
    }

    #[Test]
    public function stripsEmptyStringFieldsFromGeminiResponse(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'description'         => '',
            'acceptance_criteria' => 'Given x when y then z',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayNotHasKey('description', $result);
        $this->assertArrayHasKey('acceptance_criteria', $result);
    }
}
