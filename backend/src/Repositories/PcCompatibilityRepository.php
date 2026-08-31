<?php
declare(strict_types=1);

namespace Hexbay\Repositories;

use PDO;
use RuntimeException;

final class PcCompatibilityRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<int, int> $productIds
     *  @return array<int, array<string, mixed>> keyed by product ID
     */
    public function productsByIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->db->prepare(
            "WITH ranked_offers AS (
                SELECT l.canonical_product_id, l.id listing_id, l.price,
                       s.id shop_id, s.name shop_name,
                       GREATEST(i.quantity_on_hand-i.quantity_reserved, 0)
                           available_quantity,
                       ROW_NUMBER() OVER (
                           PARTITION BY l.canonical_product_id
                           ORDER BY
                             CASE WHEN i.quantity_on_hand-i.quantity_reserved > 0
                                  THEN 0 ELSE 1 END,
                             l.price, s.rating_average DESC, l.id
                       ) offer_rank
                FROM shop_product_listings l
                INNER JOIN shops s ON s.id=l.shop_id AND s.status='approved'
                INNER JOIN inventory i ON i.listing_id=l.id
                WHERE l.status='active'
            )
            SELECT cp.id product_id, cp.name, cp.model,
                   cp.specification_completeness, c.slug category_slug,
                   c.name category_name, b.name brand,
                   ro.listing_id, ro.price price_lkr, ro.shop_id,
                   ro.shop_name, COALESCE(ro.available_quantity, 0)
                       available_quantity,
                   ppp.performance_tier, ppp.overall_score,
                   ppp.value_score, ppp.efficiency_score,
                   ppp.upgradeability_score, pdq.review_status data_quality_status,
                   pdq.completeness_score data_completeness_score
            FROM canonical_products cp
            INNER JOIN categories c ON c.id=cp.category_id
            INNER JOIN brands b ON b.id=cp.brand_id
            LEFT JOIN ranked_offers ro
                ON ro.canonical_product_id=cp.id AND ro.offer_rank=1
            LEFT JOIN pc_product_performance_profiles ppp
                ON ppp.canonical_product_id=cp.id
            LEFT JOIN pc_product_data_quality pdq
                ON pdq.canonical_product_id=cp.id
            WHERE cp.id IN ({$placeholders}) AND cp.is_active=TRUE
              AND c.is_active=TRUE AND b.is_active=TRUE"
        );
        $statement->execute($productIds);
        $products = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['product_id'];
            $products[$id] = [
                ...$row,
                'product_id' => $id,
                'listing_id' => $row['listing_id'] === null ? null : (int) $row['listing_id'],
                'shop_id' => $row['shop_id'] === null ? null : (int) $row['shop_id'],
                'price_lkr' => $row['price_lkr'] === null ? null : (float) $row['price_lkr'],
                'available_quantity' => (int) $row['available_quantity'],
                'overall_score' => $row['overall_score'] === null ? null : (float) $row['overall_score'],
                'value_score' => $row['value_score'] === null ? null : (float) $row['value_score'],
                'efficiency_score' => $row['efficiency_score'] === null ? null : (float) $row['efficiency_score'],
                'upgradeability_score' => $row['upgradeability_score'] === null ? null : (float) $row['upgradeability_score'],
                'data_completeness_score' => $row['data_completeness_score'] === null
                    ? null : (float) $row['data_completeness_score'],
                'specifications' => [],
            ];
        }
        if ($products === []) {
            return [];
        }

        $loadedIds = array_keys($products);
        $specificationStatement = $this->db->prepare(
            'SELECT ps.canonical_product_id, sd.code, sd.data_type,
                    so.value_code, ps.value_text, ps.value_number,
                    ps.value_boolean, ps.value_json
             FROM product_specifications ps
             INNER JOIN specification_definitions sd ON sd.id=ps.definition_id
             LEFT JOIN specification_options so ON so.id=ps.option_id
             WHERE ps.canonical_product_id IN ('
                . implode(',', array_fill(0, count($loadedIds), '?')) . ')
               AND sd.is_active=TRUE
             ORDER BY ps.canonical_product_id, sd.sort_order, sd.id'
        );
        $specificationStatement->execute($loadedIds);
        foreach ($specificationStatement->fetchAll() as $specification) {
            $productId = (int) $specification['canonical_product_id'];
            $products[$productId]['specifications'][(string) $specification['code']] =
                $this->specificationValue($specification);
        }
        return $products;
    }

    /** @param array<int, int> $productIds
     *  @return array<int, array<int, array<string, mixed>>> keyed by product ID
     */
    public function offersByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($productIds === []) {
            return [];
        }
        $statement = $this->db->prepare(
            'SELECT l.canonical_product_id product_id, l.id listing_id,
                    l.price price_lkr, l.condition_type,
                    l.warranty_summary, s.id shop_id, s.name shop_name,
                    s.rating_average shop_rating,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved, 0)
                        available_quantity
             FROM shop_product_listings l
             INNER JOIN shops s ON s.id=l.shop_id AND s.status="approved"
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE l.status="active"
               AND i.quantity_on_hand-i.quantity_reserved > 0
               AND l.canonical_product_id IN ('
                . implode(',', array_fill(0, count($productIds), '?')) . ')
             ORDER BY l.canonical_product_id, l.price,
                      s.rating_average DESC, l.id'
        );
        $statement->execute($productIds);
        $offers = [];
        foreach ($statement->fetchAll() as $row) {
            $productId = (int) $row['product_id'];
            $offers[$productId][] = [
                'listing_id' => (int) $row['listing_id'],
                'shop_id' => (int) $row['shop_id'],
                'shop_name' => (string) $row['shop_name'],
                'price_lkr' => round((float) $row['price_lkr'], 2),
                'available_quantity' => (int) $row['available_quantity'],
                'condition' => (string) $row['condition_type'],
                'warranty_summary' => $row['warranty_summary'] === null
                    ? null : (string) $row['warranty_summary'],
                'shop_rating' => round((float) $row['shop_rating'], 2),
            ];
        }
        return $offers;
    }

    /** @param array<int, int> $excludedProductIds
     *  @return array<int, array<string, mixed>>
     */
    public function candidatesForCategory(
        string $categorySlug,
        array $excludedProductIds,
        int $limit = 100
    ): array {
        $excludedProductIds = array_values(array_unique(array_filter(
            array_map('intval', $excludedProductIds),
            static fn (int $id): bool => $id > 0
        )));
        $whereExcluded = '';
        $params = [$categorySlug];
        if ($excludedProductIds !== []) {
            $whereExcluded = 'AND cp.id NOT IN ('
                . implode(',', array_fill(0, count($excludedProductIds), '?')) . ')';
            $params = [...$params, ...$excludedProductIds];
        }
        $sql = "SELECT cp.id
                FROM canonical_products cp
                INNER JOIN categories c ON c.id=cp.category_id
                INNER JOIN shop_product_listings l
                    ON l.canonical_product_id=cp.id AND l.status='active'
                INNER JOIN shops s ON s.id=l.shop_id AND s.status='approved'
                INNER JOIN inventory i ON i.listing_id=l.id
                LEFT JOIN pc_product_performance_profiles ppp
                    ON ppp.canonical_product_id=cp.id
                WHERE c.slug=? AND cp.is_active=TRUE AND c.is_active=TRUE
                  AND i.quantity_on_hand-i.quantity_reserved > 0
                  {$whereExcluded}
                GROUP BY cp.id, ppp.overall_score, ppp.value_score
                ORDER BY COALESCE(ppp.value_score, 0) DESC,
                         COALESCE(ppp.overall_score, 0) DESC,
                         MIN(l.price), cp.id
                LIMIT " . max(1, min($limit, 200));
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $loaded = $this->productsByIds($ids);
        return array_values(array_filter(array_map(
            static fn (int $id): ?array => $loaded[$id] ?? null,
            $ids
        )));
    }

    /** @param array<int, int> $selectedProductIds
     *  @param array<string, mixed> $result
     */
    public function logValidation(
        string $publicId,
        string $mode,
        array $selectedProductIds,
        array $result,
        ?int $userId = null
    ): void {
        $ruleSetId = $this->ruleSetId((string) $result['rule_set_version']);
        $summary = [
            'overall_status' => $result['overall_status'],
            'complete' => $result['complete'],
            'missing_components' => $result['missing_components'],
            'summary' => $result['summary'],
            'failed_rule_codes' => array_values(array_map(
                static fn (array $check): string => (string) $check['rule_code'],
                array_filter(
                    $result['checks'],
                    static fn (array $check): bool => $check['status'] === 'fail'
                )
            )),
            'warning_rule_codes' => array_values(array_map(
                static fn (array $check): string => (string) $check['rule_code'],
                array_filter(
                    $result['checks'],
                    static fn (array $check): bool => $check['status'] === 'warning'
                )
            )),
        ];
        $statement = $this->db->prepare(
            'INSERT INTO pc_compatibility_validations
                (public_id, user_id, rule_set_id, validation_mode,
                 overall_status, selected_product_ids_json,
                 result_summary_json)
             VALUES
                (:public_id, :user_id, :rule_set, :mode, :status,
                 :products, :summary)'
        );
        $statement->execute([
            'public_id' => $publicId, 'user_id' => $userId,
            'rule_set' => $ruleSetId, 'mode' => $mode,
            'status' => $result['overall_status'],
            'products' => json_encode(
                array_values($selectedProductIds),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
            'summary' => json_encode(
                $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    private function ruleSetId(string $version): int
    {
        $statement = $this->db->prepare(
            'SELECT id FROM pc_compatibility_rule_sets
             WHERE version=:version AND status="active" LIMIT 1'
        );
        $statement->execute(['version' => $version]);
        $id = (int) $statement->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException("Active compatibility rule set {$version} is missing.");
        }
        return $id;
    }

    /** @param array<string, mixed> $row */
    private function specificationValue(array $row): mixed
    {
        return match ($row['data_type']) {
            'integer' => $row['value_number'] === null
                ? null : (int) round((float) $row['value_number']),
            'decimal' => $row['value_number'] === null
                ? null : (float) $row['value_number'],
            'boolean' => $row['value_boolean'] === null
                ? null : (bool) $row['value_boolean'],
            'option' => $row['value_code'],
            'multi_option' => $row['value_json'] === null
                ? []
                : (json_decode((string) $row['value_json'], true) ?: []),
            default => $row['value_text'],
        };
    }
}
