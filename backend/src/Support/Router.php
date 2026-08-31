<?php
declare(strict_types=1);

namespace Hexbay\Support;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '#^' . rtrim($path, '/') . '/?$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches) === 1) {
                array_shift($matches);
                ($route['handler'])(...array_values($matches));
                return;
            }
        }

        throw new HttpException(404, 'Endpoint not found.');
    }
}

