<?php
declare(strict_types=1);

namespace Hexbay\Middleware;

use Hexbay\Support\HttpException;

final class CorsMiddleware
{
    public static function handle(string $allowedOrigins): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $origins = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $allowedOrigins)
        )));

        if ($origin !== null && !in_array($origin, $origins, true)) {
            throw new HttpException(403, 'Origin is not allowed.');
        }

        if ($origin !== null && in_array($origin, $origins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
