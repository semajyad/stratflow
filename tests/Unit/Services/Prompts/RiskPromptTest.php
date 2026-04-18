<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\RiskPrompt;

#[CoversClass(RiskPrompt::class)]
class RiskPromptTest extends TestCase
{
    #[Test]
    public function generatePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(RiskPrompt::GENERATE_PROMPT);
        $this->assertIsString(RiskPrompt::GENERATE_PROMPT);
    }

    #[Test]
    public function mitigationPromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(RiskPrompt::MITIGATION_PROMPT);
        $this->assertIsString(RiskPrompt::MITIGATION_PROMPT);
    }

    #[Test]
    public function generatePromptSpecifiesRiskCount(): void
    {
        $this->assertStringContainsString('3-5', RiskPrompt::GENERATE_PROMPT);
    }

    #[Test]
    public function generatePromptRequiresRiskFields(): void
    {
        $this->assertStringContainsString('title', RiskPrompt::GENERATE_PROMPT);
        $this->assertStringContainsString('description', RiskPrompt::GENERATE_PROMPT);
        $this->assertStringContainsString('likelihood', RiskPrompt::GENERATE_PROMPT);
        $this->assertStringContainsString('impact', RiskPrompt::GENERATE_PROMPT);
    }

    #[Test]
    public function mitigationPromptContainsPlaceholders(): void
    {
        $this->assertStringContainsString('{title}', RiskPrompt::MITIGATION_PROMPT);
        $this->assertStringContainsString('{description}', RiskPrompt::MITIGATION_PROMPT);
        $this->assertStringContainsString('{likelihood}', RiskPrompt::MITIGATION_PROMPT);
        $this->assertStringContainsString('{impact}', RiskPrompt::MITIGATION_PROMPT);
    }

    #[Test]
    public function generateAndMitigationPromptsAreDifferent(): void
    {
        $this->assertNotEquals(RiskPrompt::GENERATE_PROMPT, RiskPrompt::MITIGATION_PROMPT);
    }
}
