<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\GitHubAppClient;

/**
 * GitHubAppClientTest
 *
 * Unit tests for GitHubAppClient — GitHub App JWT minting, token caching,
 * webhook signature verification, and payload parsing (PRs, pushes, installations, repos).
 *
 * Note: HTTP calls are tested via error handling; real API calls are not mocked here.
 */
class GitHubAppClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $reflection = new \ReflectionClass(GitHubAppClient::class);
        $property = $reflection->getProperty('tokenCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    // ===========================
    // JWT MINTING
    // ===========================

    public function testMintAppJwtThrowsWhenAppIdNotConfigured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GITHUB_APP_ID not configured');

        unset($_ENV['GITHUB_APP_ID']);
        unset($_ENV['GITHUB_APP_PRIVATE_KEY']);
        unset($_ENV['GITHUB_APP_PRIVATE_KEY_PATH']);

        GitHubAppClient::mintAppJwt();
    }

    public function testMintAppJwtThrowsWhenPrivateKeyNotConfigured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Neither GITHUB_APP_PRIVATE_KEY nor GITHUB_APP_PRIVATE_KEY_PATH is configured');

        $_ENV['GITHUB_APP_ID'] = '12345';
        unset($_ENV['GITHUB_APP_PRIVATE_KEY']);
        unset($_ENV['GITHUB_APP_PRIVATE_KEY_PATH']);

        GitHubAppClient::mintAppJwt();
    }

    public function testMintAppJwtThrowsWhenPrivateKeyFileNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read private key at:');

        $_ENV['GITHUB_APP_ID'] = '12345';
        $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] = '/nonexistent/path/key.pem';
        unset($_ENV['GITHUB_APP_PRIVATE_KEY']);

        GitHubAppClient::mintAppJwt();
    }

    public function testMintAppJwtThrowsOnInvalidPrivateKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to load RSA private key');

        $_ENV['GITHUB_APP_ID'] = '12345';
        $_ENV['GITHUB_APP_PRIVATE_KEY'] = 'not-a-valid-pem-key';

        GitHubAppClient::mintAppJwt();
    }

    public function testMintAppJwtReturnsValidJwtFormat(): void
    {
        $_ENV['GITHUB_APP_ID'] = '12345';
        $config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);

        $_ENV['GITHUB_APP_PRIVATE_KEY'] = $privKey;

        $jwt = GitHubAppClient::mintAppJwt();

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/') . str_repeat('=', 4 % strlen($parts[0]))), true);
        $this->assertIsArray($header);
        $this->assertSame('RS256', $header['alg'] ?? null);
    }

    public function testMintAppJwtJwtHasValidPayload(): void
    {
        $_ENV['GITHUB_APP_ID'] = '67890';
        $config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);

        $_ENV['GITHUB_APP_PRIVATE_KEY'] = $privKey;

        $jwt = GitHubAppClient::mintAppJwt();
        $parts = explode('.', $jwt);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', 4 % strlen($parts[1]))), true);
        $this->assertIsArray($payload);
        $this->assertSame('67890', $payload['iss'] ?? null);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
    }

    public function testMintAppJwtHandlesEscapedNewlines(): void
    {
        $_ENV['GITHUB_APP_ID'] = '12345';
        $config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);

        $_ENV['GITHUB_APP_PRIVATE_KEY'] = str_replace("\n", '\\n', $privKey);

        $jwt = GitHubAppClient::mintAppJwt();
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
    }

    public function testMintAppJwtPrefersEnvVarOverFilePath(): void
    {
        $_ENV['GITHUB_APP_ID'] = '12345';
        $config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);

        $_ENV['GITHUB_APP_PRIVATE_KEY'] = $privKey;
        $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] = '/nonexistent/path';

        $jwt = GitHubAppClient::mintAppJwt();
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
    }

    // ===========================
    // INSTALLATION TOKEN
    // ===========================

    public function testGetInstallationTokenThrowsOnMintJwtFailure(): void
    {
        $this->expectException(\RuntimeException::class);

        $_ENV['GITHUB_APP_ID'] = '12345';
        $_ENV['GITHUB_APP_PRIVATE_KEY'] = 'invalid-key';

        try {
            GitHubAppClient::getInstallationToken(999);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to load RSA private key', $e->getMessage());
            throw $e;
        }
    }


    // ===========================
    // LIST INSTALLATION REPOS
    // ===========================

    public function testListInstallationReposThrowsOnInvalidKey(): void
    {
        $this->expectException(\RuntimeException::class);

        $_ENV['GITHUB_APP_ID'] = '12345';
        $_ENV['GITHUB_APP_PRIVATE_KEY'] = 'invalid-key';

        GitHubAppClient::listInstallationRepos(999);
    }

    // ===========================
    // SIGNATURE VERIFICATION
    // ===========================

    public function testVerifySignatureReturnsFalseWhenSecretNotConfigured(): void
    {
        unset($_ENV['GITHUB_APP_WEBHOOK_SECRET']);

        $result = GitHubAppClient::verifySignature('test body', 'sha256=abc123');
        $this->assertFalse($result);
    }

    public function testVerifySignatureReturnsFalseOnMalformedHeader(): void
    {
        $_ENV['GITHUB_APP_WEBHOOK_SECRET'] = 'test-secret';

        $result = GitHubAppClient::verifySignature('test body', 'invalid-format');
        $this->assertFalse($result);
    }

    public function testVerifySignatureReturnsTrueOnValidSignature(): void
    {
        $secret = 'test-secret';
        $body = 'test body';
        $_ENV['GITHUB_APP_WEBHOOK_SECRET'] = $secret;

        $expectedHash = hash_hmac('sha256', $body, $secret);
        $header = 'sha256=' . $expectedHash;

        $result = GitHubAppClient::verifySignature($body, $header);
        $this->assertTrue($result);
    }

    public function testVerifySignatureReturnsFalseOnInvalidSignature(): void
    {
        $_ENV['GITHUB_APP_WEBHOOK_SECRET'] = 'test-secret';

        $result = GitHubAppClient::verifySignature('test body', 'sha256=wronghash123456789');
        $this->assertFalse($result);
    }

    public function testVerifySignatureUsesConstantTimeComparison(): void
    {
        $secret = 'my-secret';
        $_ENV['GITHUB_APP_WEBHOOK_SECRET'] = $secret;

        $body = 'webhook body';
        $correctHash = hash_hmac('sha256', $body, $secret);

        $result1 = GitHubAppClient::verifySignature($body, 'sha256=' . $correctHash);
        $result2 = GitHubAppClient::verifySignature($body, 'sha256=incorrecthash');

        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    // ===========================
    // PULL REQUEST PARSING
    // ===========================

    public function testParsePullRequestEventReturnsNullWhenNoPullRequest(): void
    {
        $payload = ['action' => 'opened'];
        $result = GitHubAppClient::parsePullRequestEvent($payload);
        $this->assertNull($result);
    }

    public function testParsePullRequestEventReturnsNullOnUnsupportedAction(): void
    {
        $payload = [
            'action' => 'labeled',
            'pull_request' => ['id' => 1],
        ];
        $result = GitHubAppClient::parsePullRequestEvent($payload);
        $this->assertNull($result);
    }

    public function testParsePullRequestEventParsesOpenedAction(): void
    {
        $payload = [
            'action' => 'opened',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/42',
                'title' => 'Fix bug',
                'body' => 'SF-123 fixes the issue',
                'merged' => false,
                'head' => ['ref' => 'feature/fix'],
                'user' => ['login' => 'alice'],
            ],
            'repository' => ['id' => 999],
        ];

        $result = GitHubAppClient::parsePullRequestEvent($payload);

        $this->assertIsArray($result);
        $this->assertSame('opened', $result['action']);
        $this->assertFalse($result['merged']);
        $this->assertSame('https://github.com/org/repo/pull/42', $result['pr_url']);
        $this->assertSame('Fix bug', $result['title']);
        $this->assertSame('feature/fix', $result['branch']);
        $this->assertSame('alice', $result['author']);
        $this->assertSame(999, $result['repo_github_id']);
    }

    public function testParsePullRequestEventParsesReopenedAction(): void
    {
        $payload = [
            'action' => 'reopened',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/42',
                'title' => 'Feature',
                'body' => '',
                'merged' => false,
                'head' => ['ref' => 'develop'],
                'user' => ['login' => 'bob'],
            ],
            'repository' => ['id' => 888],
        ];

        $result = GitHubAppClient::parsePullRequestEvent($payload);

        $this->assertSame('reopened', $result['action']);
        $this->assertFalse($result['merged']);
    }

    public function testParsePullRequestEventParsesSynchronizeAction(): void
    {
        $payload = [
            'action' => 'synchronize',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/50',
                'title' => 'Update',
                'body' => 'SF-100',
                'merged' => false,
                'head' => ['ref' => 'fix'],
                'user' => ['login' => 'charlie'],
            ],
            'repository' => ['id' => 777],
        ];

        $result = GitHubAppClient::parsePullRequestEvent($payload);

        $this->assertSame('synchronize', $result['action']);
    }

    public function testParsePullRequestEventParsesMergedClosedAction(): void
    {
        $payload = [
            'action' => 'closed',
            'pull_request' => [
                'html_url' => 'https://github.com/org/repo/pull/42',
                'title' => 'Feature',
                'body' => '',
                'merged' => true,
                'head' => ['ref' => 'main'],
                'user' => ['login' => 'bob'],
            ],
            'repository' => ['id' => 888],
        ];

        $result = GitHubAppClient::parsePullRequestEvent($payload);

        $this->assertTrue($result['merged']);
        $this->assertSame('closed', $result['action']);
    }

    // ===========================
    // INSTALLATION REPOS PARSING
    // ===========================

    public function testParseInstallationReposEventReturnsNullWhenMissingFields(): void
    {
        $payload = ['installation' => ['id' => 1]];
        $result = GitHubAppClient::parseInstallationReposEvent($payload);
        $this->assertNull($result);
    }

    public function testParseInstallationReposEventParsesAddedAndRemoved(): void
    {
        $payload = [
            'installation' => ['id' => 555],
            'repositories_added' => [
                ['id' => 111, 'full_name' => 'org/repo1'],
                ['id' => 222, 'full_name' => 'org/repo2'],
            ],
            'repositories_removed' => [
                ['id' => 333, 'full_name' => 'org/old-repo'],
            ],
        ];

        $result = GitHubAppClient::parseInstallationReposEvent($payload);

        $this->assertIsArray($result);
        $this->assertSame(555, $result['installation_id']);
        $this->assertCount(2, $result['added']);
        $this->assertCount(1, $result['removed']);
        $this->assertSame('org/repo1', $result['added'][0]['full_name']);
    }

    public function testParseInstallationReposEventHandlesEmptyLists(): void
    {
        $payload = [
            'installation' => ['id' => 555],
            'repositories_added' => [],
            'repositories_removed' => [],
        ];

        $result = GitHubAppClient::parseInstallationReposEvent($payload);

        $this->assertIsArray($result);
        $this->assertSame(555, $result['installation_id']);
        $this->assertCount(0, $result['added']);
        $this->assertCount(0, $result['removed']);
    }

    // ===========================
    // INSTALLATION EVENT PARSING
    // ===========================

    public function testParseInstallationEventReturnsNullWhenNoInstallation(): void
    {
        $payload = ['action' => 'created'];
        $result = GitHubAppClient::parseInstallationEvent($payload);
        $this->assertNull($result);
    }

    public function testParseInstallationEventReturnsNullOnUnsupportedAction(): void
    {
        $payload = [
            'installation' => ['id' => 1, 'account' => ['login' => 'user']],
            'action' => 'unknown',
        ];
        $result = GitHubAppClient::parseInstallationEvent($payload);
        $this->assertNull($result);
    }

    public function testParseInstallationEventParsesCreatedAction(): void
    {
        $payload = [
            'action' => 'created',
            'installation' => [
                'id' => 777,
                'account' => [
                    'login' => 'my-org',
                    'type' => 'Organization',
                ],
            ],
        ];

        $result = GitHubAppClient::parseInstallationEvent($payload);

        $this->assertIsArray($result);
        $this->assertSame(777, $result['installation_id']);
        $this->assertSame('created', $result['action']);
        $this->assertSame('my-org', $result['account_login']);
        $this->assertSame('Organization', $result['account_type']);
    }

    public function testParseInstallationEventParsesDeletedAction(): void
    {
        $payload = [
            'action' => 'deleted',
            'installation' => [
                'id' => 666,
                'account' => ['login' => 'user', 'type' => 'User'],
            ],
        ];

        $result = GitHubAppClient::parseInstallationEvent($payload);
        $this->assertSame('deleted', $result['action']);
    }

    public function testParseInstallationEventParsesSuspendAction(): void
    {
        $payload = [
            'action' => 'suspend',
            'installation' => [
                'id' => 666,
                'account' => ['login' => 'org-name', 'type' => 'Organization'],
            ],
        ];

        $result = GitHubAppClient::parseInstallationEvent($payload);
        $this->assertSame('suspend', $result['action']);
    }

    public function testParseInstallationEventParsesUnsuspendAction(): void
    {
        $payload = [
            'action' => 'unsuspend',
            'installation' => [
                'id' => 666,
                'account' => ['login' => 'org-name', 'type' => 'Organization'],
            ],
        ];

        $result = GitHubAppClient::parseInstallationEvent($payload);
        $this->assertSame('unsuspend', $result['action']);
    }

    // ===========================
    // PUSH EVENT PARSING
    // ===========================

    public function testParsePushEventReturnsNullWhenNoCommits(): void
    {
        $payload = [
            'commits' => [],
            'ref' => 'refs/heads/main',
            'repository' => ['id' => 1, 'full_name' => 'org/repo'],
        ];

        $result = GitHubAppClient::parsePushEvent($payload);
        $this->assertNull($result);
    }

    public function testParsePushEventParsesSingleCommit(): void
    {
        $payload = [
            'ref' => 'refs/heads/feature/test',
            'commits' => [
                [
                    'id' => 'abc123def456',
                    'message' => 'Fix bug in auth',
                    'url' => 'https://github.com/org/repo/commit/abc123',
                    'author' => ['username' => 'alice', 'name' => 'Alice Smith'],
                ],
            ],
            'repository' => ['id' => 444, 'full_name' => 'org/repo'],
        ];

        $result = GitHubAppClient::parsePushEvent($payload);

        $this->assertIsArray($result);
        $this->assertSame(444, $result['repo_github_id']);
        $this->assertSame('org/repo', $result['repo_full_name']);
        $this->assertSame('feature/test', $result['branch']);
        $this->assertCount(1, $result['commits']);
        $this->assertSame('abc123def456', $result['commits'][0]['sha']);
        $this->assertSame('alice', $result['commits'][0]['author']);
    }

    public function testParsePushEventStripsRefsHeadsPrefix(): void
    {
        $payload = [
            'ref' => 'refs/heads/main',
            'commits' => [
                ['id' => 'sha1', 'message' => 'msg', 'url' => 'url', 'author' => ['name' => 'Author']],
            ],
            'repository' => ['id' => 1, 'full_name' => 'org/repo'],
        ];

        $result = GitHubAppClient::parsePushEvent($payload);
        $this->assertSame('main', $result['branch']);
    }

    public function testParsePushEventHandlesMissingAuthorUsername(): void
    {
        $payload = [
            'ref' => 'refs/heads/main',
            'commits' => [
                [
                    'id' => 'sha1',
                    'message' => 'msg',
                    'url' => 'url',
                    'author' => ['name' => 'John Doe'],
                ],
            ],
            'repository' => ['id' => 1, 'full_name' => 'org/repo'],
        ];

        $result = GitHubAppClient::parsePushEvent($payload);
        $this->assertSame('John Doe', $result['commits'][0]['author']);
    }

    public function testParsePushEventParseMultipleCommits(): void
    {
        $payload = [
            'ref' => 'refs/heads/develop',
            'commits' => [
                ['id' => 'a1', 'message' => 'msg1', 'url' => 'url1', 'author' => ['username' => 'user1']],
                ['id' => 'a2', 'message' => 'msg2', 'url' => 'url2', 'author' => ['username' => 'user2']],
            ],
            'repository' => ['id' => 1, 'full_name' => 'org/repo'],
        ];

        $result = GitHubAppClient::parsePushEvent($payload);
        $this->assertCount(2, $result['commits']);
    }

    public function testParsePushEventPreservesCommitDetails(): void
    {
        $payload = [
            'ref' => 'refs/heads/master',
            'commits' => [
                [
                    'id' => 'commit1',
                    'message' => 'Test commit',
                    'url' => 'https://github.com/org/repo/commit/commit1',
                    'author' => ['username' => 'tester'],
                ],
            ],
            'repository' => ['id' => 999, 'full_name' => 'org/test'],
        ];

        $result = GitHubAppClient::parsePushEvent($payload);

        $commit = $result['commits'][0];
        $this->assertSame('commit1', $commit['sha']);
        $this->assertSame('Test commit', $commit['message']);
        $this->assertSame('https://github.com/org/repo/commit/commit1', $commit['url']);
    }

    #[Test]
    public function testMintAppJwtDoesNotEmitWarningOnMissingFile(): void
    {
        // file_get_contents on missing path must throw, not emit E_WARNING
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot read private key/');

        $_ENV['GITHUB_APP_PRIVATE_KEY']      = '';
        $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] = '/nonexistent/path/key.pem';
        $_ENV['GITHUB_APP_ID']               = '12345';

        $client = new GitHubAppClient();
        $client->mintAppJwt();
    }
}
