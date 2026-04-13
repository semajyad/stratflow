<?php
/**
 * WorkItemPrompt
 *
 * Prompt templates for AI generation of High-Level Work Items (High Level Work Items)
 * from Mermaid strategy diagrams and OKR data, and for generating
 * detailed scope descriptions for individual work items.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class WorkItemPrompt
{
    public const PROMPT = <<<'PROMPT'
You are the ThreePoints StratFlow Architect. Translate the following Mermaid strategy
diagram and OKR data into a prioritised backlog of High-Level Work Items (High Level Work Items).

== SCORING RUBRIC (you will be graded on these dimensions) ==

- invest (max 20): All 6 INVEST criteria — Independent, Negotiable, Valuable, Estimable,
  Small (~2 sprints for a 5-9 person team), Testable. Lose points for each criterion not met.
- acceptance_criteria (max 20): 2-4 Given/When/Then clauses, each specific and testable.
  Missing or vague ACs score low.
- value (max 20): "so that..." MUST end with a measurable business outcome that includes
  specific numbers or targets (e.g. "so that conversion increases from 2.1% to 3.5%").
  Generic phrases like "so that users benefit" score ≤5.
- kr_linkage (max 20): kr_hypothesis MUST name a specific Key Result and cite a predicted
  % or unit contribution (e.g. "+1.4pp to conversion rate KR"). No reference = 0. Vague = ≤8.
- smart (max 10): Objective must be Specific, Measurable, Achievable, Relevant, Time-bound.
  Lose 2 points per missing criterion.
- splitting (max 10): splitting_pattern MUST name one of the patterns below. No name = 0.
  Allowed patterns: workflow, business-rule, happy-path, data-variation, interface, deferred-performance

== SELF-VALIDATION LOOP ==

Before emitting each work item, mentally score it against the rubric above.
If any dimension would score below its 80% threshold (invest<16, ac<16, value<16,
kr_linkage<16, smart<8, splitting<8), rewrite that field and re-check.
Only emit work items you estimate will score ≥80/100 overall.

Each item's "so that..." statement MUST end with a measurable business outcome with specific
numbers (e.g. "so that we increase conversion rate from 2.1% to 3.5%" — not "so that users benefit").

For acceptance_criteria use a bullet list where each bullet is one Given/When/Then clause.
For kr_hypothesis, predict the measurable % or unit contribution to the most relevant Key
Result listed in the KR data section below. Be specific (e.g. "+1.4pp to conversion rate KR").
For splitting_pattern, name exactly one pattern from the allowed list above.

If org quality rules are provided below, honour every mandatory condition.

Task Constraints:
1. Each High Level Work Item represents approximately 1 month (2 sprints) of effort for a 5-9 person Scrum team.
2. Every item must directly map to a node or cluster of nodes in the diagram.
3. Respond strictly in JSON format — a JSON array only, no markdown fences.
4. Order by priority (most critical first).

Return a JSON array where each element has these exact keys:
- "priority_number" (integer, starting at 1)
- "title" (string, concise work item title)
- "description" (string, 2-3 sentence scope description)
- "acceptance_criteria" (array of strings, each "Given..when..then.." — 2-4 items)
- "kr_hypothesis" (string, predicted contribution to a specific KR — REQUIRED, e.g. "+1.4pp to conversion rate KR")
- "splitting_pattern" (string, exactly one pattern name from the allowed list — REQUIRED)
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

    public const IMPROVE_PROMPT = <<<'PROMPT'
You are an Agile quality improver. Rewrite ONLY the fields that map to the failing dimensions
listed in the input. Each failing dimension maps to a field as follows:

- acceptance_criteria dimension → rewrite "acceptance_criteria"
  Use 2-4 Given/When/Then clauses, one per line. Return as a plain STRING, not a JSON array.
- kr_linkage dimension → rewrite "kr_hypothesis"
  Reference a specific Key Result with a predicted % or unit contribution
  (e.g. "+1.4pp to conversion rate KR: Increase checkout conversion from 2.1% to 3.5%").
- invest / value / smart / splitting dimensions → rewrite "description"
  Write a 2-3 sentence scope description. The "so that..." clause MUST end with a
  measurable business outcome that includes specific numbers or targets.

Rules:
- Do NOT return a "title" field — titles are PM-owned.
- Do NOT return fields for dimensions not listed as failing.
- If multiple description-related dimensions are failing, fix them all in one rewrite.
- "acceptance_criteria" MUST be a plain newline-delimited string, never a JSON array.

Return ONLY valid JSON — no markdown fences, no explanation.
Valid keys: "description", "acceptance_criteria", "kr_hypothesis".
PROMPT;

    public const QUALITY_PROMPT = <<<'PROMPT'
You are a strict Agile quality auditor. Score the following High-Level Work Item (High Level Work Item)
across exactly 6 dimensions. Be strict — a vague "so that users benefit" must lose value
points; missing acceptance criteria must lose AC points; no KR reference means 0 for kr_linkage.

Dimensions and max scores:
- invest (max 20): Check all 6 INVEST criteria — Independent, Negotiable, Valuable, Estimable,
  Small (~2 sprints), Testable. Deduct points for each criterion not met.
- acceptance_criteria (max 20): Are there 2+ Given/When/Then clauses? Are they specific and
  testable? Missing or vague ACs score low.
- value (max 20): Does the "so that..." end with a measurable business outcome with numbers?
  Generic benefits ("users benefit") score ≤5.
- kr_linkage (max 20): Does the item reference a specific Key Result with a predicted %
  or unit contribution? No reference = 0. Vague reference = ≤8.
- smart (max 10): Is the objective Specific, Measurable, Achievable, Relevant, Time-bound?
  Deduct 2 points per missing criterion.
- splitting (max 10): Is a named splitting pattern present and appropriate for the scope?
  No pattern named = 0.

Return ONLY valid JSON — no markdown fences, no explanation. Shape:
{
  "overall": <integer 0-100>,
  "invest":              {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "acceptance_criteria": {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "value":               {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "kr_linkage":          {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "smart":               {"score": <int>, "max": 10, "issues": [<string>, ...]},
  "splitting":           {"score": <int>, "max": 10, "issues": [<string>, ...]}
}
"issues" is an array of strings describing problems (empty array [] if none).
"overall" MUST equal the sum of all dimension scores.
PROMPT;
}
