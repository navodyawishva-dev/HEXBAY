<?php
declare(strict_types=1);

namespace Hexbay\Middleware;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Support\Jwt;
use Hexbay\Support\Request;

final class AuthMiddleware
{
    public function __construct(
        private readonly Jwt $jwt,
        private readonly UserRepository $users
    ) {
    }

    /** @return array{claims: array<string, mixed>, user: array<string, mixed>} */
    public function authenticate(): array
    {
        $claims = $this->jwt->decode(Request::bearerToken());
        if ($this->users->tokenIsRevoked((string) $claims['jti'])) {
            throw new HttpException(401, 'The authentication token is no longer valid.');
        }

        $user = $this->users->findPublicById((int) $claims['sub']);
        if (
            $user === null
            || $user['status'] !== 'active'
            || $user['role'] !== $claims['role']
        ) {
            throw new HttpException(401, 'The account is unavailable or the token is invalid.');
        }

        return ['claims' => $claims, 'user' => $user];
    }
}

