<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Middleware\WorkflowWriteMiddleware;

class WorkflowWriteMiddlewareTest extends TestCase
{
    private function makeAuthWithUser(?array $user): Auth
    {
        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['user'])
            ->getMock();

        $auth->method('user')->willReturn($user);

        return $auth;
    }

    private function makeResponseCapturingRedirect(?string &$redirectedTo): Response
    {
        $response = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect'])
            ->getMock();

        $response->method('redirect')
            ->willReturnCallback(function (string $url) use (&$redirectedTo): void {
                $redirectedTo = $url;
            });

        return $response;
    }

    #[Test]
    public function viewerIsBlockedFromWorkflowWrites(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $redirectedTo = null;
        $middleware = new WorkflowWriteMiddleware();

        $result = $middleware->handle(
            $this->makeAuthWithUser(['role' => 'viewer']),
            $this->makeResponseCapturingRedirect($redirectedTo)
        );

        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    #[Test]
    public function viewerReceivesJsonForbiddenOnAjaxRequest(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $redirectedTo = null;
        $middleware = new WorkflowWriteMiddleware();

        ob_start();
        $result = $middleware->handle(
            $this->makeAuthWithUser(['role' => 'viewer']),
            $this->makeResponseCapturingRedirect($redirectedTo)
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
    public function userCanWriteWorkflow(): void
    {
        $redirectedTo = null;
        $middleware = new WorkflowWriteMiddleware();

        $result = $middleware->handle(
            $this->makeAuthWithUser(['role' => 'user']),
            $this->makeResponseCapturingRedirect($redirectedTo)
        );

        $this->assertTrue($result);
        $this->assertNull($redirectedTo);
    }

    #[Test]
    public function developerIsBlockedFromWorkflowWrites(): void
    {
        $redirectedTo = null;
        $middleware = new WorkflowWriteMiddleware();

        $result = $middleware->handle(
            $this->makeAuthWithUser(['role' => 'developer']),
            $this->makeResponseCapturingRedirect($redirectedTo)
        );

        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
    }
}
