<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\SprintPrompt;

#[CoversClass(SprintPrompt::class)]
class SprintPromptTest extends TestCase
{
    #[Test]
    public function allocatePromptIsNonEmpty(): void
    {
        $this->assertNotEmpty(SprintPrompt::ALLOCATE_PROMPT);
        $this->assertIsString(SprintPrompt::ALLOCATE_PROMPT);
    }

    #[Test]
    public function allocatePromptContainsSprintsPlaceholder(): void
    {
        $this->assertStringContainsString('{sprints}', SprintPrompt::ALLOCATE_PROMPT);
    }

    #[Test]
    public function allocatePromptContainsStoriesPlaceholder(): void
    {
        $this->assertStringContainsString('{stories}', SprintPrompt::ALLOCATE_PROMPT);
    }

    #[Test]
    public function allocatePromptMentionsCapacity(): void
    {
        $this->assertStringContainsString('capacity', SprintPrompt::ALLOCATE_PROMPT);
    }

    #[Test]
    public function allocatePromptRequestsJsonArrayWithStoryAndSprintIds(): void
    {
        $this->assertStringContainsString('"story_id"', SprintPrompt::ALLOCATE_PROMPT);
        $this->assertStringContainsString('"sprint_id"', SprintPrompt::ALLOCATE_PROMPT);
    }

    #[Test]
    public function allocatePromptMentionsDependencies(): void
    {
        $this->assertStringContainsString('Dependencies', SprintPrompt::ALLOCATE_PROMPT);
    }
}
