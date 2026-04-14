<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Risk;

/**
 * RiskTest
 *
 * Tests CRUD operations for the Risk model against the real Docker MySQL database.
 * setUp creates a test org + user + project; tearDown removes all test data.
 */
class RiskTest extends TestCase
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
             WHERE o.name = 'Test Org - RiskTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - RiskTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - RiskTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - RiskTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_risk@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - RiskTest', $userId]
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
        Risk::deleteByProjectId(self::$db, self::$projectId);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = Risk::create(self::$db, [
            'project_id' => self::$projectId,
            'title'      => 'Test risk',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByIdReturnsCreatedRisk(): void
    {
        $id = Risk::create(self::$db, [
            'project_id'  => self::$projectId,
            'title'       => 'Scope creep risk',
            'description' => 'Project scope may expand',
        ]);

        $risk = Risk::findById(self::$db, $id);

        $this->assertNotNull($risk);
        $this->assertSame('Scope creep risk', $risk['title']);
        $this->assertSame('Project scope may expand', $risk['description']);
    }

    #[Test]
    public function testFindByProjectIdReturnsAll(): void
    {
        Risk::create(self::$db, ['project_id' => self::$projectId, 'title' => 'Risk A']);
        Risk::create(self::$db, ['project_id' => self::$projectId, 'title' => 'Risk B']);
        Risk::create(self::$db, ['project_id' => self::$projectId, 'title' => 'Risk C']);

        $risks = Risk::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(3, $risks);
    }

    #[Test]
    public function testFindByIdReturnsNullForMissing(): void
    {
        $risk = Risk::findById(self::$db, 999999999);

        $this->assertNull($risk);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateChangesTitle(): void
    {
        $id = Risk::create(self::$db, [
            'project_id' => self::$projectId,
            'title'      => 'Original title',
        ]);

        Risk::update(self::$db, $id, ['title' => 'Updated title']);

        $risk = Risk::findById(self::$db, $id);
        $this->assertSame('Updated title', $risk['title']);
    }

    #[Test]
    public function testUpdateLikelihoodAndImpact(): void
    {
        $id = Risk::create(self::$db, [
            'project_id' => self::$projectId,
            'title'      => 'High impact risk',
            'likelihood' => 2,
            'impact'     => 2,
        ]);

        Risk::update(self::$db, $id, ['likelihood' => 5, 'impact' => 5]);

        $risk = Risk::findById(self::$db, $id);
        $this->assertSame('5', (string) $risk['likelihood']);
        $this->assertSame('5', (string) $risk['impact']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRemovesRisk(): void
    {
        $id = Risk::create(self::$db, [
            'project_id' => self::$projectId,
            'title'      => 'To be deleted',
        ]);

        Risk::delete(self::$db, $id);

        $this->assertNull(Risk::findById(self::$db, $id));
    }

    #[Test]
    public function testDeleteByProjectIdRemovesAll(): void
    {
        Risk::create(self::$db, ['project_id' => self::$projectId, 'title' => 'X']);
        Risk::create(self::$db, ['project_id' => self::$projectId, 'title' => 'Y']);

        Risk::deleteByProjectId(self::$db, self::$projectId);

        $risks = Risk::findByProjectId(self::$db, self::$projectId);
        $this->assertCount(0, $risks);
    }
}
