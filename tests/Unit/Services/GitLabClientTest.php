<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GitLabClient;

final class GitLabClientTest extends TestCase
{
    #[Test]
    public function verifyTokenAcceptsMatchingSecret(): void
    {
        $this->assertTrue(GitLabClient::verifyToken('shared-secret', 'shared-secret'));
    }

    #[Test]
    public function verifyTokenRejectsMismatchedSecret(): void
    {
        $this->assertFalse(GitLabClient::verifyToken('wrong-secret', 'shared-secret'));
    }

    #[Test]
    public function parseMergeRequestEventNormalisesOpenedEvent(): void
    {
        $event = GitLabClient::parseMergeRequestEvent([
            'object_kind' => 'merge_request',
            'object_attributes' => [
                'action' => 'open',
                'url' => 'https://gitlab.com/org/repo/-/merge_requests/7',
                'title' => 'Add audit log',
                'description' => 'Closes SF-7',
            ],
            'user' => ['username' => 'alex'],
        ]);

        $this->assertSame('opened', $event['action']);
        $this->assertFalse($event['merged']);
        $this->assertSame('https://gitlab.com/org/repo/-/merge_requests/7', $event['pr_url']);
        $this->assertSame('Add audit log', $event['title']);
        $this->assertSame('Closes SF-7', $event['body']);
        $this->assertSame('alex', $event['author']);
    }

    #[Test]
    public function parseMergeRequestEventNormalisesMergedEvent(): void
    {
        $event = GitLabClient::parseMergeRequestEvent([
            'object_kind' => 'merge_request',
            'object_attributes' => ['action' => 'merge'],
            'user' => ['username' => 'sam'],
        ]);

        $this->assertSame('closed', $event['action']);
        $this->assertTrue($event['merged']);
    }

    #[Test]
    public function parseMergeRequestEventRejectsUnsupportedPayloads(): void
    {
        $this->assertNull(GitLabClient::parseMergeRequestEvent(['object_kind' => 'push']));
        $this->assertNull(GitLabClient::parseMergeRequestEvent([
            'object_kind' => 'merge_request',
            'object_attributes' => ['action' => 'approved'],
        ]));
    }
}
