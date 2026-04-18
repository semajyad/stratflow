<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\PrioritisationPrompt;

#[CoversClass(PrioritisationPrompt::class)]
class PrioritisationPromptTest extends TestCase
{
    #[Test]
    public function ricePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(PrioritisationPrompt::RICE_PROMPT);
        $this->assertIsString(PrioritisationPrompt::RICE_PROMPT);
    }

    #[Test]
    public function wsjfPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(PrioritisationPrompt::WSJF_PROMPT);
        $this->assertIsString(PrioritisationPrompt::WSJF_PROMPT);
    }

    #[Test]
    public function ricePromptContainsRiceDimensions(): void
    {
        $this->assertStringContainsString('Reach', PrioritisationPrompt::RICE_PROMPT);
        $this->assertStringContainsString('Impact', PrioritisationPrompt::RICE_PROMPT);
        $this->assertStringContainsString('Confidence', PrioritisationPrompt::RICE_PROMPT);
        $this->assertStringContainsString('Effort', PrioritisationPrompt::RICE_PROMPT);
    }

    #[Test]
    public function wsjfPromptContainsWsjfDimensions(): void
    {
        $this->assertStringContainsString('Business Value', PrioritisationPrompt::WSJF_PROMPT);
        $this->assertStringContainsString('Time Criticality', PrioritisationPrompt::WSJF_PROMPT);
        $this->assertStringContainsString('Risk Reduction', PrioritisationPrompt::WSJF_PROMPT);
        $this->assertStringContainsString('Job Size', PrioritisationPrompt::WSJF_PROMPT);
    }

    #[Test]
    public function wsjfPromptSpecifiesFibonacciScale(): void
    {
        $this->assertStringContainsString('Fibonacci', PrioritisationPrompt::WSJF_PROMPT);
        $this->assertStringContainsString('1, 2, 3, 5, 8, 13, 20', PrioritisationPrompt::WSJF_PROMPT);
    }

    #[Test]
    public function ricePromptRequestsJsonArrayWithId(): void
    {
        $this->assertStringContainsString('JSON array', PrioritisationPrompt::RICE_PROMPT);
        $this->assertStringContainsString('"id"', PrioritisationPrompt::RICE_PROMPT);
    }

    #[Test]
    public function riceAndWsjfPromptsAreDifferent(): void
    {
        $this->assertNotEquals(PrioritisationPrompt::RICE_PROMPT, PrioritisationPrompt::WSJF_PROMPT);
    }
}
