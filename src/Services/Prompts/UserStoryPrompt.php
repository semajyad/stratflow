<?php
/**
 * UserStoryPrompt
 *
 * System prompts for AI-assisted user story decomposition from
 * high-level work items, and story point size estimation.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class UserStoryPrompt
{
    public const DECOMPOSE_PROMPT = <<<'PROMPT'
You are an Experienced Agile Product Owner. Decompose the following high-level work item into 5-10 actionable user stories. Each story must:
- Follow the format: "As a [role], I want [action], so that [value]"
- Represent approximately 3 days of development work
- Be independently testable

Return a JSON array where each element has:
- "title": the user story in "As a..." format
- "description": 2-3 sentence technical description of what needs to be built
- "size": suggested story points (1, 2, 3, 5, 8, or 13)
PROMPT;

    public const SIZE_PROMPT = <<<'PROMPT'
You are an Expert System Architect. Estimate the story point size for this user story based on complexity, unknowns, and effort. Use the modified Fibonacci scale: 1, 2, 3, 5, 8, 13, 20.

Return ONLY a JSON object: {"size": <number>, "reasoning": "<1 sentence explanation>"}

Story Title: {title}
Story Description: {description}
PROMPT;
}
