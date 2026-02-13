<?php
namespace Shuffle\Core;

/**
 * REST API router.
 *
 * Registers route patterns with {param} placeholders and dispatches
 * incoming requests to the appropriate controller callback.
 */
class Router
{
    /** @var array Registered routes grouped by HTTP method */
    private array $routes = [];

    /**
     * Registers a GET route.
     *
     * @param string   $pattern  URL pattern with optional {param} placeholders
     * @param callable $handler  Callback: function(Request, Response, array $params)
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Registers a POST route.
     *
     * @param string   $pattern  URL pattern
     * @param callable $handler  Callback
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Registers a PUT route.
     *
     * @param string   $pattern  URL pattern
     * @param callable $handler  Callback
     */
    public function put(string $pattern, callable $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * Registers a DELETE route.
     *
     * @param string   $pattern  URL pattern
     * @param callable $handler  Callback
     */
    public function delete(string $pattern, callable $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Dispatches the request to the matching route handler.
     *
     * Sends 404 if no pattern matches, 405 if the method is not allowed.
     *
     * @param Request  $request  The HTTP request
     * @param Response $response The HTTP response helper
     */
    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Normalize trailing slash
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $methodMatched = false;

        foreach ($this->routes as $route) {
            $params = $this->matchRoute($route['pattern'], $path);
            if ($params !== null) {
                $methodMatched = true;
                if ($route['method'] === $method) {
                    $request->setParams($params);
                    call_user_func($route['handler'], $request, $response, $params);
                    return;
                }
            }
        }

        if ($methodMatched) {
            $response->error('Method not allowed', 405);
            return;
        }

        $response->error('Not found', 404);
    }

    /**
     * Stores a route definition.
     *
     * @param string   $method  HTTP method
     * @param string   $pattern URL pattern
     * @param callable $handler Route handler
     */
    private function addRoute(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Matches a URL path against a route pattern.
     *
     * Extracts {param} values and returns them as an associative array.
     * Returns null if the path does not match.
     *
     * @param string $pattern Route pattern (e.g., /v1/users/{id})
     * @param string $path    Request path (e.g., /v1/users/42)
     * @return array|null     Extracted params or null
     */
    private function matchRoute(string $pattern, string $path): ?array
    {
        // Convert {param} placeholders to regex capture groups
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            // Filter to only named captures
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return null;
    }
}
