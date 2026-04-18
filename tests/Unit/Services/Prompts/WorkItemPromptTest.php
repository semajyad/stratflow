<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\WorkItemPrompt;

#[CoversClass(WorkItemPrompt::class)]
class WorkItemPromptTest extends TestCase
{
    #[Test]
    public function mainPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(WorkItemPrompt::PROMPT);
        $this->assertIsString(WorkItemPrompt::PROMPT);
    }

    #[Test]
    public function sizingPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(WorkItemPrompt::SIZING_PROMPT);
        $this->assertIsString(WorkItemPrompt::SIZING_PROMPT);
    }

    #[Test]
    public function descriptionPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(WorkItemPrompt::DESCRIPTION_PROMPT);
        $this->assertIsString(WorkItemPrompt::DESCRIPTION_PROMPT);
    }

    #[Test]
    public function improvePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(WorkItemPrompt::IMPROVE_PROMPT);
        $this->assertIsString(WorkItemPrompt::IMPROVE_PROMPT);
    }

    #[Test]
    public function qualityPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(WorkItemPrompt::QUALITY_PROMPT);
        $this->assertIsString(WorkItemPrompt::QUALITY_PROMPT);
    }

    #[Test]
    public function mainPromptContainsScoringRubric(): void
    {
        $this->assertStringContainsString('SCORING RUBRIC', WorkItemPrompt::PROMPT);
    }

    #[Test]
    public function mainPromptContainsRequiredJsonKeys(): void
    {
        $this->assertStringContainsString('priority_number', WorkItemPrompt::PROMPT);
        $this->assertStringContainsString('kr_hypothesis', WorkItemPrompt::PROMPT);
        $this->assertStringContainsString('splitting_pattern', WorkItemPrompt::PROMPT);
        $this->assertStringContainsString('acceptance_criteria', WorkItemPrompt::PROMPT);
    }

    #[Test]
    public function descriptionPromptContainsPlaceholders(): void
    {
        $this->assertStringContainsString('{title}', WorkItemPrompt::DESCRIPTION_PROMPT);
        $this->assertStringContainsString('{context}', WorkItemPrompt::DESCRIPTION_PROMPT);
        $this->assertStringContainsString('{summary}', WorkItemPrompt::DESCRIPTION_PROMPT);
    }

    #[Test]
    public function qualityPromptContainsSixDimensions(): void
    {
        $this->assertStringContainsString('invest', WorkItemPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('acceptance_criteria', WorkItemPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('value', WorkItemPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('kr_linkage', WorkItemPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('smart', WorkItemPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('splitting', WorkItemPrompt::QUALITY_PROMPT);
    }
}
