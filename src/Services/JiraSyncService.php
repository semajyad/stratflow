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
use StratFlow\Models\SyncMapping;
use StratFlow\Models\SyncLog;

class JiraSyncService
{
    private Database $db;
    private JiraService $jira;
    private array $integration;

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

        $counts = ['created' => 0, 'updated' => 0, 'errors' => 0];

        foreach ($workItems as $item) {
            try {
                $mapping = SyncMapping::findByLocalItem(
                    $this->db,
                    $integrationId,
                    'hl_work_item',
                    (int) $item['id']
                );

                $currentHash = $this->computeSyncHash($item);

                if ($mapping) {
                    // Check if update needed
                    if ($mapping['sync_hash'] === $currentHash) {
                        SyncLog::create($this->db, [
                            'integration_id' => $integrationId,
                            'direction'      => 'push',
                            'action'         => 'skip',
                            'local_type'     => 'hl_work_item',
                            'local_id'       => (int) $item['id'],
                            'external_id'    => $mapping['external_key'],
                            'details_json'   => json_encode(['reason' => 'no changes']),
                            'status'         => 'success',
                        ]);
                        continue;
                    }

                    // Update existing issue
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

                    SyncLog::create($this->db, [
                        'integration_id' => $integrationId,
                        'direction'      => 'push',
                        'action'         => 'update',
                        'local_type'     => 'hl_work_item',
                        'local_id'       => (int) $item['id'],
                        'external_id'    => $mapping['external_key'],
                        'details_json'   => json_encode(['title' => $item['title']]),
                        'status'         => 'success',
                    ]);

                    $counts['updated']++;
                } else {
                    // Create new Epic
                    $description = $this->buildWorkItemDescription($item);
                    $fields = [
                        'project'     => ['key' => $jiraProjectKey],
                        'issuetype'   => ['name' => 'Epic'],
                        'summary'     => $item['title'],
                        'description' => $this->jira->textToAdf($description),
                    ];

                    // Epic Name field (required for classic Jira projects)
                    // Common custom field IDs: customfield_10011 or customfield_10004
                    $fields['customfield_10011'] = $item['title'];

                    // Try to create — if it fails, retry without optional fields
                    try {
                        $result = $this->jira->createIssue($fields);
                    } catch (\RuntimeException $e) {
                        // Retry with minimal fields (no Epic Name custom field)
                        unset($fields['customfield_10011']);
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
            } catch (\Throwable $e) {
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

        $counts = ['created' => 0, 'updated' => 0, 'errors' => 0];

        foreach ($stories as $story) {
            try {
                $mapping = SyncMapping::findByLocalItem(
                    $this->db,
                    $integrationId,
                    'user_story',
                    (int) $story['id']
                );

                $currentHash = $this->computeSyncHash($story);

                if ($mapping) {
                    // Check if update needed
                    if ($mapping['sync_hash'] === $currentHash) {
                        SyncLog::create($this->db, [
                            'integration_id' => $integrationId,
                            'direction'      => 'push',
                            'action'         => 'skip',
                            'local_type'     => 'user_story',
                            'local_id'       => (int) $story['id'],
                            'external_id'    => $mapping['external_key'],
                            'details_json'   => json_encode(['reason' => 'no changes']),
                            'status'         => 'success',
                        ]);
                        continue;
                    }

                    // Update existing issue
                    $updateFields = [
                        'summary'     => $story['title'],
                        'description' => $this->jira->textToAdf($story['description'] ?? ''),
                        'priority'    => ['name' => $this->mapPriority((int) ($story['priority_number'] ?? 5))],
                    ];

                    $this->jira->updateIssue($mapping['external_key'], $updateFields);

                    SyncMapping::update($this->db, (int) $mapping['id'], [
                        'sync_hash'     => $currentHash,
                        'last_synced_at' => date('Y-m-d H:i:s'),
                    ]);

                    SyncLog::create($this->db, [
                        'integration_id' => $integrationId,
                        'direction'      => 'push',
                        'action'         => 'update',
                        'local_type'     => 'user_story',
                        'local_id'       => (int) $story['id'],
                        'external_id'    => $mapping['external_key'],
                        'details_json'   => json_encode(['title' => $story['title']]),
                        'status'         => 'success',
                    ]);

                    $counts['updated']++;
                } else {
                    // Build fields for new Story — minimal fields to avoid 400s
                    $fields = [
                        'project'     => ['key' => $jiraProjectKey],
                        'issuetype'   => ['name' => 'Story'],
                        'summary'     => $story['title'],
                        'description' => $this->jira->textToAdf($story['description'] ?? ''),
                    ];

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
            } catch (\Throwable $e) {
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
     * Searches for issues labelled 'stratflow' in the configured project,
     * then updates local items that have existing sync mappings.
     *
     * @param int    $projectId      StratFlow project ID
     * @param string $jiraProjectKey Jira project key
     * @return array                 {updated: int, errors: int}
     */
    public function pullChanges(int $projectId, string $jiraProjectKey): array
    {
        $integrationId = (int) $this->integration['id'];
        $counts = ['updated' => 0, 'errors' => 0];

        try {
            $jql = 'project = ' . $jiraProjectKey . ' AND labels = stratflow ORDER BY updated DESC';
            $result = $this->jira->searchIssues($jql, ['summary', 'description', 'status', 'priority'], 100);

            $issues = $result['issues'] ?? [];

            foreach ($issues as $issue) {
                try {
                    $externalId = (string) $issue['id'];
                    $mapping = SyncMapping::findByExternalId($this->db, $integrationId, $externalId);

                    if (!$mapping) {
                        continue;
                    }

                    $fields = $issue['fields'] ?? [];
                    $newTitle = $fields['summary'] ?? '';
                    $newDescription = '';
                    if (!empty($fields['description'])) {
                        $newDescription = $this->jira->adfToText($fields['description']);
                    }

                    $updateData = ['title' => $newTitle];
                    if ($newDescription !== '') {
                        $updateData['description'] = trim($newDescription);
                    }

                    if ($mapping['local_type'] === 'hl_work_item') {
                        HLWorkItem::update($this->db, (int) $mapping['local_id'], $updateData);
                    } elseif ($mapping['local_type'] === 'user_story') {
                        UserStory::update($this->db, (int) $mapping['local_id'], $updateData);
                    }

                    // Update mapping hash
                    $localItem = $mapping['local_type'] === 'hl_work_item'
                        ? HLWorkItem::findById($this->db, (int) $mapping['local_id'])
                        : UserStory::findById($this->db, (int) $mapping['local_id']);

                    if ($localItem) {
                        SyncMapping::update($this->db, (int) $mapping['id'], [
                            'sync_hash'     => $this->computeSyncHash($localItem),
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
}
