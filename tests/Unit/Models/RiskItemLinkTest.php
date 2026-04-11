<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Risk;
use StratFlow\Models\RiskItemLink;

/**
 * RiskItemLinkTest
 *
 * Tests the risk_item_links junction table model against the real Docker MySQL database.
 * setUp creates a test org + user + project + HL work items + risks; tearDown removes all.
 */
class RiskItemLinkTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $workItemId1;
    private static int $workItemId2;
    private static int $riskId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - RiskItemLinkTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - RiskItemLinkTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - RiskItemLinkTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - RiskItemLinkTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_ril@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - RiskItemLinkTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Create HL work items used as link targets
        self::$workItemId1 = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Work Item 1 - RiskItemLinkTest',
        ]);
        self::$workItemId2 = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Work Item 2 - RiskItemLinkTest',
        ]);

        // Create a shared risk for read/delete tests
        self::$riskId = Risk::create(self::$db, [
            'project_id' => self::$projectId,
            'title'      => 'Shared Risk - RiskItemLinkTest',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: links -> risks -> work items -> projects -> users -> orgs
        self::$db->query(
            "DELETE ril FROM risk_item_links ril
             JOIN risks r ON ril.risk_id = r.id
             WHERE r.project_id = ?",
            [self::$projectId]
        );
        Risk::deleteByProjectId(self::$db, self::$projectId);
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Clean up links after each test
        RiskItemLink::deleteByRiskId(self::$db, self::$riskId);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateLinksAssociatesRiskWithItems(): void
    {
        RiskItemLink::createLinks(self::$db, self::$riskId, [self::$workItemId1, self::$workItemId2]);

        $linkedIds = array_map('intval', RiskItemLink::findByRiskId(self::$db, self::$riskId));

        $this->assertCount(2, $linkedIds);
        $this->assertContains(self::$workItemId1, $linkedIds);
        $this->assertContains(self::$workItemId2, $linkedIds);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByRiskIdReturnsLinkedItemIds(): void
    {
        RiskItemLink::createLinks(self::$db, self::$riskId, [self::$workItemId1]);

        $linkedIds = array_map('intval', RiskItemLink::findByRiskId(self::$db, self::$riskId));

        $this->assertCount(1, $linkedIds);
        $this->assertContains(self::$workItemId1, $linkedIds);
    }

    #[Test]
    public function testFindByWorkItemIdReturnsLinkedRiskIds(): void
    {
        RiskItemLink::createLinks(self::$db, self::$riskId, [self::$workItemId1]);

        $linkedRiskIds = array_map('intval', RiskItemLink::findByWorkItemId(self::$db, self::$workItemId1));

        $this->assertCount(1, $linkedRiskIds);
        $this->assertContains(self::$riskId, $linkedRiskIds);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteByRiskIdRemovesLinks(): void
    {
        RiskItemLink::createLinks(self::$db, self::$riskId, [self::$workItemId1, self::$workItemId2]);

        RiskItemLink::deleteByRiskId(self::$db, self::$riskId);

        $linkedIds = RiskItemLink::findByRiskId(self::$db, self::$riskId);
        $this->assertCount(0, $linkedIds);
    }

    // ===========================
    // CONSTRAINTS
    // ===========================

    #[Test]
    public function testUniqueConstraintPreventsDuplicates(): void
    {
        RiskItemLink::createLinks(self::$db, self::$riskId, [self::$workItemId1]);
        // INSERT IGNORE — second call should not throw or duplicate
        RiskItemLink::createLinks(self::$db, self::$riskId, [self::$workItemId1]);

        $linkedIds = RiskItemLink::findByRiskId(self::$db, self::$riskId);
        $this->assertCount(1, $linkedIds);
    }
}
