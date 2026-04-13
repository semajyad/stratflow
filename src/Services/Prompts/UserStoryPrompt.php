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

== SCORING RUBRIC (you will be graded on these dimensions) ==

- invest (max 20): All 6 INVEST criteria — Independent, Negotiable, Valuable, Estimable,
  Small (~3 days for one developer), Testable. Lose points for each criterion not met.
- acceptance_criteria (max 20): 2-4 Given/When/Then clauses, each specific and testable.
  Missing or vague ACs score low.
- value (max 20): "so that..." MUST end with a measurable business outcome that includes
  specific numbers or targets (e.g. "so that checkout conversion increases from 2.1% to 3.5%").
  Stories without numbers in the outcome score ≤5.
- kr_linkage (max 20): kr_hypothesis MUST name a specific Key Result and cite a predicted
  % or unit contribution. No reference = 0. Vague reference = ≤8.
- smart (max 10): Objective must be Specific, Measurable, Achievable, Relevant, Time-bound.
  Lose 2 points per missing criterion.
- splitting (max 10): splitting_pattern MUST name one of the patterns below. No name = 0.
  Allowed patterns: workflow, business-rule, happy-path, data-variation, interface, deferred-performance

== SELF-VALIDATION LOOP ==

Before emitting each story, mentally score it against the rubric above.
If any dimension would score below its 80% threshold (invest<16, ac<16, value<16,
kr_linkage<16, smart<8, splitting<8), rewrite that field and re-check.
Only emit stories you estimate will score ≥80/100 overall.

Each story MUST:
- Follow the format: "As a [specific role], I want [specific action], so that [measurable outcome]"
- End "so that..." with a measurable business outcome that includes specific numbers
- Have 2-4 acceptance criteria in Given/When/Then format
- Include a kr_hypothesis naming a KR and citing a predicted % or unit contribution
- Name exactly one splitting_pattern from the allowed list above
- Represent ~3 days of work for one developer (Small criterion of INVEST)

If org quality rules are provided, honour every mandatory condition.

Return a JSON array where each element has:
- "title" (string, the "As a..." story in full)
- "description" (string, 2-3 sentence technical description of what needs to be built)
- "acceptance_criteria" (array of strings, each a "Given..when..then.." clause — 2-4 items)
- "kr_hypothesis" (string, predicted % or unit contribution to a named KR — REQUIRED)
- "splitting_pattern" (string, exactly one pattern name from the allowed list — REQUIRED)
- "size" (integer, story points: 1, 2, 3, 5, 8, or 13)
PROMPT;

    public const SIZE_PROMPT = <<<'PROMPT'
You are an Expert System Architect. Estimate the story point size for this user story based on complexity, unknowns, and effort. Use the modified Fibonacci scale: 1, 2, 3, 5, 8, 13, 20.

Return ONLY a JSON object: {"size": <number>, "reasoning": "<1 sentence explanation>"}

Story Title: {title}
Story Description: {description}
PROMPT;

    public const IMPROVE_PROMPT = <<<'PROMPT'
You are an Agile quality improver. Rewrite ONLY the fields that map to the failing dimensions
listed in the input. Each failing dimension maps to a field as follows:

- acceptance_criteria dimension → rewrite "acceptance_criteria"
  Use 2-4 Given/When/Then clauses, one per line. Return as a plain STRING, not a JSON array.
- kr_linkage dimension → rewrite "kr_hypothesis"
  Reference a specific Key Result with a predicted % or unit contribution.
- invest / value / smart / splitting dimensions → rewrite "description"
  Must follow: "As a [specific role], I want [specific action], so that [measurable outcome]".
  The "so that" clause MUST end with a measurable business outcome with numbers.
  Story should represent ~3 days of effort for one developer.

Rules:
- Do NOT return a "title" field — titles are PM-owned.
- Do NOT return fields for dimensions not listed as failing.
- If multiple description-related dimensions are failing, fix them all in one rewrite.
- "acceptance_criteria" MUST be a plain newline-delimited string, never a JSON array.

Return ONLY valid JSON — no markdown fences, no explanation.
Valid keys: "description", "acceptance_criteria", "kr_hypothesis".
PROMPT;

    public const QUALITY_PROMPT = <<<'PROMPT'
You are a strict Agile quality auditor. Score the following user story across exactly 6
dimensions. Be strict — vague outcomes, missing Given/When/Then, or absent KR references
must lose points.

Dimensions and max scores:
- invest (max 20): Check all 6 INVEST criteria — Independent, Negotiable, Valuable, Estimable,
  Small (~3 days), Testable. Deduct points for each criterion not met.
- acceptance_criteria (max 20): Are there 2+ Given/When/Then clauses? Are they specific and
  testable? Missing or vague ACs score low.
- value (max 20): Does the "so that..." end with a measurable business outcome with numbers?
  Stories not in "As a [role], I want [action], so that [measurable outcome]" format score ≤5.
- kr_linkage (max 20): Does the story reference a specific Key Result with a predicted %
  or unit contribution? No reference = 0. Vague reference = ≤8.
- smart (max 10): Is the story objective Specific, Measurable, Achievable, Relevant, Time-bound?
  Deduct 2 points per missing criterion.
- splitting (max 10): Is a named splitting pattern present and appropriate?
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
