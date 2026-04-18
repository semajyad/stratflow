<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\UserStoryPrompt;

#[CoversClass(UserStoryPrompt::class)]
class UserStoryPromptTest extends TestCase
{
    #[Test]
    public function decomposePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(UserStoryPrompt::DECOMPOSE_PROMPT);
        $this->assertIsString(UserStoryPrompt::DECOMPOSE_PROMPT);
    }

    #[Test]
    public function sizePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(UserStoryPrompt::SIZE_PROMPT);
        $this->assertIsString(UserStoryPrompt::SIZE_PROMPT);
    }

    #[Test]
    public function qualityPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(UserStoryPrompt::QUALITY_PROMPT);
        $this->assertIsString(UserStoryPrompt::QUALITY_PROMPT);
    }

    #[Test]
    public function improvePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(UserStoryPrompt::IMPROVE_PROMPT);
        $this->assertIsString(UserStoryPrompt::IMPROVE_PROMPT);
    }

    #[Test]
    public function decomposePromptContainsInvestCriteria(): void
    {
        $this->assertStringContainsString('INVEST', UserStoryPrompt::DECOMPOSE_PROMPT);
    }

    #[Test]
    public function decomposePromptContainsStoryFormat(): void
    {
        $this->assertStringContainsString('As a', UserStoryPrompt::DECOMPOSE_PROMPT);
        $this->assertStringContainsString('so that', UserStoryPrompt::DECOMPOSE_PROMPT);
    }

    #[Test]
    public function decomposePromptRequiresKrHypothesis(): void
    {
        $this->assertStringContainsString('kr_hypothesis', UserStoryPrompt::DECOMPOSE_PROMPT);
    }

    #[Test]
    public function sizePromptContainsFibonacciScale(): void
    {
        $this->assertStringContainsString('1, 2, 3, 5, 8, 13, 20', UserStoryPrompt::SIZE_PROMPT);
    }

    #[Test]
    public function sizePromptContainsPlaceholders(): void
    {
        $this->assertStringContainsString('{title}', UserStoryPrompt::SIZE_PROMPT);
        $this->assertStringContainsString('{description}', UserStoryPrompt::SIZE_PROMPT);
    }

    #[Test]
    public function qualityPromptContainsSixDimensions(): void
    {
        $this->assertStringContainsString('invest', UserStoryPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('acceptance_criteria', UserStoryPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('value', UserStoryPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('kr_linkage', UserStoryPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('smart', UserStoryPrompt::QUALITY_PROMPT);
        $this->assertStringContainsString('splitting', UserStoryPrompt::QUALITY_PROMPT);
    }

    #[Test]
    public function allPromptsAreDifferent(): void
    {
        $this->assertNotEquals(UserStoryPrompt::DECOMPOSE_PROMPT, UserStoryPrompt::SIZE_PROMPT);
        $this->assertNotEquals(UserStoryPrompt::DECOMPOSE_PROMPT, UserStoryPrompt::QUALITY_PROMPT);
        $this->assertNotEquals(UserStoryPrompt::SIZE_PROMPT, UserStoryPrompt::IMPROVE_PROMPT);
    }
}
