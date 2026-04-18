<?php

declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class BoardReviewPrompt
{
    // ===========================
    // LEVEL LABELS
    // ===========================

    private const LEVEL_LABELS = [
        'devils_advocate' => "Devil's Advocate — push back hard but remain constructive",
        'red_teaming'     => "Red Teaming — identify every failure mode, assume adversarial conditions",
        'gordon_ramsay'   => "Gordon Ramsay Mode — brutally direct, no sugar-coating, hold nothing back",
    ];

    // ===========================
    // BUILD
    // ===========================

    /**
     * Build the full AI prompt for a board review.
     *
     * @param  array  $members         Panel member rows (role_title, prompt_description)
     * @param  string $evaluationLevel One of: devils_advocate, red_teaming, gordon_ramsay
     * @param  string $screenContext   One of: summary, roadmap, work_items, user_stories
     * @param  string $screenContent   The page content to evaluate
     * @return string                  Complete prompt ready for GeminiService::generateJson()
     */
    public static function build(
        array  $members,
        string $evaluationLevel,
        string $screenContext,
        string $screenContent
    ): string {
        $levelLabel         = self::LEVEL_LABELS[$evaluationLevel] ?? self::LEVEL_LABELS['devils_advocate'];
        $memberDescriptions = self::formatMembers($members);
        $changesSchema      = self::changesSchema($screenContext);
        $roleNames          = implode(', ', array_column($members, 'role_title'));

        return <<<PROMPT
You are simulating a virtual board review session. The board members are: {$roleNames}.

## Review Level
{$levelLabel}

## Board Member Personas
{$memberDescriptions}

## Your Task
Simulate a multi-turn deliberation between the board members reviewing the content below.
Produce 10–14 conversation turns where members challenge, build on, and respond to each other's points.
After the deliberation, the board reaches a collective consensus and produces a single recommendation.

## Required JSON Output Format
Return ONLY valid JSON matching this exact structure — no markdown fences, no prose before or after:

{
  "conversation": [
    { "speaker": "<role_title>", "message": "<message text>" }
  ],
  "recommendation": {
    "summary": "<1-2 sentence summary of the board's collective verdict>",
    "rationale": "<2-3 sentences explaining the reasoning behind the recommendation>",
    "proposed_changes": {$changesSchema}
  }
}

The "conversation" array must have 10–14 entries.
The "proposed_changes" must match the schema shown above exactly.

## Content to Review
{$screenContent}
PROMPT;
    }

    // ===========================
    // HELPERS
    // ===========================

    private static function formatMembers(array $members): string
    {
        $lines = [];
        foreach ($members as $m) {
            $lines[] = "- **{$m['role_title']}**: {$m['prompt_description']}";
        }
        return implode("\n", $lines);
    }

    private static function changesSchema(string $screenContext): string
    {
        return match ($screenContext) {
            'summary'      => '{ "revised_summary": "<full rewritten summary text>" }',
            'roadmap'      => '{ "revised_mermaid_code": "<complete valid Mermaid diagram code>" }',
            'work_items'   => '{ "items": [ { "action": "add|modify|remove", "id": null, "title": "<title>", "description": "<description>" } ] }',
            'user_stories' => '{ "stories": [ { "action": "add|modify|remove", "id": null, "title": "<title>", "description": "<description>" } ] }',
            default        => '{}',
        };
    }
}
