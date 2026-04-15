<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Middleware\ExecutiveMiddleware;

class ExecutiveMiddlewareTest extends TestCase
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
        // Superadmin has '*' wildcard capability, including executive.view
        $result = (new ExecutiveMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'role' => 'superadmin']),
            $this->makeResponse()
        );
        $this->assertTrue($result);
    }

    #[Test]
    public function userWithExecutiveFlagIsAllowed(): void
    {
        $result = (new ExecutiveMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'role' => 'user', 'has_executive_access' => 1]),
            $this->makeResponse()
        );
        $this->assertTrue($result);
    }

    #[Test]
    public function regularUserWithoutFlagIsBlocked(): void
    {
        $redirectedTo = null;
        $result = (new ExecutiveMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'role' => 'user', 'has_executive_access' => 0]),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
    }

    #[Test]
    public function nullUserIsRedirectedToLogin(): void
    {
        $redirectedTo = null;
        $result = (new ExecutiveMiddleware())->handle(
            $this->makeAuth(null),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/login', $redirectedTo);
    }
}
