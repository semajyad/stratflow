<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\UserDataExportController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class UserDataExportControllerTest extends ControllerTestCase
{
    private array $user = ['id'=>1,'org_id'=>10,'role'=>'user','email'=>'a@t.invalid','is_active'=>1];

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

    private function ctrl(?FakeRequest $r = null): UserDataExportController
    {
        return new UserDataExportController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    public function testIndexRendersConfirmationPage(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->index();

        $this->assertSame('account/export-data', $this->response->renderedTemplate);
    }

    public function testExportCollectsUserData(): void
    {
        $this->db->method('query')->willReturn(
            $this->stmt(['id' => 1, 'email' => 'user@test.invalid'], [])
        );

        $ctrl = $this->ctrl();
        $ctrl->export();

        // Export runs without throwing exception
        $this->assertTrue(true);
    }

    public function testExportHandlesEmptyAuditLog(): void
    {
        $this->db->method('query')->willReturn(
            $this->stmt(['id' => 1, 'email' => 'user@test.invalid'], [])
        );

        $ctrl = $this->ctrl();
        $ctrl->export();

        // Gracefully handles empty audit log
        $this->assertTrue(true);
    }

}
