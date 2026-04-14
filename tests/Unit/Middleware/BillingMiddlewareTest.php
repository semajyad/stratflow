<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Middleware\BillingMiddleware;

/**
 * BillingMiddlewareTest
 *
 * BillingMiddleware calls Database::getInstance() internally; in tests that
 * throws (no real DB) so $db is null. PermissionService::canViewBilling then
 * resolves via legacy mode (flag-based only).
 */
class BillingMiddlewareTest extends TestCase
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
    public function userWithBillingFlagIsAllowed(): void
    {
        // has_billing_access flag grants BILLING_VIEW in legacy mode (no DB needed)
        $result = (new BillingMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'org_id' => 1, 'role' => 'user', 'has_billing_access' => 1]),
            $this->makeResponse()
        );
        $this->assertTrue($result);
    }

    #[Test]
    public function regularUserWithoutBillingFlagIsBlocked(): void
    {
        $redirectedTo = null;
        $result = (new BillingMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'org_id' => 1, 'role' => 'user', 'has_billing_access' => 0]),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
    }

    #[Test]
    public function nullUserIsRedirectedToLogin(): void
    {
        $redirectedTo = null;
        $result = (new BillingMiddleware())->handle(
            $this->makeAuth(null),
            $this->makeResponse($redirectedTo)
        );
        $this->assertFalse($result);
        $this->assertSame('/login', $redirectedTo);
    }

    #[Test]
    public function superadminIsAllowed(): void
    {
        $result = (new BillingMiddleware())->handle(
            $this->makeAuth(['id' => 1, 'org_id' => 1, 'role' => 'superadmin', 'has_billing_access' => 0]),
            $this->makeResponse()
        );
        $this->assertTrue($result);
    }
}
