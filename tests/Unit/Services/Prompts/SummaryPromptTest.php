<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\SummaryPrompt;

#[CoversClass(SummaryPrompt::class)]
class SummaryPromptTest extends TestCase
{
    #[Test]
    public function promptConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(SummaryPrompt::PROMPT);
        $this->assertIsString(SummaryPrompt::PROMPT);
    }

    #[Test]
    public function promptMentionsThreeParagraphs(): void
    {
        $this->assertStringContainsString('3-paragraph', SummaryPrompt::PROMPT);
    }

    #[Test]
    public function promptMentionsBusinessObjectives(): void
    {
        $this->assertStringContainsString('business objectives', SummaryPrompt::PROMPT);
    }

    #[Test]
    public function promptMentionsStrategicPriorities(): void
    {
        $this->assertStringContainsString('strategic priorities', SummaryPrompt::PROMPT);
    }

    #[Test]
    public function promptSpecifiesWordLimit(): void
    {
        $this->assertStringContainsString('500 words', SummaryPrompt::PROMPT);
    }
}
