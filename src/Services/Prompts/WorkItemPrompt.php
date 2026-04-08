<?php
/**
 * WorkItemPrompt
 *
 * Prompt templates for AI generation of High-Level Work Items (HLWIs)
 * from Mermaid strategy diagrams and OKR data, and for generating
 * detailed scope descriptions for individual work items.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class WorkItemPrompt
{
    public const PROMPT = <<<'PROMPT'
You are the ThreePoints StratFlow Architect. Translate the following Mermaid strategy
diagram and OKR data into a prioritised backlog of High-Level Work Items (HLWIs).

Task Constraints:
1. Each HLWI must represent approximately 1 month (4 weeks) of effort for a standard
   Scrum team (5-9 people), which equals roughly 2 sprints.
2. Every item must directly map back to a node or cluster of nodes in the diagram.
3. Respond strictly in JSON format -- a JSON array only, no markdown fences.
4. Order by priority (most critical first).

Return a JSON array where each element has these exact keys:
- "priority_number" (integer, starting at 1)
- "title" (string, concise work item title)
- "description" (string, 2-3 sentence scope description)
- "strategic_context" (string, which diagram nodes this maps to)
- "okr_title" (string, the relevant OKR if available, else empty string)
- "okr_description" (string, the relevant OKR description if available, else empty string)
- "estimated_sprints" (integer, default 2)
- "dependencies" (array of integers — priority_numbers of items that must be completed before this one; use empty array [] if none)
PROMPT;

    public const SIZING_PROMPT = <<<'PROMPT'
You are an Agile estimation expert. For each work item below, estimate how many 2-week sprints it would take for a standard Scrum team (5-9 people) to complete.

Return a JSON array where each element has: "id" (integer), "estimated_sprints" (integer, minimum 1, maximum 6).

Work items:
PROMPT;

    public const DESCRIPTION_PROMPT = <<<'PROMPT'
You are a Technical Project Manager. Generate a detailed 1-month scope description
for the following high-level work item. The description should include:
1. Key deliverables
2. Technical considerations
3. Dependencies and risks
4. Definition of Done criteria

Keep it concise but actionable. Maximum 300 words.

Work Item Title: {title}
Strategic Context: {context}
Overall Strategy Summary: {summary}
PROMPT;
}
