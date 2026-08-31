<?php
declare(strict_types=1);

namespace Hexbay\Support;

final class ApiResponse
{
    /** @param mixed $data */
    public static function success(
        string $message,
        mixed $data = null,
        int $status = 200
    ): never {
        self::send(true, $message, $data, null, $status);
    }

    /** @param array<string, mixed>|null $errors */
    public static function error(
        string $message,
        int $status,
        ?array $errors = null
    ): never {
        self::send(false, $message, null, $errors, $status);
    }

    /** @param mixed $data
     *  @param array<string, mixed>|null $errors
     */
    private static function send(
        bool $success,
        string $message,
        mixed $data,
        ?array $errors,
        int $status
    ): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => $success,
                'message' => $message,
                'data' => $data,
                'errors' => $errors,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }
}

