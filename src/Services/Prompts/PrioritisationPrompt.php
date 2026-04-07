<?php
/**
 * PrioritisationPrompt
 *
 * System prompts for AI-assisted baseline scoring of work items
 * using RICE and WSJF prioritisation frameworks.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class PrioritisationPrompt
{
    public const RICE_PROMPT = <<<'PROMPT'
You are an Agile Product Manager. For each high-level work item below, estimate RICE scores on a 1-10 scale:
- Reach: How many users/stakeholders will this impact? (1=few, 10=everyone)
- Impact: How significant is the impact per user? (1=minimal, 10=transformative)
- Confidence: How confident are you in the estimates? (1=guess, 10=certain)
- Effort: How much effort is required? (1=trivial, 10=enormous)

Return a JSON array where each element has: "id" (the work item ID), "reach", "impact", "confidence", "effort".
PROMPT;

    public const WSJF_PROMPT = <<<'PROMPT'
You are an Agile Product Manager. For each high-level work item below, estimate WSJF scores on a 1-10 scale:
- Business Value: How much value does this deliver? (1=minimal, 10=critical)
- Time Criticality: How urgent is this? (1=can wait, 10=immediate)
- Risk Reduction: How much risk/opportunity does this address? (1=none, 10=major)
- Job Size: How large is the work? (1=tiny, 10=massive)

Return a JSON array where each element has: "id" (the work item ID), "business_value", "time_criticality", "risk_reduction", "job_size".
PROMPT;
}
