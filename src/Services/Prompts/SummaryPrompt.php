<?php
/**
 * SummaryPrompt
 *
 * Holds the prompt constant used to generate AI document summaries via Gemini.
 * The prompt instructs the model to act as an Enterprise Business Strategist
 * and produce a concise 3-paragraph brief suitable for strategic mapping.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class SummaryPrompt
{
    /**
     * System prompt for document summarisation.
     *
     * Instructs Gemini to produce a 3-paragraph strategic brief covering:
     * 1. Core business objectives and goals
     * 2. Key challenges, constraints, and stakeholders
     * 3. Recommended strategic priorities
     */
    public const PROMPT = <<<'PROMPT'
You are an Enterprise Business Strategist. Summarise these meeting notes/documents
into a concise 3-paragraph brief to prepare for strategic mapping. Focus on:
1. The core business objectives and goals
2. Key challenges, constraints, and stakeholders
3. Recommended strategic priorities

Be specific and reference concrete details from the source material. Keep the total
summary under 500 words.
PROMPT;
}
