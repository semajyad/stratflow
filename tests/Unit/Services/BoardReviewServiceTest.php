<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\BoardReviewService;
use StratFlow\Services\GeminiService;

class BoardReviewServiceTest extends TestCase
{
    private function makeGemini(array $returnValue): GeminiService
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($returnValue);
        return $gemini;
    }

    private function validResult(): array
    {
        return [
            'conversation' => [
                ['speaker' => 'CEO', 'message' => 'This looks weak.'],
                ['speaker' => 'CFO', 'message' => 'Agreed on cost risk.'],
            ],
            'recommendation' => [
                'summary'          => 'Board recommends a revised approach.',
                'rationale'        => 'Cost risk is unacceptable.',
                'proposed_changes' => ['revised_summary' => 'New summary text.'],
            ],
        ];
    }

    private function members(): array
    {
        return [
            ['role_title' => 'CEO', 'prompt_description' => 'Focus on vision.'],
            ['role_title' => 'CFO', 'prompt_description' => 'Focus on cost.'],
        ];
    }

    // ===========================
    // Happy path
    // ===========================

    public function testRunReturnsConversationAndRecommendation(): void
    {
        $service = new BoardReviewService($this->makeGemini($this->validResult()));
        $result  = $service->run(
            members:         $this->members(),
            evaluationLevel: 'devils_advocate',
            screenContext:   'summary',
            screenContent:   'Our strategy is to grow fast.'
        );

        $this->assertArrayHasKey('conversation', $result);
        $this->assertArrayHasKey('recommendation', $result);
        $this->assertArrayHasKey('proposed_changes', $result['recommendation']);
        $this->assertCount(2, $result['conversation']);
    }

    public function testRunCallsGenerateJsonOnce(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())
               ->method('generateJson')
               ->willReturn($this->validResult());

        $service = new BoardReviewService($gemini);
        $service->run($this->members(), 'red_teaming', 'roadmap', 'diagram content');
    }

    // ===========================
    // Validation errors
    // ===========================

    public function testRunThrowsOnMissingConversationKey(): void
    {
        $result = $this->validResult();
        unset($result['conversation']);

        $service = new BoardReviewService($this->makeGemini($result));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/conversation/i');

        $service->run($this->members(), 'devils_advocate', 'summary', 'content');
    }

    public function testRunThrowsOnEmptyConversation(): void
    {
        $result               = $this->validResult();
        $result['conversation'] = [];

        $service = new BoardReviewService($this->makeGemini($result));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/conversation/i');

        $service->run($this->members(), 'devils_advocate', 'summary', 'content');
    }

    public function testRunThrowsOnMissingRecommendation(): void
    {
        $result = $this->validResult();
        unset($result['recommendation']);

        $service = new BoardReviewService($this->makeGemini($result));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/recommendation/i');

        $service->run($this->members(), 'devils_advocate', 'summary', 'content');
    }

    public function testRunThrowsOnMissingProposedChanges(): void
    {
        $result = $this->validResult();
        unset($result['recommendation']['proposed_changes']);

        $service = new BoardReviewService($this->makeGemini($result));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/proposed_changes/i');

        $service->run($this->members(), 'devils_advocate', 'summary', 'content');
    }
}
