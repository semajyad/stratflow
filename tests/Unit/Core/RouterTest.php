<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Router;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Core\Auth;
use StratFlow\Core\CSRF;
use StratFlow\Core\Database;

/**
 * Minimal concrete stub for Request — avoids PHPUnit 11 deprecation triggered
 * when mocking classes that have a method named "method".
 */
class StubRequest extends Request
{
    public function method(): string { return 'GET'; }
    public function uri(): string { return '/'; }
}

/**
 * RouterTest
 *
 * Tests route registration, pattern-to-regex conversion via patternToRegex,
 * and route matching behaviour. Controller dispatch is not exercised — we
 * only verify the router's internal routing logic.
 */
class RouterTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    /**
     * Expose the private patternToRegex method via reflection.
     */
    private function invokePatternToRegex(Router $router, string $pattern): string
    {
        $method = new \ReflectionMethod(Router::class, 'patternToRegex');
        $method->setAccessible(true);
        return $method->invoke($router, $pattern);
    }

    /**
     * Expose the private routes property via reflection.
     */
    private function getRoutes(Router $router): array
    {
        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        return $prop->getValue($router);
    }

    /**
     * Build a Router with minimal stub dependencies.
     *
     * We don't need real DB/auth for routing unit tests.
     * Request is stubbed via getMockBuilder (not createMock) to avoid
     * a PHPUnit 11 deprecation triggered by mocking classes that have a
     * method named "method".
     */
    private function makeRouter(): Router
    {
        $request  = new StubRequest();
        $response = $this->getMockBuilder(Response::class)->disableOriginalConstructor()->getMock();
        $auth     = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()->getMock();
        $csrf     = $this->getMockBuilder(CSRF::class)->disableOriginalConstructor()->getMock();
        $db       = $this->getMockBuilder(Database::class)->disableOriginalConstructor()->getMock();

        return new Router($request, $response, $auth, $csrf, $db, []);
    }

    // ===========================
    // ROUTE REGISTRATION
    // ===========================

    #[Test]
    public function testAddRegistersRoute(): void
    {
        $router = $this->makeRouter();
        $router->add('GET', '/dashboard', 'DashboardController@index');

        $routes = $this->getRoutes($router);

        $this->assertCount(1, $routes);
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/dashboard', $routes[0]['pattern']);
        $this->assertSame('DashboardController@index', $routes[0]['handler']);
        $this->assertSame([], $routes[0]['middleware']);
    }

    #[Test]
    public function testAddNormalisesMethodToUppercase(): void
    {
        $router = $this->makeRouter();
        $router->add('post', '/login', 'AuthController@login');

        $routes = $this->getRoutes($router);
        $this->assertSame('POST', $routes[0]['method']);
    }

    #[Test]
    public function testAddStoresMiddleware(): void
    {
        $router = $this->makeRouter();
        $router->add('GET', '/app', 'AppController@index', ['auth']);

        $routes = $this->getRoutes($router);
        $this->assertSame(['auth'], $routes[0]['middleware']);
    }

    #[Test]
    public function testMultipleRoutesAreAllRegistered(): void
    {
        $router = $this->makeRouter();
        $router->add('GET', '/a', 'A@index');
        $router->add('POST', '/b', 'B@store');
        $router->add('GET', '/c/{id}', 'C@show');

        $this->assertCount(3, $this->getRoutes($router));
    }

    // ===========================
    // PATTERN TO REGEX
    // ===========================

    #[Test]
    public function testStaticPatternBecomesExactRegex(): void
    {
        $router = $this->makeRouter();
        $regex  = $this->invokePatternToRegex($router, '/dashboard');

        $this->assertSame('#^/dashboard$#', $regex);
    }

    #[Test]
    public function testParamPlaceholderConvertsToNamedGroup(): void
    {
        $router = $this->makeRouter();
        $regex  = $this->invokePatternToRegex($router, '/items/{id}');

        $this->assertMatchesRegularExpression('/\(\?P<id>/', $regex);
    }

    #[Test]
    public function testMultipleParamsConvert(): void
    {
        $router = $this->makeRouter();
        $regex  = $this->invokePatternToRegex($router, '/orgs/{orgId}/projects/{projectId}');

        $this->assertMatchesRegularExpression('/\(\?P<orgId>/', $regex);
        $this->assertMatchesRegularExpression('/\(\?P<projectId>/', $regex);
    }

    #[Test]
    public function testRegexMatchesConcreteUri(): void
    {
        $router = $this->makeRouter();
        $regex  = $this->invokePatternToRegex($router, '/items/{id}');

        $this->assertMatchesRegularExpression($regex, '/items/42');
        $this->assertMatchesRegularExpression($regex, '/items/abc-123');
    }

    #[Test]
    public function testRegexDoesNotMatchDeeperPath(): void
    {
        $router = $this->makeRouter();
        $regex  = $this->invokePatternToRegex($router, '/items/{id}');

        $this->assertDoesNotMatchRegularExpression($regex, '/items/42/extra');
    }

    #[Test]
    public function testRootPatternMatchesRoot(): void
    {
        $router = $this->makeRouter();
        $regex  = $this->invokePatternToRegex($router, '/');

        $this->assertMatchesRegularExpression($regex, '/');
    }
}
