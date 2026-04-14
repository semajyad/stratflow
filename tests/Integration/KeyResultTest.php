<?php
declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\KeyResult;

class KeyResultTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $workItemId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any stale data from previous runs (respect FK order)
        self::$db->query(
            "DELETE krc FROM key_result_contributions krc
               JOIN key_results kr ON krc.key_result_id = kr.id
               JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
               JOIN projects p ON hwi.project_id = p.id
               JOIN organisations o ON p.org_id = o.id
              WHERE o.name = 'Test Org - KeyResultTest'"
        );
        self::$db->query(
            "DELETE kr FROM key_results kr
               JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
               JOIN projects p ON hwi.project_id = p.id
               JOIN organisations o ON p.org_id = o.id
              WHERE o.name = 'Test Org - KeyResultTest'"
        );
        $stale = self::$db->query(
            "SELECT id FROM organisations WHERE name = 'Test Org - KeyResultTest'"
        )->fetchAll();
        foreach ($stale as $row) {
            $sid = (int) $row['id'];
            self::$db->query("DELETE FROM hl_work_items WHERE project_id IN (SELECT id FROM projects WHERE org_id = ?)", [$sid]);
            self::$db->query("DELETE FROM projects WHERE org_id = ?", [$sid]);
            self::$db->query("DELETE FROM users WHERE org_id = ?", [$sid]);
            self::$db->query("DELETE FROM organisations WHERE id = ?", [$sid]);
        }
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - KeyResultTest']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'krt@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'KR Tester', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, created_by, name, status) VALUES (?, ?, ?, ?)",
            [self::$orgId, $userId, 'KR Test Project', 'active']
        );
        self::$projectId = (int) self::$db->lastInsertId();

        self::$workItemId = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 1,
            'title'            => 'OKR Work Item',
            'okr_title'        => 'Grow Revenue',
            'estimated_sprints'=> 2,
            'status'           => 'backlog',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM key_result_contributions WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM key_results WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    #[Test]
    public function testCreateAndFindByWorkItemId(): void
    {
        $id = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Increase MRR to $50k',
            'target_value'    => 50000.0,
            'unit'            => '$',
            'status'          => 'not_started',
        ]);

        $krs = KeyResult::findByWorkItemId(self::$db, self::$workItemId, self::$orgId);
        $this->assertCount(1, $krs);
        $this->assertSame('Increase MRR to $50k', $krs[0]['title']);
        $this->assertSame(self::$orgId, (int) $krs[0]['org_id']);

        KeyResult::delete(self::$db, $id, self::$orgId);
    }

    #[Test]
    public function testOrgIsolation(): void
    {
        $id = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Org A KR',
            'status'          => 'not_started',
        ]);

        // Different org_id must return empty
        $krs = KeyResult::findByWorkItemId(self::$db, self::$workItemId, self::$orgId + 9999);
        $this->assertCount(0, $krs);

        KeyResult::delete(self::$db, $id, self::$orgId);
    }

    #[Test]
    public function testCascadeDeleteWhenWorkItemDeleted(): void
    {
        $tempItemId = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 99,
            'title'            => 'Temp Item',
            'estimated_sprints'=> 1,
            'status'           => 'backlog',
        ]);

        KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => $tempItemId,
            'title'           => 'Cascade Test KR',
            'status'          => 'not_started',
        ]);

        // Delete work item via SQL — triggers ON DELETE CASCADE
        self::$db->query("DELETE FROM hl_work_items WHERE id = ?", [$tempItemId]);

        $krs = KeyResult::findByWorkItemId(self::$db, $tempItemId, self::$orgId);
        $this->assertCount(0, $krs);
    }

    #[Test]
    public function testUpdate(): void
    {
        $id = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Before Update',
            'status'          => 'not_started',
        ]);

        KeyResult::update(self::$db, $id, self::$orgId, ['title' => 'After Update', 'status' => 'on_track']);

        $kr = KeyResult::findById(self::$db, $id, self::$orgId);
        $this->assertSame('After Update', $kr['title']);
        $this->assertSame('on_track', $kr['status']);

        KeyResult::delete(self::$db, $id, self::$orgId);
    }
}
