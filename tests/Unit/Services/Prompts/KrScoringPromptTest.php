<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\KrScoringPrompt;

#[CoversClass(KrScoringPrompt::class)]
class KrScoringPromptTest extends TestCase
{
    #[Test]
    public function promptConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(KrScoringPrompt::PROMPT);
        $this->assertIsString(KrScoringPrompt::PROMPT);
    }

    #[Test]
    public function promptRequiresScoreField(): void
    {
        $this->assertStringContainsString('"score"', KrScoringPrompt::PROMPT);
    }

    #[Test]
    public function promptRequiresRationaleField(): void
    {
        $this->assertStringContainsString('"rationale"', KrScoringPrompt::PROMPT);
    }

    #[Test]
    public function promptSpecifiesScoreRange(): void
    {
        $this->assertStringContainsString('0–10', KrScoringPrompt::PROMPT);
    }

    #[Test]
    public function promptForbidsMarkdown(): void
    {
        $this->assertStringContainsString('No prose, no markdown', KrScoringPrompt::PROMPT);
    }

    #[Test]
    public function promptMentionsKeyResult(): void
    {
        $this->assertStringContainsString('Key Result', KrScoringPrompt::PROMPT);
    }
}
