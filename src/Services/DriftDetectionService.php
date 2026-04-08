<?php
/**
 * DriftDetectionService
 *
 * Core service for the Strategic Drift Engine. Creates baseline snapshots
 * of project state and detects deviations: capacity tripwires (scope creep),
 * dependency tripwires (cross-team blockers), and AI alignment checks.
 *
 * Usage:
 *   $service = new DriftDetectionService($db, $gemini);
 *   $baselineId = $service->createBaseline($projectId);
 *   $drifts = $service->detectDrift($projectId, 0.20);
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\DriftAlert;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\StrategicBaseline;
use StratFlow\Models\UserStory;
use StratFlow\Services\Prompts\DriftPrompt;

class DriftDetectionService
{
    // ===========================
    // CONFIG
    // ===========================

    private Database $db;
    private ?GeminiService $gemini;

    public function __construct(Database $db, ?GeminiService $gemini = null)
    {
        $this->db = $db;
        $this->gemini = $gemini;
    }

    // ===========================
    // BASELINE
    // ===========================

    /**
     * Create a baseline snapshot of current project state.
     *
     * Captures all work items and story metrics at this point in time,
     * allowing future drift detection to compare against this snapshot.
     *
     * @param int $projectId Project to snapshot
     * @return int           ID of the created baseline row
     */
    public function createBaseline(int $projectId): int
    {
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $stories = UserStory::findByProjectId($this->db, $projectId);

        $snapshot = [
            'created_at' => date('Y-m-d H:i:s'),
            'work_items' => array_map(fn($item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'priority_number' => $item['priority_number'],
                'estimated_sprints' => $item['estimated_sprints'],
                'final_score' => $item['final_score'] ?? null,
            ], $workItems),
            'stories' => [
                'total_count' => count($stories),
                'total_size' => array_sum(array_column($stories, 'size')),
                'by_parent' => $this->groupStoriesByParent($stories),
            ],
        ];

        return StrategicBaseline::create($this->db, [
            'project_id' => $projectId,
            'snapshot_json' => json_encode($snapshot),
        ]);
    }

    // ===========================
    // DRIFT DETECTION
    // ===========================

    /**
     * Run full drift detection against latest baseline.
     *
     * Checks capacity tripwires (scope creep per parent item) and
     * dependency tripwires (cross-team blockers). Creates DriftAlert
     * records for each issue found.
     *
     * @param int   $projectId         Project to check
     * @param float $capacityThreshold Growth fraction that triggers alert (e.g. 0.20 = 20%)
     * @return array                   Array of detected drift issues
     */
    public function detectDrift(int $projectId, float $capacityThreshold = 0.20): array
    {
        $baseline = StrategicBaseline::findLatestByProjectId($this->db, $projectId);
        if (!$baseline) {
            return [];
        }

        $snapshot = json_decode($baseline['snapshot_json'], true);
        $drifts = [];

        // Check capacity tripwires
        $capacityDrifts = $this->checkCapacityTripwire($snapshot, $projectId, $capacityThreshold);
        $drifts = array_merge($drifts, $capacityDrifts);

        // Check dependency tripwires
        $depDrifts = $this->checkDependencyTripwire($projectId);
        $drifts = array_merge($drifts, $depDrifts);

        // Create alerts for each drift found
        foreach ($drifts as $drift) {
            DriftAlert::create($this->db, [
                'project_id' => $projectId,
                'alert_type' => $drift['type'],
                'severity' => $drift['severity'],
                'details_json' => json_encode($drift['details']),
            ]);
        }

        return $drifts;
    }

    // ===========================
    // CAPACITY TRIPWIRE
    // ===========================

    /**
     * Check if any work item's story count/size has grown beyond threshold.
     *
     * Compares the current total story size per parent HL item against
     * the baseline snapshot. Flags parent items whose scope has grown
     * beyond the allowed threshold.
     *
     * @param array $snapshot           Decoded baseline snapshot
     * @param int   $projectId          Project to check
     * @param float $threshold          Growth fraction (e.g. 0.20 = 20%)
     * @return array                    Array of capacity drift alerts
     */
    private function checkCapacityTripwire(array $snapshot, int $projectId, float $threshold): array
    {
        $currentStories = UserStory::findByProjectId($this->db, $projectId);
        $currentByParent = $this->groupStoriesByParent($currentStories);
        $baselineByParent = $snapshot['stories']['by_parent'] ?? [];

        $alerts = [];

        foreach ($currentByParent as $parentId => $current) {
            $baseline = $baselineByParent[$parentId] ?? null;
            if (!$baseline) {
                continue; // New parent, not a drift
            }

            $baselineSize = $baseline['total_size'];
            $currentSize = $current['total_size'];

            if ($baselineSize > 0) {
                $growth = ($currentSize - $baselineSize) / $baselineSize;
                if ($growth > $threshold) {
                    $alerts[] = [
                        'type' => 'capacity_tripwire',
                        'severity' => $growth > 0.5 ? 'critical' : 'warning',
                        'details' => [
                            'parent_item_id' => $parentId,
                            'parent_title' => $current['parent_title'] ?? 'Unknown',
                            'baseline_size' => $baselineSize,
                            'current_size' => $currentSize,
                            'growth_percent' => round($growth * 100, 1),
                        ],
                    ];

                    // Flag the parent work item for review
                    HLWorkItem::update($this->db, (int) $parentId, ['requires_review' => 1]);
                }
            }
        }

        return $alerts;
    }

    // ===========================
    // DEPENDENCY TRIPWIRE
    // ===========================

    /**
     * Check for cross-team dependency blockers.
     *
     * Finds stories that are blocked by stories assigned to a different
     * team, indicating a cross-team dependency risk.
     *
     * @param int $projectId Project to check
     * @return array         Array of dependency drift alerts
     */
    private function checkDependencyTripwire(int $projectId): array
    {
        $stories = UserStory::findByProjectId($this->db, $projectId);
        $alerts = [];

        $storyMap = [];
        foreach ($stories as $story) {
            $storyMap[$story['id']] = $story;
        }

        foreach ($stories as $story) {
            if (!$story['blocked_by'] || !$story['team_assigned']) {
                continue;
            }

            $blocker = $storyMap[$story['blocked_by']] ?? null;
            if (!$blocker || !$blocker['team_assigned']) {
                continue;
            }

            if ($story['team_assigned'] !== $blocker['team_assigned']) {
                $alerts[] = [
                    'type' => 'dependency_tripwire',
                    'severity' => 'warning',
                    'details' => [
                        'blocked_story_id' => $story['id'],
                        'blocked_story_title' => $story['title'],
                        'blocked_team' => $story['team_assigned'],
                        'blocker_story_id' => $blocker['id'],
                        'blocker_story_title' => $blocker['title'],
                        'blocker_team' => $blocker['team_assigned'],
                    ],
                ];
            }
        }

        return $alerts;
    }

    // ===========================
    // AI ALIGNMENT CHECK
    // ===========================

    /**
     * Check if a new story aligns with original strategic OKRs.
     *
     * Uses Gemini AI to assess whether a story serves the project's
     * strategic goals. Returns null if AI is unavailable.
     *
     * @param string $okrs             Combined OKR text from the project
     * @param string $storyTitle       Title of the new story
     * @param string $storyDescription Description of the new story
     * @return array|null              AI assessment with aligned, confidence, explanation keys
     */
    public function checkAlignment(string $okrs, string $storyTitle, string $storyDescription): ?array
    {
        if (!$this->gemini) {
            return null;
        }

        $prompt = str_replace(
            ['{okrs}', '{story_title}', '{story_description}'],
            [$okrs, $storyTitle, $storyDescription],
            DriftPrompt::ALIGNMENT_PROMPT
        );

        try {
            return $this->gemini->generateJson($prompt, '');
        } catch (\RuntimeException $e) {
            return null; // Don't block on AI failures
        }
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Group stories by their parent HL item ID, summing sizes and counts.
     *
     * @param array $stories Array of story rows
     * @return array         Grouped data keyed by parent_hl_item_id
     */
    private function groupStoriesByParent(array $stories): array
    {
        $groups = [];
        foreach ($stories as $story) {
            $parentId = $story['parent_hl_item_id'] ?? 'unlinked';
            if (!isset($groups[$parentId])) {
                $groups[$parentId] = [
                    'count' => 0,
                    'total_size' => 0,
                    'parent_title' => $story['parent_title'] ?? 'Unlinked',
                ];
            }
            $groups[$parentId]['count']++;
            $groups[$parentId]['total_size'] += (int) ($story['size'] ?? 0);
        }
        return $groups;
    }
}
