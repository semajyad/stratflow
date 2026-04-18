<?php

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Services\Prompts\BoardReviewPrompt;

class BoardReviewService
{
    private GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    // ===========================
    // RUN
    // ===========================

    /**
     * Execute a board review: build the prompt, call Gemini, validate and return the parsed response.
     *
     * @param  array  $members         Panel member rows (role_title, prompt_description)
     * @param  string $evaluationLevel devils_advocate | red_teaming | gordon_ramsay
     * @param  string $screenContext   summary | roadmap | work_items | user_stories
     * @param  string $screenContent   The content to evaluate
     * @return array                   Validated response with keys: conversation, recommendation (incl. proposed_changes)
     * @throws \RuntimeException       If the AI response is missing required keys
     */
    public function run(
        array  $members,
        string $evaluationLevel,
        string $screenContext,
        string $screenContent
    ): array {
        $prompt = BoardReviewPrompt::build($members, $evaluationLevel, $screenContext, $screenContent);
        $result = $this->gemini->generateJson($prompt, '');

        if (empty($result['conversation']) || !is_array($result['conversation'])) {
            throw new \RuntimeException('Board review AI response missing required "conversation" array');
        }
        if (!isset($result['recommendation']) || !is_array($result['recommendation'])) {
            throw new \RuntimeException('Board review AI response missing required "recommendation" object');
        }
        if (!array_key_exists('proposed_changes', $result['recommendation'])) {
            throw new \RuntimeException('Board review AI response missing required "proposed_changes" in recommendation');
        }

        return $result;
    }
}
