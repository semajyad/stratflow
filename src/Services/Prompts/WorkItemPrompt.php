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

Before writing each item, silently verify it against all INVEST criteria:
  Independent, Negotiable, Valuable, Estimable, Small (~2 sprints), Testable.
  If it fails any criterion, rewrite it until it passes.

Each item's "so that..." statement MUST end with a measurable business outcome
(e.g. "so that we increase conversion rate from 2.1% to 3.5%" — not "so that users benefit").

For acceptance_criteria use a bullet list where each bullet is one Given/When/Then clause.
For kr_hypothesis, predict the measurable % or unit contribution to the most relevant Key
Result listed in the KR data section below. Be specific (e.g. "+1.4pp to conversion rate KR").
For splitting_pattern, name which pattern from the ORG QUALITY RULES list best describes
how this item was decomposed from the diagram.

If org quality rules are provided below, honour every mandatory condition.

Task Constraints:
1. Each HLWI represents approximately 1 month (2 sprints) of effort for a 5-9 person Scrum team.
2. Every item must directly map to a node or cluster of nodes in the diagram.
3. Respond strictly in JSON format — a JSON array only, no markdown fences.
4. Order by priority (most critical first).

Return a JSON array where each element has these exact keys:
- "priority_number" (integer, starting at 1)
- "title" (string, concise work item title)
- "description" (string, 2-3 sentence scope description)
- "acceptance_criteria" (array of strings, each "Given..when..then.." — 2-4 items)
- "kr_hypothesis" (string, predicted contribution to a specific KR — e.g. "+1.4pp to conversion rate KR")
- "splitting_pattern" (string, the pattern name used from the available list)
- "strategic_context" (string, which diagram nodes this maps to)
- "okr_title" (string, the relevant OKR if available, else empty string)
- "okr_description" (string, the relevant OKR description if available, else empty string)
- "estimated_sprints" (integer, default 2)
- "dependencies" (array of integers — priority_numbers of prerequisite items; [] if none)
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
