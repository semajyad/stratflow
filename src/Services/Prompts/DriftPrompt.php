<?php
/**
 * DriftPrompt
 *
 * Prompt templates for the Strategic Drift Engine's AI alignment checks.
 * Used by DriftDetectionService to assess whether new user stories
 * align with the project's original strategic OKRs.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class DriftPrompt
{
    public const ALIGNMENT_PROMPT = <<<'PROMPT'
You are a Strategic Alignment Assessor. Given the original strategic OKRs and a newly added user story, assess whether this story aligns with the original strategic goals.

Return a JSON object with:
- "aligned": boolean (true if the story serves the strategic goals)
- "confidence": number 0-100 (how confident you are)
- "explanation": string (1-2 sentences explaining your assessment)

Original Strategic OKRs:
{okrs}

New User Story:
Title: {story_title}
Description: {story_description}
PROMPT;
}
