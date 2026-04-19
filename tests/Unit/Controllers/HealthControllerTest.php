<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\HealthController;
use StratFlow\Tests\Support\ControllerTestCase;

#[AllowMockObjectsWithoutExpectations]
final class HealthControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        defined('ASSET_VERSION') || define('ASSET_VERSION', 'test-1');
    }

    #[Test]
    public function indexReturnsOkJsonWhenDatabaseResponds(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $this->db->method('query')->willReturn($stmt);

        $controller = new HealthController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config,
        );

        ob_start();
        $controller->index();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('test-1', $payload['build']);
        $this->assertIsInt($payload['db_ms']);
    }

    #[Test]
    public function indexReturnsDegradedJsonWhenDatabaseFails(): void
    {
        $this->db->method('query')->willThrowException(new \RuntimeException('database unavailable'));

        $controller = new HealthController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config,
        );

        ob_start();
        $controller->index();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame('test-1', $payload['build']);
        $this->assertNull($payload['db_ms']);
    }
}
