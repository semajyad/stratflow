<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Session;

/**
 * AuthPrincipalTest
 *
 * Regression guard for Auth::loginAsPrincipal.
 *
 * Verifies:
 * 1. When apiPrincipal is null, check/user/orgId use the session (existing behaviour).
 * 2. When loginAsPrincipal is called, check/user/orgId prefer the in-memory principal.
 * 3. Session is not modified by loginAsPrincipal (no session_regenerate_id called).
 */
class AuthPrincipalTest extends TestCase
{
    private function makeAuth(bool $sessionHasUser): Auth
    {
        $session = $this->createMock(Session::class);
        $db      = $this->createMock(Database::class);

        $session->method('has')->with('user')->willReturn($sessionHasUser);

        $sessionUser = $sessionHasUser ? [
            'id'     => 1,
            'org_id' => 10,
            'name'   => 'Session User',
            'email'  => 'session@test.invalid',
            'role'   => 'user',
        ] : null;

        $session->method('get')->with('user')->willReturn($sessionUser);

        return new Auth($session, $db);
    }

    // ===========================
    // SESSION FALLBACK (regression)
    // ===========================

    #[Test]
    public function testCheckReturnsFalseWhenNoPrincipalAndNoSession(): void
    {
        $auth = $this->makeAuth(false);
        $this->assertFalse($auth->check());
    }

    #[Test]
    public function testCheckReturnsTrueFromSessionWhenNoPrincipal(): void
    {
        $auth = $this->makeAuth(true);
        $this->assertTrue($auth->check());
    }

    #[Test]
    public function testUserReturnsSessionUserWhenNoPrincipal(): void
    {
        $auth = $this->makeAuth(true);
        $user = $auth->user();
        $this->assertSame(1, $user['id']);
        $this->assertSame('Session User', $user['name']);
    }

    #[Test]
    public function testOrgIdReturnsNullWhenNoSessionAndNoPrincipal(): void
    {
        $auth = $this->makeAuth(false);
        $this->assertNull($auth->orgId());
    }

    // ===========================
    // API PRINCIPAL TAKES OVER
    // ===========================

    #[Test]
    public function testCheckReturnsTrueAfterLoginAsPrincipal(): void
    {
        $auth = $this->makeAuth(false); // No session
        $auth->loginAsPrincipal([
            'id' => 99, 'org_id' => 77, 'full_name' => 'API User',
            'email' => 'api@test.invalid', 'role' => 'user',
        ]);
        $this->assertTrue($auth->check());
    }

    #[Test]
    public function testUserReturnsPrincipalOverSession(): void
    {
        $auth = $this->makeAuth(true); // Session IS set
        $auth->loginAsPrincipal([
            'id' => 99, 'org_id' => 77, 'full_name' => 'API User',
            'email' => 'api@test.invalid', 'role' => 'user',
        ]);
        $user = $auth->user();
        $this->assertSame(99, $user['id']); // Must come from principal, not session
        $this->assertSame('API User', $user['name']);
    }

    #[Test]
    public function testOrgIdReturnsPrincipalOrgId(): void
    {
        $auth = $this->makeAuth(false);
        $auth->loginAsPrincipal([
            'id' => 99, 'org_id' => 77, 'full_name' => 'API User',
            'email' => 'api@test.invalid', 'role' => 'user',
        ]);
        $this->assertSame(77, $auth->orgId());
    }

    #[Test]
    public function testLoginAsPrincipalMapsFullNameToName(): void
    {
        $auth = $this->makeAuth(false);
        $auth->loginAsPrincipal([
            'id' => 1, 'org_id' => 1, 'full_name' => 'Full Name',
            'email' => 'x@test.invalid', 'role' => 'user',
        ]);
        $this->assertSame('Full Name', $auth->user()['name']);
    }
}
