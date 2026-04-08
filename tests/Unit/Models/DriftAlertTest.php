<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\DriftAlert;

/**
 * DriftAlertTest
 *
 * Tests CRUD operations for the DriftAlert model against the real Docker MySQL database.
 * setUp creates a test org + user + project; tearDown removes all test data in FK-safe order.
 */
class DriftAlertTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
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
             WHERE o.name = 'Test Org - DriftAlertTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - DriftAlertTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - DriftAlertTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - DriftAlertTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_da@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - DriftAlertTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: drift_alerts -> projects -> users -> orgs
        self::$db->query(
            "DELETE FROM drift_alerts WHERE project_id = ?",
            [self::$projectId]
        );
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        self::$db->query(
            "DELETE FROM drift_alerts WHERE project_id = ?",
            [self::$projectId]
        );
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'capacity_tripwire',
            'details_json' => json_encode(['growth_percent' => 25.0]),
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindActiveByProjectIdFiltersActive(): void
    {
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'capacity_tripwire',
            'status'       => 'active',
            'details_json' => json_encode(['note' => 'active alert']),
        ]);
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'dependency_tripwire',
            'status'       => 'acknowledged',
            'details_json' => json_encode(['note' => 'acknowledged alert']),
        ]);
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'alignment',
            'status'       => 'resolved',
            'details_json' => json_encode(['note' => 'resolved alert']),
        ]);

        $active = DriftAlert::findActiveByProjectId(self::$db, self::$projectId);

        $this->assertCount(1, $active);
        $this->assertSame('active', $active[0]['status']);
        $this->assertSame('capacity_tripwire', $active[0]['alert_type']);
    }

    #[Test]
    public function testFindByProjectIdReturnsAll(): void
    {
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'capacity_tripwire',
            'status'       => 'active',
            'details_json' => json_encode([]),
        ]);
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'dependency_tripwire',
            'status'       => 'resolved',
            'details_json' => json_encode([]),
        ]);

        $all = DriftAlert::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(2, $all);
    }

    #[Test]
    public function testFindByIdReturnsAlert(): void
    {
        $id = DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'alignment',
            'severity'     => 'critical',
            'details_json' => json_encode(['confidence' => 10]),
        ]);

        $alert = DriftAlert::findById(self::$db, $id);

        $this->assertNotNull($alert);
        $this->assertSame($id, (int) $alert['id']);
        $this->assertSame('alignment', $alert['alert_type']);
        $this->assertSame('critical', $alert['severity']);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateStatusChanges(): void
    {
        $id = DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'capacity_tripwire',
            'details_json' => json_encode([]),
        ]);

        DriftAlert::updateStatus(self::$db, $id, 'acknowledged');

        $alert = DriftAlert::findById(self::$db, $id);
        $this->assertSame('acknowledged', $alert['status']);
    }

    // ===========================
    // COUNT
    // ===========================

    #[Test]
    public function testCountActiveByProjectId(): void
    {
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'capacity_tripwire',
            'status'       => 'active',
            'details_json' => json_encode([]),
        ]);
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'dependency_tripwire',
            'status'       => 'active',
            'details_json' => json_encode([]),
        ]);
        DriftAlert::create(self::$db, [
            'project_id'   => self::$projectId,
            'alert_type'   => 'alignment',
            'status'       => 'resolved',
            'details_json' => json_encode([]),
        ]);

        $count = DriftAlert::countActiveByProjectId(self::$db, self::$projectId);

        $this->assertSame(2, $count);
    }
}
