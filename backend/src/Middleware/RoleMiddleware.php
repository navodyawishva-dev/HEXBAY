<?php
declare(strict_types=1);

namespace Hexbay\Middleware;

use Hexbay\Support\HttpException;

final class RoleMiddleware
{
    /** @param array<string, mixed> $user
     *  @param array<int, string> $allowedRoles
     */
    public static function require(array $user, array $allowedRoles): void
    {
        if (!in_array((string) ($user['role'] ?? ''), $allowedRoles, true)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
    }
}

