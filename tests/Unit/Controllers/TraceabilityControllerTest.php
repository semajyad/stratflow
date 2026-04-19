<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\TraceabilityController;
use StratFlow\Tests\Support\ControllerTestCase;

#[AllowMockObjectsWithoutExpectations]
final class TraceabilityControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function indexRedirectsHomeWhenProjectIsNotAccessible(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 2, 'role' => 'user']);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $controller = new TraceabilityController(
            $this->makeGetRequest(['project_id' => '99']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config,
        );

        $controller->index();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function indexRendersTraceabilityWhenProjectIsAccessible(): void
    {
        $this->actingAs(['id' => 1, 'org_id' => 2, 'role' => 'user']);
        $this->db->method('query')->willReturnCallback(function (): \PDOStatement {
            static $call = 0;
            $call++;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(
                $call === 1 ? ['id' => 5, 'org_id' => 2, 'name' => 'Traceable Project'] : false
            );
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        $controller = new TraceabilityController(
            $this->makeGetRequest(['project_id' => '5']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config,
        );

        $controller->index();

        $this->assertSame('traceability', $this->response->renderedTemplate);
        $this->assertSame('Traceable Project', $this->response->renderedData['project']['name']);
        $this->assertSame('traceability', $this->response->renderedData['active_page']);
    }
}
