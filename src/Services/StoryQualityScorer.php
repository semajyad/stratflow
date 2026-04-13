<?php
/**
 * StoryQualityScorer
 *
 * Scores a work item or user story 0-100 across 6 quality dimensions
 * (INVEST, AC, Value, KR Linkage, SMART, Splitting) using Gemini AI.
 *
 * Usage:
 *   $scorer = new StoryQualityScorer(new GeminiService($config));
 *   $result = $scorer->scoreWorkItem($item, $qualityBlock);
 *   // $result = ['score' => 73, 'breakdown' => [...]]
 *   // or       ['score' => null, 'breakdown' => null] on failure
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Services\Prompts\WorkItemPrompt;
use StratFlow\Services\Prompts\UserStoryPrompt;

class StoryQualityScorer
{
    private const REQUIRED_DIMENSIONS = [
        'invest', 'acceptance_criteria', 'value', 'kr_linkage', 'smart', 'splitting',
    ];

    public function __construct(private GeminiService $gemini) {}

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Score a High-Level Work Item.
     *
     * @param array  $item         Work item row (title, description, acceptance_criteria, kr_hypothesis)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               ['score' => int|null, 'breakdown' => array|null]
     */
    public function scoreWorkItem(array $item, string $qualityBlock): array
    {
        $input = $this->buildWorkItemInput($item) . $qualityBlock;
        return $this->score($input, WorkItemPrompt::QUALITY_PROMPT);
    }

    /**
     * Score a user story.
     *
     * @param array  $story        User story row (title, description, acceptance_criteria, kr_hypothesis)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               ['score' => int|null, 'breakdown' => array|null]
     */
    public function scoreStory(array $story, string $qualityBlock): array
    {
        $input = $this->buildStoryInput($story) . $qualityBlock;
        return $this->score($input, UserStoryPrompt::QUALITY_PROMPT);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Call Gemini and validate the response shape.
     * Returns null scores on any failure — never throws.
     * Error key: 'schema:<dim>' for missing dimensions, 'exc:<class>' for exceptions.
     */
    private function score(string $input, string $prompt): array
    {
        try {
            $result = $this->gemini->generateJson($prompt, $input);
            return $this->validate($result);
        } catch (\Throwable $e) {
            $errorKey = 'exc:' . (new \ReflectionClass($e))->getShortName();
            \StratFlow\Services\Logger::warn('[StoryQualityScorer] scoring failed: ' . $e->getMessage());
            return ['score' => null, 'breakdown' => null, 'error' => $errorKey];
        }
    }

    /**
     * Validate that all 6 dimension keys are present and extract overall score.
     */
    private function validate(array $result): array
    {
        foreach (self::REQUIRED_DIMENSIONS as $key) {
            if (!isset($result[$key])) {
                \StratFlow\Services\Logger::warn('[StoryQualityScorer] missing dimension key: ' . $key);
                return ['score' => null, 'breakdown' => null, 'error' => 'schema:' . $key];
            }
        }

        $overall = (int) ($result['overall'] ?? 0);

        // Remove overall from breakdown — it's stored separately as quality_score
        $breakdown = array_intersect_key($result, array_flip(self::REQUIRED_DIMENSIONS));

        return ['score' => $overall, 'breakdown' => $breakdown, 'error' => null];
    }

    /**
     * Build compact input string for a work item.
     */
    private function buildWorkItemInput(array $item): string
    {
        $parts = ['Title: ' . ($item['title'] ?? '')];

        if (!empty($item['description'])) {
            $parts[] = 'Description: ' . $item['description'];
        }
        if (!empty($item['acceptance_criteria'])) {
            $parts[] = "Acceptance Criteria:\n" . $item['acceptance_criteria'];
        }
        if (!empty($item['kr_hypothesis'])) {
            $parts[] = 'KR Hypothesis: ' . $item['kr_hypothesis'];
        }

        return implode("\n", $parts) . "\n";
    }

    /**
     * Build compact input string for a user story.
     */
    private function buildStoryInput(array $story): string
    {
        $parts = ['Title: ' . ($story['title'] ?? '')];

        if (!empty($story['description'])) {
            $parts[] = 'Description: ' . $story['description'];
        }
        if (!empty($story['acceptance_criteria'])) {
            $parts[] = "Acceptance Criteria:\n" . $story['acceptance_criteria'];
        }
        if (!empty($story['kr_hypothesis'])) {
            $parts[] = 'KR Hypothesis: ' . $story['kr_hypothesis'];
        }

        return implode("\n", $parts) . "\n";
    }
}
