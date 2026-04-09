<?php
/**
 * DiagramPrompt
 *
 * Holds the prompt constant used to generate Mermaid.js strategy diagrams
 * via Gemini. The prompt instructs the model to convert a strategic brief
 * into a valid top-down flowchart with 5-15 nodes.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class DiagramPrompt
{
    /**
     * System prompt for Mermaid.js diagram generation.
     *
     * Instructs Gemini to produce a valid Mermaid flowchart from a strategic
     * brief, using top-down direction, descriptive labels, and dependency arrows.
     */
    public const PROMPT = <<<'PROMPT'
You are an Expert System Architect. Convert the following strategic brief into a valid Mermaid.js flowchart.

CRITICAL RULES — you MUST follow ALL of these:
1. Start your response with exactly: graph TD
2. Each node uses a single uppercase letter ID with a label in square brackets: A[Label Text]
3. Show dependencies with arrows: A --> B
4. Use 5 to 15 nodes representing strategic phases or initiatives
5. Use descriptive labels (3-8 words per label)
6. Do NOT use parentheses, quotes, ampersands, or special characters in labels
7. Do NOT wrap output in markdown code fences
8. Do NOT add any explanation or commentary — output ONLY the Mermaid code
9. Every node must connect to at least one other node

EXAMPLE OUTPUT FORMAT:
graph TD
    A[Establish Market Presence] --> B[Launch Pilot Programs]
    A --> C[Build Core Platform]
    B --> D[Acquire Enterprise Clients]
    C --> D
    D --> E[Scale Operations]

Now convert this strategic brief:
PROMPT;

    /**
     * Prompt for generating SMART OKRs for each diagram node.
     */
    public const OKR_PROMPT = <<<'PROMPT'
You are a Strategic OKR Specialist. For each strategic initiative node listed below, generate a SMART objective with 2-3 measurable key results.

SMART means:
- Specific: clearly defined, not vague
- Measurable: includes numbers, percentages, or concrete deliverables
- Achievable: realistic within the initiative's scope
- Relevant: directly tied to the strategic initiative
- Time-bound: includes a timeframe or deadline

For each node, return:
- "node_key": the node identifier (e.g. "A", "STR1")
- "okr_title": a SMART objective statement (one sentence)
- "okr_description": 2-3 key results as bullet points, each starting with "KR1:", "KR2:", "KR3:"

Return a JSON array only, no markdown fences.

Example output:
[
  {
    "node_key": "A",
    "okr_title": "Launch Australian market presence with 3 enterprise pilot customers by Q3 2026",
    "okr_description": "KR1: Establish Australian subsidiary and hire Regional Sales Director by May 2026\nKR2: Secure 3 signed pilot agreements with ASX-200 companies by July 2026\nKR3: Achieve $100K in Australian pipeline revenue by August 2026"
  }
]
PROMPT;
}
