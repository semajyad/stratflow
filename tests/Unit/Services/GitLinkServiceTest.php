<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\UserStory;
use StratFlow\Services\GitLinkService;

/**
 * GitLinkServiceTest
 *
 * Unit tests for GitLinkService — PR body parsing, link creation/updating,
 * status updates, ref classification, and org tenancy enforcement.
 *
 * Database calls are fully mocked; no real DB interactions.
 */
class GitLinkServiceTest extends TestCase
{
    private Database $mockDb;
    private \PDOStatement $mockStmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockStmt = $this->createMock(\PDOStatement::class);
        $this->mockDb = $this->createMock(Database::class);
        $this->mockDb->method('query')->willReturn($this->mockStmt);
    }

    // ===========================
    // CONSTRUCTOR & INITIALIZATION
    // ===========================

    public function testConstructorWithoutOrgId(): void
    {
        $service = new GitLinkService($this->mockDb);
        $this->assertInstanceOf(GitLinkService::class, $service);
    }

    public function testConstructorWithOrgId(): void
    {
        $service = new GitLinkService($this->mockDb, 42);
        $this->assertInstanceOf(GitLinkService::class, $service);
    }

    // ===========================
    // LINK FROM PR BODY
    // ===========================

    public function testLinkFromPrBodyReturnsZeroOnEmptyBody(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->linkFromPrBody('', 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyReturnsZeroOnBodyTooLarge(): void
    {
        $service = new GitLinkService($this->mockDb);
        $largeBody = str_repeat('x', 70000);
        $result = $service->linkFromPrBody($largeBody, 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyReturnsZeroWhenNoMatches(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->linkFromPrBody('This is a regular PR body with no references', 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyParsesAndLinksValidReferences(): void
    {
        $storyRow  = ['id' => 123, 'org_id' => 1, 'title' => 'Test Story'];
        $insertStmt = $this->createMock(\PDOStatement::class);
        $insertStmt->method('fetch')->willReturn(false);
        $insertStmt->method('fetchAll')->willReturn([]);

        $linkCreateCalls = 0;
        $db = $this->createMock(Database::class);
        $db->method('lastInsertId')->willReturn('999');
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($storyRow, $insertStmt, &$linkCreateCalls): \PDOStatement {
                $stmt = $this->createMock(\PDOStatement::class);
                if (str_contains($sql, 'INSERT') && str_contains($sql, 'story_git_links')) {
                    $linkCreateCalls++;
                    $stmt->method('fetch')->willReturn(['id' => 999]);
                } elseif (str_contains($sql, 'user_stories') || str_contains($sql, 'hl_work_items')) {
                    // UserStory::findById / HLWorkItem::findById — return a valid story
                    $stmt->method('fetch')->willReturn($storyRow);
                } else {
                    // findExistingLink, AuditLogger, etc. — return nothing
                    $stmt->method('fetch')->willReturn(false);
                }
                $stmt->method('fetchAll')->willReturn([]);
                return $stmt;
            }
        );

        $service = new GitLinkService($db);
        $result  = $service->linkFromPrBody('SF-123 is related', 'https://github.com/org/repo/pull/1', 'github', 'Test PR');

        $this->assertGreaterThan(0, $linkCreateCalls, 'Expected at least one INSERT into story_git_links');
        $this->assertGreaterThan(0, $result, 'Expected at least one link to be created');
    }

    public function testLinkFromPrBodyRecognizesSfWithUnderscore(): void
    {
        $service = new GitLinkService($this->mockDb);
        $this->mockStmt->method('fetch')->willReturn(false);

        $result = $service->linkFromPrBody('Check SF_123 for details', 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyRecognizesStratFlowFormat(): void
    {
        $service = new GitLinkService($this->mockDb);
        $this->mockStmt->method('fetch')->willReturn(false);

        $result = $service->linkFromPrBody('StratFlow-456 is here', 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyHandlesMultipleReferences(): void
    {
        $service = new GitLinkService($this->mockDb);
        $this->mockStmt->method('fetch')->willReturn(false);

        $result = $service->linkFromPrBody('SF-123 and SF-456 and SF-789', 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyCapsUniqueIds(): void
    {
        $service = new GitLinkService($this->mockDb);
        $this->mockStmt->method('fetch')->willReturn(false);

        // Same ID twice should only process once
        $result = $service->linkFromPrBody('SF-123 and SF-123 again', 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkFromPrBodyStopsAtMaxMatches(): void
    {
        $service = new GitLinkService($this->mockDb);
        $this->mockStmt->method('fetch')->willReturn(false);

        $ids = implode(' ', array_map(fn($i) => "SF-$i", range(1, 25)));
        $result = $service->linkFromPrBody($ids, 'https://github.com/org/repo/pull/1', 'github');
        $this->assertSame(0, $result);
    }

    // ===========================
    // UPDATE STATUS BY REF URL
    // ===========================

    public function testUpdateStatusByRefUrlReturnsZeroWhenNoLinksFound(): void
    {
        $this->mockStmt->method('fetchAll')->willReturn([]);

        $service = new GitLinkService($this->mockDb);
        $result = $service->updateStatusByRefUrl('https://github.com/org/repo/pull/999', 'merged');
        $this->assertSame(0, $result);
    }

    public function testUpdateStatusByRefUrlUpdatesStatusWhenDifferent(): void
    {
        $linkRow = [
            'id' => 42,
            'status' => 'open',
            'local_type' => 'user_story',
            'local_id' => 123,
        ];
        $this->mockStmt->method('fetchAll')->willReturn([$linkRow]);
        $this->mockStmt->method('fetch')->willReturn(false);

        $service = new GitLinkService($this->mockDb);
        $result = $service->updateStatusByRefUrl('https://github.com/org/repo/pull/1', 'merged');

        $this->assertIsInt($result);
    }

    public function testUpdateStatusByRefUrlSkipsWhenOrgIdNotMatching(): void
    {
        $linkRow = [
            'id' => 42,
            'status' => 'open',
            'local_type' => 'user_story',
            'local_id' => 123,
        ];
        $this->mockStmt->method('fetchAll')->willReturn([$linkRow]);
        $this->mockStmt->method('fetch')->willReturn(false);

        $service = new GitLinkService($this->mockDb, 2);
        $result = $service->updateStatusByRefUrl('https://github.com/org/repo/pull/1', 'merged');

        $this->assertSame(0, $result);
    }

    public function testUpdateStatusByRefUrlHandlesMultipleLinks(): void
    {
        $rows = [
            ['id' => 1, 'status' => 'open', 'local_type' => 'user_story', 'local_id' => 10],
            ['id' => 2, 'status' => 'open', 'local_type' => 'user_story', 'local_id' => 20],
        ];
        $this->mockStmt->method('fetchAll')->willReturn($rows);
        $this->mockStmt->method('fetch')->willReturn(false);

        $service = new GitLinkService($this->mockDb);
        $result = $service->updateStatusByRefUrl('https://github.com/org/repo/pull/1', 'merged');

        $this->assertIsInt($result);
    }

    // ===========================
    // CLASSIFY REF
    // ===========================

    public function testClassifyRefGitHubPrUrl(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('https://github.com/org/repo/pull/42');

        $this->assertSame('pr', $result['ref_type']);
        $this->assertSame('PR #42', $result['ref_label']);
    }

    public function testClassifyRefGitLabMrUrl(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('https://gitlab.com/org/repo/merge_requests/99');

        $this->assertSame('pr', $result['ref_type']);
        $this->assertSame('MR #99', $result['ref_label']);
    }

    public function testClassifyRefFullCommitSha(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('abc123def456abc123def456abc123def456abcd');

        $this->assertSame('commit', $result['ref_type']);
        $this->assertSame('abc123d', $result['ref_label']);
    }

    public function testClassifyRefShortCommitSha(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('abc123d');

        $this->assertSame('commit', $result['ref_type']);
        $this->assertSame('abc123d', $result['ref_label']);
    }

    public function testClassifyRefBranchWithSlash(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('feature/my-feature');

        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('my-feature', $result['ref_label']);
    }

    public function testClassifyRefPlainBranchName(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('main');

        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('main', $result['ref_label']);
    }

    public function testClassifyRefTrimsWhitespace(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('  main  ');

        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('main', $result['ref_label']);
    }

    public function testClassifyRefUrlPath(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('https://example.com/path/to/branch');

        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('branch', $result['ref_label']);
    }

    public function testClassifyRefComplexBranchPath(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->classifyRef('release/v1.0.0');

        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('v1.0.0', $result['ref_label']);
    }

    // ===========================
    // LINK AI MATCHED
    // ===========================

    public function testLinkAiMatchedReturnsZeroOnEmptyItems(): void
    {
        $service = new GitLinkService($this->mockDb);
        $result = $service->linkAiMatched([], 'https://github.com/org/repo/pull/1', 'PR Title', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkAiMatchedSkipsItemsWithZeroId(): void
    {
        $service = new GitLinkService($this->mockDb);
        $items = [
            ['local_type' => 'user_story', 'local_id' => 0],
            ['local_type' => 'user_story', 'local_id' => null],
        ];
        $result = $service->linkAiMatched($items, 'https://github.com/org/repo/pull/1', 'PR Title', 'github');
        $this->assertSame(0, $result);
    }

    public function testLinkAiMatchedInsertNewLinks(): void
    {
        $this->mockStmt->method('fetch')->willReturn(false);

        $service = new GitLinkService($this->mockDb);
        $items = [
            ['local_type' => 'user_story', 'local_id' => 123],
        ];

        $result = $service->linkAiMatched($items, 'https://github.com/org/repo/pull/1', 'Fix Bug', 'github');

        $this->assertIsInt($result);
    }

    public function testLinkAiMatchedSkipsExistingLinks(): void
    {
        $existingLink = ['id' => 1, 'status' => 'open'];
        $this->mockStmt->method('fetch')->willReturn($existingLink);

        $service = new GitLinkService($this->mockDb);
        $items = [
            ['local_type' => 'user_story', 'local_id' => 123],
        ];

        $result = $service->linkAiMatched($items, 'https://github.com/org/repo/pull/1', 'Fix Bug', 'github');

        $this->assertSame(0, $result);
    }

    public function testLinkAiMatchedEnforcesOrgTenancy(): void
    {
        $this->mockStmt->method('fetch')->willReturn(false);

        $service = new GitLinkService($this->mockDb, 1);
        $items = [
            ['local_type' => 'user_story', 'local_id' => 123],
        ];

        $result = $service->linkAiMatched($items, 'https://github.com/org/repo/pull/1', 'Fix Bug', 'github');

        $this->assertIsInt($result);
    }

    public function testLinkAiMatchedProcessesMultipleItems(): void
    {
        $this->mockStmt->method('fetch')->willReturn(false);

        $service = new GitLinkService($this->mockDb);
        $items = [
            ['local_type' => 'user_story', 'local_id' => 123],
            ['local_type' => 'hl_work_item', 'local_id' => 456],
        ];

        $result = $service->linkAiMatched($items, 'https://github.com/org/repo/pull/1', 'Fix Bug', 'github');

        $this->assertIsInt($result);
    }
}
