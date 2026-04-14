<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Team;

/**
 * TeamTest
 *
 * Tests CRUD operations on the `teams` table against the real Docker MySQL database.
 * setUp inserts a test org + user; tearDown removes all test data in FK-safe order.
 */
class TeamTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE t FROM teams t
             JOIN organisations o ON t.org_id = o.id
             WHERE o.name = 'Test Org - TeamTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - TeamTest'");

        // Insert a test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - TeamTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // Teams CASCADE-delete when org is deleted
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Remove all teams created during the test to prevent state leaking
        self::$db->query("DELETE FROM teams WHERE org_id = ?", [self::$orgId]);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = Team::create(self::$db, [
            'org_id' => self::$orgId,
            'name'   => 'Engineering',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    #[Test]
    public function testCreatedTeamCanBeFoundById(): void
    {
        $id = Team::create(self::$db, [
            'org_id'      => self::$orgId,
            'name'        => 'Design',
            'description' => 'UI/UX team',
            'capacity'    => 10,
        ]);

        $team = Team::findById(self::$db, $id);

        $this->assertNotNull($team);
        $this->assertSame('Design', $team['name']);
        $this->assertSame('UI/UX team', $team['description']);
        $this->assertSame('10', (string) $team['capacity']);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByOrgIdReturnsAllTeamsForOrg(): void
    {
        Team::create(self::$db, ['org_id' => self::$orgId, 'name' => 'Alpha']);
        Team::create(self::$db, ['org_id' => self::$orgId, 'name' => 'Beta']);
        Team::create(self::$db, ['org_id' => self::$orgId, 'name' => 'Gamma']);

        $teams = Team::findByOrgId(self::$db, self::$orgId);

        $this->assertCount(3, $teams);
    }

    #[Test]
    public function testFindByIdReturnsNullForMissingRecord(): void
    {
        $team = Team::findById(self::$db, 999999999);
        $this->assertNull($team);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateChangesNameAndCapacity(): void
    {
        $id = Team::create(self::$db, [
            'org_id'   => self::$orgId,
            'name'     => 'Old Name',
            'capacity' => 5,
        ]);

        Team::update(self::$db, $id, ['name' => 'New Name', 'capacity' => 12]);

        $team = Team::findById(self::$db, $id);
        $this->assertSame('New Name', $team['name']);
        $this->assertSame('12', (string) $team['capacity']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRemovesTeam(): void
    {
        $id = Team::create(self::$db, [
            'org_id' => self::$orgId,
            'name'   => 'To Be Deleted',
        ]);

        Team::delete(self::$db, $id);

        $this->assertNull(Team::findById(self::$db, $id));
    }
}
