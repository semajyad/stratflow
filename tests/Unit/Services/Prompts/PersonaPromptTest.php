<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services\Prompts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\Prompts\PersonaPrompt;

#[CoversClass(PersonaPrompt::class)]
class PersonaPromptTest extends TestCase
{
    #[Test]
    public function buildPromptReturnsNonEmptyString(): void
    {
        $result = PersonaPrompt::buildPrompt('CEO', 'Focused on business value', 'devils_advocate', 'Some screen content');

        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    #[Test]
    public function buildPromptContainsRoleTitle(): void
    {
        $result = PersonaPrompt::buildPrompt('Senior Developer', 'Cares about code quality', 'red_teaming', 'Screen content here');

        $this->assertStringContainsString('Senior Developer', $result);
    }

    #[Test]
    public function buildPromptContainsPromptDescription(): void
    {
        $result = PersonaPrompt::buildPrompt('Designer', 'Focus on UX and accessibility', 'devils_advocate', 'Content');

        $this->assertStringContainsString('Focus on UX and accessibility', $result);
    }

    #[Test]
    public function buildPromptContainsScreenContent(): void
    {
        $screenContent = 'This is the page content to evaluate.';
        $result = PersonaPrompt::buildPrompt('PM', 'Thinks about roadmap', 'gordon_ramsay', $screenContent);

        $this->assertStringContainsString($screenContent, $result);
    }

    #[Test]
    public function differentEvaluationLevelsProduceDifferentOutputs(): void
    {
        $devils = PersonaPrompt::buildPrompt('CEO', 'Desc', 'devils_advocate', 'Content');
        $red    = PersonaPrompt::buildPrompt('CEO', 'Desc', 'red_teaming', 'Content');
        $gordon = PersonaPrompt::buildPrompt('CEO', 'Desc', 'gordon_ramsay', 'Content');

        $this->assertNotEquals($devils, $red);
        $this->assertNotEquals($devils, $gordon);
        $this->assertNotEquals($red, $gordon);
    }

    #[Test]
    public function unknownEvaluationLevelFallsBackToDevilsAdvocate(): void
    {
        $result   = PersonaPrompt::buildPrompt('Role', 'Desc', 'unknown_level', 'Content');
        $expected = PersonaPrompt::buildPrompt('Role', 'Desc', 'devils_advocate', 'Content');

        $this->assertEquals($expected, $result);
    }

    #[Test]
    public function customLevelsOverrideDefaults(): void
    {
        $customLevels = ['custom_mode' => 'Custom instruction text for evaluation.'];
        $result       = PersonaPrompt::buildPrompt('Role', 'Desc', 'custom_mode', 'Content', $customLevels);

        $this->assertStringContainsString('Custom instruction text for evaluation.', $result);
    }

    #[Test]
    public function promptContainsStructureHeaders(): void
    {
        $result = PersonaPrompt::buildPrompt('QA', 'Quality focused', 'red_teaming', 'App content');

        $this->assertStringContainsString('Overall Assessment', $result);
        $this->assertStringContainsString('Key Concerns', $result);
        $this->assertStringContainsString('Recommendations', $result);
        $this->assertStringContainsString('Risk Rating', $result);
    }
}
