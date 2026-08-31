<?php
declare(strict_types=1);

namespace Hexbay\Support;

final class Request
{
    /** @return array<string, mixed> */
    public static function json(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains($contentType, 'application/json')) {
            throw new HttpException(415, 'Content-Type must be application/json.');
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'The request contains invalid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new HttpException(400, 'The JSON body must be an object.');
        }

        return $decoded;
    }

    public static function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            throw new HttpException(401, 'Authentication is required.');
        }

        return $matches[1];
    }

    public static function ipAddress(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
    }

    public static function userAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    public static function uploadedFile(string $field = 'file'): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains($contentType, 'multipart/form-data')) {
            throw new HttpException(415, 'Content-Type must be multipart/form-data.');
        }
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw new HttpException(422, 'Choose one file to upload.', [
                $field => ['A file is required.'],
            ]);
        }
        return [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    public static function formString(string $field, string $default = ''): string
    {
        $value = $_POST[$field] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}
