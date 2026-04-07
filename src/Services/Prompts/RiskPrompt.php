<?php
/**
 * RiskPrompt
 *
 * System prompts for AI-assisted risk generation from work items
 * and mitigation strategy generation for individual risks.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class RiskPrompt
{
    public const GENERATE_PROMPT = <<<'PROMPT'
You are an Enterprise Risk Manager. Analyse the following high-level work items and identify 3-5 major project risks. For each risk:
- title: concise risk title
- description: 2-3 sentence description of the risk
- likelihood: integer 1-5 (1=rare, 5=almost certain)
- impact: integer 1-5 (1=negligible, 5=catastrophic)
- linked_items: array of work item titles this risk relates to

Return a JSON array only, no markdown.
PROMPT;

    public const MITIGATION_PROMPT = <<<'PROMPT'
You are an Enterprise Risk Manager. Given the following risk and its linked work items, write a concise 2-3 sentence proactive mitigation strategy. Be specific and actionable.

Risk: {title}
Description: {description}
Likelihood: {likelihood}/5
Impact: {impact}/5
Linked Work Items: {linked_items}
PROMPT;
}
