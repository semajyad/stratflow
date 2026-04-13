<?php
/**
 * TraceabilityService
 *
 * Builds the full strategy-to-code traceability tree for one project.
 * Assembles virtual OKR buckets (grouped by hl_work_items.okr_title) →
 * High Level work items → user stories → git links in ≤ 5 queries, with rollup
 * counts at every level.
 *
 * Schema reality:
 * - No strategy_diagram_nodes table; OKR info is stored as okr_title /
 *   okr_description directly on hl_work_items.
 * - No position column; ordering uses priority_number.
 * - parent_hl_item_id on user_stories is the story→work-item FK.
 *
 * Usage:
 *   $service = new TraceabilityService($db);
 *   $tree    = $service->forProject($projectId, $orgId);
 *   // Returns null if the project does not exist or belongs to another org.
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\StoryGitLink;

class TraceabilityService
{
    // ===========================
    // PROPERTIES
    // ===========================

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ===========================
    // PUBLIC API
    // ===========================

    /**
     * Build the full traceability tree for a project.
     *
     * Returns null if the project does not exist or does not belong to the
     * given org (ownership check). Otherwise returns an associative array
     * with keys: project, okrs (virtual buckets grouped by okr_title),
     * unlinked_stories.
     *
     * Queries issued (≤ 5):
     *   1. Ownership check  — projects WHERE id=? AND org_id=?
     *   2. High Level work items    — hl_work_items WHERE project_id=? ORDER BY priority_number
     *   3. User stories     — user_stories LEFT JOIN sync_mappings (Jira key)
     *   4. Git links        — StoryGitLink::findByLocalItemsBulk for user_story
     *   5. Work item Jira   — hl_work_items LEFT JOIN sync_mappings (Jira key)
     *
     * @param int $projectId Project primary key
     * @param int $orgId     Organisation ID for multi-tenancy scoping
     * @return array|null    Nested traceability tree, or null on ownership failure
     */
    public function forProject(int $projectId, int $orgId): ?array
    {
        // Query 1: Verify project ownership
        $project = $this->fetchProject($projectId, $orgId);
        if ($project === null) {
            return null;
        }

        // Query 2: High Level work items ordered by priority
        $workItems = $this->fetchWorkItems($projectId);

        // Query 3: User stories with Jira keys
        $stories = $this->fetchStories($projectId, $orgId);

        // Query 4: Git links bulk-fetched for all stories
        $storyIds    = array_map('intval', array_column($stories, 'id'));
        $gitLinksMap = StoryGitLink::findByLocalItemsBulk($this->db, 'user_story', $storyIds);

        // Query 5: Jira keys for High Level work items
        $workItemJiraMap = $this->fetchWorkItemJiraKeys($projectId, $orgId);

        // Assemble the full nested tree in PHP
        return $this->assembleTree(
            $project,
            $workItems,
            $stories,
            $gitLinksMap,
            $workItemJiraMap
        );
    }

    // ===========================
    // QUERIES
    // ===========================

    /**
     * Load a single project row scoped to an org.
     *
     * @param int $projectId Project primary key
     * @param int $orgId     Organisation ID for tenancy check
     * @return array|null    Project row, or null if not found / wrong org
     */
    private function fetchProject(int $projectId, int $orgId): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM projects WHERE id = :id AND org_id = :org_id LIMIT 1",
            [':id' => $projectId, ':org_id' => $orgId]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Load all High Level work items for a project, ordered by priority number.
     *
     * @param int $projectId Project to scope the query
     * @return array         Array of hl_work_items rows
     */
    private function fetchWorkItems(int $projectId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM hl_work_items WHERE project_id = :project_id ORDER BY priority_number ASC",
            [':project_id' => $projectId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Load all user stories for a project with their Jira external keys.
     *
     * A correlated subquery resolves the org's Jira integration id so no
     * extra parameter binding is needed for the join.
     *
     * @param int $projectId Project to scope the query
     * @param int $orgId     Organisation ID for Jira integration lookup
     * @return array         Array of user_stories rows with a jira_key alias
     */
    private function fetchStories(int $projectId, int $orgId): array
    {
        $stmt = $this->db->query(
            "SELECT us.*,
                    sm_story.external_key AS jira_key
             FROM user_stories us
             LEFT JOIN sync_mappings sm_story
                ON  sm_story.local_type      = 'user_story'
                AND sm_story.local_id        = us.id
                AND sm_story.integration_id  = (
                        SELECT id FROM integrations
                        WHERE org_id = :org_id AND provider = 'jira'
                        LIMIT 1
                    )
             WHERE us.project_id = :project_id
             ORDER BY us.priority_number ASC",
            [':project_id' => $projectId, ':org_id' => $orgId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Load Jira external keys for all High Level work items in a project.
     *
     * Returns a map of work_item_id => external_key (null when no mapping exists).
     *
     * @param int $projectId Project to scope the query
     * @param int $orgId     Organisation ID for Jira integration lookup
     * @return array<int, string|null>  Map of work item id => jira key
     */
    private function fetchWorkItemJiraKeys(int $projectId, int $orgId): array
    {
        $stmt = $this->db->query(
            "SELECT hw.id,
                    sm.external_key AS jira_key
             FROM hl_work_items hw
             LEFT JOIN sync_mappings sm
                ON  sm.local_type      = 'hl_work_item'
                AND sm.local_id        = hw.id
                AND sm.integration_id  = (
                        SELECT id FROM integrations
                        WHERE org_id = :org_id AND provider = 'jira'
                        LIMIT 1
                    )
             WHERE hw.project_id = :project_id",
            [':project_id' => $projectId, ':org_id' => $orgId]
        );

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = $row['jira_key'] ?: null;
        }

        return $map;
    }

    // ===========================
    // ASSEMBLY
    // ===========================

    /**
     * Assemble the full nested tree from flat query results.
     *
     * Groups stories under their parent work item via parent_hl_item_id.
     * Groups work items into virtual OKR buckets by their okr_title field
     * (NULL or empty → "Unassigned"). Computes rollup counts at every level.
     * Stories with no matching work item land in unlinked_stories.
     *
     * @param array              $project         Project row
     * @param array              $workItems       hl_work_items rows
     * @param array              $stories         user_stories rows (with jira_key)
     * @param array<int, array>  $gitLinksMap     StoryGitLink bulk map: story_id => links[]
     * @param array<int, string> $workItemJiraMap Work item id => jira key
     * @return array             Assembled tree ready for the traceability template
     */
    private function assembleTree(
        array $project,
        array $workItems,
        array $stories,
        array $gitLinksMap,
        array $workItemJiraMap
    ): array {
        // Index work items by id for quick lookup
        $workItemsById = [];
        foreach ($workItems as $wi) {
            $workItemsById[(int) $wi['id']] = $wi;
        }

        // Group stories by parent work item id; unmatched go to unlinked bucket
        $storiesByWorkItem  = [];
        $unlinkedStoryNodes = [];

        foreach ($stories as $story) {
            $storyId  = (int) $story['id'];
            $parentId = isset($story['parent_hl_item_id']) ? (int) $story['parent_hl_item_id'] : null;

            $storyNode = [
                'story'     => $story,
                'jira_key'  => $story['jira_key'] ?? null,
                'git_links' => $gitLinksMap[$storyId] ?? [],
            ];

            if ($parentId !== null && isset($workItemsById[$parentId])) {
                $storiesByWorkItem[$parentId][] = $storyNode;
            } else {
                $unlinkedStoryNodes[] = $storyNode;
            }
        }

        // Build work item nodes and group them into virtual OKR buckets by okr_title
        // Use a stable key: trim+lowercase of the title, or '__unassigned__' for blank
        $okrBuckets = [];  // [okr_key => ['title' => string, 'description' => string, 'work_items' => []]]

        foreach ($workItems as $wi) {
            $wiId       = (int) $wi['id'];
            $wiStories  = $storiesByWorkItem[$wiId] ?? [];

            $storyCount   = count($wiStories);
            $doneCount    = 0;
            $gitLinkCount = 0;
            foreach ($wiStories as $sn) {
                if (($sn['story']['status'] ?? '') === 'done') {
                    $doneCount++;
                }
                $gitLinkCount += count($sn['git_links']);
            }

            $workItemNode = [
                'item'           => $wi,
                'stories'        => $wiStories,
                'story_count'    => $storyCount,
                'done_count'     => $doneCount,
                'git_link_count' => $gitLinkCount,
                'jira_key'       => $workItemJiraMap[$wiId] ?? null,
            ];

            // Bucket key: normalised okr_title string (empty → unassigned)
            $rawTitle  = trim((string) ($wi['okr_title'] ?? ''));
            $bucketKey = $rawTitle !== '' ? $rawTitle : '__unassigned__';

            if (!isset($okrBuckets[$bucketKey])) {
                $okrBuckets[$bucketKey] = [
                    'title'       => $rawTitle !== '' ? $rawTitle : 'Unassigned',
                    'description' => $rawTitle !== '' ? (string) ($wi['okr_description'] ?? '') : '',
                    'work_items'  => [],
                    'story_count'    => 0,
                    'done_count'     => 0,
                    'git_link_count' => 0,
                ];
            }

            $okrBuckets[$bucketKey]['work_items'][]    = $workItemNode;
            $okrBuckets[$bucketKey]['story_count']    += $storyCount;
            $okrBuckets[$bucketKey]['done_count']     += $doneCount;
            $okrBuckets[$bucketKey]['git_link_count'] += $gitLinkCount;
        }

        return [
            'project'          => $project,
            'okrs'             => array_values($okrBuckets),
            'unlinked_stories' => $unlinkedStoryNodes,
        ];
    }
}
