<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Middleware\SuperadminMiddleware;

class SuperadminMiddlewareTest extends TestCase
{
    private function makeAuth(?array $user): Auth
    {
        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['user'])
            ->getMock();
        $auth->method('user')->willReturn($user);
        return $auth;
    }

    private function makeResponse(?string &$redirectedTo = null): Response
    {
        $response = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect'])
            ->getMock();
        $response->method('redirect')->willReturnCallback(function (string $url) use (&$redirectedTo): void {
            $redirectedTo = $url;
        });
        return $response;
    }

    #[Test]
    public function superadminIsAllowed(): void
    {
        $redirectedTo = null;
        $result = (new SuperadminMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'role' => 'superadmin']),
            $this->makeResponse($redirectedTo)
        );
        $this->assertTrue($result);
        $this->assertNull($redirectedTo);
    }

    #[Test]
    public function orgAdminIsBlocked(): void
    {
        $redirectedTo = null;
        $result = (new SuperadminMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'role' => 'org_admin']),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
    }

    #[Test]
    public function regularUserIsBlocked(): void
    {
        $result = (new SuperadminMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'role' => 'user']),
            $this->makeResponse()
        );
        $this->assertFalse($result);
    }

    #[Test]
    public function nullUserIsBlocked(): void
    {
        $result = (new SuperadminMiddleware())->handle(
            $this->makeAuth(null),
            $this->makeResponse()
        );
        $this->assertFalse($result);
    }
}
