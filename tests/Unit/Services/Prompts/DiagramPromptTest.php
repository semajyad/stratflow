<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\DiagramPrompt;

#[CoversClass(DiagramPrompt::class)]
class DiagramPromptTest extends TestCase
{
    #[Test]
    public function promptConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(DiagramPrompt::PROMPT);
        $this->assertIsString(DiagramPrompt::PROMPT);
    }

    #[Test]
    public function promptContainsMermaidInstruction(): void
    {
        $this->assertStringContainsString('graph TD', DiagramPrompt::PROMPT);
    }

    #[Test]
    public function promptContainsNodeCountGuidance(): void
    {
        $this->assertStringContainsString('5 to 15 nodes', DiagramPrompt::PROMPT);
    }

    #[Test]
    public function promptInstructsNoMarkdownFences(): void
    {
        $this->assertStringContainsString('Do NOT wrap output in markdown code fences', DiagramPrompt::PROMPT);
    }

    #[Test]
    public function okrPromptConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(DiagramPrompt::OKR_PROMPT);
        $this->assertIsString(DiagramPrompt::OKR_PROMPT);
    }

    #[Test]
    public function okrPromptContainsSmartCriteria(): void
    {
        $this->assertStringContainsString('SMART', DiagramPrompt::OKR_PROMPT);
        $this->assertStringContainsString('Measurable', DiagramPrompt::OKR_PROMPT);
        $this->assertStringContainsString('Time-bound', DiagramPrompt::OKR_PROMPT);
    }

    #[Test]
    public function okrPromptRequestsJsonArray(): void
    {
        $this->assertStringContainsString('JSON array', DiagramPrompt::OKR_PROMPT);
    }
}
