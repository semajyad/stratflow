<?php
declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\KeyResult;
use StratFlow\Models\KeyResultContribution;
use StratFlow\Services\GeminiService;
use StratFlow\Services\KrScoringService;

class KrScoringServiceTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $orgBId;
    private static int $projectId;
    private static int $workItemId;
    private static int $krId;
    private static int $linkId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query(
            "DELETE FROM key_result_contributions WHERE org_id IN (
                SELECT id FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')
            )"
        );
        self::$db->query(
            "DELETE FROM key_results WHERE org_id IN (
                SELECT id FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')
            )"
        );
        self::$db->query("DELETE FROM story_git_links WHERE ref_url LIKE 'https://github.com/test-score/%'");
        self::$db->query(
            "DELETE FROM hl_work_items WHERE project_id IN (
                SELECT p.id
                FROM projects p
                JOIN organisations o ON o.id = p.org_id
                WHERE o.name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')
            )"
        );
        self::$db->query(
            "DELETE FROM projects WHERE org_id IN (
                SELECT id FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')
            )"
        );
        self::$db->query(
            "DELETE FROM users WHERE org_id IN (
                SELECT id FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')
            )"
        );
        self::$db->query("DELETE FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')");

        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - KrScoringA']);
        self::$orgId = (int) self::$db->lastInsertId();
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'krscore@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'Scorer', 'user']
        );
        $userId = (int) self::$db->lastInsertId();
        self::$db->query(
            "INSERT INTO projects (org_id, created_by, name, status) VALUES (?, ?, ?, ?)",
            [self::$orgId, $userId, 'KR Score Project', 'active']
        );
        self::$projectId = (int) self::$db->lastInsertId();

        self::$workItemId = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 1,
            'title'            => 'Checkout Improvement',
            'okr_title'        => 'Grow Revenue',
            'estimated_sprints'=> 2,
            'status'           => 'in_progress',
        ]);

        self::$krId = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Increase conversion to 5%',
            'target_value'    => 5.0,
            'unit'            => '%',
            'status'          => 'not_started',
        ]);

        self::$db->query(
            "INSERT INTO story_git_links (local_type, local_id, provider, ref_url, ref_label, ref_type, status)
             VALUES ('hl_work_item', ?, 'github', ?, ?, 'pr', 'merged')",
            [self::$workItemId, 'https://github.com/test-score/repo/pull/99', 'Reduce checkout steps']
        );
        self::$linkId = (int) self::$db->lastInsertId();

        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - KrScoringB']);
        self::$orgBId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM key_result_contributions WHERE org_id IN (?, ?)", [self::$orgId, self::$orgBId]);
        self::$db->query("DELETE FROM key_results WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM story_git_links WHERE ref_url LIKE 'https://github.com/test-score/%'");
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')");
    }

    #[Test]
    public function testScoresClamped0To10(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn(['score' => 15, 'rationale' => 'Directly reduces checkout friction']);

        $service = new KrScoringService(self::$db, $gemini);
        $service->scoreForMergedPr('https://github.com/test-score/repo/pull/99', self::$orgId);

        $contribs = KeyResultContribution::findByKeyResultId(self::$db, self::$krId, self::$orgId);
        $this->assertNotEmpty($contribs);
        $this->assertLessThanOrEqual(10, (int) $contribs[0]['ai_relevance_score']);
    }

    #[Test]
    public function testDoesNotScoreIfNoKrsExistForWorkItem(): void
    {
        $itemId = HLWorkItem::create(self::$db, [
            'project_id' => self::$projectId, 'priority_number' => 99,
            'title' => 'No KR Item', 'estimated_sprints' => 1, 'status' => 'backlog',
        ]);
        self::$db->query(
            "INSERT INTO story_git_links (local_type, local_id, provider, ref_url, ref_label, ref_type, status)
             VALUES ('hl_work_item', ?, 'github', ?, ?, 'pr', 'merged')",
            [$itemId, 'https://github.com/test-score/repo/pull/100', 'No-KR PR']
        );

        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateJson');

        $service = new KrScoringService(self::$db, $gemini);
        $service->scoreForMergedPr('https://github.com/test-score/repo/pull/100', self::$orgId);

        self::$db->query("DELETE FROM story_git_links WHERE ref_url = ?", ['https://github.com/test-score/repo/pull/100']);
        self::$db->query("DELETE FROM hl_work_items WHERE id = ?", [$itemId]);
    }

    #[Test]
    public function testOrgIsolation(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateJson');

        $service = new KrScoringService(self::$db, $gemini);
        $service->scoreForMergedPr('https://github.com/test-score/repo/pull/99', self::$orgBId);

        $contribs = KeyResultContribution::findByKeyResultId(self::$db, self::$krId, self::$orgBId);
        $this->assertEmpty($contribs);
    }
}
