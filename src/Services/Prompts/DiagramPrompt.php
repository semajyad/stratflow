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
}
