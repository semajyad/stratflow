<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\PersonaMember;
use StratFlow\Models\PersonaPanel;
use StratFlow\Services\PanelResolverService;

class PanelResolverServiceTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(): Database
    {
        return $this->createMock(Database::class);
    }

    private function makeStmt(mixed $fetchResult = false, array $fetchAllResult = []): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchResult);
        $stmt->method('fetchAll')->willReturn($fetchAllResult);
        return $stmt;
    }

    private function panelRow(string $type = 'executive'): array
    {
        return ['id' => 1, 'org_id' => null, 'panel_type' => $type, 'name' => 'Test Panel', 'created_at' => '2026-01-01'];
    }

    private function memberRow(): array
    {
        return ['id' => 1, 'panel_id' => 1, 'role_title' => 'CEO', 'prompt_description' => 'Strategic focus'];
    }

    // ===========================
    // resolve() — org panel found
    // ===========================

    public function testResolveReturnsExistingOrgPanel(): void
    {
        $panelRow = $this->panelRow('executive');
        $db       = $this->makeDb();

        // findByOrgId returns the panel; findDefaults never called; findById not needed
        $db->method('query')->willReturnCallback(function (string $sql) use ($panelRow) {
            $stmt = $this->createMock(\PDOStatement::class);
            if (str_contains($sql, 'org_id = :org_id')) {
                $stmt->method('fetchAll')->willReturn([$panelRow]);
            } else {
                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetch')->willReturn(false);
            }
            return $stmt;
        });

        $service = new PanelResolverService($db);
        $result  = $service->resolve(1, 'executive');

        $this->assertSame('executive', $result['panel_type']);
    }

    // ===========================
    // resolve() — falls through to system default
    // ===========================

    public function testResolveReturnsSystemDefaultWhenNoOrgPanel(): void
    {
        $panelRow = $this->panelRow('product_management');
        $db       = $this->makeDb();

        $db->method('query')->willReturnCallback(function (string $sql) use ($panelRow) {
            $stmt = $this->createMock(\PDOStatement::class);
            if (str_contains($sql, 'org_id = :org_id')) {
                // Org has no panels
                $stmt->method('fetchAll')->willReturn([]);
            } elseif (str_contains($sql, 'org_id IS NULL')) {
                // System default exists
                $stmt->method('fetchAll')->willReturn([$panelRow]);
            } else {
                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetch')->willReturn(false);
            }
            return $stmt;
        });

        $service = new PanelResolverService($db);
        $result  = $service->resolve(99, 'product_management');

        $this->assertSame('product_management', $result['panel_type']);
    }

    // ===========================
    // resolve() — seeds when nothing exists
    // ===========================

    public function testResolveSeededDefaultWhenNoExistingPanels(): void
    {
        $seededPanel = $this->panelRow('executive');
        $db          = $this->makeDb();
        $callCount   = 0;

        $db->method('query')->willReturnCallback(function (string $sql) use ($seededPanel, &$callCount) {
            $stmt = $this->createMock(\PDOStatement::class);
            $callCount++;

            if (str_contains($sql, 'org_id = :org_id')) {
                // No org panels
                $stmt->method('fetchAll')->willReturn([]);
            } elseif (str_contains($sql, 'org_id IS NULL') && str_contains($sql, 'SELECT')) {
                // No system defaults initially
                $stmt->method('fetchAll')->willReturn([]);
            } elseif (str_contains($sql, 'INSERT INTO persona_panels')) {
                // Panel created — lastInsertId handled separately
            } elseif (str_contains($sql, 'INSERT INTO persona_members')) {
                // Members created
            } elseif (str_contains($sql, 'WHERE id = :id')) {
                // findById returns the seeded panel
                $stmt->method('fetch')->willReturn($seededPanel);
            } else {
                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetch')->willReturn(false);
            }
            return $stmt;
        });

        $db->method('lastInsertId')->willReturn('1');

        $service = new PanelResolverService($db);
        $result  = $service->resolve(99, 'executive');

        $this->assertSame('executive', $result['panel_type']);
    }

    // ===========================
    // resolveWithMembers()
    // ===========================

    public function testResolveWithMembersReturnsPanelAndMembers(): void
    {
        $panelRow  = $this->panelRow('executive');
        $memberRow = $this->memberRow();
        $db        = $this->makeDb();

        $db->method('query')->willReturnCallback(function (string $sql) use ($panelRow, $memberRow) {
            $stmt = $this->createMock(\PDOStatement::class);
            if (str_contains($sql, 'org_id = :org_id')) {
                $stmt->method('fetchAll')->willReturn([$panelRow]);
            } elseif (str_contains($sql, 'panel_id = :panel_id')) {
                $stmt->method('fetchAll')->willReturn([$memberRow]);
            } else {
                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetch')->willReturn(false);
            }
            return $stmt;
        });

        $service           = new PanelResolverService($db);
        [$panel, $members] = $service->resolveWithMembers(1, 'executive');

        $this->assertSame('executive', $panel['panel_type']);
        $this->assertCount(1, $members);
        $this->assertSame('CEO', $members[0]['role_title']);
    }
}
