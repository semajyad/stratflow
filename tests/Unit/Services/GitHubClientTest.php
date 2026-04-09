<?php
/**
 * GitHubClientTest
 *
 * Unit tests for GitHubClient static helpers.
 * No database or HTTP connections needed — all pure function tests.
 */

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GitHubClient;

class GitHubClientTest extends TestCase
{
    // ===========================
    // verifySignature
    // ===========================

    #[Test]
    public function testVerifySignatureAcceptsCorrectSignature(): void
    {
        $secret    = 'my-webhook-secret';
        $body      = '{"action":"opened"}';
        $hash      = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue(GitHubClient::verifySignature($body, $hash, $secret));
    }

    #[Test]
    public function testVerifySignatureRejectsWrongSecret(): void
    {
        $body      = '{"action":"opened"}';
        $hash      = 'sha256=' . hash_hmac('sha256', $body, 'correct-secret');

        $this->assertFalse(GitHubClient::verifySignature($body, $hash, 'wrong-secret'));
    }

    #[Test]
    public function testVerifySignatureRejectsMissingPrefix(): void
    {
        $secret = 'my-webhook-secret';
        $body   = '{"action":"opened"}';
        // No "sha256=" prefix
        $hash   = hash_hmac('sha256', $body, $secret);

        $this->assertFalse(GitHubClient::verifySignature($body, $hash, $secret));
    }

    #[Test]
    public function testVerifySignatureRejectsEmptyBody(): void
    {
        $secret = 'my-webhook-secret';
        $hash   = 'sha256=' . hash_hmac('sha256', '', $secret);

        $this->assertTrue(GitHubClient::verifySignature('', $hash, $secret));
    }

    // ===========================
    // parsePullRequestEvent
    // ===========================

    #[Test]
    public function testParsePullRequestEventOpened(): void
    {
        $payload = [
            'action'       => 'opened',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/42',
                'title'    => 'Add login feature',
                'body'     => 'Closes SF-10',
                'merged'   => false,
                'user'     => ['login' => 'alice'],
            ],
        ];

        $result = GitHubClient::parsePullRequestEvent($payload);

        $this->assertNotNull($result);
        $this->assertSame('opened', $result['action']);
        $this->assertFalse($result['merged']);
        $this->assertSame('https://github.com/org/repo/pull/42', $result['pr_url']);
        $this->assertSame('Add login feature', $result['title']);
        $this->assertSame('Closes SF-10', $result['body']);
        $this->assertSame('alice', $result['author']);
    }

    #[Test]
    public function testParsePullRequestEventMerged(): void
    {
        $payload = [
            'action'       => 'closed',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/7',
                'title'    => 'Hotfix',
                'body'     => '',
                'merged'   => true,
                'user'     => ['login' => 'bob'],
            ],
        ];

        $result = GitHubClient::parsePullRequestEvent($payload);

        $this->assertNotNull($result);
        $this->assertSame('closed', $result['action']);
        $this->assertTrue($result['merged']);
    }

    #[Test]
    public function testParsePullRequestEventClosedNotMerged(): void
    {
        $payload = [
            'action'       => 'closed',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/7',
                'title'    => 'Draft abandoned',
                'body'     => '',
                'merged'   => false,
                'user'     => ['login' => 'bob'],
            ],
        ];

        $result = GitHubClient::parsePullRequestEvent($payload);

        $this->assertNotNull($result);
        $this->assertFalse($result['merged']);
    }

    #[Test]
    public function testParsePullRequestEventIgnoresUnknownAction(): void
    {
        $payload = [
            'action'       => 'labeled',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/7',
                'title'    => 'Something',
                'body'     => '',
                'merged'   => false,
                'user'     => ['login' => 'bob'],
            ],
        ];

        $this->assertNull(GitHubClient::parsePullRequestEvent($payload));
    }

    #[Test]
    public function testParsePullRequestEventReturnNullWhenNoPrKey(): void
    {
        $payload = ['action' => 'opened'];

        $this->assertNull(GitHubClient::parsePullRequestEvent($payload));
    }

    #[Test]
    public function testParsePullRequestEventSynchronize(): void
    {
        $payload = [
            'action'       => 'synchronize',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/55',
                'title'    => 'WIP: new feature',
                'body'     => 'StratFlow-3',
                'merged'   => false,
                'user'     => ['login' => 'carol'],
            ],
        ];

        $result = GitHubClient::parsePullRequestEvent($payload);

        $this->assertNotNull($result);
        $this->assertSame('synchronize', $result['action']);
        $this->assertFalse($result['merged']);
    }
}
