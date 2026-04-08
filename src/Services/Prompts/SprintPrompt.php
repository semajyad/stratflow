<?php
/**
 * SprintPrompt
 *
 * System prompt for AI-assisted sprint allocation of user stories
 * based on priority, capacity, and dependencies.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class SprintPrompt
{
    public const ALLOCATE_PROMPT = <<<'PROMPT'
You are an Agile Project Manager. Allocate the following user stories into sprints based on:
1. Story priority (lower priority_number = higher priority, should be in earlier sprints)
2. Sprint capacity (total story points should not exceed team_capacity)
3. Dependencies (blocked stories should come after their blockers)
4. Even distribution across sprints

Available sprints with their capacities:
{sprints}

Unallocated stories:
{stories}

Return a JSON array where each element has: "story_id" (integer), "sprint_id" (integer).
Only allocate stories that fit within sprint capacity. Leave stories unallocated if they don't fit.
PROMPT;
}
