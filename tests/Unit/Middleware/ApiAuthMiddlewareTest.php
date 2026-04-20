<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Response;
use StratFlow\Middleware\ApiAuthMiddleware;

/**
 * ApiAuthMiddlewareTest (unit)
 *
 * Unit tests for ApiAuthMiddleware using mocked DB + Auth.
 */
class ApiAuthMiddlewareTest extends TestCase
{
    private function makeAuth(bool $expectLogin = false): Auth
    {
        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loginAsPrincipal'])
            ->getMock();

        if ($expectLogin) {
            $auth->expects($this->once())->method('loginAsPrincipal');
        }

        return $auth;
    }

    private function makeDb(mixed $tokenRow, mixed $userRow, bool $hasAccountType = false): Database
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'tableExists'])
            ->getMock();

        $tokenStmt = $this->createMock(\PDOStatement::class);
        $tokenStmt->method('fetch')->willReturn($tokenRow);

        // account_type column check
        $infoStmt = $this->createMock(\PDOStatement::class);
        $infoStmt->method('fetch')->willReturn($hasAccountType ? ['1' => 1] : false);

        $userStmt = $this->createMock(\PDOStatement::class);
        $userStmt->method('fetch')->willReturn($userRow);

        $touchStmt = $this->createMock(\PDOStatement::class);
        $touchStmt->method('fetch')->willReturn(false);

        $db->method('query')->willReturnOnConsecutiveCalls($tokenStmt, $infoStmt, $userStmt, $touchStmt);
        $db->method('tableExists')->willReturn(false);

        return $db;
    }

    private function makeResponse(): Response
    {
        return $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function captureMiddlewareResult(callable $callback): array
    {
        ob_start();
        try {
            $result = $callback();
            $output = ob_get_clean();
            return [$result, $output];
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD']
        );
    }

    // ===========================
    // Happy path
    // ===========================

    #[Test]
    public function validTokenCallsLoginAsPrincipalAndReturnsTrue(): void
    {
        $tokenRow = [
            'id'      => 1,
            'user_id' => 42,
            'org_id'  => 5,
            'scopes'  => null,
        ];
        $userRow = [
            'id'                   => 42,
            'org_id'               => 5,
            'full_name'            => 'Test User',
            'email'                => 'u@test.invalid',
            'role'                 => 'user',
            'has_billing_access'   => 0,
            'has_executive_access' => 0,
            'is_project_admin'     => 0,
            'jira_account_id'      => null,
            'team'                 => null,
        ];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_testtoken123456789012345678901';
        $_SERVER['REMOTE_ADDR']        = '127.0.0.1';
        $_SERVER['REQUEST_URI']        = '/api/v1/stories';
        $_SERVER['REQUEST_METHOD']     = 'GET';

        $result = (new ApiAuthMiddleware())->handle(
            $this->makeAuth(true),
            $this->makeDb($tokenRow, $userRow),
            $this->makeResponse()
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function validTokenWithNullScopesGetsDefaultScopes(): void
    {
        // Tokens with NULL scopes should receive the default scope set
        $tokenRow = ['id' => 2, 'user_id' => 1, 'org_id' => 1, 'scopes' => null];
        $userRow  = [
            'id' => 1, 'org_id' => 1, 'full_name' => 'U', 'email' => 'u@x.com',
            'role' => 'user', 'has_billing_access' => 0, 'has_executive_access' => 0,
            'is_project_admin' => 0, 'jira_account_id' => null, 'team' => null,
        ];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_anothervalidtoken12345678901';
        $_SERVER['REMOTE_ADDR']        = '127.0.0.1';
        $_SERVER['REQUEST_URI']        = '/api/v1/me';
        $_SERVER['REQUEST_METHOD']     = 'GET';

        $result = (new ApiAuthMiddleware())->handle(
            $this->makeAuth(true),
            $this->makeDb($tokenRow, $userRow),
            $this->makeResponse()
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function missingBearerTokenReturnsFalseWithUnauthorizedJson(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = '';

        [$result, $output] = $this->captureMiddlewareResult(fn() => (new ApiAuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeDb(null, null),
            $this->makeResponse()
        ));

        $this->assertFalse($result);
        $this->assertStringContainsString('unauthorized', $output);
        $this->assertStringContainsString('Authorization header', $output);
    }

    #[Test]
    public function unknownTokenReturnsFalseWithUnauthorizedJson(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_unknown';

        [$result, $output] = $this->captureMiddlewareResult(fn() => (new ApiAuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeDb(null, null),
            $this->makeResponse()
        ));

        $this->assertFalse($result);
        $this->assertStringContainsString('unauthorized', $output);
        $this->assertStringContainsString('Token not found', $output);
    }

    #[Test]
    public function missingTokenOwnerReturnsFalseWithUnauthorizedJson(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_known';

        $tokenRow = [
            'id' => 3,
            'user_id' => 42,
            'org_id' => 5,
            'scopes' => null,
        ];

        [$result, $output] = $this->captureMiddlewareResult(fn() => (new ApiAuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeDb($tokenRow, false),
            $this->makeResponse()
        ));

        $this->assertFalse($result);
        $this->assertStringContainsString('unauthorized', $output);
        $this->assertStringContainsString('Token owner not found', $output);
    }

    #[Test]
    public function disallowedScopeReturnsFalseWithForbiddenJson(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_limited';
        $_SERVER['REQUEST_URI'] = '/api/v1/stories/99/status';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $tokenRow = [
            'id' => 4,
            'user_id' => 42,
            'org_id' => 5,
            'scopes' => json_encode(['profile:read'], JSON_UNESCAPED_SLASHES),
        ];
        $userRow = [
            'id' => 42,
            'org_id' => 5,
            'full_name' => 'Test User',
            'email' => 'u@test.invalid',
            'role' => 'user',
            'has_billing_access' => 0,
            'has_executive_access' => 0,
            'is_project_admin' => 0,
            'jira_account_id' => null,
            'team' => null,
        ];

        [$result, $output] = $this->captureMiddlewareResult(fn() => (new ApiAuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeDb($tokenRow, $userRow),
            $this->makeResponse()
        ));

        $this->assertFalse($result);
        $this->assertStringContainsString('forbidden', $output);
        $this->assertStringContainsString('scope', $output);
    }

    #[Test]
    public function validTokenIncludesAccountTypeWhenColumnExists(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_with_account_type';
        $_SERVER['REQUEST_URI'] = '/api/v1/me';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $tokenRow = [
            'id' => 5,
            'user_id' => 42,
            'org_id' => 5,
            'scopes' => json_encode(['profile:read'], JSON_UNESCAPED_SLASHES),
        ];
        $userRow = [
            'id' => 42,
            'org_id' => 5,
            'full_name' => 'Test User',
            'email' => 'u@test.invalid',
            'role' => 'user',
            'account_type' => 'member',
            'has_billing_access' => 0,
            'has_executive_access' => 0,
            'is_project_admin' => 0,
            'jira_account_id' => null,
            'team' => null,
        ];

        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loginAsPrincipal'])
            ->getMock();
        $auth->expects($this->once())
            ->method('loginAsPrincipal')
            ->with($this->callback(fn(array $principal): bool => ($principal['account_type'] ?? null) === 'member'));

        $result = (new ApiAuthMiddleware())->handle(
            $auth,
            $this->makeDb($tokenRow, $userRow, true),
            $this->makeResponse()
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function validTokenWithoutRequiredScopeReturnsForbidden(): void
    {
        $tokenRow = [
            'id'      => 3,
            'user_id' => 42,
            'org_id'  => 5,
            'scopes'  => json_encode(['profile:read']),
        ];
        $userRow = [
            'id' => 42, 'org_id' => 5, 'full_name' => 'Scoped User', 'email' => 'scoped@test.invalid',
            'role' => 'user', 'has_billing_access' => 0, 'has_executive_access' => 0,
            'is_project_admin' => 0, 'jira_account_id' => null, 'team' => null,
        ];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sf_pat_scopedtoken12345678901234567';
        $_SERVER['REMOTE_ADDR']        = '127.0.0.1';
        $_SERVER['REQUEST_URI']        = '/api/v1/stories/123/status';
        $_SERVER['REQUEST_METHOD']     = 'POST';

        ob_start();
        $result = (new ApiAuthMiddleware())->handle(
            $this->makeAuth(false),
            $this->makeDb($tokenRow, $userRow),
            $this->makeResponse()
        );
        $output = (string) ob_get_clean();

        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

        $this->assertFalse($result);
        $this->assertStringContainsString('forbidden', $output);
    }
}
