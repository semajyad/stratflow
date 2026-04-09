<?php
/**
 * JiraSyncService — Push & Pull Sync Engine
 *
 * Handles bidirectional synchronisation between StratFlow items
 * (HL work items, user stories) and Jira Cloud issues. Pushes
 * create Epics/Stories in Jira; pulls update local items from
 * Jira changes.
 *
 * Usage:
 *   $sync = new JiraSyncService($db, $jira, $integration);
 *   $result = $sync->pushWorkItems($projectId, 'PROJ');
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\UserStory;
use StratFlow\Models\Risk;
use StratFlow\Models\Sprint;
use StratFlow\Models\SprintStory;
use StratFlow\Models\SyncMapping;
use StratFlow\Models\SyncLog;

class JiraSyncService
{
    private Database $db;
    private JiraService $jira;
    private array $integration;
    private array $fieldMapping;

    // ===========================
    // CONSTRUCTOR
    // ===========================

    /**
     * @param Database    $db          Database instance
     * @param JiraService $jira        Authenticated Jira API client
     * @param array       $integration Integration record
     */
    public function __construct(Database $db, JiraService $jira, array $integration)
    {
        $this->db          = $db;
        $this->jira        = $jira;
        $this->integration = $integration;

        $config = json_decode($integration['config_json'] ?? '{}', true) ?: [];
        $this->fieldMapping = $config['field_mapping'] ?? [];
    }

    /** Get a field mapping value with a default fallback. */
    private function mapping(string $key, string $default = ''): string
    {
        return $this->fieldMapping[$key] ?? $default;
    }

    // ===========================
    // PUSH: WORK ITEMS -> JIRA EPICS
    // ===========================

    /**
     * Push HL work items to Jira as Epics.
     *
     * Creates new Epics for unmapped items, updates existing ones
     * if the sync hash has changed. Logs every operation.
     *
     * @param int    $projectId      StratFlow project ID
     * @param string $jiraProjectKey Jira project key (e.g. 'PROJ')
     * @return array                 {created: int, updated: int, errors: int}
     */
    public function pushWorkItems(int $projectId, string $jiraProjectKey): array
    {
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $integrationId = (int) $this->integration['id'];

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $consecutiveErrors = 0;

        // Bulk-validate existing mappings in one API call
        $validKeys = $this->validateMappedKeys($integrationId, 'hl_work_item');

        foreach ($workItems as $item) {
            if ($consecutiveErrors >= 3) {
                $counts['errors'] += count($workItems) - $counts['created'] - $counts['updated'] - $counts['skipped'] - $counts['errors'];
                break;
            }
            try {
                $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'hl_work_item', (int) $item['id']);
                $currentHash = $this->computeSyncHash($item);

                if ($mapping) {
                    // Check if Jira issue still exists (from bulk check)
                    if (!isset($validKeys[$mapping['external_key']])) {
                        SyncMapping::delete($this->db, (int) $mapping['id']);
                        $mapping = null;
                    } elseif ($mapping['sync_hash'] === $currentHash) {
                        $counts['skipped']++;
                        continue;
                    } else {
                        // Update changed item
                        $description = $this->buildWorkItemDescription($item);
                        $this->jira->updateIssue($mapping['external_key'], [
                            'summary'     => $item['title'],
                            'description' => $this->jira->textToAdf($description),
                            'priority'    => ['name' => $this->mapPriority((int) ($item['priority_number'] ?? 5))],
                        ]);

                        SyncMapping::update($this->db, (int) $mapping['id'], [
                            'sync_hash'     => $currentHash,
                            'last_synced_at' => date('Y-m-d H:i:s'),
                        ]);

                        $counts['updated']++;
                        $consecutiveErrors = 0;
                        continue;
                    }
                }

                // Create new Epic
                {
                    // Create new Epic
                    $description = $this->buildWorkItemDescription($item);
                    $fields = [
                        'project'     => ['key' => $jiraProjectKey],
                        'issuetype'   => ['name' => $this->mapping('epic_type', 'Epic')],
                        'summary'     => $item['title'],
                        'description' => $this->jira->textToAdf($description),
                    ];

                    // Epic Name field (required for company-managed projects)
                    $epicNameField = $this->mapping('epic_name_field', 'customfield_10011');
                    if ($epicNameField) {
                        $fields[$epicNameField] = $item['title'];
                    }

                    // Try to create — if it fails, retry without optional fields
                    try {
                        $result = $this->jira->createIssue($fields);
                    } catch (\RuntimeException $e) {
                        if ($epicNameField) unset($fields[$epicNameField]);
                        $result = $this->jira->createIssue($fields);
                    }

                    $siteUrl = rtrim($this->integration['site_url'] ?? '', '/');
                    $externalUrl = $siteUrl . '/browse/' . $result['key'];

                    SyncMapping::create($this->db, [
                        'integration_id' => $integrationId,
                        'local_type'     => 'hl_work_item',
                        'local_id'       => (int) $item['id'],
                        'external_id'    => $result['id'],
                        'external_key'   => $result['key'],
                        'external_url'   => $externalUrl,
                        'sync_hash'      => $currentHash,
                    ]);

                    SyncLog::create($this->db, [
                        'integration_id' => $integrationId,
                        'direction'      => 'push',
                        'action'         => 'create',
                        'local_type'     => 'hl_work_item',
                        'local_id'       => (int) $item['id'],
                        'external_id'    => $result['key'],
                        'details_json'   => json_encode(['title' => $item['title'], 'key' => $result['key']]),
                        'status'         => 'success',
                    ]);

                    $counts['created']++;
                }
                $consecutiveErrors = 0;
            } catch (\Throwable $e) {
                $consecutiveErrors++;
                SyncLog::create($this->db, [
                    'integration_id' => $integrationId,
                    'direction'      => 'push',
                    'action'         => 'create',
                    'local_type'     => 'hl_work_item',
                    'local_id'       => (int) $item['id'],
                    'external_id'    => null,
                    'details_json'   => json_encode(['error' => $e->getMessage()]),
                    'status'         => 'error',
                ]);
                $counts['errors']++;
            }
        }

        return $counts;
    }

    // ===========================
    // PUSH: USER STORIES -> JIRA STORIES
    // ===========================

    /**
     * Push user stories to Jira as Story issues.
     *
     * Links each Story to its parent Epic via the Epic's sync mapping.
     *
     * @param int    $projectId      StratFlow project ID
     * @param string $jiraProjectKey Jira project key (e.g. 'PROJ')
     * @return array                 {created: int, updated: int, errors: int}
     */
    public function pushUserStories(int $projectId, string $jiraProjectKey): array
    {
        $stories = UserStory::findByProjectId($this->db, $projectId);
        $integrationId = (int) $this->integration['id'];

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $consecutiveErrors = 0;

        // Bulk-validate existing mappings in one API call
        $validKeys = $this->validateMappedKeys($integrationId, 'user_story');

        foreach ($stories as $story) {
            if ($consecutiveErrors >= 3) {
                $counts['errors'] += count($stories) - $counts['created'] - $counts['updated'] - $counts['skipped'] - $counts['errors'];
                break;
            }
            try {
                $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'user_story', (int) $story['id']);
                $currentHash = $this->computeSyncHash($story);

                if ($mapping) {
                    if (!isset($validKeys[$mapping['external_key']])) {
                        SyncMapping::delete($this->db, (int) $mapping['id']);
                        $mapping = null;
                    } elseif ($mapping['sync_hash'] === $currentHash) {
                        $counts['skipped']++;
                        continue;
                    } else {
                        $this->jira->updateIssue($mapping['external_key'], [
                            'summary'     => $story['title'],
                            'description' => $this->jira->textToAdf($story['description'] ?? ''),
                            'priority'    => ['name' => $this->mapPriority((int) ($story['priority_number'] ?? 5))],
                        ]);

                        SyncMapping::update($this->db, (int) $mapping['id'], [
                            'sync_hash'     => $currentHash,
                            'last_synced_at' => date('Y-m-d H:i:s'),
                        ]);

                        $counts['updated']++;
                        $consecutiveErrors = 0;
                        continue;
                    }
                }

                // Create new Story
                {
                    // Build fields for new Story — minimal fields to avoid 400s
                    $fields = [
                        'project'     => ['key' => $jiraProjectKey],
                        'issuetype'   => ['name' => $this->mapping('story_type', 'Story')],
                        'summary'     => $story['title'],
                        'description' => $this->jira->textToAdf($story['description'] ?? ''),
                    ];

                    // Story points
                    $spField = $this->mapping('story_points_field', 'customfield_10016');
                    if (!empty($story['size']) && $spField) {
                        $fields[$spField] = (float) $story['size'];
                    }

                    // Team field
                    $teamField = $this->mapping('team_field', '');
                    if ($teamField && !empty($story['team_assigned'])) {
                        $fields[$teamField] = $story['team_assigned'];
                    }

                    // Link to parent Epic if available
                    if (!empty($story['parent_hl_item_id'])) {
                        $parentMapping = SyncMapping::findByLocalItem(
                            $this->db,
                            $integrationId,
                            'hl_work_item',
                            (int) $story['parent_hl_item_id']
                        );
                        if ($parentMapping) {
                            $fields['parent'] = ['key' => $parentMapping['external_key']];
                        }
                    }

                    // Try create — retry without parent link if it fails
                    try {
                        $result = $this->jira->createIssue($fields);
                    } catch (\RuntimeException $e) {
                        unset($fields['parent']);
                        $result = $this->jira->createIssue($fields);
                    }

                    $siteUrl = rtrim($this->integration['site_url'] ?? '', '/');
                    $externalUrl = $siteUrl . '/browse/' . $result['key'];

                    SyncMapping::create($this->db, [
                        'integration_id' => $integrationId,
                        'local_type'     => 'user_story',
                        'local_id'       => (int) $story['id'],
                        'external_id'    => $result['id'],
                        'external_key'   => $result['key'],
                        'external_url'   => $externalUrl,
                        'sync_hash'      => $currentHash,
                    ]);

                    SyncLog::create($this->db, [
                        'integration_id' => $integrationId,
                        'direction'      => 'push',
                        'action'         => 'create',
                        'local_type'     => 'user_story',
                        'local_id'       => (int) $story['id'],
                        'external_id'    => $result['key'],
                        'details_json'   => json_encode(['title' => $story['title'], 'key' => $result['key']]),
                        'status'         => 'success',
                    ]);

                    $counts['created']++;
                }
                $consecutiveErrors = 0;
            } catch (\Throwable $e) {
                $consecutiveErrors++;
                SyncLog::create($this->db, [
                    'integration_id' => $integrationId,
                    'direction'      => 'push',
                    'action'         => 'create',
                    'local_type'     => 'user_story',
                    'local_id'       => (int) $story['id'],
                    'external_id'    => null,
                    'details_json'   => json_encode(['error' => $e->getMessage()]),
                    'status'         => 'error',
                ]);
                $counts['errors']++;
            }
        }

        return $counts;
    }

    // ===========================
    // PULL: JIRA -> STRATFLOW
    // ===========================

    /**
     * Pull changes from Jira and update local items.
     *
     * Fetches all mapped items from Jira by their external keys,
     * compares sync hashes, and updates local items that have changed.
     *
     * @param int    $projectId      StratFlow project ID
     * @param string $jiraProjectKey Jira project key
     * @return array                 {updated: int, skipped: int, errors: int}
     */
    public function pullChanges(int $projectId, string $jiraProjectKey): array
    {
        $integrationId = (int) $this->integration['id'];
        $counts = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            // Get all sync mappings for this integration
            $mappings = SyncMapping::findByIntegration($this->db, $integrationId);

            if (empty($mappings)) {
                return $counts;
            }

            // Build a JQL query from mapped external keys
            $keys = array_filter(array_column($mappings, 'external_key'));
            if (empty($keys)) {
                return $counts;
            }

            // Query Jira for all mapped issues
            $keyList = implode(', ', $keys);
            $jql = "key IN ({$keyList}) ORDER BY updated DESC";
            $result = $this->jira->searchIssues(
                $jql,
                ['summary', 'description', 'status', 'priority', $this->mapping('story_points_field', 'customfield_10016')],
                100
            );

            $issues = $result['issues'] ?? [];

            // Index mappings by external_id for fast lookup
            $mappingsByExtId = [];
            foreach ($mappings as $m) {
                $mappingsByExtId[$m['external_id']] = $m;
            }

            foreach ($issues as $issue) {
                try {
                    $externalId = (string) $issue['id'];
                    $mapping = $mappingsByExtId[$externalId] ?? null;

                    if (!$mapping) {
                        continue;
                    }

                    $fields = $issue['fields'] ?? [];
                    $newTitle = $fields['summary'] ?? '';
                    $newDescription = '';
                    if (!empty($fields['description'])) {
                        $newDescription = $this->jira->adfToText($fields['description']);
                    }

                    // Build update data
                    $updateData = ['title' => $newTitle];
                    if ($newDescription !== '') {
                        $updateData['description'] = trim($newDescription);
                    }

                    // Pull story points for user stories
                    $spField = $this->mapping('story_points_field', 'customfield_10016');
                    if ($mapping['local_type'] === 'user_story' && $spField && isset($fields[$spField])) {
                        $updateData['size'] = (int) $fields[$spField];
                    }

                    // Check if anything actually changed via sync hash
                    $localItem = $mapping['local_type'] === 'hl_work_item'
                        ? HLWorkItem::findById($this->db, (int) $mapping['local_id'])
                        : UserStory::findById($this->db, (int) $mapping['local_id']);

                    if (!$localItem) {
                        continue;
                    }

                    // Apply update
                    if ($mapping['local_type'] === 'hl_work_item') {
                        HLWorkItem::update($this->db, (int) $mapping['local_id'], $updateData);
                    } elseif ($mapping['local_type'] === 'user_story') {
                        UserStory::update($this->db, (int) $mapping['local_id'], $updateData);
                    }

                    // Reload and update mapping hash
                    $updatedItem = $mapping['local_type'] === 'hl_work_item'
                        ? HLWorkItem::findById($this->db, (int) $mapping['local_id'])
                        : UserStory::findById($this->db, (int) $mapping['local_id']);

                    if ($updatedItem) {
                        SyncMapping::update($this->db, (int) $mapping['id'], [
                            'sync_hash'      => $this->computeSyncHash($updatedItem),
                            'last_synced_at' => date('Y-m-d H:i:s'),
                        ]);
                    }

                    SyncLog::create($this->db, [
                        'integration_id' => $integrationId,
                        'direction'      => 'pull',
                        'action'         => 'update',
                        'local_type'     => $mapping['local_type'],
                        'local_id'       => (int) $mapping['local_id'],
                        'external_id'    => $issue['key'] ?? $externalId,
                        'details_json'   => json_encode(['title' => $newTitle]),
                        'status'         => 'success',
                    ]);

                    $counts['updated']++;
                } catch (\Throwable $e) {
                    SyncLog::create($this->db, [
                        'integration_id' => $integrationId,
                        'direction'      => 'pull',
                        'action'         => 'update',
                        'local_type'     => null,
                        'local_id'       => null,
                        'external_id'    => $issue['key'] ?? null,
                        'details_json'   => json_encode(['error' => $e->getMessage()]),
                        'status'         => 'error',
                    ]);
                    $counts['errors']++;
                }
            }
        } catch (\Throwable $e) {
            SyncLog::create($this->db, [
                'integration_id' => $integrationId,
                'direction'      => 'pull',
                'action'         => 'update',
                'local_type'     => null,
                'local_id'       => null,
                'external_id'    => null,
                'details_json'   => json_encode(['error' => $e->getMessage()]),
                'status'         => 'error',
            ]);
            $counts['errors']++;
        }

        return $counts;
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Compute a hash of an item's syncable fields for change detection.
     *
     * @param array $item Work item or user story record
     * @return string     SHA-256 hash
     */
    public function computeSyncHash(array $item): string
    {
        $parts = [
            strtolower($item['title'] ?? ''),
            $item['description'] ?? '',
            (string) ($item['priority_number'] ?? 0),
            $item['owner'] ?? '',
            (string) ($item['size'] ?? 0),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Map a StratFlow priority number to a Jira priority name.
     *
     * @param int $priorityNumber StratFlow priority (1 = highest)
     * @return string             Jira priority name
     */
    public function mapPriority(int $priorityNumber): string
    {
        if ($priorityNumber <= 2) {
            return 'Highest';
        }
        if ($priorityNumber <= 4) {
            return 'High';
        }
        if ($priorityNumber <= 6) {
            return 'Medium';
        }
        if ($priorityNumber <= 8) {
            return 'Low';
        }

        return 'Lowest';
    }

    /**
     * Build the description text for a work item being pushed to Jira.
     *
     * Combines the item description with OKR context.
     *
     * @param array $item Work item record
     * @return string     Combined description text
     */
    private function buildWorkItemDescription(array $item): string
    {
        $description = $item['description'] ?? '';

        if (!empty($item['okr_title']) || !empty($item['okr_description'])) {
            $description .= "\n\nOKR: " . ($item['okr_title'] ?? '');
            if (!empty($item['okr_description'])) {
                $description .= "\n" . $item['okr_description'];
            }
        }

        return trim($description);
    }

    // ===========================
    // PUSH: SPRINTS -> JIRA SPRINTS + STORY ALLOCATION
    // ===========================

    /**
     * Push sprints to Jira and allocate stories into them.
     *
     * Creates Jira sprints on the board, then moves already-synced
     * user story issues into the corresponding Jira sprints.
     */
    public function pushSprints(int $projectId, string $jiraProjectKey, int $defaultBoardId): array
    {
        $sprints = Sprint::findByProjectId($this->db, $projectId);
        $integrationId = (int) $this->integration['id'];

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'allocated' => 0, 'errors' => 0];

        foreach ($sprints as $sprint) {
            try {
                // Use team's board ID if sprint has a team, otherwise default
                $boardId = $defaultBoardId;
                if (!empty($sprint['team_id'])) {
                    $team = \StratFlow\Models\Team::findById($this->db, (int) $sprint['team_id']);
                    if ($team && !empty($team['jira_board_id'])) {
                        $boardId = (int) $team['jira_board_id'];
                    }
                }

                $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'sprint', (int) $sprint['id']);

                if ($mapping) {
                    $counts['skipped']++;
                } else {
                    $startDate = $sprint['start_date'] ? date('c', strtotime($sprint['start_date'])) : null;
                    $endDate   = $sprint['end_date']   ? date('c', strtotime($sprint['end_date']))   : null;

                    $result = $this->jira->createSprint($boardId, $sprint['name'], $startDate, $endDate);

                    SyncMapping::create($this->db, [
                        'integration_id' => $integrationId,
                        'local_type'     => 'sprint',
                        'local_id'       => (int) $sprint['id'],
                        'external_id'    => (string) $result['id'],
                        'external_key'   => 'sprint-' . $result['id'],
                        'external_url'   => null,
                        'sync_hash'      => hash('sha256', $sprint['name'] . '|' . ($sprint['start_date'] ?? '') . '|' . ($sprint['end_date'] ?? '')),
                    ]);

                    $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'sprint', (int) $sprint['id']);
                    $counts['created']++;
                }

                // Allocate stories into this Jira sprint
                if ($mapping) {
                    $stories = SprintStory::findBySprintId($this->db, (int) $sprint['id']);
                    $issueKeys = [];

                    foreach ($stories as $story) {
                        $storyMapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'user_story', (int) $story['id']);
                        if ($storyMapping && !empty($storyMapping['external_key'])) {
                            $issueKeys[] = $storyMapping['external_key'];
                        }
                    }

                    if (!empty($issueKeys)) {
                        $jiraSprintId = (int) $mapping['external_id'];
                        $this->jira->moveIssuesToSprint($jiraSprintId, $issueKeys);
                        $counts['allocated'] += count($issueKeys);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[JiraPush] Sprint push error: " . $e->getMessage());
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Bulk-validate which mapped Jira keys still exist.
     *
     * Makes a single JQL query instead of N individual GETs.
     * Returns a set of valid keys for O(1) lookup.
     */
    private function validateMappedKeys(int $integrationId, string $localType): array
    {
        $mappings = SyncMapping::findByIntegration($this->db, $integrationId);
        $keys = [];
        foreach ($mappings as $m) {
            if ($m['local_type'] === $localType && !empty($m['external_key'])) {
                $keys[] = $m['external_key'];
            }
        }

        if (empty($keys)) {
            return [];
        }

        try {
            $keyList = implode(', ', $keys);
            $result = $this->jira->searchIssues("key IN ({$keyList})", ['summary'], 100);
            $validKeys = [];
            foreach ($result['issues'] ?? [] as $issue) {
                $validKeys[$issue['key']] = true;
            }
            return $validKeys;
        } catch (\Throwable $e) {
            // If search fails, assume all exist (will catch 404 individually as fallback)
            return array_fill_keys($keys, true);
        }
    }

    // ===========================
    // PUSH: RISKS -> JIRA TASKS
    // ===========================

    /**
     * Push risks to Jira as Task issues with "Risk" label.
     */
    public function pushRisks(int $projectId, string $jiraProjectKey): array
    {
        $risks = Risk::findByProjectId($this->db, $projectId);
        $integrationId = (int) $this->integration['id'];

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $consecutiveErrors = 0;

        $validKeys = $this->validateMappedKeys($integrationId, 'risk');

        foreach ($risks as $risk) {
            if ($consecutiveErrors >= 3) {
                $counts['errors'] += count($risks) - $counts['created'] - $counts['updated'] - $counts['skipped'] - $counts['errors'];
                break;
            }
            try {
                $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'risk', (int) $risk['id']);
                $currentHash = hash('sha256', strtolower($risk['title'] ?? '') . '|' . ($risk['description'] ?? '') . '|' . ($risk['likelihood'] ?? 0) . '|' . ($risk['impact'] ?? 0));

                if ($mapping) {
                    if (!isset($validKeys[$mapping['external_key']])) {
                        SyncMapping::delete($this->db, (int) $mapping['id']);
                        $mapping = null;
                    } elseif ($mapping['sync_hash'] === $currentHash) {
                        $counts['skipped']++;
                        continue;
                    } else {
                        $likelihood = (int) ($risk['likelihood'] ?? 3);
                        $impact     = (int) ($risk['impact'] ?? 3);
                        $rpn        = $likelihood * $impact;
                        $riskLevel  = $rpn >= 15 ? 'critical' : ($rpn >= 9 ? 'high' : ($rpn >= 5 ? 'medium' : 'low'));
                        $this->jira->updateIssue($mapping['external_key'], [
                            'summary'     => $risk['title'],
                            'description' => $this->buildRiskAdf($risk, $likelihood, $impact, $rpn, $riskLevel),
                        ]);
                        SyncMapping::update($this->db, (int) $mapping['id'], [
                            'sync_hash'     => $currentHash,
                            'last_synced_at' => date('Y-m-d H:i:s'),
                        ]);
                        $counts['updated']++;
                        $consecutiveErrors = 0;
                        continue;
                    }
                }

                // Create new Risk issue
                {
                    $likelihood = (int) ($risk['likelihood'] ?? 3);
                    $impact     = (int) ($risk['impact'] ?? 3);
                    $rpn        = $likelihood * $impact;
                    $riskLevel  = $rpn >= 15 ? 'critical' : ($rpn >= 9 ? 'high' : ($rpn >= 5 ? 'medium' : 'low'));
                    $priority   = $rpn >= 15 ? 'Highest' : ($rpn >= 9 ? 'High' : ($rpn >= 5 ? 'Medium' : 'Low'));

                    $adfDesc = $this->buildRiskAdf($risk, $likelihood, $impact, $rpn, $riskLevel);
                    $labels  = ['stratflow', 'risk', "risk-{$riskLevel}", "rpn-{$rpn}"];

                    $fields = [
                        'project'     => ['key' => $jiraProjectKey],
                        'issuetype'   => ['name' => $this->mapping('risk_type', 'Risk')],
                        'summary'     => $risk['title'],
                        'description' => $adfDesc,
                        'priority'    => ['name' => $priority],
                        'labels'      => $labels,
                    ];

                    // Fallback: try without labels, then without configured type
                    try {
                        $result = $this->jira->createIssue($fields);
                    } catch (\RuntimeException $e) {
                        unset($fields['labels']);
                        try {
                            $result = $this->jira->createIssue($fields);
                        } catch (\RuntimeException $e2) {
                            $fields['issuetype'] = ['name' => 'Task'];
                            $result = $this->jira->createIssue($fields);
                        }
                    }

                    $siteUrl = rtrim($this->integration['site_url'] ?? '', '/');
                    $externalUrl = $siteUrl . '/browse/' . $result['key'];

                    SyncMapping::create($this->db, [
                        'integration_id' => $integrationId,
                        'local_type'     => 'risk',
                        'local_id'       => (int) $risk['id'],
                        'external_id'    => $result['id'],
                        'external_key'   => $result['key'],
                        'external_url'   => $externalUrl,
                        'sync_hash'      => $currentHash,
                    ]);

                    $counts['created']++;
                }
                $consecutiveErrors = 0;
            } catch (\Throwable $e) {
                $consecutiveErrors++;
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Build ADF description for a risk with structured risk matrix table.
     */
    private function buildRiskAdf(array $risk, int $likelihood, int $impact, int $rpn, string $level): array
    {
        $content = [];

        // Description paragraph
        if (!empty($risk['description'])) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $risk['description']]],
            ];
        }

        // Risk Assessment heading
        $content[] = [
            'type' => 'heading',
            'attrs' => ['level' => 3],
            'content' => [['type' => 'text', 'text' => 'Risk Assessment']],
        ];

        // Risk matrix table
        $content[] = [
            'type' => 'table',
            'attrs' => ['isNumberColumnEnabled' => false, 'layout' => 'default'],
            'content' => [
                $this->adfTableRow(['Metric', 'Value', 'Scale'], true),
                $this->adfTableRow(['Likelihood', (string) $likelihood, '1 (rare) — 5 (certain)']),
                $this->adfTableRow(['Impact', (string) $impact, '1 (negligible) — 5 (catastrophic)']),
                $this->adfTableRow(['RPN (Risk Priority Number)', (string) $rpn, '1-25 (L×I)']),
                $this->adfTableRow(['Risk Level', strtoupper($level), 'Low / Medium / High / Critical']),
            ],
        ];

        // Mitigation section
        if (!empty($risk['mitigation'])) {
            $content[] = [
                'type' => 'heading',
                'attrs' => ['level' => 3],
                'content' => [['type' => 'text', 'text' => 'Mitigation Strategy']],
            ];
            $content[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $risk['mitigation']]],
            ];
        }

        return ['type' => 'doc', 'version' => 1, 'content' => $content];
    }

    /**
     * Helper: build an ADF table row.
     */
    private function adfTableRow(array $cells, bool $header = false): array
    {
        $cellType = $header ? 'tableHeader' : 'tableCell';
        $row = ['type' => 'tableRow', 'content' => []];
        foreach ($cells as $text) {
            $textNode = ['type' => 'text', 'text' => $text];
            if ($header) {
                $textNode['marks'] = [['type' => 'strong']];
            }
            $row['content'][] = [
                'type' => $cellType,
                'content' => [
                    ['type' => 'paragraph', 'content' => [$textNode]],
                ],
            ];
        }
        return $row;
    }
}
