<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\EvaluationResult;
use StratFlow\Models\PersonaPanel;

/**
 * EvaluationResultTest
 *
 * Tests CRUD operations for the EvaluationResult model against the real Docker MySQL database.
 * setUpBeforeClass creates a test org, user, project, and persona panel.
 * tearDownAfterClass removes all test data in FK-safe order.
 * Per-test tearDown deletes created evaluation_results so tests remain independent.
 */
class EvaluationResultTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $userId;
    private static int $projectId;
    private static int $panelId;

    /** Minimal valid results_json for every INSERT */
    private const RESULTS_JSON = '[{"role":"CEO","feedback":"Test feedback","status":"pending"}]';

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - EvaluationResultTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - EvaluationResultTest'");

        // Create test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - EvaluationResultTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        // Create a test user
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'evalresult_test@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();

        // Create a test project
        self::$db->query(
            "INSERT INTO projects (org_id, name, status, created_by) VALUES (?, ?, ?, ?)",
            [self::$orgId, 'Test Project - EvaluationResultTest', 'draft', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Create a test persona panel (system default — org_id NULL)
        self::$panelId = PersonaPanel::create(self::$db, [
            'org_id'     => null,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - EvaluationResultTest',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        // evaluation_results CASCADE-deletes when project or panel is deleted
        self::$db->query("DELETE FROM persona_panels WHERE id = ?", [self::$panelId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Remove all evaluation results for the test project to avoid state leaking between tests
        self::$db->query(
            "DELETE FROM evaluation_results WHERE project_id = ?",
            [self::$projectId]
        );
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'prioritisation',
            'results_json'     => self::RESULTS_JSON,
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function testFindByProjectIdReturnsResults(): void
    {
        EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'prioritisation',
            'results_json'     => self::RESULTS_JSON,
        ]);
        EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'red_teaming',
            'screen_context'   => 'strategy',
            'results_json'     => self::RESULTS_JSON,
        ]);

        $results = EvaluationResult::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(2, $results);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function testFindByIdReturnsResult(): void
    {
        $id = EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'gordon_ramsay',
            'screen_context'   => 'work_items',
            'results_json'     => self::RESULTS_JSON,
        ]);

        $result = EvaluationResult::findById(self::$db, $id);

        $this->assertNotNull($result);
        $this->assertSame((string) self::$projectId, (string) $result['project_id']);
        $this->assertSame('gordon_ramsay', $result['evaluation_level']);
        $this->assertSame('work_items', $result['screen_context']);
    }

    // ===========================
    // FIND BY PROJECT AND SCREEN
    // ===========================

    #[Test]
    public function testFindByProjectAndScreenFilters(): void
    {
        EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'prioritisation',
            'results_json'     => self::RESULTS_JSON,
        ]);
        EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'red_teaming',
            'screen_context'   => 'strategy',
            'results_json'     => self::RESULTS_JSON,
        ]);

        $filtered = EvaluationResult::findByProjectAndScreen(self::$db, self::$projectId, 'prioritisation');

        $this->assertCount(1, $filtered);
        $this->assertSame('prioritisation', $filtered[0]['screen_context']);
    }

    // ===========================
    // UPDATE STATUS
    // ===========================

    #[Test]
    public function testUpdateStatusChangesStatus(): void
    {
        $id = EvaluationResult::create(self::$db, [
            'project_id'       => self::$projectId,
            'panel_id'         => self::$panelId,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'risks',
            'results_json'     => self::RESULTS_JSON,
            'status'           => 'pending',
        ]);

        EvaluationResult::updateStatus(self::$db, $id, 'accepted');

        $result = EvaluationResult::findById(self::$db, $id);
        $this->assertSame('accepted', $result['status']);
    }
}
