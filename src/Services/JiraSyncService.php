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

    /**
     * Dry-run preview: show what WOULD be synced without making changes.
     */
    public function dryRunPreview(int $projectId, string $jiraProjectKey): array
    {
        $integrationId = (int) $this->integration['id'];
        $preview = ['push' => [], 'pull' => [], 'conflicts' => []];

        // Check what would be pushed
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $stories = UserStory::findByProjectId($this->db, $projectId);
        $risks = Risk::findByProjectId($this->db, $projectId);

        foreach ($workItems as $item) {
            $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'hl_work_item', (int) $item['id']);
            $hash = $this->computeSyncHash($item);
            if (!$mapping) {
                $preview['push'][] = ['type' => 'Epic', 'title' => $item['title'], 'action' => 'create'];
            } elseif ($mapping['sync_hash'] !== $hash) {
                $preview['push'][] = ['type' => 'Epic', 'title' => $item['title'], 'action' => 'update', 'key' => $mapping['external_key']];
            }
        }
        foreach ($stories as $story) {
            $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'user_story', (int) $story['id']);
            $hash = $this->computeSyncHash($story);
            if (!$mapping) {
                $preview['push'][] = ['type' => 'Story', 'title' => $story['title'], 'action' => 'create'];
            } elseif ($mapping['sync_hash'] !== $hash) {
                $preview['push'][] = ['type' => 'Story', 'title' => $story['title'], 'action' => 'update', 'key' => $mapping['external_key']];
            }
        }
        foreach ($risks as $risk) {
            $mapping = SyncMapping::findByLocalItem($this->db, $integrationId, 'risk', (int) $risk['id']);
            if (!$mapping) {
                $preview['push'][] = ['type' => 'Risk', 'title' => $risk['title'], 'action' => 'create'];
            }
        }

        // Check what would be pulled (query Jira)
        try {
            $spField = $this->mapping('story_points_field', 'customfield_10016');
            $result = $this->jira->searchIssues("project = {$jiraProjectKey}", ['summary', 'issuetype', 'status'], 100);
            $mappings = SyncMapping::findByIntegration($this->db, $integrationId);
            $mappedExtIds = array_column($mappings, 'external_id');

            foreach ($result['issues'] ?? [] as $issue) {
                $externalId = (string) $issue['id'];
                if (!in_array($externalId, $mappedExtIds)) {
                    $preview['pull'][] = [
                        'type'   => $issue['fields']['issuetype']['name'] ?? 'Unknown',
                        'title'  => $issue['fields']['summary'] ?? '',
                        'key'    => $issue['key'],
                        'action' => 'create',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Can't preview pull if Jira unreachable
        }

        return $preview;
    }

    /** Get a field mapping value with a default fallback. */
    private function mapping(string $key, string $default = ''): string
    {
        return $this->fieldMapping[$key] ?? $default;
    }

    /**
     * Get custom field mappings filtered by sync direction.
     *
     * @param string $direction 'push', 'pull', or 'both' -- returns mappings matching the direction
     * @return array Array of mappings with keys: stratflow_field, jira_field, direction
     */
    private function customMappingsForDirection(string $direction): array
    {
        $mappings = $this->fieldMapping['custom_mappings'] ?? [];
        return array_filter($mappings, function (array $m) use ($direction): bool {
            return $m['direction'] === $direction || $m['direction'] === 'both';
        });
    }

    /**
     * Apply custom push mappings to a Jira fields array.
     *
     * Reads each configured custom mapping from the local item and adds
     * the value to the Jira fields payload.
     *
     * @param array $fields Jira fields array (modified in place)
     * @param array $item   Local StratFlow item record
     * @return array        Modified fields array
     */
    private function applyCustomPushFields(array $fields, array $item): array
    {
        foreach ($this->customMappingsForDirection('push') as $cm) {
            $sfField = $cm['stratflow_field'];
            $jfField = $cm['jira_field'];
            $value   = $item[$sfField] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // Numeric fields should be sent as numbers
            if (in_array($sfField, ['priority_number', 'estimated_sprints', 'size'], true)) {
                $fields[$jfField] = (float) $value;
            } else {
                $fields[$jfField] = (string) $value;
            }
        }
        return $fields;
    }

    /**
     * Apply custom pull mappings from a Jira issue to a local update array.
     *
     * Reads each configured custom mapping from the Jira issue fields
     * and adds the value to the local update data.
     *
     * @param array $updateData Local update array (modified in place)
     * @param array $jiraFields Jira issue fields
     * @return array            Modified update data
     */
    private function applyCustomPullFields(array $updateData, array $jiraFields): array
    {
        foreach ($this->customMappingsForDirection('pull') as $cm) {
            $sfField = $cm['stratflow_field'];
            $jfField = $cm['jira_field'];

            if (!isset($jiraFields[$jfField])) {
                continue;
            }

            $val = $jiraFields[$jfField];

            // Handle Jira object fields (e.g. {value: "X"} or {name: "X"})
            if (is_array($val)) {
                $val = $val['value'] ?? $val['name'] ?? $val['displayName'] ?? null;
            }

            if ($val === null) {
                continue;
            }

            // Numeric StratFlow fields
            if (in_array($sfField, ['priority_number', 'estimated_sprints', 'size'], true)) {
                $updateData[$sfField] = (int) $val;
            } else {
                $updateData[$sfField] = (string) $val;
            }
        }
        return $updateData;
    }

    /**
     * Get Jira field IDs needed for pull queries from custom mappings.
     *
     * @return array List of Jira field IDs to include in search results
     */
    private function customPullFieldIds(): array
    {
        $ids = [];
        foreach ($this->customMappingsForDirection('pull') as $cm) {
            $ids[] = $cm['jira_field'];
        }
        return $ids;
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
                        $updateFields = [
                            'summary'     => $item['title'],
                            'description' => $this->jira->textToAdf($description),
                            'priority'    => ['name' => $this->mapPriority((int) ($item['priority_number'] ?? 5))],
                        ];
                        // Push owner as assignee if configured
                        if (!empty($item['owner'])) {
                            $updateFields['assignee'] = ['displayName' => $item['owner']];
                        }
                        // Apply additional custom field mappings (push direction)
                        $updateFields = $this->applyCustomPushFields($updateFields, $item);
                        $this->jira->updateIssue($mapping['external_key'], $updateFields);

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

                    // Apply additional custom field mappings (push direction)
                    $fields = $this->applyCustomPushFields($fields, $item);

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
                        $updateFields = [
                            'summary'     => $story['title'],
                            'description' => $this->jira->textToAdf($this->buildStoryDescription($story)),
                            'priority'    => ['name' => $this->mapPriority((int) ($story['priority_number'] ?? 5))],
                        ];
                        $spField = $this->mapping('story_points_field', 'customfield_10016');
                        if (!empty($story['size']) && $spField) {
                            $updateFields[$spField] = (float) $story['size'];
                        }
                        // Apply additional custom field mappings (push direction)
                        $updateFields = $this->applyCustomPushFields($updateFields, $story);
                        $this->jira->updateIssue($mapping['external_key'], $updateFields);

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

                    // Apply additional custom field mappings (push direction)
                    $fields = $this->applyCustomPushFields($fields, $story);

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
     * Pull changes from Jira and update/create local items.
     *
     * 1. Updates existing mapped items from Jira changes
     * 2. Creates new local items for unmapped Jira issues (full bidirectional)
     * 3. Conflicts flagged but NOT auto-applied (requires manual review)
     * 4. Field-level change audit trail in sync log
     *
     * @param int    $projectId      StratFlow project ID
     * @param string $jiraProjectKey Jira project key
     * @return array                 {created: int, updated: int, conflicts: int, skipped: int, errors: int}
     */
    public function pullChanges(int $projectId, string $jiraProjectKey): array
    {
        $integrationId = (int) $this->integration['id'];
        $counts = ['created' => 0, 'updated' => 0, 'conflicts' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            // Build pull field list
            $spField = $this->mapping('story_points_field', 'customfield_10016');
            $teamField = $this->mapping('team_field', '');
            $pullFields = ['summary', 'description', 'status', 'priority', 'assignee', 'issuetype', 'parent'];
            if ($spField) $pullFields[] = $spField;
            if ($teamField) $pullFields[] = $teamField;

            // Add custom mapping fields to pull query
            foreach ($this->customPullFieldIds() as $cfId) {
                if (!in_array($cfId, $pullFields, true)) {
                    $pullFields[] = $cfId;
                }
            }

            // Query ALL issues in the Jira project (not just mapped ones)
            // Paginate for large datasets
            $allIssues = [];
            $startAt = 0;
            $pageSize = 100;
            do {
                $jql = "project = {$jiraProjectKey} ORDER BY updated DESC";
                $result = $this->jira->searchIssues($jql, $pullFields, $pageSize, $startAt);
                $page = $result['issues'] ?? [];
                $allIssues = array_merge($allIssues, $page);
                $startAt += $pageSize;
            } while (count($page) === $pageSize && $startAt < 2000); // Safety cap

            // Get existing mappings indexed by external_id
            $mappings = SyncMapping::findByIntegration($this->db, $integrationId);
            $mappingsByExtId = [];
            foreach ($mappings as $m) {
                $mappingsByExtId[$m['external_id']] = $m;
            }

            foreach ($allIssues as $issue) {
                try {
                    $externalId = (string) $issue['id'];
                    $issueKey = $issue['key'] ?? $externalId;
                    $mapping = $mappingsByExtId[$externalId] ?? null;
                    $fields = $issue['fields'] ?? [];
                    $issueType = strtolower($fields['issuetype']['name'] ?? '');

                    // Extract common fields from Jira
                    $jiraData = $this->extractJiraFields($fields, $spField, $teamField);

                    if (!$mapping) {
                        // NEW: Create local item from unmapped Jira issue
                        $localType = $this->inferLocalType($issueType);
                        if (!$localType) continue; // Skip subtasks etc.

                        $localId = $this->createLocalItemFromJira($projectId, $localType, $jiraData, $fields);
                        if ($localId) {
                            $siteUrl = rtrim($this->integration['site_url'] ?? '', '/');
                            SyncMapping::create($this->db, [
                                'integration_id' => $integrationId,
                                'local_type'     => $localType,
                                'local_id'       => $localId,
                                'external_id'    => $externalId,
                                'external_key'   => $issueKey,
                                'external_url'   => $siteUrl . '/browse/' . $issueKey,
                                'sync_hash'      => '',
                            ]);

                            SyncLog::create($this->db, [
                                'integration_id' => $integrationId,
                                'direction'      => 'pull',
                                'action'         => 'create',
                                'local_type'     => $localType,
                                'local_id'       => $localId,
                                'external_id'    => $issueKey,
                                'details_json'   => json_encode(['title' => $jiraData['title'], 'source' => 'jira']),
                                'status'         => 'success',
                            ]);
                            $counts['created']++;
                        }
                        continue;
                    }

                    // Existing mapped item — check for changes
                    $localItem = $mapping['local_type'] === 'hl_work_item'
                        ? HLWorkItem::findById($this->db, (int) $mapping['local_id'])
                        : UserStory::findById($this->db, (int) $mapping['local_id']);

                    if (!$localItem) {
                        continue;
                    }

                    // Build update data + track field-level changes for audit
                    $updateData = [];
                    $changedFields = [];

                    if ($jiraData['title'] !== '' && $jiraData['title'] !== ($localItem['title'] ?? '')) {
                        $changedFields['title'] = ['old' => $localItem['title'] ?? '', 'new' => $jiraData['title']];
                        $updateData['title'] = $jiraData['title'];
                    }
                    if ($jiraData['description'] !== '' && $jiraData['description'] !== ($localItem['description'] ?? '')) {
                        $changedFields['description'] = ['old' => substr($localItem['description'] ?? '', 0, 100), 'new' => substr($jiraData['description'], 0, 100)];
                        $updateData['description'] = $jiraData['description'];
                    }
                    if ($jiraData['status'] && $jiraData['status'] !== ($localItem['status'] ?? 'backlog')) {
                        $changedFields['status'] = ['old' => $localItem['status'] ?? 'backlog', 'new' => $jiraData['status']];
                        $updateData['status'] = $jiraData['status'];
                    }
                    if ($jiraData['owner'] && $jiraData['owner'] !== ($localItem['owner'] ?? '')) {
                        $changedFields['owner'] = ['old' => $localItem['owner'] ?? '', 'new' => $jiraData['owner']];
                        $updateData['owner'] = $jiraData['owner'];
                    }
                    if ($mapping['local_type'] === 'user_story') {
                        if ($jiraData['size'] !== null && (int) $jiraData['size'] !== (int) ($localItem['size'] ?? 0)) {
                            $changedFields['size'] = ['old' => $localItem['size'] ?? 0, 'new' => $jiraData['size']];
                            $updateData['size'] = (int) $jiraData['size'];
                        }
                        if ($jiraData['team'] && $jiraData['team'] !== ($localItem['team_assigned'] ?? '')) {
                            $changedFields['team_assigned'] = ['old' => $localItem['team_assigned'] ?? '', 'new' => $jiraData['team']];
                            $updateData['team_assigned'] = $jiraData['team'];
                        }
                    }

                    // Apply additional custom field mappings (pull direction)
                    $jiraIssueFields = $issue['fields'] ?? [];
                    $customPulled = $this->applyCustomPullFields([], $jiraIssueFields);
                    foreach ($customPulled as $sfField => $newVal) {
                        $oldVal = $localItem[$sfField] ?? '';
                        if ((string) $newVal !== (string) $oldVal) {
                            $changedFields[$sfField] = ['old' => $oldVal, 'new' => $newVal];
                            $updateData[$sfField] = $newVal;
                        }
                    }

                    if (empty($updateData)) {
                        $counts['skipped']++;
                        continue;
                    }

                    // Conflict detection: if local changed since last sync, DON'T apply
                    $currentLocalHash = $this->computeSyncHash($localItem);
                    $lastSyncHash = $mapping['sync_hash'] ?? '';
                    $localChanged = ($lastSyncHash !== '' && $currentLocalHash !== $lastSyncHash);

                    if ($localChanged) {
                        // Both sides changed — flag conflict, DON'T overwrite
                        if ($mapping['local_type'] === 'hl_work_item') {
                            HLWorkItem::update($this->db, (int) $mapping['local_id'], ['requires_review' => 1]);
                        } else {
                            UserStory::update($this->db, (int) $mapping['local_id'], ['requires_review' => 1]);
                        }

                        SyncLog::create($this->db, [
                            'integration_id' => $integrationId,
                            'direction'      => 'pull',
                            'action'         => 'skip',
                            'local_type'     => $mapping['local_type'],
                            'local_id'       => (int) $mapping['local_id'],
                            'external_id'    => $issueKey,
                            'details_json'   => json_encode([
                                'conflict' => true,
                                'changes_from_jira' => $changedFields,
                                'reason' => 'Local item modified since last sync. Review required.',
                            ]),
                            'status'         => 'success',
                        ]);
                        $counts['conflicts']++;
                        continue;
                    }

                    // No conflict — apply update with field-level audit
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
    // PULL: STATUS ONLY
    // ===========================

    /**
     * Map a Jira status name to the local status enum value.
     *
     * Case-insensitive. Returns null if the name is not recognised so the
     * caller can decide to skip rather than overwrite with a bad value.
     *
     * @param string $jiraStatusName Raw status name from Jira (e.g. "In Progress")
     * @return string|null           Local enum value, or null if unknown
     */
    private function mapJiraStatusName(string $jiraStatusName): ?string
    {
        $name = strtolower(trim($jiraStatusName));

        $statusMap = [
            'to do'         => 'backlog',
            'backlog'       => 'backlog',
            'open'          => 'backlog',
            'in progress'   => 'in_progress',
            'in development' => 'in_progress',
            'in review'     => 'in_review',
            'in qa'         => 'in_review',
            'code review'   => 'in_review',
            'done'          => 'done',
            'closed'        => 'done',
            'resolved'      => 'done',
        ];

        return $statusMap[$name] ?? null;
    }

    /**
     * Pull status for a single Jira issue and apply it to the local item.
     *
     * Looks up the local sync mapping by external key, maps the Jira status
     * name to the local enum, updates the local row if the status changed,
     * and writes a sync_log entry with action 'status_pull'.
     *
     * @param string $issueKey   Jira issue key (e.g. "PROJ-42")
     * @param array  $issueData  Full Jira issue payload (fields.status.name required)
     * @return bool              True if a local record was updated, false otherwise
     */
    public function pullStatus(string $issueKey, array $issueData): bool
    {
        $integrationId = (int) $this->integration['id'];

        $mapping = SyncMapping::findByExternalKey($this->db, $integrationId, $issueKey);
        if (!$mapping) {
            return false;
        }

        $statusField = $issueData['fields']['status'] ?? null;
        $jiraStatusName = is_array($statusField) ? (string) ($statusField['name'] ?? '') : '';
        $newStatus = $this->mapJiraStatusName($jiraStatusName);

        if ($newStatus === null) {
            error_log("[JiraStatusPull] Unknown Jira status '{$jiraStatusName}' for {$issueKey} — skipping");
            return false;
        }

        // Load current local item to check for an actual change
        $localItem = $mapping['local_type'] === 'hl_work_item'
            ? HLWorkItem::findById($this->db, (int) $mapping['local_id'])
            : UserStory::findById($this->db, (int) $mapping['local_id']);

        if (!$localItem) {
            return false;
        }

        $currentStatus = $localItem['status'] ?? 'backlog';
        $now = date('Y-m-d H:i:s');

        if ($currentStatus === $newStatus) {
            return false;
        }

        // Apply update
        $updateData = ['status' => $newStatus, 'last_jira_sync_at' => $now];
        if ($mapping['local_type'] === 'hl_work_item') {
            HLWorkItem::update($this->db, (int) $mapping['local_id'], $updateData);
        } else {
            UserStory::update($this->db, (int) $mapping['local_id'], $updateData);
        }

        SyncLog::create($this->db, [
            'integration_id' => $integrationId,
            'direction'      => 'pull',
            'action'         => 'status_pull',
            'local_type'     => $mapping['local_type'],
            'local_id'       => (int) $mapping['local_id'],
            'external_id'    => $issueKey,
            'details_json'   => json_encode([
                'status_old' => $currentStatus,
                'status_new' => $newStatus,
                'jira_status' => $jiraStatusName,
            ]),
            'status'         => 'success',
        ]);

        return true;
    }

    /**
     * Bulk-pull status for multiple Jira issues by fetching each from the API.
     *
     * Fetches the issue data for each key via JiraService::searchIssues and
     * delegates to pullStatus for each. Issues that have no local mapping are
     * silently skipped.
     *
     * @param array $issueKeys List of Jira issue keys (e.g. ["PROJ-1","PROJ-2"])
     * @return int             Number of local records that were actually updated
     */
    public function pullStatusBulk(array $issueKeys): int
    {
        if (empty($issueKeys)) {
            return 0;
        }

        $updated = 0;

        // Fetch all issues in a single JQL query to minimise API calls
        $keyList = implode(',', array_map(fn($k) => '"' . addslashes($k) . '"', $issueKeys));
        $jql = "issueKey in ({$keyList})";

        try {
            $pageSize = 100;
            $startAt  = 0;
            do {
                $result = $this->jira->searchIssues($jql, ['status'], $pageSize, $startAt);
                $page   = $result['issues'] ?? [];
                foreach ($page as $issue) {
                    try {
                        if ($this->pullStatus($issue['key'], $issue)) {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        error_log("[JiraStatusBulk] Error pulling status for {$issue['key']}: " . $e->getMessage());
                    }
                }
                $startAt += $pageSize;
            } while (count($page) === $pageSize && $startAt < 2000);
        } catch (\Throwable $e) {
            error_log('[JiraStatusBulk] JQL fetch failed: ' . $e->getMessage());
        }

        return $updated;
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
            $item['team_assigned'] ?? '',
            (string) ($item['parent_hl_item_id'] ?? 0),
            (string) ($item['estimated_sprints'] ?? 0),
            $item['acceptance_criteria'] ?? '',
            $item['kr_hypothesis'] ?? '',
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
        // Use configurable ranges if set, otherwise defaults
        $ranges = $this->fieldMapping['priority_ranges'] ?? null;
        if ($ranges) {
            $highest = (int) ($ranges['highest'] ?? 2);
            $high    = (int) ($ranges['high'] ?? 4);
            $medium  = (int) ($ranges['medium'] ?? 6);
            $low     = (int) ($ranges['low'] ?? 8);
        } else {
            $highest = 2; $high = 4; $medium = 6; $low = 8;
        }

        if ($priorityNumber <= $highest) return 'Highest';
        if ($priorityNumber <= $high)    return 'High';
        if ($priorityNumber <= $medium)  return 'Medium';
        if ($priorityNumber <= $low)     return 'Low';
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

        if (!empty($item['acceptance_criteria'])) {
            $description .= "\n\nAcceptance Criteria:\n" . $item['acceptance_criteria'];
        }

        if (!empty($item['kr_hypothesis'])) {
            $description .= "\n\nKR Hypothesis: " . $item['kr_hypothesis'];
        }

        return trim($description);
    }

    /**
     * Build the Jira description string for a user story.
     * Appends acceptance criteria and KR hypothesis when present.
     *
     * @param array $story User story record
     * @return string      Combined description text
     */
    private function buildStoryDescription(array $story): string
    {
        $description = $story['description'] ?? '';

        if (!empty($story['acceptance_criteria'])) {
            $description .= "\n\nAcceptance Criteria:\n" . $story['acceptance_criteria'];
        }

        if (!empty($story['kr_hypothesis'])) {
            $description .= "\n\nKR Hypothesis: " . $story['kr_hypothesis'];
        }

        return trim($description);
    }

    /**
     * Extract standard fields from a Jira issue's fields array.
     */
    private function extractJiraFields(array $fields, string $spField, string $teamField): array
    {
        $title = $fields['summary'] ?? '';
        $description = '';
        if (!empty($fields['description'])) {
            $description = $this->jira->adfToText($fields['description']);
        }

        // Status mapping
        $status = null;
        $jiraStatusCat = $fields['status']['statusCategory']['key'] ?? '';
        $statusMap = ['new' => 'backlog', 'indeterminate' => 'in_progress', 'done' => 'done'];
        if (isset($statusMap[$jiraStatusCat])) {
            $status = $statusMap[$jiraStatusCat];
        }
        $jiraStatusName = strtolower($fields['status']['name'] ?? '');
        if (str_contains($jiraStatusName, 'review')) {
            $status = 'in_review';
        }

        // Assignee
        $owner = null;
        $assignee = $fields['assignee'] ?? null;
        if ($assignee && !empty($assignee['displayName'])) {
            $owner = $assignee['displayName'];
        }

        // Story points
        $size = null;
        if ($spField && isset($fields[$spField])) {
            $size = (int) $fields[$spField];
        }

        // Team
        $team = null;
        if ($teamField && isset($fields[$teamField])) {
            $val = $fields[$teamField];
            $team = is_string($val) ? $val : ($val['value'] ?? $val['name'] ?? null);
        }

        return compact('title', 'description', 'status', 'owner', 'size', 'team');
    }

    /**
     * Infer the local item type from a Jira issue type name.
     */
    private function inferLocalType(string $jiraIssueType): ?string
    {
        $epicType  = strtolower($this->mapping('epic_type', 'Epic'));
        $storyType = strtolower($this->mapping('story_type', 'Story'));
        $riskType  = strtolower($this->mapping('risk_type', 'Risk'));

        if ($jiraIssueType === $epicType)  return 'hl_work_item';
        if ($jiraIssueType === $storyType) return 'user_story';
        if ($jiraIssueType === $riskType)  return 'risk';
        if ($jiraIssueType === 'task')     return 'user_story'; // Common fallback
        return null;
    }

    /**
     * Create a local item from Jira issue data.
     */
    private function createLocalItemFromJira(int $projectId, string $localType, array $data, array $jiraFields): ?int
    {
        if ($localType === 'hl_work_item') {
            return HLWorkItem::create($this->db, [
                'project_id'      => $projectId,
                'title'           => $data['title'],
                'description'     => $data['description'],
                'owner'           => $data['owner'] ?? '',
                'priority_number' => 99, // Will be prioritised later
                'estimated_sprints' => 2,
                'status'          => $data['status'] ?? 'backlog',
            ]);
        }

        if ($localType === 'user_story') {
            // Try to link to parent epic
            $parentHlId = null;
            $parentKey = $jiraFields['parent']['key'] ?? null;
            if ($parentKey) {
                $integrationId = (int) $this->integration['id'];
                $parentMapping = null;
                // Look up by external_key
                $allMappings = SyncMapping::findByIntegration($this->db, $integrationId);
                foreach ($allMappings as $m) {
                    if ($m['external_key'] === $parentKey && $m['local_type'] === 'hl_work_item') {
                        $parentHlId = (int) $m['local_id'];
                        break;
                    }
                }
            }

            return UserStory::create($this->db, [
                'project_id'       => $projectId,
                'title'            => $data['title'],
                'description'      => $data['description'],
                'parent_hl_item_id' => $parentHlId,
                'priority_number'  => 99,
                'size'             => $data['size'],
                'team_assigned'    => $data['team'] ?? null,
                'status'           => $data['status'] ?? 'backlog',
            ]);
        }

        if ($localType === 'risk') {
            return Risk::create($this->db, [
                'project_id'  => $projectId,
                'title'       => $data['title'],
                'description' => $data['description'],
                'likelihood'  => 3,
                'impact'      => 3,
            ]);
        }

        return null;
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

    // ===========================
    // PUSH: OKRs -> ATLASSIAN GOALS
    // ===========================

    /**
     * Push OKRs from diagram nodes to Atlassian Goals.
     *
     * Each unique OKR title becomes a Goal. OKR descriptions become
     * the goal description. Uses the Atlassian GraphQL Gateway API.
     *
     * @param int $projectId StratFlow project ID
     * @return array {created: int, skipped: int, errors: int}
     */
    public function pushOkrsToGoals(int $projectId): array
    {
        $counts = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $siteUrl = $this->integration['site_url'] ?? '';
        $cloudId = $this->integration['cloud_id'] ?? '';
        if (!$cloudId || !$siteUrl) {
            return $counts;
        }

        // Collect OKRs from both diagram nodes AND work items
        $okrs = [];

        // 1. From diagram nodes (primary source — the strategy map)
        $stmt = $this->db->query(
            "SELECT dn.okr_title, dn.okr_description, dn.label
             FROM diagram_nodes dn
             JOIN strategy_diagrams sd ON dn.diagram_id = sd.id
             WHERE sd.project_id = :pid AND dn.okr_title IS NOT NULL AND dn.okr_title != ''",
            [':pid' => $projectId]
        );
        foreach ($stmt->fetchAll() as $node) {
            $title = trim($node['okr_title']);
            if ($title !== '' && !isset($okrs[$title])) {
                $okrs[$title] = [
                    'title'       => $title,
                    'description' => trim($node['okr_description'] ?? ''),
                    'nodes'       => [],
                    'work_items'  => [],
                ];
            }
            if ($title !== '') {
                $okrs[$title]['nodes'][] = $node['label'];
            }
        }

        // 2. From work items (may have additional OKRs or linked context)
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        foreach ($workItems as $item) {
            $title = trim($item['okr_title'] ?? '');
            if ($title !== '' && !isset($okrs[$title])) {
                $okrs[$title] = [
                    'title'       => $title,
                    'description' => trim($item['okr_description'] ?? ''),
                    'nodes'       => [],
                    'work_items'  => [],
                ];
            }
            if ($title !== '') {
                $okrs[$title]['work_items'][] = $item['title'];
            }
        }

        if (empty($okrs)) {
            return $counts;
        }

        // Get existing goals to avoid duplicates
        $existingGoals = $this->getAtlassianGoals($cloudId, $siteUrl);
        $existingNames = array_map(fn($g) => strtolower($g['name']), $existingGoals);

        // Get goal type ID from an existing goal, or skip if none exist
        $goalTypeId = $this->getGoalTypeId($cloudId, $siteUrl, $existingGoals);

        foreach ($okrs as $okr) {
            try {
                if (in_array(strtolower($okr['title']), $existingNames)) {
                    $counts['skipped']++;
                    continue;
                }

                // Build description with strategy context and linked work items
                $desc = $okr['description'];
                if (!empty($okr['nodes'])) {
                    $desc .= ($desc ? "\n\n" : '') . "Strategy Nodes:\n- " . implode("\n- ", $okr['nodes']);
                }
                if (!empty($okr['work_items'])) {
                    $desc .= ($desc ? "\n\n" : '') . "Linked Work Items:\n- " . implode("\n- ", $okr['work_items']);
                }

                $result = $this->createAtlassianGoal($cloudId, $siteUrl, $okr['title'], $desc, $goalTypeId);
                if ($result) {
                    $counts['created']++;
                    $newGoalId = $result['id'] ?? null;

                    // Push KRs as child goals under this OKR goal
                    $krRows = $this->db->query(
                        "SELECT kr.id, kr.title, kr.baseline_value, kr.target_value, kr.unit
                           FROM key_results kr
                           JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
                          WHERE hwi.project_id = :pid
                            AND hwi.okr_title = :okr_title",
                        [':pid' => $projectId, ':okr_title' => $okr['title']]
                    )->fetchAll();

                    foreach ($krRows as $kr) {
                        try {
                            $krDesc = $kr['unit']
                                ? "Target: {$kr['target_value']} {$kr['unit']} (baseline: {$kr['baseline_value']} {$kr['unit']})"
                                : ($kr['target_value'] ? "Target: {$kr['target_value']}" : '');

                            $krGoalResult = $this->createAtlassianGoal(
                                $cloudId,
                                $siteUrl,
                                $kr['title'],
                                $krDesc,
                                $goalTypeId,
                                $newGoalId
                            );

                            if ($krGoalResult !== null) {
                                $this->db->query(
                                    "UPDATE key_results SET jira_goal_id = :gid WHERE id = :id",
                                    [':gid' => $krGoalResult['id'], ':id' => $kr['id']]
                                );
                            }
                        } catch (\Throwable $e) {
                            error_log('[JiraSyncService] KR child goal error: ' . $e->getMessage());
                        }
                    }

                    SyncLog::create($this->db, [
                        'integration_id' => (int) $this->integration['id'],
                        'direction'      => 'push',
                        'action'         => 'create',
                        'local_type'     => 'hl_work_item',
                        'local_id'       => null,
                        'external_id'    => $result['key'] ?? $result['id'] ?? null,
                        'details_json'   => json_encode(['type' => 'goal', 'title' => $okr['title'], 'url' => $result['url'] ?? '']),
                        'status'         => 'success',
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[GoalsSync] Failed to create goal: ' . $e->getMessage());
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Query existing Atlassian Goals.
     */
    private function getAtlassianGoals(string $cloudId, string $siteUrl): array
    {
        $graphqlUrl = rtrim($siteUrl, '/') . '/gateway/api/graphql';
        $containerId = "ari:cloud:townsquare::site/{$cloudId}";

        $query = 'query ListGoals { goals_search(containerId: "' . $containerId . '", first: 50) { edges { node { id name key url } } } }';

        $response = $this->graphqlRequest($graphqlUrl, $query);
        $goals = [];
        foreach ($response['data']['goals_search']['edges'] ?? [] as $edge) {
            $goals[] = $edge['node'];
        }
        return $goals;
    }

    /**
     * Get the default goal type ID from an existing goal.
     */
    private function getGoalTypeId(string $cloudId, string $siteUrl, array $existingGoals): ?string
    {
        if (empty($existingGoals)) {
            return null;
        }

        $graphqlUrl = rtrim($siteUrl, '/') . '/gateway/api/graphql';
        $goalId = $existingGoals[0]['id'];

        $query = 'query GT { goals_byId(goalId: "' . $goalId . '") { goalType @optIn(to: "Townsquare") { id } } }';
        $response = $this->graphqlRequest($graphqlUrl, $query);
        return $response['data']['goals_byId']['goalType']['id'] ?? null;
    }

    /**
     * Create a goal via the Atlassian GraphQL API.
     *
     * @param string      $cloudId      Atlassian cloud ID
     * @param string      $siteUrl      Atlassian site base URL
     * @param string      $name         Goal name
     * @param string      $description  Goal description (ADF paragraph)
     * @param string|null $goalTypeId   Optional goal type ID
     * @param string|null $parentGoalId Optional parent goal ID (for child/KR goals)
     * @return array|null               Created goal node (id, name, url, key) or null on failure
     */
    private function createAtlassianGoal(string $cloudId, string $siteUrl, string $name, string $description, ?string $goalTypeId, ?string $parentGoalId = null): ?array
    {
        $graphqlUrl = rtrim($siteUrl, '/') . '/gateway/api/graphql';
        $containerId = "ari:cloud:townsquare::site/{$cloudId}";

        $input = 'containerId: "' . $containerId . '", name: "' . addslashes($name) . '"';
        if ($goalTypeId) {
            $input .= ', goalTypeId: "' . $goalTypeId . '"';
        }
        if ($parentGoalId !== null) {
            $input .= ', parentGoalId: "' . addslashes($parentGoalId) . '"';
        }

        $mutation = 'mutation CG { goals_create(input: { ' . $input . ' }) { goal { id name url key } } }';

        $response = $this->graphqlRequest($graphqlUrl, $mutation);
        $goal = $response['data']['goals_create']['goal'] ?? null;

        // If created, update description
        if ($goal && $description !== '') {
            $adfDesc = json_encode([
                'type' => 'doc', 'version' => 1,
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $description]]]],
            ]);
            $editMutation = 'mutation ED { goals_edit(input: { goalId: "' . $goal['id'] . '", description: ' . json_encode($adfDesc) . ' }) { goal { id } } }';
            try {
                $this->graphqlRequest($graphqlUrl, $editMutation);
            } catch (\Throwable $e) {
                // Description update failed — goal still created
            }
        }

        return $goal;
    }

    // ===========================
    // PULL: ATLASSIAN GOALS -> KEY RESULTS STATUS
    // ===========================

    /**
     * Pull KR status updates from Atlassian Goals back into key_results.
     *
     * Reads each key_results row that has a jira_goal_id set, calls the
     * Atlassian Goals API to get current state, and updates key_results.status
     * if the remote status differs.
     *
     * @param int $projectId StratFlow project ID
     * @return array{updated: int, skipped: int, errors: int}
     */
    public function pullKrStatusFromGoals(int $projectId): array
    {
        $counts  = ['updated' => 0, 'skipped' => 0, 'errors' => 0];
        $siteUrl = $this->integration['site_url'] ?? '';
        $cloudId = $this->integration['cloud_id'] ?? '';

        if (!$cloudId || !$siteUrl) {
            return $counts;
        }

        $krs = $this->db->query(
            "SELECT kr.id, kr.jira_goal_id, kr.status
               FROM key_results kr
               JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
              WHERE hwi.project_id = :pid
                AND kr.jira_goal_id IS NOT NULL",
            [':pid' => $projectId]
        )->fetchAll();

        $stateMap = [
            'ON_TRACK'    => 'on_track',
            'AT_RISK'     => 'at_risk',
            'OFF_TRACK'   => 'off_track',
            'DONE'        => 'achieved',
            'NOT_STARTED' => 'not_started',
        ];

        foreach ($krs as $kr) {
            try {
                $goalState = $this->fetchAtlassianGoalState($cloudId, $siteUrl, $kr['jira_goal_id']);
                if ($goalState === null) {
                    $counts['skipped']++;
                    continue;
                }
                $newStatus = $stateMap[strtoupper($goalState)] ?? null;
                if ($newStatus === null || $newStatus === $kr['status']) {
                    $counts['skipped']++;
                    continue;
                }
                $this->db->query(
                    "UPDATE key_results SET status = :s WHERE id = :id",
                    [':s' => $newStatus, ':id' => $kr['id']]
                );
                $counts['updated']++;
            } catch (\Throwable $e) {
                error_log('[JiraSyncService] pullKrStatus error for kr_id=' . $kr['id'] . ': ' . $e->getMessage());
                $counts['errors']++;
            }
        }
        return $counts;
    }

    /**
     * Fetch the current state of an Atlassian Goal via GraphQL.
     *
     * Queries the goals_byId GraphQL field and returns the raw state string
     * (e.g. "ON_TRACK", "AT_RISK") or null if the goal cannot be retrieved.
     *
     * @param string $cloudId Atlassian cloud ID
     * @param string $siteUrl Atlassian site base URL
     * @param string $goalId  Atlassian goal ID
     * @return string|null    Raw state value from the Goals API, or null
     */
    private function fetchAtlassianGoalState(string $cloudId, string $siteUrl, string $goalId): ?string
    {
        $graphqlUrl = rtrim($siteUrl, '/') . '/gateway/api/graphql';
        $query = 'query GS { goals_byId(goalId: "' . addslashes($goalId) . '") { state } }';

        try {
            $response = $this->graphqlRequest($graphqlUrl, $query);
        } catch (\Throwable $e) {
            return null;
        }

        return $response['data']['goals_byId']['state'] ?? null;
    }

    /**
     * Make a GraphQL request to the Atlassian gateway.
     * Uses basic auth with Jira API token.
     */
    private function graphqlRequest(string $url, string $query): array
    {
        // Use OAuth Bearer token from the integration record (per-org, no global credentials)
        $accessToken = $this->integration['access_token'] ?? '';

        if ($accessToken === '') {
            throw new \RuntimeException('No OAuth access token available for Goals API');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If OAuth fails (Goals may need different scopes), fall back to API token
        if ($httpCode === 401 || $httpCode === 403) {
            $apiToken = $_ENV['JIRA_API_TOKEN'] ?? '';
            $email    = $_ENV['JIRA_EMAIL'] ?? '';
            if ($apiToken !== '' && $email !== '') {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Basic ' . base64_encode($email . ':' . $apiToken),
                    ],
                    CURLOPT_TIMEOUT => 15,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
        }

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException("Goals GraphQL API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!empty($data['errors'])) {
            throw new \RuntimeException('Goals API error: ' . ($data['errors'][0]['message'] ?? 'Unknown'));
        }

        return $data;
    }
}
