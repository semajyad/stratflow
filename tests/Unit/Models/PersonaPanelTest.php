<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PersonaPanel;

/**
 * PersonaPanelTest
 *
 * Tests CRUD operations for the PersonaPanel model against the real Docker MySQL database.
 * setUpBeforeClass creates a test org; tearDownAfterClass removes all test data in FK-safe order.
 * Per-test tearDown deletes created panels so tests remain independent.
 */
class PersonaPanelTest extends TestCase
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
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - PersonaPanelTest'");

        // Create a test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - PersonaPanelTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // persona_panels CASCADE-deletes when org is deleted
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);

        // Remove any system-default panels (org_id IS NULL) created by tests
        self::$db->query(
            "DELETE FROM persona_panels WHERE org_id IS NULL AND name LIKE 'Test Panel%'"
        );
    }

    protected function tearDown(): void
    {
        // Remove all panels created for the test org to avoid state leaking between tests
        self::$db->query(
            "DELETE FROM persona_panels WHERE org_id = ?",
            [self::$orgId]
        );
        // Also clean up any null-org test panels
        self::$db->query(
            "DELETE FROM persona_panels WHERE org_id IS NULL AND name LIKE 'Test Panel%'"
        );
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - Executive',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // FIND BY ORG ID
    // ===========================

    #[Test]
    public function testFindByOrgIdReturnsOrgPanels(): void
    {
        PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - Executive',
        ]);
        PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'product_management',
            'name'       => 'Test Panel - PM',
        ]);

        $panels = PersonaPanel::findByOrgId(self::$db, self::$orgId);

        $this->assertCount(2, $panels);
    }

    // ===========================
    // FIND DEFAULTS
    // ===========================

    #[Test]
    public function testFindDefaultsReturnsNullOrgPanels(): void
    {
        // Create an org-specific panel — must NOT appear in defaults
        PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - Org Executive',
        ]);

        // Create a system-default panel (org_id = NULL)
        self::$db->query(
            "INSERT INTO persona_panels (org_id, panel_type, name) VALUES (NULL, 'executive', 'Test Panel - Default')"
        );

        $defaults = PersonaPanel::findDefaults(self::$db);

        // All returned rows must have org_id === null
        foreach ($defaults as $panel) {
            $this->assertNull($panel['org_id']);
        }

        // At least one row must be the one we just inserted
        $names = array_column($defaults, 'name');
        $this->assertContains('Test Panel - Default', $names);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function testFindByIdReturnsPanel(): void
    {
        $id = PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - FindById',
        ]);

        $panel = PersonaPanel::findById(self::$db, $id);

        $this->assertNotNull($panel);
        $this->assertSame('Test Panel - FindById', $panel['name']);
        $this->assertSame((string) self::$orgId, (string) $panel['org_id']);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateChangesName(): void
    {
        $id = PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - Original Name',
        ]);

        PersonaPanel::update(self::$db, $id, ['name' => 'Test Panel - Updated Name']);

        $panel = PersonaPanel::findById(self::$db, $id);
        $this->assertSame('Test Panel - Updated Name', $panel['name']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRemovesPanel(): void
    {
        $id = PersonaPanel::create(self::$db, [
            'org_id'     => self::$orgId,
            'panel_type' => 'executive',
            'name'       => 'Test Panel - To Delete',
        ]);

        PersonaPanel::delete(self::$db, $id);

        $panel = PersonaPanel::findById(self::$db, $id);
        $this->assertNull($panel);
    }
}
