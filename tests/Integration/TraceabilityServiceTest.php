<?php
/**
 * TraceabilityServiceTest
 *
 * Integration tests for TraceabilityService::forProject against the real
 * Docker MySQL database. Verifies that the service correctly assembles the
 * OKR → work item → user story → git link traceability tree.
 *
 * Each test cleans up after itself via tearDownAfterClass.
 */

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\UserStory;
use StratFlow\Services\TraceabilityService;

class TraceabilityServiceTest extends TestCase
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
        self::$db = new Database(getTestDbConfig());

        // Clean up leftovers from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - TraceabilityServiceTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - TraceabilityServiceTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - TraceabilityServiceTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - TraceabilityServiceTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_tracea@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - TraceabilityServiceTest', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanProject();
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        self::cleanProject();
    }

    private static function cleanProject(): void
    {
        // Delete git links first, then stories, then work items
        self::$db->query(
            "DELETE sgl FROM story_git_links sgl
             JOIN user_stories us ON sgl.local_type = 'user_story' AND sgl.local_id = us.id
             WHERE us.project_id = ?",
            [self::$projectId]
        );
        self::$db->query(
            "DELETE sgl FROM story_git_links sgl
             JOIN hl_work_items hw ON sgl.local_type = 'hl_work_item' AND sgl.local_id = hw.id
             WHERE hw.project_id = ?",
            [self::$projectId]
        );
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
    }

    // ===========================
    // forProject — ownership
    // ===========================

    #[Test]
    public function testForProjectReturnsNullForWrongOrg(): void
    {
        $service = new TraceabilityService(self::$db);
        $result  = $service->forProject(self::$projectId, self::$orgId + 99999);

        $this->assertNull($result);
    }

    // ===========================
    // forProject — empty project
    // ===========================

    #[Test]
    public function testForProjectReturnsEmptyTreeWhenNoWorkItems(): void
    {
        $service = new TraceabilityService(self::$db);
        $tree    = $service->forProject(self::$projectId, self::$orgId);

        $this->assertNotNull($tree);
        $this->assertSame(self::$projectId, (int) $tree['project']['id']);
        $this->assertSame([], $tree['okrs']);
        $this->assertSame([], $tree['unlinked_stories']);
    }

    // ===========================
    // forProject — OKR grouping
    // ===========================

    #[Test]
    public function testForProjectGroupsWorkItemsByOkrTitle(): void
    {
        $wi1 = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Build Auth',
            'okr_title'       => 'Security',
        ]);
        $wi2 = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Add MFA',
            'okr_title'       => 'Security',
        ]);
        $wi3 = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 3,
            'title'           => 'Launch Dashboard',
            'okr_title'       => 'Growth',
        ]);

        $service = new TraceabilityService(self::$db);
        $tree    = $service->forProject(self::$projectId, self::$orgId);

        $this->assertCount(2, $tree['okrs']);

        $okrTitles = array_column($tree['okrs'], 'title');
        $this->assertContains('Security', $okrTitles);
        $this->assertContains('Growth', $okrTitles);

        // Find the Security bucket and verify it contains both work items
        $securityBucket = null;
        foreach ($tree['okrs'] as $bucket) {
            if ($bucket['title'] === 'Security') {
                $securityBucket = $bucket;
                break;
            }
        }
        $this->assertNotNull($securityBucket);
        $this->assertCount(2, $securityBucket['work_items']);
    }

    // ===========================
    // forProject — stories nested under work items
    // ===========================

    #[Test]
    public function testForProjectNestsStoriesUnderParentWorkItem(): void
    {
        $wiId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Build Auth',
        ]);
        $s1 = UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'Login story',
            'parent_hl_item_id' => $wiId,
        ]);
        $s2 = UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 2,
            'title'             => 'Logout story',
            'parent_hl_item_id' => $wiId,
        ]);

        $service = new TraceabilityService(self::$db);
        $tree    = $service->forProject(self::$projectId, self::$orgId);

        $this->assertCount(1, $tree['okrs']);
        $wiNodes = $tree['okrs'][0]['work_items'];
        $this->assertCount(1, $wiNodes);
        $this->assertCount(2, $wiNodes[0]['stories']);
        $this->assertSame(2, $wiNodes[0]['story_count']);
        $this->assertSame(0, $wiNodes[0]['done_count']);
    }

    // ===========================
    // forProject — done count rollup
    // ===========================

    #[Test]
    public function testForProjectCountsDoneStoriesInRollup(): void
    {
        $wiId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Build Auth',
        ]);
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'Done story',
            'parent_hl_item_id' => $wiId,
            'status'            => 'done',
        ]);
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 2,
            'title'             => 'In progress story',
            'parent_hl_item_id' => $wiId,
            'status'            => 'in_progress',
        ]);

        $service = new TraceabilityService(self::$db);
        $tree    = $service->forProject(self::$projectId, self::$orgId);

        $wiNode = $tree['okrs'][0]['work_items'][0];
        $this->assertSame(2, $wiNode['story_count']);
        $this->assertSame(1, $wiNode['done_count']);

        // OKR-level rollup
        $this->assertSame(2, $tree['okrs'][0]['story_count']);
        $this->assertSame(1, $tree['okrs'][0]['done_count']);
    }

    // ===========================
    // forProject — unlinked stories
    // ===========================

    #[Test]
    public function testForProjectPlacesOrphanStoriesInUnlinked(): void
    {
        // A work item exists but the story has no parent_hl_item_id
        HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Some WI',
        ]);
        UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Orphan story',
            // No parent_hl_item_id
        ]);

        $service = new TraceabilityService(self::$db);
        $tree    = $service->forProject(self::$projectId, self::$orgId);

        $this->assertCount(1, $tree['unlinked_stories']);
        $this->assertSame('Orphan story', $tree['unlinked_stories'][0]['story']['title']);
    }

    // ===========================
    // forProject — git link rollup
    // ===========================

    #[Test]
    public function testForProjectCountsGitLinksInRollup(): void
    {
        $wiId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Build Auth',
        ]);
        $storyId = UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'Login story',
            'parent_hl_item_id' => $wiId,
        ]);

        // Insert a git link for the story
        self::$db->query(
            "INSERT INTO story_git_links
             (local_type, local_id, provider, ref_type, ref_url, ref_label, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['user_story', $storyId, 'github', 'pr',
             'https://github.com/org/repo/pull/1', 'PR #1', 'open']
        );

        $service = new TraceabilityService(self::$db);
        $tree    = $service->forProject(self::$projectId, self::$orgId);

        $wiNode = $tree['okrs'][0]['work_items'][0];
        $this->assertSame(1, $wiNode['git_link_count']);
        $this->assertCount(1, $wiNode['stories'][0]['git_links']);
    }

    // ===========================
    // StoryGitLink::findByLocalItemsBulk
    // ===========================

    #[Test]
    public function testFindByLocalItemsBulkGroupsByLocalId(): void
    {
        $wiId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'WI for bulk test',
        ]);
        $s1 = UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'Story A',
            'parent_hl_item_id' => $wiId,
        ]);
        $s2 = UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 2,
            'title'             => 'Story B',
            'parent_hl_item_id' => $wiId,
        ]);

        self::$db->query(
            "INSERT INTO story_git_links
             (local_type, local_id, provider, ref_type, ref_url, ref_label, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['user_story', $s1, 'github', 'pr',
             'https://github.com/org/repo/pull/10', 'PR #10', 'open']
        );
        self::$db->query(
            "INSERT INTO story_git_links
             (local_type, local_id, provider, ref_type, ref_url, ref_label, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['user_story', $s1, 'github', 'commit',
             'https://github.com/org/repo/commit/abc1234', 'abc1234', 'unknown']
        );
        self::$db->query(
            "INSERT INTO story_git_links
             (local_type, local_id, provider, ref_type, ref_url, ref_label, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['user_story', $s2, 'github', 'pr',
             'https://github.com/org/repo/pull/11', 'PR #11', 'merged']
        );

        $map = StoryGitLink::findByLocalItemsBulk(self::$db, 'user_story', [$s1, $s2]);

        $this->assertArrayHasKey($s1, $map);
        $this->assertArrayHasKey($s2, $map);
        $this->assertCount(2, $map[$s1]);
        $this->assertCount(1, $map[$s2]);
    }

    #[Test]
    public function testFindByLocalItemsBulkReturnsEmptyForNoIds(): void
    {
        $map = StoryGitLink::findByLocalItemsBulk(self::$db, 'user_story', []);
        $this->assertSame([], $map);
    }

    #[Test]
    public function testFindByLocalItemsBulkReturnsEmptyForUnknownIds(): void
    {
        $map = StoryGitLink::findByLocalItemsBulk(self::$db, 'user_story', [999999999]);
        $this->assertSame([], $map);
    }
}
