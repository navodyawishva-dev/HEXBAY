<?php
declare(strict_types=1);

namespace Hexbay\Support;

use JsonException;

final class Jwt
{
    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $ttlSeconds = 3600
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must contain at least 32 characters.');
        }
        if ($ttlSeconds < 60 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('JWT lifetime must be between 60 and 86400 seconds.');
        }
    }

    /** @param array<string, mixed> $identity
     *  @return array{token: string, expires_at: string, jti: string}
     */
    public function issue(array $identity, ?int $now = null): array
    {
        $issuedAt = $now ?? time();
        $expiresAt = $issuedAt + $this->ttlSeconds;
        $jti = bin2hex(random_bytes(16));

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge(
            $identity,
            [
                'iss' => $this->issuer,
                'aud' => $this->audience,
                'iat' => $issuedAt,
                'nbf' => $issuedAt,
                'exp' => $expiresAt,
                'jti' => $jti,
            ]
        );

        $segments = [
            $this->encodePart($header),
            $this->encodePart($payload),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $this->secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return [
            'token' => implode('.', $segments),
            'expires_at' => gmdate(DATE_ATOM, $expiresAt),
            'jti' => $jti,
        ];
    }

    /** @return array<string, mixed> */
    public function decode(string $token, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'The authentication token is invalid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        try {
            $header = $this->decodePart($encodedHeader);
            $payload = $this->decodePart($encodedPayload);
            $signature = self::base64UrlDecode($encodedSignature);
        } catch (\Throwable) {
            throw new HttpException(401, 'The authentication token is invalid.');
        }

        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw new HttpException(401, 'The authentication token is invalid.');
        }

        $expected = hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            $this->secret,
            true
        );
        if (!hash_equals($expected, $signature)) {
            throw new HttpException(401, 'The authentication token is invalid.');
        }

        $current = $now ?? time();
        if (
            ($payload['iss'] ?? null) !== $this->issuer
            || ($payload['aud'] ?? null) !== $this->audience
            || !isset($payload['sub'], $payload['role'], $payload['jti'])
            || !is_numeric($payload['iat'] ?? null)
            || !is_numeric($payload['nbf'] ?? null)
            || !is_numeric($payload['exp'] ?? null)
            || (int) $payload['nbf'] > $current + 30
            || (int) $payload['exp'] <= $current
        ) {
            throw new HttpException(401, 'The authentication token has expired or is invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $data */
    private function encodePart(array $data): string
    {
        return self::base64UrlEncode(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string, mixed> */
    private function decodePart(string $part): array
    {
        $decoded = json_decode(self::base64UrlDecode($part), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('JWT part is not an object.');
        }
        return $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new \UnexpectedValueException('Invalid base64url data.');
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value . str_repeat('=', $padding), '-_', '+/'),
            true
        );
        if ($decoded === false) {
            throw new \UnexpectedValueException('Invalid base64url data.');
        }
        return $decoded;
    }
}

