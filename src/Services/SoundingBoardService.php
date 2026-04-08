<?php
/**
 * SoundingBoardService
 *
 * Orchestrates AI-based evaluations by running screen content through
 * a panel of personas at a chosen criticism level. Each persona generates
 * an independent assessment via the Gemini API.
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Services\Prompts\PersonaPrompt;

class SoundingBoardService
{
    // ===========================
    // PROPERTIES
    // ===========================

    private GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    // ===========================
    // EVALUATION
    // ===========================

    /**
     * Run evaluation with all panel members at a given criticism level.
     *
     * Iterates through each persona, builds a tailored prompt, and collects
     * the AI response. Errors are captured per-member rather than aborting
     * the entire evaluation.
     *
     * @param array  $panelMembers    Array of member rows (id, role_title, prompt_description)
     * @param string $evaluationLevel One of: devils_advocate, red_teaming, gordon_ramsay
     * @param string $screenContent   The page content to evaluate
     * @return array                  Array of persona response objects with role_title, member_id, response, status
     */
    public function evaluate(array $panelMembers, string $evaluationLevel, string $screenContent): array
    {
        $results = [];

        foreach ($panelMembers as $member) {
            $prompt = PersonaPrompt::buildPrompt(
                $member['role_title'],
                $member['prompt_description'],
                $evaluationLevel,
                $screenContent
            );

            try {
                $response = $this->gemini->generate($prompt, '');
                $results[] = [
                    'role_title' => $member['role_title'],
                    'member_id' => $member['id'],
                    'response'  => $response,
                    'status'    => 'pending',
                ];
            } catch (\RuntimeException $e) {
                $results[] = [
                    'role_title' => $member['role_title'],
                    'member_id' => $member['id'],
                    'response'  => 'Error: ' . $e->getMessage(),
                    'status'    => 'error',
                ];
            }
        }

        return $results;
    }
}
