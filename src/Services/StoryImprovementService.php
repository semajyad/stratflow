<?php

/**
 * StoryImprovementService
 *
 * Rewrites only the fields of a work item or user story that score below
 * 80% of their quality dimension maximum, using Gemini AI.
 *
 * Usage:
 *   $service = new StoryImprovementService(new GeminiService($config));
 *   $fields  = $service->improveWorkItem($item, $breakdown, $qualityBlock);
 *   // Returns array of improved fields, or [] on failure / all-at-threshold
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Services\Prompts\WorkItemPrompt;
use StratFlow\Services\Prompts\UserStoryPrompt;

class StoryImprovementService
{
    // Dimensions and their maximum scores — mirrors StoryQualityScorer::REQUIRED_DIMENSIONS
    private const DIMENSION_MAX = [
        'acceptance_criteria' => 20,
        'kr_linkage'          => 20,
        'invest'              => 20,
        'value'               => 20,
        'smart'               => 10,
        'splitting'           => 10,
    ];
// Only these fields may be rewritten — title is PM-owned, never touched
    private const ALLOWED_FIELDS = ['description', 'acceptance_criteria', 'kr_hypothesis'];

    public function __construct(private GeminiService $gemini)
    {
    }

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Improve a High-Level Work Item's fields based on quality breakdown.
     *
     * @param array  $item         Work item row (title, description, acceptance_criteria, kr_hypothesis)
     * @param array  $breakdown    Decoded quality_breakdown JSON (6 dimension keys)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               Subset of improved fields, or [] if all at threshold / on failure
     */
    public function improveWorkItem(array $item, array $breakdown, string $qualityBlock): array
    {
        return $this->improve($item, $breakdown, $qualityBlock, WorkItemPrompt::IMPROVE_PROMPT);
    }

    /**
     * Improve a user story's fields based on quality breakdown.
     *
     * @param array  $story        User story row (title, description, acceptance_criteria, kr_hypothesis)
     * @param array  $breakdown    Decoded quality_breakdown JSON (6 dimension keys)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               Subset of improved fields, or [] if all at threshold / on failure
     */
    public function improveStory(array $story, array $breakdown, string $qualityBlock): array
    {
        return $this->improve($story, $breakdown, $qualityBlock, UserStoryPrompt::IMPROVE_PROMPT);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Core improvement logic — shared between work items and stories.
     * Returns [] without calling Gemini if no dimensions are below threshold.
     */
    private function improve(array $item, array $breakdown, string $qualityBlock, string $prompt): array
    {
        $failing = $this->identifyFailingDimensions($breakdown);
        if (empty($failing)) {
            return [];
        }

        try {
            $input  = $this->buildInput($item, $failing) . $qualityBlock;
            $result = $this->gemini->generateJson($prompt, $input);
            return $this->validateResponse($result);
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[StoryImprovementService] improvement failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return only the dimensions that score below 80% of their maximum.
     * Score exactly at 80% is not failing (e.g. 16/20 = 80% → skip).
     */
    private function identifyFailingDimensions(array $breakdown): array
    {
        $failing = [];
        foreach (self::DIMENSION_MAX as $dim => $max) {
            if (!isset($breakdown[$dim])) {
                continue;
            }
            $score = (int) ($breakdown[$dim]['score'] ?? 0);
            if ($score < (int) ($max * 0.8)) {
                $failing[$dim] = $breakdown[$dim];
            }
        }
        return $failing;
    }

    /**
     * Build the input string passed to Gemini: current fields + failing dimension issues.
     */
    private function buildInput(array $item, array $failingDimensions): string
    {
        $parts = [
            'Current item:',
            'Title: ' . ($item['title'] ?? ''),
        ];
        if (!empty($item['description'])) {
            $parts[] = 'Description: ' . $item['description'];
        }
        if (!empty($item['acceptance_criteria'])) {
            $parts[] = "Acceptance Criteria:\n" . $item['acceptance_criteria'];
        }
        if (!empty($item['kr_hypothesis'])) {
            $parts[] = 'KR Hypothesis: ' . $item['kr_hypothesis'];
        }

        $parts[] = "\nDimensions requiring improvement:";
        foreach ($failingDimensions as $dim => $data) {
            $score     = (int) ($data['score'] ?? 0);
            $max       = (int) ($data['max'] ?? 0);
            $issueList = implode('; ', (array) ($data['issues'] ?? []));
            $line      = "- {$dim} ({$score}/{$max})";
            if ($issueList !== '') {
                $line .= ": {$issueList}";
            }
            $parts[] = $line;
        }

        return implode("\n", $parts) . "\n";
    }

    /**
     * Strip unknown keys and empty strings from Gemini's response.
     * Only 'description', 'acceptance_criteria', 'kr_hypothesis' are accepted.
     */
    private function validateResponse(array $result): array
    {
        $filtered = array_intersect_key($result, array_flip(self::ALLOWED_FIELDS));
        foreach ($filtered as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                unset($filtered[$key]);
            }
        }
        return $filtered;
    }
}
