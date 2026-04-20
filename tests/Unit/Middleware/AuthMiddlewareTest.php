<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Middleware\AuthMiddleware;

class AuthMiddlewareTest extends TestCase
{
    private function makeAuth(bool $loggedIn, ?array $user = null): Auth
    {
        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['check', 'user'])
            ->getMock();
        $auth->method('check')->willReturn($loggedIn);
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
    public function unauthenticatedUserIsRedirectedToLogin(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $redirectedTo = null;
        $result = (new AuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/login', $redirectedTo);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    #[Test]
    public function unauthenticatedAjaxRequestReceivesJson401(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $redirectedTo = null;

        ob_start();
        $result = (new AuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeResponse($redirectedTo)
        );
        $body = ob_get_clean();

        $this->assertFalse($result);
        $this->assertNull($redirectedTo, 'Should not redirect on AJAX request');
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    #[Test]
    public function authenticatedUserIsAllowed(): void
    {
        $redirectedTo = null;
        $result = (new AuthMiddleware())->handle(
            $this->makeAuth(true, ['id' => 1, 'role' => 'user']),
            $this->makeResponse($redirectedTo)
        );
        $this->assertTrue($result);
        $this->assertNull($redirectedTo);
    }

    #[Test]
    public function developerRoleIsRedirectedToTokensPageForNonAccountRoutes(): void
    {
        // Simulating a developer trying to access /app/home
        $_SERVER['REQUEST_URI'] = '/app/home';
        $redirectedTo = null;
        $result = (new AuthMiddleware())->handle(
            $this->makeAuth(true, ['id' => 1, 'role' => 'developer']),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/app/account/tokens', $redirectedTo);
        unset($_SERVER['REQUEST_URI']);
    }

    #[Test]
    public function developerRoleIsAllowedOnAccountRoutes(): void
    {
        $_SERVER['REQUEST_URI'] = '/app/account/tokens';
        $redirectedTo = null;
        $result = (new AuthMiddleware())->handle(
            $this->makeAuth(true, ['id' => 1, 'role' => 'developer']),
            $this->makeResponse($redirectedTo)
        );
        $this->assertTrue($result);
        $this->assertNull($redirectedTo);
        unset($_SERVER['REQUEST_URI']);
    }
}
