<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\DriftPrompt;

#[CoversClass(DriftPrompt::class)]
class DriftPromptTest extends TestCase
{
    #[Test]
    public function alignmentPromptConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(DriftPrompt::ALIGNMENT_PROMPT);
        $this->assertIsString(DriftPrompt::ALIGNMENT_PROMPT);
    }

    #[Test]
    public function alignmentPromptContainsPlaceholders(): void
    {
        $this->assertStringContainsString('{okrs}', DriftPrompt::ALIGNMENT_PROMPT);
        $this->assertStringContainsString('{story_title}', DriftPrompt::ALIGNMENT_PROMPT);
        $this->assertStringContainsString('{story_description}', DriftPrompt::ALIGNMENT_PROMPT);
    }

    #[Test]
    public function alignmentPromptRequiresAlignedField(): void
    {
        $this->assertStringContainsString('"aligned"', DriftPrompt::ALIGNMENT_PROMPT);
    }

    #[Test]
    public function alignmentPromptRequiresConfidenceField(): void
    {
        $this->assertStringContainsString('"confidence"', DriftPrompt::ALIGNMENT_PROMPT);
    }

    #[Test]
    public function alignmentPromptRequiresExplanationField(): void
    {
        $this->assertStringContainsString('"explanation"', DriftPrompt::ALIGNMENT_PROMPT);
    }

    #[Test]
    public function alignmentPromptMentionsOkrs(): void
    {
        $this->assertStringContainsString('OKR', DriftPrompt::ALIGNMENT_PROMPT);
    }
}
