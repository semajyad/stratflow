<?php
/**
 * PersonaPrompt
 *
 * Builds evaluation prompts for sounding board personas at varying
 * criticism levels. Used by SoundingBoardService to generate per-persona
 * AI assessments of screen content.
 */

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class PersonaPrompt
{
    // ===========================
    // EVALUATION LEVELS
    // ===========================

    public const EVALUATION_LEVELS = [
        'devils_advocate' => "Act as a devil's advocate. Challenge this by pointing out flaws, counterarguments, missing evidence, and unintended consequences. Your goal is to create constructive doubt and identify blind spots.",
        'red_teaming'     => "Act as a red team reviewer. Your job is to find and expose flaws, loopholes, and things that aren't good enough by purposefully hunting for them. Be thorough and adversarial.",
        'gordon_ramsay'   => "Give this the Gordon Ramsay treatment. Be surgical. What's wrong and what needs to be completely redone? Make sure the feedback is specific, actionable, and pulls no punches.",
    ];

    // ===========================
    // PROMPT BUILDER
    // ===========================

    /**
     * Build a full evaluation prompt for a single persona.
     *
     * @param string $roleTitle       The persona's role (e.g. "CEO", "Senior Developer")
     * @param string $promptDescription Additional context about the persona's perspective
     * @param string $evaluationLevel  One of: devils_advocate, red_teaming, gordon_ramsay
     * @param string $screenContent    The page content to evaluate
     * @param array|null $customLevels Optional overrides for evaluation level prompts
     * @return string                  Complete prompt ready for LLM submission
     */
    public static function buildPrompt(string $roleTitle, string $promptDescription, string $evaluationLevel, string $screenContent, ?array $customLevels = null): string
    {
        $levels = $customLevels ?: self::EVALUATION_LEVELS;
        $levelInstruction = $levels[$evaluationLevel] ?? self::EVALUATION_LEVELS[$evaluationLevel] ?? self::EVALUATION_LEVELS['devils_advocate'];

        return <<<PROMPT
You are a {$roleTitle}. {$promptDescription}

{$levelInstruction}

Evaluate the following content and provide your professional assessment. Be specific, reference concrete items from the content, and provide actionable recommendations.

Structure your response as:
1. **Overall Assessment** (2-3 sentences)
2. **Key Concerns** (bullet points)
3. **Recommendations** (bullet points)
4. **Risk Rating** (Low/Medium/High/Critical)

Content to evaluate:
---
{$screenContent}
PROMPT;
    }
}
