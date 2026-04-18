<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\CSRF;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Middleware\CSRFMiddleware;
use StratFlow\Tests\Support\FakeRequest;

class CSRFMiddlewareTest extends TestCase
{
    private function makeCsrf(bool $valid): CSRF
    {
        $csrf = $this->getMockBuilder(CSRF::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateToken'])
            ->getMock();
        $csrf->method('validateToken')->willReturn($valid);
        return $csrf;
    }

    private function makeResponse(): Response
    {
        return $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    #[Test]
    public function validTokenPassesPost(): void
    {
        $request = new FakeRequest('POST', '/', ['_csrf_token' => 'valid-token']);
        $result  = (new CSRFMiddleware())->handle($request, $this->makeCsrf(true), $this->makeResponse());
        $this->assertTrue($result);
    }

    #[Test]
    public function missingTokenBlocksPost(): void
    {
        $request = new FakeRequest('POST', '/', []);
        ob_start(); // suppress echo from middleware
        $result = (new CSRFMiddleware())->handle($request, $this->makeCsrf(false), $this->makeResponse());
        ob_end_clean();
        $this->assertFalse($result);
    }

    #[Test]
    public function invalidTokenBlocksPost(): void
    {
        $request = new FakeRequest('POST', '/', ['_csrf_token' => 'wrong']);
        ob_start();
        $result = (new CSRFMiddleware())->handle($request, $this->makeCsrf(false), $this->makeResponse());
        ob_end_clean();
        $this->assertFalse($result);
    }

    #[Test]
    public function getRequestBypassesCheck(): void
    {
        $request = new FakeRequest('GET', '/app/home');
        $result  = (new CSRFMiddleware())->handle($request, $this->makeCsrf(false), $this->makeResponse());
        $this->assertTrue($result);
    }

    #[Test]
    public function validTokenInJsonBodyPassesPost(): void
    {
        $body    = json_encode(['project_id' => 1, '_csrf_token' => 'valid-token']);
        $request = new FakeRequest('POST', '/app/sounding-board/evaluate', [], [], '127.0.0.1', [], $body);
        $result  = (new CSRFMiddleware())->handle($request, $this->makeCsrf(true), $this->makeResponse());
        $this->assertTrue($result);
    }

    #[Test]
    public function missingTokenInJsonBodyBlocksPost(): void
    {
        $body    = json_encode(['project_id' => 1]);
        $request = new FakeRequest('POST', '/app/sounding-board/evaluate', [], [], '127.0.0.1', [], $body);
        ob_start();
        $result = (new CSRFMiddleware())->handle($request, $this->makeCsrf(false), $this->makeResponse());
        ob_end_clean();
        $this->assertFalse($result);
    }
}
