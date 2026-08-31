<?php
declare(strict_types=1);

namespace Hexbay\Repositories;

use Hexbay\Contracts\ProductCatalogueGateway;
use PDO;

final class MarketplaceRepository implements ProductCatalogueGateway
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function activeCategories(): array
    {
        return $this->db->query(
            'SELECT c.id, c.name, c.slug, c.description,
                    (
                        SELECT pi.stored_filename
                        FROM canonical_products cp
                        INNER JOIN shop_product_listings l
                            ON l.canonical_product_id=cp.id AND l.status="active"
                        INNER JOIN shops s
                            ON s.id=l.shop_id AND s.status="approved"
                        INNER JOIN inventory i
                            ON i.listing_id=l.id
                           AND i.quantity_on_hand-i.quantity_reserved > 0
                        INNER JOIN product_images pi ON pi.listing_id=l.id
                        WHERE cp.category_id=c.id AND cp.is_active=1
                        ORDER BY pi.sort_order, pi.id
                        LIMIT 1
                    ) representative_image_filename
             FROM categories c
             WHERE c.is_active = 1
             ORDER BY c.sort_order, c.name'
        )->fetchAll();
    }

    /** @return array<string, mixed> */
    public function currentCommission(): array
    {
        $statement = $this->db->query(
            'SELECT id, percentage, effective_from
             FROM commission_rules
             WHERE effective_from <= CURRENT_TIMESTAMP
               AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
             ORDER BY effective_from DESC
             LIMIT 1'
        );
        $rule = $statement->fetch();
        if ($rule === false) {
            throw new \RuntimeException('No active commission rule is configured.');
        }

        $termsStatement = $this->db->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key'
        );
        $termsStatement->execute(['setting_key' => 'commission_terms']);
        $terms = json_decode((string) $termsStatement->fetchColumn(), true);

        return [
            'id' => (int) $rule['id'],
            'percentage' => (string) $rule['percentage'],
            'effective_from' => $rule['effective_from'],
            'terms_version' => (string) ($terms['version'] ?? '2026-07-v1'),
            'summary' => (string) (
                $terms['summary']
                ?? 'Commission applies to completed vendor sub-orders.'
            ),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function notifications(int $userId, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT n.id, n.type, n.title, n.message,
                    n.related_resource_type, n.related_resource_id,
                    n.read_at, n.created_at,
                    CASE
                        WHEN n.related_resource_type="order"
                            THEN n.related_resource_id
                        WHEN n.related_resource_type="vendor_sub_order"
                            THEN so.order_id
                        ELSE NULL
                    END order_id
             FROM notifications n
             LEFT JOIN vendor_sub_orders so
                ON n.related_resource_type="vendor_sub_order"
               AND so.id=n.related_resource_id
             WHERE n.user_id = :user_id
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', min(max($limit, 1), 50), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function unreadNotificationCount(int $userId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE user_id=:user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    public function markNotificationRead(int $notificationId, int $userId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE notifications
             SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $userId]);
        if ($statement->rowCount() === 1) {
            return true;
        }
        $owned = $this->db->prepare(
            'SELECT 1 FROM notifications WHERE id=:id AND user_id=:user_id'
        );
        $owned->execute(['id' => $notificationId, 'user_id' => $userId]);
        return $owned->fetchColumn() !== false;
    }

    public function markAllNotificationsRead(int $userId): int
    {
        $statement = $this->db->prepare(
            'UPDATE notifications SET read_at=CURRENT_TIMESTAMP
             WHERE user_id=:user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->rowCount();
    }

    /** @param array<string, mixed> $filters
     *  @return array<string, mixed>
     */
    public function catalogue(array $filters): array
    {
        $page = max(1, min((int) ($filters['page'] ?? 1), 10000));
        $perPage = max(6, min((int) ($filters['per_page'] ?? 12), 36));
        $search = substr(trim((string) ($filters['search'] ?? '')), 0, 100);
        $category = substr(trim((string) ($filters['category'] ?? '')), 0, 120);
        $brandId = max(0, (int) ($filters['brand_id'] ?? 0));
        $minimumPrice = is_numeric($filters['min_price'] ?? null)
            ? max(0, (float) $filters['min_price']
            ) : null;
        $maximumPrice = is_numeric($filters['max_price'] ?? null)
            ? max(0, (float) $filters['max_price'])
            : null;
        $availableOnly = filter_var(
            $filters['available'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $sort = (string) ($filters['sort'] ?? 'featured');
        $sortSql = match ($sort) {
            'price_low' => 'starting_price ASC, cp.name ASC',
            'price_high' => 'starting_price DESC, cp.name ASC',
            'rating' => 'best_shop_rating DESC, cp.name ASC',
            'newest' => 'newest_listing_at DESC',
            'name' => 'cp.name ASC',
            default => 'available_quantity DESC, offer_count DESC, cp.name ASC',
        };

        $where = [
            'l.status="active"',
            's.status="approved"',
            'c.is_active=TRUE',
            'b.is_active=TRUE',
        ];
        $params = [];
        if ($search !== '') {
            $where[] = "CONCAT_WS(' ', cp.name, cp.model, b.name, c.name)
                LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        if ($category !== '') {
            $where[] = 'c.slug=:category';
            $params['category'] = $category;
        }
        if ($brandId > 0) {
            $where[] = 'b.id=:brand_id';
            $params['brand_id'] = $brandId;
        }
        if ($minimumPrice !== null) {
            $where[] = 'l.price>=:minimum_price';
            $params['minimum_price'] = $minimumPrice;
        }
        if ($maximumPrice !== null) {
            $where[] = 'l.price<=:maximum_price';
            $params['maximum_price'] = $maximumPrice;
        }
        if ($availableOnly) {
            $where[] = '(i.quantity_on_hand-i.quantity_reserved)>0';
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare(
            "SELECT COUNT(DISTINCT cp.id)
             FROM canonical_products cp
             INNER JOIN categories c ON c.id=cp.category_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE {$whereSql}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $statement = $this->db->prepare(
            "SELECT cp.id, cp.name, cp.model, cp.specification_completeness,
                    c.id category_id, c.name category_name, c.slug category_slug,
                    b.id brand_id, b.name brand_name,
                    MIN(l.price) starting_price,
                    COUNT(DISTINCT l.id) offer_count,
                    SUM(GREATEST(i.quantity_on_hand-i.quantity_reserved,0))
                        available_quantity,
                    MAX(s.rating_average) best_shop_rating,
                    MAX(l.created_at) newest_listing_at,
                    (
                        SELECT pi.stored_filename
                        FROM product_images pi
                        INNER JOIN shop_product_listings image_listing
                          ON image_listing.id=pi.listing_id
                        INNER JOIN shops image_shop
                          ON image_shop.id=image_listing.shop_id
                        WHERE image_listing.canonical_product_id=cp.id
                          AND image_listing.status='active'
                          AND image_shop.status='approved'
                        ORDER BY pi.sort_order, pi.id
                        LIMIT 1
                    ) image_filename
             FROM canonical_products cp
             INNER JOIN categories c ON c.id=cp.category_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE {$whereSql}
             GROUP BY cp.id, cp.name, cp.model, cp.specification_completeness,
                      c.id, c.name, c.slug, b.id, b.name
             ORDER BY {$sortSql}
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $brands = $this->db->query(
            'SELECT DISTINCT b.id, b.name
             FROM brands b
             INNER JOIN canonical_products cp ON cp.brand_id=b.id
             INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id
             INNER JOIN shops s ON s.id=l.shop_id
             WHERE b.is_active=TRUE AND l.status="active" AND s.status="approved"
             ORDER BY b.name'
        )->fetchAll();

        return [
            'products' => $statement->fetchAll(),
            'brands' => $brands,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'brand_id' => $brandId,
                'min_price' => $minimumPrice,
                'max_price' => $maximumPrice,
                'available' => $availableOnly,
                'sort' => $sort,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT cp.id, cp.name, cp.model, cp.specification_completeness,
                    c.id category_id, c.name category_name, c.slug category_slug,
                    b.id brand_id, b.name brand_name,
                    COALESCE(AVG(CASE WHEN r.status="published" THEN r.rating END),0)
                        rating_average,
                    COUNT(DISTINCT CASE WHEN r.status="published" THEN r.id END)
                        rating_count
             FROM canonical_products cp
             INNER JOIN categories c ON c.id=cp.category_id
             INNER JOIN brands b ON b.id=cp.brand_id
             LEFT JOIN reviews r ON r.canonical_product_id=cp.id
             WHERE cp.id=:id AND c.is_active=TRUE AND b.is_active=TRUE
             GROUP BY cp.id, cp.name, cp.model, cp.specification_completeness,
                      c.id, c.name, c.slug, b.id, b.name'
        );
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();
        if ($product === false) {
            return null;
        }
        $offers = $this->db->prepare(
            'SELECT l.id listing_id, l.sku, l.condition_type, l.price,
                    l.vendor_description, l.warranty_summary,
                    s.id shop_id, s.name shop_name, s.logo_path,
                    s.rating_average shop_rating, s.rating_count shop_rating_count,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                        available_quantity,
                    (
                        SELECT pi.stored_filename
                        FROM product_images pi
                        WHERE pi.listing_id=l.id
                        ORDER BY pi.sort_order, pi.id LIMIT 1
                    ) image_filename
             FROM shop_product_listings l
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE l.canonical_product_id=:product_id
               AND l.status="active" AND s.status="approved"
             ORDER BY
                CASE WHEN i.quantity_on_hand-i.quantity_reserved > 0 THEN 0 ELSE 1 END,
                l.price, s.rating_average DESC'
        );
        $offers->execute(['product_id' => $productId]);
        $offerRows = $offers->fetchAll();
        if ($offerRows === []) {
            return null;
        }
        $specifications = $this->db->prepare(
            'SELECT sd.display_name, sd.code, sd.data_type, sd.unit,
                    COALESCE(
                        so.display_value,
                        ps.value_text,
                        CAST(ps.value_number AS CHAR),
                        CAST(ps.value_boolean AS CHAR),
                        CAST(ps.value_json AS CHAR)
                    ) specification_value
             FROM product_specifications ps
             INNER JOIN specification_definitions sd ON sd.id=ps.definition_id
             LEFT JOIN specification_options so ON so.id=ps.option_id
             WHERE ps.canonical_product_id=:product_id
             ORDER BY sd.sort_order, sd.display_name'
        );
        $specifications->execute(['product_id' => $productId]);
        $reviews = $this->db->prepare(
            'SELECT r.id, r.rating, r.title, r.review_text,
                    r.is_verified_purchase, r.created_at,
                    CONCAT(cp.first_name, " ", LEFT(cp.last_name,1), ".") reviewer_name,
                    s.name shop_name
             FROM reviews r
             INNER JOIN customer_profiles cp ON cp.user_id=r.customer_user_id
             INNER JOIN shops s ON s.id=r.shop_id
             WHERE r.canonical_product_id=:product_id AND r.status="published"
             ORDER BY r.created_at DESC LIMIT 20'
        );
        $reviews->execute(['product_id' => $productId]);
        $product['offers'] = $offerRows;
        $product['specifications'] = $specifications->fetchAll();
        $product['reviews'] = $reviews->fetchAll();
        return $product;
    }

    /** @return array<string, mixed>|null */
    public function shop(int $shopId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, slug, description, address_text, contact_phone,
                    contact_email, logo_path, rating_average, rating_count,
                    approved_at
             FROM shops WHERE id=:id AND status="approved"'
        );
        $statement->execute(['id' => $shopId]);
        $shop = $statement->fetch();
        if ($shop === false) {
            return null;
        }
        $products = $this->db->prepare(
            'SELECT l.id listing_id, l.price, l.condition_type,
                    cp.id product_id, cp.name product_name, cp.model,
                    b.name brand_name, c.name category_name,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                        available_quantity,
                    (
                        SELECT pi.stored_filename FROM product_images pi
                        WHERE pi.listing_id=l.id
                        ORDER BY pi.sort_order, pi.id LIMIT 1
                    ) image_filename
             FROM shop_product_listings l
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN categories c ON c.id=cp.category_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE l.shop_id=:shop_id AND l.status="active"
             ORDER BY cp.name, l.price'
        );
        $products->execute(['shop_id' => $shopId]);
        $shop['products'] = $products->fetchAll();
        return $shop;
    }

    /** @return array<int, array<string, mixed>> */
    public function featuredProducts(int $limit = 6): array
    {
        return $this->catalogue([
            'page' => 1,
            'per_page' => max(6, min($limit, 12)),
            'available' => true,
            'sort' => 'featured',
        ])['products'];
    }
}
