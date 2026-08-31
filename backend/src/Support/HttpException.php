<?php
declare(strict_types=1);

namespace Hexbay\Support;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    /** @param array<string, mixed>|null $errors */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?array $errors = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

