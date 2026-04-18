<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\BoardReviewPrompt;

#[CoversClass(BoardReviewPrompt::class)]
class BoardReviewPromptTest extends TestCase
{
    private array $members = [
        ['role_title' => 'CEO', 'prompt_description' => 'Focused on growth'],
        ['role_title' => 'CTO', 'prompt_description' => 'Focused on technical feasibility'],
    ];

    #[Test]
    public function buildReturnsNonEmptyString(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'devils_advocate', 'summary', 'Some content');

        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    #[Test]
    public function buildContainsMemberRoleTitles(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'devils_advocate', 'summary', 'Content');

        $this->assertStringContainsString('CEO', $result);
        $this->assertStringContainsString('CTO', $result);
    }

    #[Test]
    public function buildContainsMemberDescriptions(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'red_teaming', 'roadmap', 'Content');

        $this->assertStringContainsString('Focused on growth', $result);
        $this->assertStringContainsString('Focused on technical feasibility', $result);
    }

    #[Test]
    public function buildContainsScreenContent(): void
    {
        $screenContent = 'Strategic roadmap Q3 2026.';
        $result = BoardReviewPrompt::build($this->members, 'gordon_ramsay', 'roadmap', $screenContent);

        $this->assertStringContainsString($screenContent, $result);
    }

    #[Test]
    public function differentEvaluationLevelsProduceDifferentOutputs(): void
    {
        $devils = BoardReviewPrompt::build($this->members, 'devils_advocate', 'summary', 'Content');
        $red    = BoardReviewPrompt::build($this->members, 'red_teaming', 'summary', 'Content');
        $gordon = BoardReviewPrompt::build($this->members, 'gordon_ramsay', 'summary', 'Content');

        $this->assertNotEquals($devils, $red);
        $this->assertNotEquals($devils, $gordon);
    }

    #[Test]
    public function summaryScreenContextIncludesRevisedSummarySchema(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'devils_advocate', 'summary', 'Content');

        $this->assertStringContainsString('revised_summary', $result);
    }

    #[Test]
    public function roadmapScreenContextIncludesMermaidSchema(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'devils_advocate', 'roadmap', 'Content');

        $this->assertStringContainsString('revised_mermaid_code', $result);
    }

    #[Test]
    public function workItemsScreenContextIncludesItemsSchema(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'devils_advocate', 'work_items', 'Content');

        $this->assertStringContainsString('"items"', $result);
    }

    #[Test]
    public function userStoriesScreenContextIncludesStoriesSchema(): void
    {
        $result = BoardReviewPrompt::build($this->members, 'devils_advocate', 'user_stories', 'Content');

        $this->assertStringContainsString('"stories"', $result);
    }

    #[Test]
    public function unknownEvaluationLevelFallsBackToDevilsAdvocate(): void
    {
        $result   = BoardReviewPrompt::build($this->members, 'unknown_level', 'summary', 'Content');
        $expected = BoardReviewPrompt::build($this->members, 'devils_advocate', 'summary', 'Content');

        $this->assertEquals($expected, $result);
    }
}
