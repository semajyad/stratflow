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
You are an Expert System Architect. Convert the following strategic brief into a
valid Mermaid.js flowchart. Requirements:
- Use "graph TD" direction (top-down)
- Each node should represent a distinct strategic phase or initiative
- Use descriptive node labels in square brackets, e.g., A[Label Text]
- Show dependencies as arrows (-->)
- Use unique single-letter or short IDs for nodes (A, B, C... or STR1, STR2...)
- Output ONLY the Mermaid.js code, no explanation, no markdown fences
- Minimum 5 nodes, maximum 15 nodes
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
