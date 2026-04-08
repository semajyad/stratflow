<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\DriftAlert;
use StratFlow\Models\GovernanceItem;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\StrategicBaseline;
use StratFlow\Models\UserStory;
use StratFlow\Services\DriftDetectionService;

/**
 * DriftDetectionServiceTest
 *
 * Integration tests for DriftDetectionService against the real Docker MySQL database.
 * Each test creates real data (org → user → project → work items → stories → baseline),
 * runs detection, and verifies alerts are raised correctly. Data is cleaned up after each test.
 */
class DriftDetectionServiceTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $userId;
    private static int $projectId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database([
            'host'     => 'mysql',
            'port'     => '3306',
            'database' => 'stratflow',
            'username' => 'stratflow',
            'password' => 'stratflow_secret',
        ]);

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - DriftDetectionServiceTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - DriftDetectionServiceTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - DriftDetectionServiceTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - DriftDetectionServiceTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_dds@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - DriftDetectionServiceTest', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: drift_alerts -> strategic_baselines -> governance_queue
        //                -> user_stories -> hl_work_items -> projects -> users -> orgs
        self::$db->query("DELETE FROM drift_alerts WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM strategic_baselines WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM governance_queue WHERE project_id = ?", [self::$projectId]);
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Clean test data between tests (FK-safe order)
        self::$db->query("DELETE FROM drift_alerts WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM strategic_baselines WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM governance_queue WHERE project_id = ?", [self::$projectId]);
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
    }

    // ===========================
    // BASELINE
    // ===========================

    #[Test]
    public function testCreateBaselineStoresSnapshot(): void
    {
        $hlItemId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Build Auth Module',
        ]);

        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'As a user, I want to log in',
            'parent_hl_item_id' => $hlItemId,
            'size'              => 3,
        ]);
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 2,
            'title'             => 'As a user, I want to reset my password',
            'parent_hl_item_id' => $hlItemId,
            'size'              => 5,
        ]);

        $service    = new DriftDetectionService(self::$db);
        $baselineId = $service->createBaseline(self::$projectId);

        $this->assertGreaterThan(0, $baselineId);

        $baseline = StrategicBaseline::findLatestByProjectId(self::$db, self::$projectId);
        $this->assertNotNull($baseline);

        $snapshot = json_decode($baseline['snapshot_json'], true);
        $this->assertSame(2, $snapshot['stories']['total_count']);
        $this->assertSame(8, $snapshot['stories']['total_size']);
        $this->assertCount(1, $snapshot['work_items']);
    }

    // ===========================
    // DRIFT DETECTION — NO BASELINE
    // ===========================

    #[Test]
    public function testDetectDriftReturnsEmptyWithNoBaseline(): void
    {
        $service = new DriftDetectionService(self::$db);
        $drifts  = $service->detectDrift(self::$projectId);

        $this->assertSame([], $drifts);
    }

    // ===========================
    // CAPACITY TRIPWIRE
    // ===========================

    #[Test]
    public function testCapacityTripwireDetectsGrowth(): void
    {
        // Create a parent work item and two baseline stories (total size = 4)
        $hlItemId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Feature X',
        ]);

        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'Story baseline 1',
            'parent_hl_item_id' => $hlItemId,
            'size'              => 2,
        ]);
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 2,
            'title'             => 'Story baseline 2',
            'parent_hl_item_id' => $hlItemId,
            'size'              => 2,
        ]);

        // Create baseline (total size = 4 for this parent)
        $service = new DriftDetectionService(self::$db);
        $service->createBaseline(self::$projectId);

        // Add more stories to push size to 12 (200% growth, well above any threshold)
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 3,
            'title'             => 'Story added after baseline 1',
            'parent_hl_item_id' => $hlItemId,
            'size'              => 5,
        ]);
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 4,
            'title'             => 'Story added after baseline 2',
            'parent_hl_item_id' => $hlItemId,
            'size'              => 3,
        ]);

        // Run detection with a 20% threshold — growth of 200% should trigger
        $drifts = $service->detectDrift(self::$projectId, 0.20);

        $this->assertNotEmpty($drifts);
        $types = array_column($drifts, 'type');
        $this->assertContains('capacity_tripwire', $types);

        // Verify a DriftAlert was persisted
        $alerts = DriftAlert::findActiveByProjectId(self::$db, self::$projectId);
        $this->assertNotEmpty($alerts);
        $alertTypes = array_column($alerts, 'alert_type');
        $this->assertContains('capacity_tripwire', $alertTypes);
    }

    // ===========================
    // DEPENDENCY TRIPWIRE
    // ===========================

    #[Test]
    public function testDependencyTripwireDetectsCrossTeam(): void
    {
        // Create two stories assigned to different teams where one blocks the other
        $blockerStoryId = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Team Alpha core story',
            'team_assigned'   => 'Alpha',
            'size'            => 3,
        ]);

        UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Team Beta dependent story',
            'team_assigned'   => 'Beta',
            'size'            => 2,
            'blocked_by'      => $blockerStoryId,
        ]);

        // Create a baseline (required for detectDrift to proceed)
        $service = new DriftDetectionService(self::$db);
        $service->createBaseline(self::$projectId);

        $drifts = $service->detectDrift(self::$projectId, 0.20);

        $this->assertNotEmpty($drifts);
        $types = array_column($drifts, 'type');
        $this->assertContains('dependency_tripwire', $types);

        // Verify a DriftAlert was persisted
        $alerts = DriftAlert::findActiveByProjectId(self::$db, self::$projectId);
        $alertTypes = array_column($alerts, 'alert_type');
        $this->assertContains('dependency_tripwire', $alertTypes);
    }
}
