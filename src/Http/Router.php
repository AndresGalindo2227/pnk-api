<?php

declare(strict_types=1);

namespace Pnk\Http;

/** Minimal router: "GET /bookings/{id}" => callable(Request): Response. */
final class Router
{
    /** @var list<array{string, string, callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($method === $request->method) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return $handler($request->withParams($params));
            }
        }
        throw $pathMatched
            ? new HttpException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.')
            : HttpException::notFound('Endpoint');
    }
}
