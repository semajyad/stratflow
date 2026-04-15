<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\GitHubAppController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class GitHubAppControllerTest extends ControllerTestCase
{
    private array $user = ['id'=>1,'org_id'=>10,'role'=>'admin','email'=>'a@t.invalid','is_active'=>1];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = [];
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): GitHubAppController
    {
        return new GitHubAppController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    public function testInstallFlashesErrorWhenAppSlugMissing(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->install();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('GITHUB_APP_SLUG is not configured.', $_SESSION['flash_error']);
    }

    public function testCallbackVerifiesStateNonce(): void
    {
        $_SESSION['github_install_state'] = 'valid_state_123';
        $request = $this->makeGetRequest(['state' => 'invalid_state', 'installation_id' => '999']);

        $ctrl = $this->ctrl($request);
        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('Invalid GitHub install state. Please try again.', $_SESSION['flash_error']);
    }

    public function testCallbackRejectsWhenInstallationIdMissing(): void
    {
        $_SESSION['github_install_state'] = 'state123';
        $request = $this->makeGetRequest(['state' => 'state123']);

        $ctrl = $this->ctrl($request);
        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('Missing installation_id from GitHub callback.', $_SESSION['flash_error']);
    }

    public function testDisconnectMarksIntegrationRevoked(): void
    {
        $this->db->method('query')->willReturn(
            $this->stmt(['id' => 1])
        );

        $ctrl = $this->ctrl();
        $ctrl->disconnect(1);

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('GitHub installation disconnected.', $_SESSION['flash_message']);
    }

    public function testDisconnectReturnsErrorWhenIntegrationNotFound(): void
    {
        $this->db->method('query')->willReturn(
            $this->stmt(false)
        );

        $ctrl = $this->ctrl();
        $ctrl->disconnect(999);

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('GitHub integration not found.', $_SESSION['flash_error']);
    }

    public function testCallbackHandlesDeleteAction(): void
    {
        $_SESSION['github_install_state'] = 'state123';
        $request = $this->makeGetRequest([
            'state' => 'state123',
            'installation_id' => '123',
            'setup_action' => 'delete',
        ]);

        $ctrl = $this->ctrl($request);
        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('GitHub App uninstalled.', $_SESSION['flash_message']);
    }
}
