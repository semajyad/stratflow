<?php
declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Services\GeminiService;
use StratFlow\Services\GitPrMatcherService;

class GitPrMatcherServiceTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $storyId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query(
            "DELETE FROM story_git_links WHERE local_type = 'user_story' AND local_id IN (
                SELECT us.id
                FROM user_stories us
                JOIN projects p ON p.id = us.project_id
                JOIN organisations o ON o.id = p.org_id
                WHERE o.name = 'Test Org - GitPrMatcherServiceTest'
            )"
        );
        self::$db->query(
            "DELETE FROM user_stories WHERE project_id IN (
                SELECT p.id
                FROM projects p
                JOIN organisations o ON o.id = p.org_id
                WHERE o.name = 'Test Org - GitPrMatcherServiceTest'
            )"
        );
        self::$db->query(
            "DELETE FROM projects WHERE org_id IN (
                SELECT id FROM organisations WHERE name = 'Test Org - GitPrMatcherServiceTest'
            )"
        );
        self::$db->query(
            "DELETE FROM users WHERE org_id IN (
                SELECT id FROM organisations WHERE name = 'Test Org - GitPrMatcherServiceTest'
            )"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - GitPrMatcherServiceTest'");
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - GitPrMatcherServiceTest']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'matcher@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'Matcher', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, created_by, name, status) VALUES (?, ?, ?, ?)",
            [self::$orgId, $userId, 'Matcher Project', 'active']
        );
        self::$projectId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO user_stories (project_id, priority_number, title, description, status, size)
             VALUES (?, ?, ?, ?, ?, ?)",
            [self::$projectId, 1, 'Improve checkout flow', 'Reduce steps in checkout', 'backlog', 3]
        );
        self::$storyId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM story_git_links WHERE ref_url LIKE 'https://github.com/test-matcher/%'");
        self::$db->query("DELETE FROM user_stories WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    #[Test]
    public function testReturnsZeroWhenGeminiIsNull(): void
    {
        $service = new GitPrMatcherService(self::$db, null);
        $result  = $service->matchAndLink(
            'Fix checkout',
            'Removes unnecessary steps',
            'feat/checkout',
            'https://github.com/test-matcher/repo/pull/1',
            self::$orgId
        );
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testDoesNotLinkBelowConfidenceThreshold(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            ['id' => self::$storyId, 'type' => 'user_story', 'confidence' => 0.5],
        ]);

        $service = new GitPrMatcherService(self::$db, $gemini);
        $result  = $service->matchAndLink(
            'Fix checkout',
            'Removes unnecessary steps',
            'feat/checkout',
            'https://github.com/test-matcher/repo/pull/2',
            self::$orgId
        );
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testLinksAboveThresholdWithAiMatchedFlag(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            ['id' => self::$storyId, 'type' => 'user_story', 'confidence' => 0.85],
        ]);

        $service = new GitPrMatcherService(self::$db, $gemini);
        $prUrl   = 'https://github.com/test-matcher/repo/pull/3';
        $result  = $service->matchAndLink(
            'Fix checkout', 'Removes unnecessary steps', 'feat/checkout', $prUrl, self::$orgId
        );

        $this->assertSame(1, $result);

        $row = self::$db->query(
            "SELECT ai_matched FROM story_git_links WHERE ref_url = ? LIMIT 1",
            [$prUrl]
        )->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['ai_matched']);
    }
}
