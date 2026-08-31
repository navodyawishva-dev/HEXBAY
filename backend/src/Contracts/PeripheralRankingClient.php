<?php
declare(strict_types=1);

namespace Hexbay\Contracts;

interface PeripheralRankingClient
{
    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function rank(array $payload): array;
}
