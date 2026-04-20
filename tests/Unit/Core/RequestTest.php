<?php
declare(strict_types=1);
namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Request;

#[CoversClass(\StratFlow\Core\Request::class)]
class RequestTest extends TestCase {
    private array $originalServer;
    private array $originalGet;
    private array $originalPost;
    private array $originalFiles;

    protected function setUp(): void {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalFiles = $_FILES;
    }

    protected function tearDown(): void {
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_FILES = $this->originalFiles;
        parent::tearDown();
    }

    // ========== method() Tests ==========

    public function testMethodReturnsServerRequestMethod(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertSame('GET', $request->method());
    }

    public function testMethodReturnsPostMethod(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();
        $this->assertSame('POST', $request->method());
    }

    // ========== uri() Tests ==========

    public function testUriReturnsPathOnly(): void {
        $_SERVER['REQUEST_URI'] = '/foo/bar?baz=1';
        $request = new Request();
        $this->assertSame('/foo/bar', $request->uri());
    }

    public function testUriDefaultsToSlash(): void {
        unset($_SERVER['REQUEST_URI']);
        $request = new Request();
        $this->assertSame('/', $request->uri());
    }

    // ========== get() Tests ==========

    public function testGetReturnsQueryValue(): void {
        $_GET['foo'] = 'bar';
        $request = new Request();
        $this->assertSame('bar', $request->get('foo'));
    }

    public function testGetReturnsDefaultWhenMissing(): void {
        $_GET = [];
        $request = new Request();
        $this->assertSame('default', $request->get('nonexistent', 'default'));
    }

    public function testGetReturnsNullDefaultWhenMissing(): void {
        $_GET = [];
        $request = new Request();
        $this->assertNull($request->get('nonexistent'));
    }

    // ========== post() Tests ==========

    public function testPostReturnsBodyValue(): void {
        $_POST['key'] = 'value';
        $request = new Request();
        $this->assertSame('value', $request->post('key'));
    }

    public function testPostReturnsDefaultWhenMissing(): void {
        $_POST = [];
        $request = new Request();
        $this->assertSame('fallback', $request->post('missing', 'fallback'));
    }

    // ========== file() Tests ==========

    public function testFileReturnsFileArray(): void {
        $_FILES['upload'] = [
            'name' => 'f.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/x',
            'error' => 0,
            'size' => 5
        ];
        $request = new Request();
        $fileArray = $request->file('upload');

        $this->assertIsArray($fileArray);
        $this->assertSame('f.txt', $fileArray['name']);
        $this->assertSame('text/plain', $fileArray['type']);
        $this->assertSame(0, $fileArray['error']);
    }

    public function testFileReturnsNullWhenMissing(): void {
        $_FILES = [];
        $request = new Request();
        $this->assertNull($request->file('nonexistent'));
    }

    // ========== ip() Tests ==========

    public function testIpReturnsRemoteAddr(): void {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $request = new Request();
        $this->assertSame('1.2.3.4', $request->ip());
    }

    public function testIpDefaultsWhenMissing(): void {
        unset($_SERVER['REMOTE_ADDR']);
        $request = new Request();
        $this->assertSame('0.0.0.0', $request->ip());
    }

    // ========== isPost() Tests ==========

    public function testIsPostReturnsTrueForPost(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();
        $this->assertTrue($request->isPost());
    }

    public function testIsPostReturnsFalseForGet(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isPost());
    }

    // ========== isAjax() Tests ==========

    public function testIsAjaxReturnsTrueForXhr(): void {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $request = new Request();
        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxReturnsFalseWithoutHeader(): void {
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $request = new Request();
        $this->assertFalse($request->isAjax());
    }

    // ========== header() Tests ==========

    public function testHeaderReturnsServerHeaderValue(): void {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token';
        $request = new Request();
        $this->assertSame('Bearer token', $request->header('Authorization'));
    }

    public function testHeaderConvertsHyphensToUnderscores(): void {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $request = new Request();
        $this->assertSame('application/json', $request->header('Content-Type'));
    }

    public function testHeaderReturnsEmptyStringWhenMissing(): void {
        unset($_SERVER['HTTP_X_CUSTOM_HEADER']);
        $request = new Request();
        $this->assertSame('', $request->header('X-Custom-Header'));
    }

    // ========== body() Tests ==========

    public function testBodyReadsPhpInputStream(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        // body() calls file_get_contents('php://input').
        // In tests, php://input is empty by default but still readable
        $body = $request->body();
        $this->assertIsString($body);
    }

    // ========== json() Tests ==========

    /** @return Request with body() stubbed — avoids PHPUnit 12 MethodNamedMethodException */
    private function makeRequestWithBody(string $body): Request
    {
        return new class($body) extends Request {
            private string $fakeBody;
            public function __construct(string $body) { $this->fakeBody = $body; }
            public function body(): string { return $this->fakeBody; }
        };
    }

    public function testJsonDecodesRequestBody(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = $this->makeRequestWithBody('{"key":"value","num":42}');

        $result = $request->json();
        $this->assertIsArray($result);
        $this->assertSame('value', $result['key']);
        $this->assertSame(42, $result['num']);
    }

    public function testJsonReturnsEmptyArrayForEmptyBody(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame([], $this->makeRequestWithBody('')->json());
    }

    public function testJsonReturnsEmptyArrayForNullBody(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame([], $this->makeRequestWithBody('')->json());
    }

    public function testJsonReturnsEmptyArrayForInvalidJson(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame([], $this->makeRequestWithBody('not-valid-json')->json());
    }

    public function testJsonReturnsEmptyArrayForNonArrayJson(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame([], $this->makeRequestWithBody('"string-value"')->json());
    }

    public function testExpectsJsonReturnsTrueForXmlHttpRequest(): void {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $this->assertTrue(Request::expectsJson());
    }

    public function testExpectsJsonReturnsTrueForJsonContentType(): void {
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $this->assertTrue(Request::expectsJson());
    }

    public function testExpectsJsonReturnsFalseForBrowserRequest(): void {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
        $this->assertFalse(Request::expectsJson());
    }

    public function testExpectsJsonReturnsTrueForHttpContentTypeFallback(): void {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['CONTENT_TYPE']);
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $this->assertTrue(Request::expectsJson());
    }

    public function testExpectsJsonReturnsTrueForMixedCaseContentType(): void {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_CONTENT_TYPE']);
        $_SERVER['CONTENT_TYPE'] = 'Application/JSON';
        $this->assertTrue(Request::expectsJson());
    }
}
