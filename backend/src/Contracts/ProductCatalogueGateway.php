<?php
declare(strict_types=1);

namespace Hexbay\Contracts;

interface ProductCatalogueGateway
{
    /** @param array<string, mixed> $filters
     *  @return array<string, mixed>
     */
    public function catalogue(array $filters): array;

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array;
}
