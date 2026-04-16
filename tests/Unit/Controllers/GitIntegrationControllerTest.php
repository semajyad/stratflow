<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\GitIntegrationController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class GitIntegrationControllerTest extends ControllerTestCase
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

    private function ctrl(?FakeRequest $r = null): GitIntegrationController
    {
        return new GitIntegrationController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    public function testDisconnectValidatesProvider(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->disconnect('bitbucket');

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('Unknown Git provider.', $_SESSION['flash_error']);
    }

    public function testConnectRejectsInvalidProvider(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->connect('bitbucket');

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('Unknown Git provider.', $_SESSION['flash_error']);
    }

    public function testRegenerateSecretRejectsInvalidProvider(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->regenerateSecret('unknown');

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('Unknown Git provider.', $_SESSION['flash_error']);
    }

    public function testRevealSecretRejectsInvalidProvider(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->revealSecret('unknown');

        $this->assertEquals(400, $this->response->jsonStatus);
        $this->assertEquals('Unknown Git provider.', $this->response->jsonPayload['error']);
    }

    public function testRegenerateSecretValidatesProvider(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->regenerateSecret('invalid');

        // Validates provider before looking up integration
        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertEquals('Unknown Git provider.', $_SESSION['flash_error']);
    }
}
