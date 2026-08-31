<?php
declare(strict_types=1);

namespace Hexbay\Config;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read environment configuration.');
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $separator = strpos($trimmed, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $separator));
            $value = trim(substr($trimmed, $separator + 1));
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $systemValue = getenv($key);
        if ($systemValue !== false) {
            return $systemValue;
        }

        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException("Missing required environment variable: {$key}");
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, (string) $default);
        if (!ctype_digit($value)) {
            throw new RuntimeException("Environment variable {$key} must be a positive integer.");
        }

        return (int) $value;
    }
}

