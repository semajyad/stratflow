<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Controllers\WebhookController;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;

class WebhookControllerTest extends TestCase
{
    private function makeController(): WebhookController
    {
        return new WebhookController(
            $this->createMock(Request::class),
            $this->createMock(Response::class),
            $this->createMock(Auth::class),
            $this->createMock(Database::class),
            ['app' => ['url' => 'https://example.test'], 'stripe' => [], 'mail' => []]
        );
    }

    #[Test]
    public function testExtractStripeCustomerIdAcceptsExpandedStripeObjectShape(): void
    {
        $controller = $this->makeController();
        $method = new \ReflectionMethod($controller, 'extractStripeCustomerId');
        $method->setAccessible(true);

        $customer = (object) ['id' => 'cus_12345'];

        $this->assertSame('cus_12345', $method->invoke($controller, $customer));
    }

    #[Test]
    public function testExtractCustomerEmailFallsBackToExpandedCustomerEmail(): void
    {
        $controller = $this->makeController();
        $method = new \ReflectionMethod($controller, 'extractCustomerEmail');
        $method->setAccessible(true);

        $session = new \stdClass();
        $session->customer = (object) ['id' => 'cus_12345', 'email' => 'owner@example.com'];

        $this->assertSame('owner@example.com', $method->invoke($controller, $session));
    }
}
