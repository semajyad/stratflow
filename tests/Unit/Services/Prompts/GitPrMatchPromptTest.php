<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\GitPrMatchPrompt;

#[CoversClass(GitPrMatchPrompt::class)]
class GitPrMatchPromptTest extends TestCase
{
    #[Test]
    public function promptConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(GitPrMatchPrompt::PROMPT);
        $this->assertIsString(GitPrMatchPrompt::PROMPT);
    }

    #[Test]
    public function promptRequiresJsonArrayResponse(): void
    {
        $this->assertStringContainsString('JSON array', GitPrMatchPrompt::PROMPT);
    }

    #[Test]
    public function promptSpecifiesConfidenceThreshold(): void
    {
        $this->assertStringContainsString('0.5', GitPrMatchPrompt::PROMPT);
    }

    #[Test]
    public function promptIncludesConfidenceFieldRequirement(): void
    {
        $this->assertStringContainsString('confidence', GitPrMatchPrompt::PROMPT);
    }

    #[Test]
    public function promptForbidsMarkdownFences(): void
    {
        $this->assertStringContainsString('No prose, no markdown fences', GitPrMatchPrompt::PROMPT);
    }

    #[Test]
    public function promptMentionsPullRequest(): void
    {
        $this->assertStringContainsString('pull request', GitPrMatchPrompt::PROMPT);
    }
}
