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
You are an Experienced Agile Product Owner. Decompose the following high-level work item
into 5-10 actionable user stories.

Before writing each story, silently verify it passes all INVEST criteria:
  Independent, Negotiable, Valuable, Estimable, Small (~3 days), Testable.
  Rewrite until it passes.

Each story MUST:
- Follow the format: "As a [specific role], I want [specific action], so that [measurable outcome]"
- End "so that..." with a measurable business outcome tied to the KR data below (if provided)
- Have 2-4 acceptance criteria in Given/When/Then format
- Include a kr_hypothesis predicting its specific % contribution to a KR (if KR data is provided)
- Name the splitting_pattern used from the list in the org quality rules (if provided)

If org quality rules are provided, honour every mandatory condition.

Return a JSON array where each element has:
- "title" (string, the "As a..." story in full)
- "description" (string, 2-3 sentence technical description of what needs to be built)
- "acceptance_criteria" (array of strings, each a "Given..when..then.." clause — 2-4 items)
- "kr_hypothesis" (string, predicted contribution to a KR, or empty string if no KR data)
- "splitting_pattern" (string, pattern name used, or empty string if no rules provided)
- "size" (integer, story points: 1, 2, 3, 5, 8, or 13)
PROMPT;

    public const SIZE_PROMPT = <<<'PROMPT'
You are an Expert System Architect. Estimate the story point size for this user story based on complexity, unknowns, and effort. Use the modified Fibonacci scale: 1, 2, 3, 5, 8, 13, 20.

Return ONLY a JSON object: {"size": <number>, "reasoning": "<1 sentence explanation>"}

Story Title: {title}
Story Description: {description}
PROMPT;
}
