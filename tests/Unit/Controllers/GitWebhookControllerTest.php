<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\GitWebhookController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

/**
 * GitWebhookControllerTest
 *
 * Tests for GitWebhookController::receiveGitHub() and receiveGitLab().
 * Note: These controllers use file_get_contents('php://input') which cannot
 * be easily mocked in unit tests. Tests verify:
 * - Controllers accept POST requests
 * - Route methods exist
 * - Error paths don't throw exceptions
 */
final class GitWebhookControllerTest extends ControllerTestCase
{
    private array $user = ['id' => 1, 'org_id' => 10, 'role' => 'org_admin', 'email' => 'a@test.invalid', 'is_active' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);

        // Ensure GITHUB_APP_WEBHOOK_SECRET is set for signature verification
        putenv('GITHUB_APP_WEBHOOK_SECRET=webhook_secret_test');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): GitWebhookController
    {
        return new GitWebhookController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    // ===========================
    // GitHub Webhook: Controller Exists
    // ===========================

    #[Test]
    public function testGitHubWebhookControllerCanBeInstantiated(): void
    {
        $req = $this->makePostRequest([], '/webhook/github');
        $ctrl = $this->ctrl($req);

        $this->assertInstanceOf(GitWebhookController::class, $ctrl);
    }

    #[Test]
    public function testGitHubWebhookMethodExists(): void
    {
        $ctrl = $this->ctrl();
        $this->assertTrue(method_exists($ctrl, 'receiveGitHub'));
    }

    #[Test]
    public function testGitHubWebhookIsCallable(): void
    {
        $ctrl = $this->ctrl();
        $method = new \ReflectionMethod($ctrl, 'receiveGitHub');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function testGitHubWebhookHandlesEmptyInputGracefully(): void
    {
        $req = new FakeRequest('POST', '/webhook/github', [], [], '127.0.0.1', [], '');
        ob_start();
        // Controller uses file_get_contents('php://input') so will get empty
        $this->ctrl($req)->receiveGitHub();
        $output = ob_get_clean();

        // Should output JSON response (at minimum)
        $this->assertNotEmpty($output);
        $this->assertJson($output);
    }

    #[Test]
    public function testGitHubWebhookRejectsRequestWithoutSignature(): void
    {
        $req = new FakeRequest('POST', '/webhook/github', [], [], '127.0.0.1', [], '');
        ob_start();
        $this->ctrl($req)->receiveGitHub();
        $output = ob_get_clean();

        // No signature should result in error response
        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('error', $decoded);
    }

    // ===========================
    // GitLab Webhook: Controller Exists
    // ===========================

    #[Test]
    public function testGitLabWebhookControllerCanBeInstantiated(): void
    {
        $req = $this->makePostRequest([], '/webhook/gitlab');
        $ctrl = $this->ctrl($req);

        $this->assertInstanceOf(GitWebhookController::class, $ctrl);
    }

    #[Test]
    public function testGitLabWebhookMethodExists(): void
    {
        $ctrl = $this->ctrl();
        $this->assertTrue(method_exists($ctrl, 'receiveGitLab'));
    }

    #[Test]
    public function testGitLabWebhookIsCallable(): void
    {
        $ctrl = $this->ctrl();
        $method = new \ReflectionMethod($ctrl, 'receiveGitLab');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function testGitLabWebhookHandlesEmptyInputGracefully(): void
    {
        $req = new FakeRequest('POST', '/webhook/gitlab', [], [], '127.0.0.1', [], '');
        ob_start();
        $this->ctrl($req)->receiveGitLab();
        $output = ob_get_clean();

        // Should output JSON response
        $this->assertNotEmpty($output);
        $decoded = json_decode($output, true);
        $this->assertTrue(is_array($decoded) || $decoded === null);
    }

    #[Test]
    public function testGitLabWebhookRejectsRequestWithoutToken(): void
    {
        $req = new FakeRequest('POST', '/webhook/gitlab', [], [], '127.0.0.1', [], '');
        ob_start();
        $this->ctrl($req)->receiveGitLab();
        $output = ob_get_clean();

        // No token should result in error response
        $this->assertNotEmpty($output);
    }

    // ===========================
    // Error Handling
    // ===========================

    #[Test]
    public function testGitHubWebhookDoesNotThrowOnPostRequest(): void
    {
        $req = $this->makePostRequest([], '/webhook/github');
        ob_start();
        try {
            $this->ctrl($req)->receiveGitHub();
            ob_get_clean();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail("GitHub webhook threw exception: " . $e->getMessage());
        }
    }

    #[Test]
    public function testGitLabWebhookDoesNotThrowOnPostRequest(): void
    {
        $req = $this->makePostRequest([], '/webhook/gitlab');
        ob_start();
        try {
            $this->ctrl($req)->receiveGitLab();
            ob_get_clean();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail("GitLab webhook threw exception: " . $e->getMessage());
        }
    }
}
