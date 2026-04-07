<?php

declare(strict_types=1);

namespace StratFlow\Core;

use StratFlow\Middleware\AuthMiddleware;
use StratFlow\Middleware\CSRFMiddleware;

/**
 * HTTP Router
 *
 * Registers routes with method, URI pattern (supporting {param} placeholders),
 * controller@method handler strings, and optional middleware. Dispatches the
 * matched route by instantiating the controller and calling the method.
 */
class Router
{
    /** @var array Registered routes */
    private array $routes = [];

    private Request $request;
    private Response $response;
    private Auth $auth;
    private CSRF $csrf;
    private Database $db;
    private array $config;

    public function __construct(
        Request $request,
        Response $response,
        Auth $auth,
        CSRF $csrf,
        Database $db,
        array $config
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Register a route.
     *
     * @param string $method     HTTP method (GET, POST, etc.)
     * @param string $pattern    URI pattern, e.g. '/app/work-items/{id}'
     * @param string $handler    Controller@method string, e.g. 'WorkItemController@show'
     * @param array  $middleware Middleware keys: 'auth', 'csrf'
     */
    public function add(string $method, string $pattern, string $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Match the current request against registered routes and dispatch.
     *
     * Converts {param} placeholders to named regex capture groups, runs
     * middleware, then instantiates the controller and calls the method.
     * Renders a 404 page if no route matches.
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri = rtrim($request->uri(), '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $regex = $this->patternToRegex($route['pattern']);

            if (preg_match($regex, $uri, $matches)) {
                // Extract named parameters only
                $params = array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);

                // Run middleware
                if (!$this->runMiddleware($route['middleware'])) {
                    return;
                }

                // Parse handler
                [$controllerName, $methodName] = explode('@', $route['handler']);
                $controllerClass = 'StratFlow\\Controllers\\' . $controllerName;

                $controller = new $controllerClass(
                    $this->request,
                    $this->response,
                    $this->auth,
                    $this->db,
                    $this->config
                );

                $controller->$methodName(...array_values($params));
                return;
            }
        }

        // No route matched
        http_response_code(404);
        echo '<h1>404 - Page Not Found</h1>';
    }

    /**
     * Convert a route pattern like '/items/{id}' to a regex.
     *
     * @param string $pattern Route pattern with {param} placeholders
     * @return string Regex pattern
     */
    private function patternToRegex(string $pattern): string
    {
        $pattern = rtrim($pattern, '/') ?: '/';
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * Run an array of middleware keys. Returns false if any middleware halts.
     *
     * @param array $middlewareKeys e.g. ['auth', 'csrf']
     * @return bool True if all middleware passed
     */
    private function runMiddleware(array $middlewareKeys): bool
    {
        foreach ($middlewareKeys as $key) {
            $result = match ($key) {
                'auth' => (new AuthMiddleware())->handle($this->auth, $this->response),
                'csrf' => (new CSRFMiddleware())->handle($this->request, $this->csrf, $this->response),
                default => true,
            };

            if (!$result) {
                return false;
            }
        }

        return true;
    }
}
