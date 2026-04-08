<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;

/**
 * PrioritisationTest
 *
 * Tests RICE/WSJF scoring methods on HLWorkItem:
 * updateScores, findByProjectIdRankedByScore, and batchUpdateScores.
 */
class PrioritisationTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - PrioritisationTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - PrioritisationTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - PrioritisationTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - PrioritisationTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_prio@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Prio Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - PrioritisationTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
    }

    // ===========================
    // RICE SCORING
    // ===========================

    #[Test]
    public function testUpdateScoresSavesRiceValues(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'RICE Test Item',
        ]);

        HLWorkItem::updateScores(self::$db, $id, [
            'rice_reach'      => 500,
            'rice_impact'     => 3,
            'rice_confidence' => 80,
            'rice_effort'     => 2,
            'final_score'     => 60.00,
        ]);

        $item = HLWorkItem::findById(self::$db, $id);

        $this->assertSame('500', (string) $item['rice_reach']);
        $this->assertSame('3', (string) $item['rice_impact']);
        $this->assertSame('80', (string) $item['rice_confidence']);
        $this->assertSame('2', (string) $item['rice_effort']);
        $this->assertSame('60.00', number_format((float) $item['final_score'], 2));
    }

    // ===========================
    // WSJF SCORING
    // ===========================

    #[Test]
    public function testUpdateScoresSavesWsjfValues(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'WSJF Test Item',
        ]);

        HLWorkItem::updateScores(self::$db, $id, [
            'wsjf_business_value'   => 8,
            'wsjf_time_criticality' => 5,
            'wsjf_risk_reduction'   => 3,
            'wsjf_job_size'         => 4,
            'final_score'           => 80.00,
        ]);

        $item = HLWorkItem::findById(self::$db, $id);

        $this->assertSame('8', (string) $item['wsjf_business_value']);
        $this->assertSame('5', (string) $item['wsjf_time_criticality']);
        $this->assertSame('3', (string) $item['wsjf_risk_reduction']);
        $this->assertSame('4', (string) $item['wsjf_job_size']);
        $this->assertSame('80.00', number_format((float) $item['final_score'], 2));
    }

    // ===========================
    // RANKED FETCH
    // ===========================

    #[Test]
    public function testFindByProjectIdRankedByScoreOrdersDescending(): void
    {
        $lowId  = HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 3, 'title' => 'Low Score']);
        $highId = HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'High Score']);
        $midId  = HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Mid Score']);

        HLWorkItem::updateScores(self::$db, $lowId,  ['final_score' => 10.00]);
        HLWorkItem::updateScores(self::$db, $highId, ['final_score' => 90.00]);
        HLWorkItem::updateScores(self::$db, $midId,  ['final_score' => 50.00]);

        $items = HLWorkItem::findByProjectIdRankedByScore(self::$db, self::$projectId);

        $this->assertCount(3, $items);
        $this->assertSame('High Score', $items[0]['title']);
        $this->assertSame('Mid Score',  $items[1]['title']);
        $this->assertSame('Low Score',  $items[2]['title']);
    }

    // ===========================
    // BATCH SCORING
    // ===========================

    #[Test]
    public function testBatchUpdateScoresSavesMultiple(): void
    {
        $idA = HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'Batch A']);
        $idB = HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Batch B']);

        HLWorkItem::batchUpdateScores(self::$db, [
            ['id' => $idA, 'scores' => ['final_score' => 70.00, 'rice_reach' => 100]],
            ['id' => $idB, 'scores' => ['final_score' => 30.00, 'rice_reach' => 50]],
        ]);

        $itemA = HLWorkItem::findById(self::$db, $idA);
        $itemB = HLWorkItem::findById(self::$db, $idB);

        $this->assertSame('70.00', number_format((float) $itemA['final_score'], 2));
        $this->assertSame('100', (string) $itemA['rice_reach']);
        $this->assertSame('30.00', number_format((float) $itemB['final_score'], 2));
        $this->assertSame('50', (string) $itemB['rice_reach']);
    }
}
