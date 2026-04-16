<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Integration;
use StratFlow\Models\SyncMapping;
use StratFlow\Models\SyncLog;
use StratFlow\Services\JiraSyncService;
use StratFlow\Services\JiraService;

/**
 * JiraSyncMappingTest
 *
 * Integration tests for the JiraSyncService sync state machine.
 * Tests database persistence of sync state — mappings, logs, and hash
 * tracking — without calling the actual Jira API.
 *
 * Covers:
 * 1. Mapping created on first push
 * 2. Changed hash triggers re-push (updateIssue called)
 * 3. Unchanged item is skipped (no API calls)
 * 4. Sync log written for each push
 * 5. pullStatus maps Jira status to local status
 * 6. Conflict detected when both sides changed (via pullChanges)
 * 7. Integration config_json field_mapping round-trips intact
 */
class JiraSyncMappingTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $userId;
    private static int $projectId;
    private int $integrationId;

    private const CONFIG_JSON = '{"base_url":"https://test.atlassian.net","cloud_id":"test-cloud","project_key":"TEST","field_mapping":{"story_points_field":"story_points","board_id":0}}';

    // ===========================
    // SET UP / TEAR DOWN
    // ===========================

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover state from a previous crashed run
        self::$db->query(
            "DELETE u FROM users u INNER JOIN organisations o ON u.org_id = o.id WHERE o.name = ?",
            ['Test Org - JiraSync']
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - JiraSync'");
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - JiraSync']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'jirasync@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'Jira Sync User', 'org_admin']
        );
        self::$userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, status, created_by) VALUES (?, ?, ?, ?)",
            [self::$orgId, 'Sync Test Project', 'active', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE id = ?", [self::$userId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function setUp(): void
    {
        // Create a fresh integration for each test to avoid cross-test pollution
        self::$db->query(
            "INSERT INTO integrations (org_id, provider, display_name, status, config_json) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'jira', 'Test Jira', 'active', self::CONFIG_JSON]
        );
        $this->integrationId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        self::$db->query("DELETE FROM sync_log WHERE integration_id = ?", [$this->integrationId]);
        self::$db->query("DELETE FROM sync_mappings WHERE integration_id = ?", [$this->integrationId]);
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM integrations WHERE id = ?", [$this->integrationId]);
    }

    // ===========================
    // HELPERS
    // ===========================

    private function makeIntegration(): array
    {
        return [
            'id'          => $this->integrationId,
            'org_id'      => self::$orgId,
            'provider'    => 'jira',
            'status'      => 'active',
            'site_url'    => 'https://test.atlassian.net',
            'config_json' => self::CONFIG_JSON,
        ];
    }

    /**
     * Build a JiraMock that will return valid keys from searchIssues so
     * validateMappedKeys does not delete existing mappings.
     *
     * @param string[] $validKeys External keys already mapped (returned from searchIssues)
     */
    private function makeJiraMock(array $validKeys = []): JiraService
    {
        $mock = $this->createMock(JiraService::class);

        $mock->method('createIssue')->willReturn(['key' => 'TEST-1', 'id' => '10001']);
        $mock->method('updateIssue'); // void return type — no willReturn
        $mock->method('textToAdf')->willReturnCallback(fn(string $t): array => ['type' => 'doc', 'content' => []]);
        $mock->method('adfToText')->willReturn('');

        // searchIssues is used by validateMappedKeys AND dryRunPreview/pullChanges.
        // Return $validKeys as existing issues so mappings survive the bulk check.
        $mock->method('searchIssues')->willReturnCallback(
            function (string $jql, array $fields = [], int $max = 50, int $startAt = 0) use ($validKeys): array {
                $issues = array_map(
                    fn(string $k): array => ['key' => $k, 'id' => '10001', 'fields' => []],
                    $validKeys
                );
                return ['issues' => $issues, 'total' => count($issues)];
            }
        );

        return $mock;
    }

    private function createWorkItem(string $title = 'Test Epic'): int
    {
        return HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => $title,
            'description'     => 'A description',
        ]);
    }

    // ===========================
    // TEST 1: MAPPING CREATED ON FIRST PUSH
    // ===========================

    #[Test]
    public function testSyncMappingCreatedOnFirstPush(): void
    {
        $itemId = $this->createWorkItem('First Push Epic');
        $jiraMock = $this->makeJiraMock();

        $sync = new JiraSyncService(self::$db, $jiraMock, $this->makeIntegration());
        $result = $sync->pushWorkItems(self::$projectId, 'TEST');

        $this->assertSame(1, $result['created'], 'Expected 1 created');

        $mapping = SyncMapping::findByLocalItem(self::$db, $this->integrationId, 'hl_work_item', $itemId);

        $this->assertNotNull($mapping, 'Mapping row should have been created');
        $this->assertSame('TEST-1', $mapping['external_key']);
        $this->assertNotEmpty($mapping['sync_hash'], 'sync_hash should be populated');
    }

    // ===========================
    // TEST 2: CHANGED HASH TRIGGERS REPUSH
    // ===========================

    #[Test]
    public function testSyncHashChangedItemIsRepushed(): void
    {
        $itemId = $this->createWorkItem('Original Title');

        // Manually insert a mapping with an old/stale hash
        SyncMapping::create(self::$db, [
            'integration_id' => $this->integrationId,
            'local_type'     => 'hl_work_item',
            'local_id'       => $itemId,
            'external_id'    => '10001',
            'external_key'   => 'TEST-1',
            'external_url'   => 'https://test.atlassian.net/browse/TEST-1',
            'sync_hash'      => 'stale-hash-that-will-not-match',
        ]);

        $jiraMock = $this->makeJiraMock(['TEST-1']);

        // Verify updateIssue is called exactly once with the correct key
        $jiraMock->expects($this->once())
            ->method('updateIssue')
            ->with('TEST-1', $this->isArray());

        $sync = new JiraSyncService(self::$db, $jiraMock, $this->makeIntegration());
        $result = $sync->pushWorkItems(self::$projectId, 'TEST');

        $this->assertSame(1, $result['updated'], 'Expected 1 updated');
        $this->assertSame(0, $result['created'], 'Should not create a new issue');
    }

    // ===========================
    // TEST 3: UNCHANGED ITEM IS SKIPPED
    // ===========================

    #[Test]
    public function testUnchangedItemIsSkipped(): void
    {
        $itemId = $this->createWorkItem('Unchanged Epic');
        $item = HLWorkItem::findById(self::$db, $itemId);

        // Compute the real current hash so the service considers it unchanged
        $tempSync = new JiraSyncService(self::$db, $this->makeJiraMock(), $this->makeIntegration());
        $currentHash = $tempSync->computeSyncHash($item);

        SyncMapping::create(self::$db, [
            'integration_id' => $this->integrationId,
            'local_type'     => 'hl_work_item',
            'local_id'       => $itemId,
            'external_id'    => '10002',
            'external_key'   => 'TEST-2',
            'external_url'   => 'https://test.atlassian.net/browse/TEST-2',
            'sync_hash'      => $currentHash,
        ]);

        $jiraMock = $this->makeJiraMock(['TEST-2']);
        $jiraMock->expects($this->never())->method('createIssue');
        $jiraMock->expects($this->never())->method('updateIssue');

        $sync = new JiraSyncService(self::$db, $jiraMock, $this->makeIntegration());
        $result = $sync->pushWorkItems(self::$projectId, 'TEST');

        $this->assertSame(1, $result['skipped'], 'Expected 1 skipped');
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
    }

    // ===========================
    // TEST 4: SYNC LOG WRITTEN FOR EACH PUSH
    // ===========================

    #[Test]
    public function testSyncLogWrittenForEachPush(): void
    {
        $this->createWorkItem('Logged Epic A');
        $this->createWorkItem('Logged Epic B');

        $mock = $this->createMock(JiraService::class);
        $mock->method('textToAdf')->willReturn(['type' => 'doc', 'content' => []]);
        $mock->method('adfToText')->willReturn('');
        $mock->method('searchIssues')->willReturn(['issues' => [], 'total' => 0]);
        // Return distinct keys for the two creates
        $callCount = 0;
        $mock->method('createIssue')->willReturnCallback(function () use (&$callCount): array {
            $callCount++;
            return ['key' => 'TEST-' . $callCount, 'id' => (string)(10000 + $callCount)];
        });
        $mock->method('updateIssue'); // void return type — no willReturn

        $sync = new JiraSyncService(self::$db, $mock, $this->makeIntegration());
        $sync->pushWorkItems(self::$projectId, 'TEST');

        $logs = SyncLog::findByIntegration(self::$db, $this->integrationId);

        $this->assertGreaterThanOrEqual(2, count($logs), 'At least 2 log entries expected');

        $actions = array_column($logs, 'action');
        $this->assertContains('create', $actions, 'At least one log entry should have action=create');

        $integrationIds = array_unique(array_column($logs, 'integration_id'));
        $this->assertCount(1, $integrationIds);
        $this->assertSame((string) $this->integrationId, (string) $integrationIds[0]);
    }

    // ===========================
    // TEST 5: PULL STATUS UPDATES LOCAL WORK ITEM STATUS
    // ===========================

    #[Test]
    public function testPullStatusUpdatesLocalWorkItemStatus(): void
    {
        $itemId = $this->createWorkItem('Status Update Epic');

        SyncMapping::create(self::$db, [
            'integration_id' => $this->integrationId,
            'local_type'     => 'hl_work_item',
            'local_id'       => $itemId,
            'external_id'    => '10010',
            'external_key'   => 'TEST-10',
            'external_url'   => 'https://test.atlassian.net/browse/TEST-10',
            'sync_hash'      => 'any-hash',
        ]);

        $jiraMock = $this->makeJiraMock(['TEST-10']);

        $sync = new JiraSyncService(self::$db, $jiraMock, $this->makeIntegration());

        // Jira reports 'Done' — maps to local 'done'
        $issueData = [
            'fields' => [
                'status' => ['name' => 'Done'],
            ],
        ];

        $updated = $sync->pullStatus('TEST-10', $issueData);
        $this->assertTrue($updated, 'pullStatus should return true when status changed');

        $item = HLWorkItem::findById(self::$db, $itemId);
        $this->assertSame('done', $item['status'], "Local status should be 'done' after pull");
    }

    // ===========================
    // TEST 6: CONFLICT DETECTED WHEN BOTH SIDES CHANGED
    // ===========================

    #[Test]
    public function testConflictDetectedWhenBothSidesChanged(): void
    {
        $itemId = $this->createWorkItem('Conflict Epic');
        $item = HLWorkItem::findById(self::$db, $itemId);

        // Compute the original hash and store it as the last-synced hash
        $tempSync = new JiraSyncService(self::$db, $this->makeJiraMock(), $this->makeIntegration());
        $originalHash = $tempSync->computeSyncHash($item);

        SyncMapping::create(self::$db, [
            'integration_id' => $this->integrationId,
            'local_type'     => 'hl_work_item',
            'local_id'       => $itemId,
            'external_id'    => '10020',
            'external_key'   => 'TEST-20',
            'external_url'   => 'https://test.atlassian.net/browse/TEST-20',
            'sync_hash'      => $originalHash,
        ]);

        // Simulate local change: update the title (changes the hash)
        HLWorkItem::update(self::$db, $itemId, ['title' => 'Conflict Epic — locally changed']);

        // Build a dedicated mock for pullChanges — searchIssues returns the Jira issue
        // with a changed title to trigger change detection alongside the local hash mismatch.
        $jiraMock = $this->createMock(JiraService::class);
        $jiraMock->method('textToAdf')->willReturnCallback(fn(string $t): array => ['type' => 'doc', 'content' => []]);
        $jiraMock->method('adfToText')->willReturn('');
        $jiraMock->method('createIssue')->willReturn(['key' => 'TEST-20', 'id' => '10020']);
        $jiraMock->method('updateIssue'); // void
        $jiraMock->method('searchIssues')->willReturn([
            'issues' => [[
                'id'  => '10020',
                'key' => 'TEST-20',
                'fields' => [
                    'summary'   => 'Conflict Epic — changed in Jira',
                    'status'    => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']],
                    'issuetype' => ['name' => 'Epic'],
                    'priority'  => ['name' => 'Medium'],
                    'assignee'  => null,
                    'description' => null,
                ],
            ]],
            'total' => 1,
        ]);

        $sync = new JiraSyncService(self::$db, $jiraMock, $this->makeIntegration());
        $result = $sync->pullChanges(self::$projectId, 'TEST');

        $this->assertGreaterThanOrEqual(1, $result['conflicts'], 'Expected at least 1 conflict');
    }

    // ===========================
    // TEST 7: INTEGRATION CONFIG PERSISTENCE
    // ===========================

    #[Test]
    public function testIntegrationConfigPersistence(): void
    {
        $fieldMapping = [
            'story_points_field' => 'customfield_10016',
            'board_id'           => 42,
            'epic_type'          => 'Epic',
            'story_type'         => 'Story',
        ];

        $configJson = json_encode([
            'base_url'      => 'https://mycompany.atlassian.net',
            'cloud_id'      => 'abc-123',
            'project_key'   => 'MYPROJ',
            'field_mapping' => $fieldMapping,
        ], JSON_THROW_ON_ERROR);

        Integration::update(self::$db, $this->integrationId, ['config_json' => $configJson]);

        $reloaded = Integration::findById(self::$db, $this->integrationId);
        $this->assertNotNull($reloaded);

        $config = json_decode($reloaded['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($config, 'config_json should decode to array');
        $this->assertArrayHasKey('field_mapping', $config);

        $reloadedMapping = $config['field_mapping'];
        $this->assertSame('customfield_10016', $reloadedMapping['story_points_field']);
        $this->assertSame(42, $reloadedMapping['board_id']);
        $this->assertSame('Epic', $reloadedMapping['epic_type']);
        $this->assertSame('Story', $reloadedMapping['story_type']);
    }
}
