<?php
/**
 * GitLinkServiceTest
 *
 * Unit tests for GitLinkService::classifyRef.
 * classifyRef is a pure function — no database or HTTP connections needed.
 */

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Services\GitLinkService;

class GitLinkServiceTest extends TestCase
{
    private GitLinkService $service;

    protected function setUp(): void
    {
        // classifyRef doesn't use the DB but the constructor requires it.
        // Use a Database instance with the real Docker MySQL config.
        $this->service = new GitLinkService(new Database(getTestDbConfig()));
    }

    // ===========================
    // classifyRef — PR URLs
    // ===========================

    #[Test]
    public function testClassifyRefGitHubPr(): void
    {
        $result = $this->service->classifyRef('https://github.com/org/repo/pull/42');
        $this->assertSame('pr', $result['ref_type']);
        $this->assertSame('PR #42', $result['ref_label']);
    }

    #[Test]
    public function testClassifyRefGitHubPrWithTrailingSlash(): void
    {
        $result = $this->service->classifyRef('https://github.com/org/repo/pull/42/');
        $this->assertSame('pr', $result['ref_type']);
        $this->assertSame('PR #42', $result['ref_label']);
    }

    #[Test]
    public function testClassifyRefGitLabMr(): void
    {
        $result = $this->service->classifyRef('https://gitlab.com/group/repo/-/merge_requests/7');
        $this->assertSame('pr', $result['ref_type']);
        $this->assertSame('MR #7', $result['ref_label']);
    }

    // ===========================
    // classifyRef — commits
    // ===========================

    #[Test]
    public function testClassifyRefFullCommitSha(): void
    {
        $sha    = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
        $result = $this->service->classifyRef($sha);
        $this->assertSame('commit', $result['ref_type']);
        $this->assertSame('a1b2c3d', $result['ref_label']);
    }

    #[Test]
    public function testClassifyRefShortCommitSha(): void
    {
        $result = $this->service->classifyRef('a1b2c3d');
        $this->assertSame('commit', $result['ref_type']);
        $this->assertSame('a1b2c3d', $result['ref_label']);
    }

    // ===========================
    // classifyRef — branches
    // ===========================

    #[Test]
    public function testClassifyRefPlainBranch(): void
    {
        $result = $this->service->classifyRef('feature/my-feature');
        $this->assertSame('branch', $result['ref_type']);
        // Last segment of a slash-containing string
        $this->assertSame('my-feature', $result['ref_label']);
    }

    #[Test]
    public function testClassifyRefSimpleBranchName(): void
    {
        $result = $this->service->classifyRef('main');
        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('main', $result['ref_label']);
    }

    #[Test]
    public function testClassifyRefUrlLikeBranch(): void
    {
        // A non-GitHub/GitLab URL treated as a branch by URL basename
        $result = $this->service->classifyRef('https://bitbucket.org/org/repo/branch/feature-x');
        $this->assertSame('branch', $result['ref_type']);
        $this->assertSame('feature-x', $result['ref_label']);
    }
}
