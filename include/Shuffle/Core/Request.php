<?php
namespace Shuffle\Core;

/**
 * HTTP request wrapper.
 *
 * Provides a clean interface over PHP superglobals for accessing
 * request method, path, query parameters, body, and headers.
 */
class Request
{
    private string $method;
    private string $path;
    private array $query;
    private ?array $body = null;
    private array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->parsePath();
        $this->query = $_GET;
    }

    /**
     * Returns the HTTP method (GET, POST, PUT, DELETE, etc.).
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Returns the request path without query string.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns a query parameter value, or a default if not set.
     *
     * @param string|null $key     Parameter name, or null for all parameters
     * @param mixed       $default Default value
     * @return mixed
     */
    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /**
     * Returns the parsed JSON request body.
     *
     * @return array Decoded JSON body, or empty array if not JSON
     */
    public function getBody(): array
    {
        if ($this->body === null) {
            $input = $this->getInputStream();
            $decoded = json_decode($input, true);
            $this->body = is_array($decoded) ? $decoded : [];
        }
        return $this->body;
    }

    /**
     * Returns a specific header value.
     *
     * @param string      $name    Header name (case-insensitive)
     * @param string|null $default Default value
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        // PHP stores headers as HTTP_HEADER_NAME in $_SERVER
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$serverKey] ?? $default;
    }

    /**
     * Returns a cookie value.
     *
     * @param string      $name    Cookie name
     * @param string|null $default Default value
     * @return string|null
     */
    public function getCookie(string $name, ?string $default = null): ?string
    {
        return $_COOKIE[$name] ?? $default;
    }

    /**
     * Returns the raw input stream content.
     *
     * @return string
     */
    public function getInputStream(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Sets route parameters extracted by the router.
     *
     * @param array $params Route parameters (e.g., ['id' => '42'])
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Returns route parameters.
     *
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Returns a specific route parameter.
     *
     * @param string      $name    Parameter name
     * @param string|null $default Default value
     * @return string|null
     */
    public function getParam(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * Parses the request URI to extract the path component.
     *
     * @return string Clean path without query string
     */
    private function parsePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Remove /v1/index.php prefix if present (for front-controller routing)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);
        if ($scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir)) ?: '/';
        }

        return $path;
    }
}
