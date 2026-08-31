<?php
declare(strict_types=1);

namespace Hexbay\Repositories;

use PDO;

final class LaptopRecommendationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function eligibleCandidates(int $limit = 500): array
    {
        $statement = $this->db->prepare(
            "WITH ranked_offers AS (
                SELECT
                    cp.id product_id,
                    l.id listing_id,
                    cp.name,
                    cp.model,
                    b.name brand,
                    l.price price_lkr,
                    l.condition_type,
                    s.id shop_id,
                    s.name shop_name,
                    s.rating_average vendor_rating,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved, 0)
                        stock_quantity,
                    ROW_NUMBER() OVER (
                        PARTITION BY cp.id
                        ORDER BY l.price, s.rating_average DESC, l.id
                    ) offer_rank
                FROM canonical_products cp
                INNER JOIN categories c ON c.id=cp.category_id
                INNER JOIN brands b ON b.id=cp.brand_id
                INNER JOIN shop_product_listings l
                    ON l.canonical_product_id=cp.id
                INNER JOIN shops s ON s.id=l.shop_id
                INNER JOIN inventory i ON i.listing_id=l.id
                WHERE cp.is_active=TRUE
                  AND c.slug='laptops'
                  AND c.is_active=TRUE
                  AND b.is_active=TRUE
                  AND l.status='active'
                  AND s.status='approved'
                  AND i.quantity_on_hand-i.quantity_reserved > 0
            )
            SELECT
                ro.product_id,
                ro.listing_id,
                ro.name,
                ro.model,
                ro.brand,
                ro.price_lkr,
                ro.condition_type,
                ro.shop_id,
                ro.shop_name,
                ro.vendor_rating,
                ro.stock_quantity,
                MAX(CASE WHEN sd.code='ram_capacity_gb'
                    THEN ps.value_number END) ram_gb,
                MAX(CASE WHEN sd.code='storage_capacity_gb'
                    THEN ps.value_number END) storage_gb,
                MAX(CASE WHEN sd.code='processor_model'
                    THEN ps.value_text END) cpu,
                COALESCE(
                    MAX(CASE WHEN sd.code='gpu_model' THEN ps.value_text END),
                    'Not specified'
                ) gpu,
                MAX(CASE WHEN sd.code='screen_size_inches'
                    THEN ps.value_number END) screen_size_inches,
                GROUP_CONCAT(
                    DISTINCT CASE WHEN pt.tag_type='intended_use' THEN pt.code END
                    ORDER BY pt.code SEPARATOR ','
                ) tags_csv,
                COALESCE(
                    AVG(CASE WHEN r.status='published' THEN r.rating END),
                    0
                ) rating_average,
                COUNT(DISTINCT CASE WHEN r.status='published' THEN r.id END)
                    rating_count,
                (
                    SELECT pi.stored_filename
                    FROM product_images pi
                    WHERE pi.listing_id=ro.listing_id
                    ORDER BY pi.sort_order, pi.id
                    LIMIT 1
                ) image_filename
            FROM ranked_offers ro
            LEFT JOIN product_specifications ps
                ON ps.canonical_product_id=ro.product_id
            LEFT JOIN specification_definitions sd ON sd.id=ps.definition_id
            LEFT JOIN canonical_product_tags cpt
                ON cpt.canonical_product_id=ro.product_id
            LEFT JOIN product_tags pt ON pt.id=cpt.tag_id AND pt.is_active=TRUE
            LEFT JOIN reviews r ON r.canonical_product_id=ro.product_id
            WHERE ro.offer_rank=1
            GROUP BY
                ro.product_id, ro.listing_id, ro.name, ro.model, ro.brand,
                ro.price_lkr, ro.condition_type, ro.shop_id, ro.shop_name,
                ro.vendor_rating, ro.stock_quantity
            HAVING ram_gb IS NOT NULL
               AND storage_gb IS NOT NULL
               AND cpu IS NOT NULL
            ORDER BY ro.product_id
            LIMIT :limit"
        );
        $statement->bindValue(':limit', max(1, min($limit, 500)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static function (array $row): array {
                $tags = $row['tags_csv'] === null || $row['tags_csv'] === ''
                    ? []
                    : explode(',', (string) $row['tags_csv']);
                unset($row['tags_csv']);
                return [
                    ...$row,
                    'product_id' => (int) $row['product_id'],
                    'listing_id' => (int) $row['listing_id'],
                    'shop_id' => (int) $row['shop_id'],
                    'price_lkr' => (float) $row['price_lkr'],
                    'ram_gb' => (float) $row['ram_gb'],
                    'storage_gb' => (float) $row['storage_gb'],
                    'screen_size_inches' => $row['screen_size_inches'] === null
                        ? null
                        : (float) $row['screen_size_inches'],
                    'rating_average' => (float) $row['rating_average'],
                    'rating_count' => (int) $row['rating_count'],
                    'vendor_rating' => (float) $row['vendor_rating'],
                    'stock_quantity' => (int) $row['stock_quantity'],
                    'tags' => $tags,
                    'eligible' => true,
                ];
            },
            $statement->fetchAll()
        );
    }

    /**
     * @param array<string, mixed> $requestContext
     * @param array<string, mixed> $results
     */
    public function log(
        array $requestContext,
        array $results,
        string $algorithmVersion,
        ?int $userId = null,
        ?string $sessionKey = null
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO recommendation_logs
                (user_id, session_key, recommendation_type, algorithm_version,
                 request_context_json, results_json)
             VALUES
                (:user_id, :session_key, "laptop", :algorithm_version,
                 :request_context_json, :results_json)'
        );
        $statement->execute([
            'user_id' => $userId,
            'session_key' => $sessionKey,
            'algorithm_version' => substr($algorithmVersion, 0, 60),
            'request_context_json' => json_encode(
                $requestContext,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
            'results_json' => json_encode(
                $results,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
        ]);
    }
}
