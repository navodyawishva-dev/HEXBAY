<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Contracts\LaptopRankingClient;
use Hexbay\Support\HttpException;

final class FlaskLaptopRankingClient implements LaptopRankingClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 8
    ) {
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function rank(array $payload): array
    {
        if (trim($this->secret) === '') {
            throw new HttpException(
                503,
                'Laptop recommendations are not configured yet.',
                ['service' => ['Run setup-hexbay-ai.bat once.']]
            );
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HttpException(
                500,
                'The recommendation request could not be prepared.',
                null,
                $exception
            );
        }

        $handle = curl_init(
            rtrim($this->baseUrl, '/') . '/internal/recommend/laptops'
        );
        if ($handle === false) {
            throw new HttpException(503, 'The recommendation service is unavailable.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => max(3, min($this->timeoutSeconds, 20)),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Hexbay-Internal-Secret: ' . $this->secret,
            ],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($body === false || $status === 0) {
            throw new HttpException(
                503,
                'The laptop adviser is temporarily unavailable. Please try again shortly.',
                ['service' => [$curlError !== '' ? $curlError : 'No service response.']]
            );
        }
        if (strlen($body) > 2_000_000) {
            throw new HttpException(502, 'The recommendation service returned too much data.');
        }

        try {
            $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HttpException(
                502,
                'The recommendation service returned an unreadable response.',
                null,
                $exception
            );
        }
        if (!is_array($response)) {
            throw new HttpException(502, 'The recommendation service returned an invalid response.');
        }
        if ($status >= 400 || ($response['success'] ?? false) !== true) {
            $message = (string) (
                $response['message']
                ?? 'The recommendation service could not rank these laptops.'
            );
            $errors = is_array($response['errors'] ?? null)
                ? $response['errors']
                : null;
            throw new HttpException($status === 422 ? 422 : 503, $message, $errors);
        }
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new HttpException(502, 'The recommendation service omitted its result.');
        }
        return $data;
    }
}
