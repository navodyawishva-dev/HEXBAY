<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\BuyerValidator;
use PDO;

final class BuyerService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function addresses(int $customerUserId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, label, recipient_name, phone, address_line_1,
                    address_line_2, city, district, postal_code, country_code,
                    is_default, created_at, updated_at
             FROM customer_addresses
             WHERE customer_user_id=:customer_id
             ORDER BY is_default DESC, created_at, id'
        );
        $statement->execute(['customer_id' => $customerUserId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function saveAddress(
        int $customerUserId,
        ?int $addressId,
        array $input
    ): array {
        $data = BuyerValidator::address($input);
        try {
            $this->db->beginTransaction();
            if ($addressId !== null) {
                $owned = $this->db->prepare(
                    'SELECT id FROM customer_addresses
                     WHERE id=:id AND customer_user_id=:customer_id
                     FOR UPDATE'
                );
                $owned->execute(['id' => $addressId, 'customer_id' => $customerUserId]);
                if ($owned->fetchColumn() === false) {
                    throw new HttpException(404, 'Delivery address not found.');
                }
            }
            $count = $this->db->prepare(
                'SELECT COUNT(*) FROM customer_addresses
                 WHERE customer_user_id=:customer_id'
            );
            $count->execute(['customer_id' => $customerUserId]);
            $makeDefault = $data['is_default'] || (int) $count->fetchColumn() === 0;
            if ($makeDefault) {
                $clear = $this->db->prepare(
                    'UPDATE customer_addresses SET is_default=FALSE
                     WHERE customer_user_id=:customer_id'
                );
                $clear->execute(['customer_id' => $customerUserId]);
            }
            $values = [
                'customer_id' => $customerUserId,
                'label' => $data['label'],
                'recipient_name' => $data['recipient_name'],
                'phone' => $data['phone'],
                'line_1' => $data['address_line_1'],
                'line_2' => $data['address_line_2'],
                'city' => $data['city'],
                'district' => $data['district'],
                'postal_code' => $data['postal_code'],
                'country_code' => $data['country_code'],
                'is_default' => $makeDefault ? 1 : 0,
            ];
            if ($addressId === null) {
                $statement = $this->db->prepare(
                    'INSERT INTO customer_addresses
                        (customer_user_id, label, recipient_name, phone,
                         address_line_1, address_line_2, city, district,
                         postal_code, country_code, is_default)
                     VALUES
                        (:customer_id, :label, :recipient_name, :phone,
                         :line_1, :line_2, :city, :district,
                         :postal_code, :country_code, :is_default)'
                );
                $statement->execute($values);
                $addressId = (int) $this->db->lastInsertId();
            } else {
                $statement = $this->db->prepare(
                    'UPDATE customer_addresses
                     SET label=:label, recipient_name=:recipient_name, phone=:phone,
                         address_line_1=:line_1, address_line_2=:line_2,
                         city=:city, district=:district, postal_code=:postal_code,
                         country_code=:country_code, is_default=:is_default
                     WHERE id=:id AND customer_user_id=:customer_id'
                );
                $statement->execute($values + ['id' => $addressId]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->addressById($customerUserId, $addressId);
    }

    public function deleteAddress(int $customerUserId, int $addressId): void
    {
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT is_default FROM customer_addresses
                 WHERE id=:id AND customer_user_id=:customer_id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $addressId, 'customer_id' => $customerUserId]);
            $address = $statement->fetch();
            if ($address === false) {
                throw new HttpException(404, 'Delivery address not found.');
            }
            $delete = $this->db->prepare(
                'DELETE FROM customer_addresses
                 WHERE id=:id AND customer_user_id=:customer_id'
            );
            $delete->execute(['id' => $addressId, 'customer_id' => $customerUserId]);
            if ((bool) $address['is_default']) {
                $replacement = $this->db->prepare(
                    'UPDATE customer_addresses
                     SET is_default=TRUE
                     WHERE customer_user_id=:customer_id
                     ORDER BY created_at, id LIMIT 1'
                );
                $replacement->execute(['customer_id' => $customerUserId]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function wishlist(int $customerUserId): array
    {
        $wishlistId = $this->wishlistId($customerUserId, true);
        $statement = $this->db->prepare(
            'SELECT wi.listing_id, wi.created_at, l.price, l.condition_type,
                    l.status listing_status, l.canonical_product_id product_id,
                    cp.name product_name, cp.model, b.name brand_name,
                    c.name category_name, s.id shop_id, s.name shop_name,
                    s.status shop_status,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                        available_quantity,
                    (
                        SELECT pi.stored_filename FROM product_images pi
                        WHERE pi.listing_id=l.id
                        ORDER BY pi.sort_order, pi.id LIMIT 1
                    ) image_filename
             FROM wishlist_items wi
             INNER JOIN shop_product_listings l ON l.id=wi.listing_id
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN categories c ON c.id=cp.category_id
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE wi.wishlist_id=:wishlist_id
             ORDER BY wi.created_at DESC'
        );
        $statement->execute(['wishlist_id' => $wishlistId]);
        return ['id' => $wishlistId, 'items' => $statement->fetchAll()];
    }

    public function addWishlistItem(
        int $customerUserId,
        int $listingId
    ): array {
        $this->requirePublicListing($listingId);
        $wishlistId = $this->wishlistId($customerUserId, true);
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO wishlist_items (wishlist_id, listing_id)
             VALUES (:wishlist_id, :listing_id)'
        );
        $statement->execute([
            'wishlist_id' => $wishlistId,
            'listing_id' => $listingId,
        ]);
        $this->recordInteraction($customerUserId, $listingId, 'wishlist', 3.0);
        return $this->wishlist($customerUserId);
    }

    public function removeWishlistItem(int $customerUserId, int $listingId): array
    {
        $wishlistId = $this->wishlistId($customerUserId, false);
        if ($wishlistId !== null) {
            $statement = $this->db->prepare(
                'DELETE FROM wishlist_items
                 WHERE wishlist_id=:wishlist_id AND listing_id=:listing_id'
            );
            $statement->execute([
                'wishlist_id' => $wishlistId,
                'listing_id' => $listingId,
            ]);
        }
        return $this->wishlist($customerUserId);
    }

    /** @return array<string, mixed> */
    public function cart(int $customerUserId): array
    {
        $cartId = $this->cartId($customerUserId, true);
        $statement = $this->db->prepare(
            'SELECT ci.id, ci.listing_id, ci.quantity, ci.created_at, ci.updated_at,
                    l.price, l.condition_type, l.status listing_status,
                    l.canonical_product_id product_id, cp.name product_name,
                    cp.model, b.name brand_name, c.name category_name,
                    s.id shop_id, s.name shop_name, s.status shop_status,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                        available_quantity,
                    (
                        SELECT pi.stored_filename FROM product_images pi
                        WHERE pi.listing_id=l.id
                        ORDER BY pi.sort_order, pi.id LIMIT 1
                    ) image_filename
             FROM cart_items ci
             INNER JOIN shop_product_listings l ON l.id=ci.listing_id
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN categories c ON c.id=cp.category_id
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE ci.cart_id=:cart_id
             ORDER BY s.name, ci.created_at'
        );
        $statement->execute(['cart_id' => $cartId]);
        $items = $statement->fetchAll();
        $subtotalCents = 0;
        foreach ($items as &$item) {
            $item['is_available'] = (
                $item['listing_status'] === 'active'
                && $item['shop_status'] === 'approved'
                && (int) $item['available_quantity'] >= (int) $item['quantity']
            );
            $item['line_total'] = number_format(
                ((int) $item['quantity'] * $this->moneyToCents($item['price'])) / 100,
                2,
                '.',
                ''
            );
            if ($item['is_available']) {
                $subtotalCents += (int) $item['quantity']
                    * $this->moneyToCents($item['price']);
            }
        }
        unset($item);
        $setups = $this->cartSetups($cartId);
        $setupsComplete = !in_array(
            false,
            array_column($setups, 'is_complete_in_cart'),
            true
        );
        return [
            'id' => $cartId,
            'items' => $items,
            'setups' => $setups,
            'summary' => [
                'item_count' => count($items),
                'quantity' => array_sum(array_map(
                    static fn (array $item): int => (int) $item['quantity'],
                    $items
                )),
                'subtotal' => $this->centsToMoney($subtotalCents),
                'ready_for_checkout' => $items !== []
                    && !in_array(false, array_column($items, 'is_available'), true)
                    && $setupsComplete,
            ],
        ];
    }

    public function addCartItem(
        int $customerUserId,
        int $listingId,
        array $input
    ): array {
        $quantity = BuyerValidator::quantity($input);
        $listing = $this->requirePublicListing($listingId);
        if ((int) $listing['available_quantity'] < 1) {
            throw new HttpException(409, 'This seller offer is currently out of stock.');
        }
        if ($quantity > (int) $listing['available_quantity']) {
            throw new HttpException(409, 'The requested quantity is not available.', [
                'quantity' => ["Only {$listing['available_quantity']} units are currently available."],
            ]);
        }
        $cartId = $this->cartId($customerUserId, true);
        $statement = $this->db->prepare(
            'INSERT INTO cart_items (cart_id, listing_id, quantity)
             VALUES (:cart_id, :listing_id, :quantity)
             ON DUPLICATE KEY UPDATE
                quantity=LEAST(99, quantity + VALUES(quantity)),
                updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'cart_id' => $cartId,
            'listing_id' => $listingId,
            'quantity' => $quantity,
        ]);
        $current = $this->db->prepare(
            'SELECT quantity FROM cart_items
             WHERE cart_id=:cart_id AND listing_id=:listing_id'
        );
        $current->execute(['cart_id' => $cartId, 'listing_id' => $listingId]);
        if ((int) $current->fetchColumn() > (int) $listing['available_quantity']) {
            $cap = $this->db->prepare(
                'UPDATE cart_items SET quantity=:quantity
                 WHERE cart_id=:cart_id AND listing_id=:listing_id'
            );
            $cap->execute([
                'quantity' => $listing['available_quantity'],
                'cart_id' => $cartId,
                'listing_id' => $listingId,
            ]);
        }
        $this->recordInteraction($customerUserId, $listingId, 'cart', 4.0);
        return $this->cart($customerUserId);
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function addSetupToCart(int $customerUserId, array $input): array
    {
        $items = BuyerValidator::setupCartItems($input);
        $setupIdentity = BuyerValidator::setupIdentity($input);
        $listingIds = array_column($items, 'listing_id');
        $expectedByListing = [];
        foreach ($items as $item) {
            $expectedByListing[$item['listing_id']] = $item;
        }

        try {
            $this->db->beginTransaction();
            $cartId = $this->cartId($customerUserId, true, true);
            $placeholders = [];
            $parameters = ['cart_id' => $cartId];
            foreach ($listingIds as $index => $listingId) {
                $key = "listing_{$index}";
                $placeholders[] = ":{$key}";
                $parameters[$key] = $listingId;
            }
            $statement = $this->db->prepare(
                'SELECT l.id, l.canonical_product_id, l.shop_id, l.price,
                        cp.name product_name, s.name shop_name,
                        l.status listing_status, s.status shop_status,
                        GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                            available_quantity,
                        COALESCE(ci.quantity,0) cart_quantity
                 FROM shop_product_listings l
                 INNER JOIN shops s ON s.id=l.shop_id
                 INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
                 INNER JOIN inventory i ON i.listing_id=l.id
                 LEFT JOIN cart_items ci
                    ON ci.cart_id=:cart_id AND ci.listing_id=l.id
                 WHERE l.id IN (' . implode(',', $placeholders) . ')
                 ORDER BY l.id
                 FOR UPDATE'
            );
            $statement->execute($parameters);
            $listings = $statement->fetchAll();

            if (count($listings) !== count($items)) {
                throw new HttpException(
                    409,
                    'One or more selected seller offers are no longer available. Refresh the recommendations and try again.'
                );
            }

            $productIds = [];
            $listingById = [];
            $shopIds = [];
            $addedSubtotalCents = 0;
            $availabilityErrors = [];
            $priceErrors = [];
            foreach ($listings as $listing) {
                $listingId = (int) $listing['id'];
                $requested = $expectedByListing[$listingId];
                $productId = (int) $listing['canonical_product_id'];
                if (isset($productIds[$productId])) {
                    throw new HttpException(422, 'Complete setup details are invalid.', [
                        'items' => ['Choose only one seller offer for each product.'],
                    ]);
                }
                $productIds[$productId] = true;
                $listingById[$listingId] = $listing;
                if (
                    $listing['listing_status'] !== 'active'
                    || $listing['shop_status'] !== 'approved'
                ) {
                    $availabilityErrors[(string) $listingId] = 'This seller offer is no longer active.';
                    continue;
                }
                $requiredQuantity = (int) $listing['cart_quantity']
                    + (int) $requested['quantity'];
                if (
                    $requiredQuantity > 99
                    || $requiredQuantity > (int) $listing['available_quantity']
                ) {
                    $availabilityErrors[(string) $listingId] = sprintf(
                        'Only %d units are currently available; your cart would require %d.',
                        (int) $listing['available_quantity'],
                        $requiredQuantity
                    );
                }
                $livePriceCents = $this->moneyToCents($listing['price']);
                if ($livePriceCents !== $this->moneyToCents($requested['expected_price_lkr'])) {
                    $priceErrors[(string) $listingId] = [
                        'expected' => $requested['expected_price_lkr'],
                        'current' => $this->centsToMoney($livePriceCents),
                    ];
                }
                $shopIds[(int) $listing['shop_id']] = true;
                $addedSubtotalCents += $livePriceCents * (int) $requested['quantity'];
            }
            if ($availabilityErrors !== []) {
                throw new HttpException(
                    409,
                    'The complete setup was not added because one or more seller offers lack stock. Refresh the recommendations and try again.',
                    ['availability' => $availabilityErrors]
                );
            }
            if ($priceErrors !== []) {
                throw new HttpException(
                    409,
                    'The complete setup was not added because one or more seller prices changed. Refresh the recommendations before continuing.',
                    ['price_changes' => $priceErrors]
                );
            }

            if ($setupIdentity['source_recommendation_id'] !== null) {
                $source = $this->db->prepare(
                    'SELECT result.selected_product_ids_json
                     FROM pc_build_recommendation_requests request
                     INNER JOIN pc_build_recommendation_results result
                        ON result.request_id=request.id
                     WHERE request.public_id=:public_id
                       AND result.result_rank=:build_rank
                     LIMIT 1'
                );
                $source->execute([
                    'public_id' => $setupIdentity['source_recommendation_id'],
                    'build_rank' => $setupIdentity['build_rank'],
                ]);
                $sourceProductsJson = $source->fetchColumn();
                if ($sourceProductsJson === false) {
                    throw new HttpException(
                        409,
                        'The source HexBot recommendation is no longer available. Ask HexBot to refresh the setup.'
                    );
                }
                $sourceProducts = array_map(
                    'intval',
                    (array) json_decode((string) $sourceProductsJson, true, 512, JSON_THROW_ON_ERROR)
                );
                $selectedProducts = array_map('intval', array_keys($productIds));
                sort($sourceProducts);
                sort($selectedProducts);
                if ($sourceProducts !== $selectedProducts) {
                    throw new HttpException(
                        409,
                        'The selected products no longer match this HexBot build. Refresh the recommendations before continuing.'
                    );
                }
            }

            $setupPublicId = $this->uuid();
            $insertSetup = $this->db->prepare(
                'INSERT INTO hexbot_setups
                    (public_id, customer_user_id, cart_id,
                     source_recommendation_public_id, name, build_rank,
                     setup_scope, target_budget_lkr, max_budget_lkr,
                     selected_total_lkr, requirements_json, scores_json,
                     compatibility_json)
                 VALUES
                    (:public_id, :customer_id, :cart_id,
                     :source_recommendation_id, :name, :build_rank,
                     :setup_scope, :target_budget, :max_budget,
                     :selected_total, :requirements, :scores, :compatibility)'
            );
            $insertSetup->execute([
                'public_id' => $setupPublicId,
                'customer_id' => $customerUserId,
                'cart_id' => $cartId,
                'source_recommendation_id' => $setupIdentity['source_recommendation_id'],
                'name' => $setupIdentity['name'],
                'build_rank' => $setupIdentity['build_rank'],
                'setup_scope' => $setupIdentity['setup_scope'],
                'target_budget' => $setupIdentity['target_budget_lkr'],
                'max_budget' => $setupIdentity['max_budget_lkr'],
                'selected_total' => $this->centsToMoney($addedSubtotalCents),
                'requirements' => json_encode(
                    $setupIdentity['requirements'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'scores' => json_encode(
                    $setupIdentity['scores'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'compatibility' => json_encode(
                    $setupIdentity['compatibility'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
            ]);
            $setupId = (int) $this->db->lastInsertId();
            $insertSetupItem = $this->db->prepare(
                'INSERT INTO hexbot_setup_items
                    (setup_id, component_group, component_code, sort_order,
                     canonical_product_id, listing_id, shop_id,
                     product_name_snapshot, shop_name_snapshot, quantity,
                     unit_price_snapshot, line_total_snapshot)
                 VALUES
                    (:setup_id, :component_group, :component_code, :sort_order,
                     :product_id, :listing_id, :shop_id,
                     :product_name, :shop_name, :quantity,
                     :unit_price, :line_total)'
            );
            foreach ($items as $item) {
                $listing = $listingById[$item['listing_id']];
                $unitPriceCents = $this->moneyToCents($listing['price']);
                $insertSetupItem->execute([
                    'setup_id' => $setupId,
                    'component_group' => $item['component_group'],
                    'component_code' => $item['component_code'],
                    'sort_order' => $item['sort_order'],
                    'product_id' => $listing['canonical_product_id'],
                    'listing_id' => $item['listing_id'],
                    'shop_id' => $listing['shop_id'],
                    'product_name' => $listing['product_name'],
                    'shop_name' => $listing['shop_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $this->centsToMoney($unitPriceCents),
                    'line_total' => $this->centsToMoney(
                        $unitPriceCents * (int) $item['quantity']
                    ),
                ]);
            }

            $insert = $this->db->prepare(
                'INSERT INTO cart_items (cart_id, listing_id, quantity)
                 VALUES (:cart_id, :listing_id, :quantity)
                 ON DUPLICATE KEY UPDATE
                    quantity=quantity + VALUES(quantity),
                    updated_at=CURRENT_TIMESTAMP'
            );
            foreach ($items as $item) {
                $insert->execute([
                    'cart_id' => $cartId,
                    'listing_id' => $item['listing_id'],
                    'quantity' => $item['quantity'],
                ]);
                $this->recordInteraction(
                    $customerUserId,
                    $item['listing_id'],
                    'cart',
                    4.0,
                    ['source' => 'hexbot_x_board', 'setup_item_count' => count($items)]
                );
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [
            'cart' => $this->cart($customerUserId),
            'setup' => [
                'id' => $setupId,
                'public_id' => $setupPublicId,
                'name' => $setupIdentity['name'],
                'build_rank' => $setupIdentity['build_rank'],
                'setup_scope' => $setupIdentity['setup_scope'],
                'added_item_count' => count($items),
                'shop_count' => count($shopIds),
                'added_subtotal' => $this->centsToMoney($addedSubtotalCents),
                'validated_at' => gmdate('c'),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function cartSetups(int $cartId): array
    {
        $statement = $this->db->prepare(
            'SELECT setup.id, setup.public_id, setup.name, setup.build_rank,
                    setup.setup_scope, setup.status,
                    setup.source_recommendation_public_id,
                    setup.target_budget_lkr, setup.max_budget_lkr,
                    setup.selected_total_lkr, setup.currency_code,
                    setup.requirements_json, setup.scores_json,
                    setup.compatibility_json, setup.created_at,
                    COUNT(item.id) item_count,
                    COUNT(DISTINCT item.shop_id) shop_count,
                    SUM(CASE WHEN cart_item.quantity >= item.quantity THEN 1 ELSE 0 END)
                        present_item_count
             FROM hexbot_setups setup
             INNER JOIN hexbot_setup_items item ON item.setup_id=setup.id
             LEFT JOIN cart_items cart_item
                ON cart_item.cart_id=setup.cart_id
               AND cart_item.listing_id=item.listing_id
             WHERE setup.cart_id=:cart_id AND setup.status="in_cart"
             GROUP BY setup.id
             ORDER BY setup.created_at DESC, setup.id DESC'
        );
        $statement->execute(['cart_id' => $cartId]);
        $setups = $statement->fetchAll();
        if ($setups === []) {
            return [];
        }
        $setupIds = array_map('intval', array_column($setups, 'id'));
        $placeholders = implode(',', array_fill(0, count($setupIds), '?'));
        $items = $this->db->prepare(
            'SELECT id, setup_id, component_group, component_code, sort_order,
                    canonical_product_id product_id, listing_id, shop_id,
                    product_name_snapshot product_name,
                    shop_name_snapshot shop_name, quantity,
                    unit_price_snapshot unit_price,
                    line_total_snapshot line_total
             FROM hexbot_setup_items
             WHERE setup_id IN (' . $placeholders . ')
             ORDER BY setup_id, sort_order, id'
        );
        $items->execute($setupIds);
        $itemsBySetup = [];
        foreach ($items->fetchAll() as $item) {
            $itemsBySetup[(int) $item['setup_id']][] = $item;
        }
        foreach ($setups as &$setup) {
            $setup['requirements'] = json_decode(
                (string) $setup['requirements_json'], true, 512, JSON_THROW_ON_ERROR
            );
            $setup['scores'] = json_decode(
                (string) $setup['scores_json'], true, 512, JSON_THROW_ON_ERROR
            );
            $setup['compatibility'] = json_decode(
                (string) $setup['compatibility_json'], true, 512, JSON_THROW_ON_ERROR
            );
            unset(
                $setup['requirements_json'],
                $setup['scores_json'],
                $setup['compatibility_json']
            );
            $setup['item_count'] = (int) $setup['item_count'];
            $setup['shop_count'] = (int) $setup['shop_count'];
            $setup['present_item_count'] = (int) $setup['present_item_count'];
            $setup['is_complete_in_cart'] = $setup['item_count'] === $setup['present_item_count'];
            $setup['items'] = $itemsBySetup[(int) $setup['id']] ?? [];
        }
        unset($setup);
        return $setups;
    }

    public function updateCartItem(
        int $customerUserId,
        int $cartItemId,
        array $input
    ): array {
        $quantity = BuyerValidator::quantity($input);
        $cartId = $this->cartId($customerUserId, false);
        if ($cartId === null) {
            throw new HttpException(404, 'Cart item not found.');
        }
        $statement = $this->db->prepare(
            'SELECT ci.listing_id,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                        available_quantity,
                    l.status listing_status, s.status shop_status
             FROM cart_items ci
             INNER JOIN shop_product_listings l ON l.id=ci.listing_id
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE ci.id=:id AND ci.cart_id=:cart_id'
        );
        $statement->execute(['id' => $cartItemId, 'cart_id' => $cartId]);
        $item = $statement->fetch();
        if ($item === false) {
            throw new HttpException(404, 'Cart item not found.');
        }
        if (
            $item['listing_status'] !== 'active'
            || $item['shop_status'] !== 'approved'
            || $quantity > (int) $item['available_quantity']
        ) {
            throw new HttpException(409, 'The requested quantity is not available.');
        }
        $update = $this->db->prepare(
            'UPDATE cart_items SET quantity=:quantity
             WHERE id=:id AND cart_id=:cart_id'
        );
        $update->execute([
            'quantity' => $quantity,
            'id' => $cartItemId,
            'cart_id' => $cartId,
        ]);
        return $this->cart($customerUserId);
    }

    public function removeCartItem(int $customerUserId, int $cartItemId): array
    {
        $cartId = $this->cartId($customerUserId, false);
        if ($cartId !== null) {
            $statement = $this->db->prepare(
                'DELETE FROM cart_items WHERE id=:id AND cart_id=:cart_id'
            );
            $statement->execute(['id' => $cartItemId, 'cart_id' => $cartId]);
        }
        return $this->cart($customerUserId);
    }

    /** @return array<string, mixed> */
    public function restoreCartSetup(
        int $customerUserId,
        string $setupPublicId,
        string $ipAddress
    ): array {
        $cartId = $this->cartId($customerUserId, false, true);
        if ($cartId === null) {
            throw new HttpException(404, 'Saved setup not found in this cart.');
        }
        $this->validateSetupPublicId($setupPublicId);
        try {
            $this->db->beginTransaction();
            $setup = $this->db->prepare(
                'SELECT id FROM hexbot_setups
                 WHERE public_id=:public_id AND customer_user_id=:customer_id
                   AND cart_id=:cart_id AND status="in_cart"
                 FOR UPDATE'
            );
            $setup->execute([
                'public_id' => $setupPublicId,
                'customer_id' => $customerUserId,
                'cart_id' => $cartId,
            ]);
            $setupId = (int) $setup->fetchColumn();
            if ($setupId < 1) {
                throw new HttpException(404, 'Saved setup not found in this cart.');
            }
            $items = $this->db->prepare(
                'SELECT item.listing_id, item.quantity, item.product_name_snapshot,
                        l.status listing_status, s.status shop_status,
                        GREATEST(i.quantity_on_hand-i.quantity_reserved,0) available_quantity
                 FROM hexbot_setup_items item
                 INNER JOIN shop_product_listings l ON l.id=item.listing_id
                 INNER JOIN shops s ON s.id=l.shop_id
                 INNER JOIN inventory i ON i.listing_id=l.id
                 WHERE item.setup_id=:setup_id
                 ORDER BY item.sort_order, item.id
                 FOR UPDATE'
            );
            $items->execute(['setup_id' => $setupId]);
            $insert = $this->db->prepare(
                'INSERT INTO cart_items (cart_id, listing_id, quantity)
                 VALUES (:cart_id, :listing_id, :quantity)
                 ON DUPLICATE KEY UPDATE
                    quantity=GREATEST(quantity, VALUES(quantity)),
                    updated_at=CURRENT_TIMESTAMP'
            );
            foreach ($items->fetchAll() as $item) {
                if (
                    $item['listing_status'] !== 'active'
                    || $item['shop_status'] !== 'approved'
                    || (int) $item['available_quantity'] < (int) $item['quantity']
                ) {
                    throw new HttpException(
                        409,
                        $item['product_name_snapshot']
                            . ' cannot currently be restored. Refresh the build or choose another seller offer.'
                    );
                }
                $insert->execute([
                    'cart_id' => $cartId,
                    'listing_id' => $item['listing_id'],
                    'quantity' => $item['quantity'],
                ]);
            }
            $this->users->audit(
                $customerUserId,
                'buyer.hexbot_setup_restored',
                'hexbot_setup',
                $setupId,
                ['public_id' => $setupPublicId],
                $ipAddress
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->cart($customerUserId);
    }

    /** @return array<string, mixed> */
    public function releaseCartSetup(
        int $customerUserId,
        string $setupPublicId,
        string $ipAddress
    ): array {
        $this->validateSetupPublicId($setupPublicId);
        $owned = $this->db->prepare(
            'SELECT id FROM hexbot_setups
             WHERE public_id=:public_id AND customer_user_id=:customer_id
               AND status="in_cart"'
        );
        $owned->execute([
            'public_id' => $setupPublicId,
            'customer_id' => $customerUserId,
        ]);
        $setupId = (int) $owned->fetchColumn();
        if ($setupId < 1) {
            throw new HttpException(404, 'Saved setup not found in this cart.');
        }
        $statement = $this->db->prepare(
            'UPDATE hexbot_setups
             SET status="cancelled"
             WHERE public_id=:public_id AND customer_user_id=:customer_id
               AND status="in_cart"'
        );
        $statement->execute([
            'public_id' => $setupPublicId,
            'customer_id' => $customerUserId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new HttpException(404, 'Saved setup not found in this cart.');
        }
        $this->users->audit(
            $customerUserId,
            'buyer.hexbot_setup_released',
            'hexbot_setup',
            $setupId,
            ['public_id' => $setupPublicId, 'cart_items_kept' => true],
            $ipAddress
        );
        return $this->cart($customerUserId);
    }

    private function validateSetupPublicId(string $setupPublicId): void
    {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                strtolower(trim($setupPublicId))
            ) !== 1
        ) {
            throw new HttpException(404, 'Saved setup not found in this cart.');
        }
    }

    /** @return array<string, mixed> */
    public function checkout(
        int $customerUserId,
        array $input,
        string $ipAddress
    ): array {
        $checkout = BuyerValidator::checkout($input);
        try {
            $this->db->beginTransaction();
            $addressStatement = $this->db->prepare(
                'SELECT id, label, recipient_name, phone, address_line_1,
                        address_line_2, city, district, postal_code, country_code
                 FROM customer_addresses
                 WHERE id=:id AND customer_user_id=:customer_id
                 FOR UPDATE'
            );
            $addressStatement->execute([
                'id' => $checkout['address_id'],
                'customer_id' => $customerUserId,
            ]);
            $address = $addressStatement->fetch();
            if ($address === false) {
                throw new HttpException(404, 'Delivery address not found.');
            }
            $cartId = $this->cartId($customerUserId, false, true);
            if ($cartId === null) {
                throw new HttpException(409, 'Your cart is empty.');
            }
            $lines = $this->db->prepare(
                'SELECT ci.id cart_item_id, ci.quantity, l.id listing_id,
                        l.shop_id, l.canonical_product_id, l.sku, l.price,
                        l.condition_type, l.status listing_status,
                        cp.name product_name, cp.model, b.name brand_name,
                        s.name shop_name, s.status shop_status,
                        s.owner_user_id, i.id inventory_id,
                        i.quantity_on_hand, i.quantity_reserved
                 FROM cart_items ci
                 INNER JOIN shop_product_listings l ON l.id=ci.listing_id
                 INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
                 INNER JOIN brands b ON b.id=cp.brand_id
                 INNER JOIN shops s ON s.id=l.shop_id
                 INNER JOIN inventory i ON i.listing_id=l.id
                 WHERE ci.cart_id=:cart_id
                 ORDER BY l.id
                 FOR UPDATE'
            );
            $lines->execute(['cart_id' => $cartId]);
            $cartLines = $lines->fetchAll();
            if ($cartLines === []) {
                throw new HttpException(409, 'Your cart is empty.');
            }
            $availabilityErrors = [];
            foreach ($cartLines as $line) {
                $available = (int) $line['quantity_on_hand']
                    - (int) $line['quantity_reserved'];
                if (
                    $line['listing_status'] !== 'active'
                    || $line['shop_status'] !== 'approved'
                    || (int) $line['quantity'] > $available
                ) {
                    $availabilityErrors[(string) $line['listing_id']] = [
                        $line['product_name'] . " has {$available} available.",
                    ];
                }
            }
            if ($availabilityErrors !== []) {
                throw new HttpException(
                    409,
                    'One or more cart items are no longer available.',
                    ['listings' => $availabilityErrors]
                );
            }
            $commission = $this->db->query(
                'SELECT id, percentage FROM commission_rules
                 WHERE effective_from <= CURRENT_TIMESTAMP
                   AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
                 ORDER BY effective_from DESC LIMIT 1
                 FOR UPDATE'
            )->fetch();
            if ($commission === false) {
                throw new HttpException(409, 'Checkout is unavailable until a commission rule is configured.');
            }
            $groups = [];
            $grandTotalCents = 0;
            foreach ($cartLines as &$line) {
                $line['unit_price_cents'] = $this->moneyToCents($line['price']);
                $line['line_total_cents'] = $line['unit_price_cents']
                    * (int) $line['quantity'];
                $grandTotalCents += $line['line_total_cents'];
                $groups[(int) $line['shop_id']][] = $line;
            }
            unset($line);
            $expectedTotalCents = $this->moneyToCents($checkout['expected_total_lkr']);
            if ($expectedTotalCents !== $grandTotalCents) {
                throw new HttpException(
                    409,
                    'Your checkout total changed. Review the refreshed cart before placing the order.',
                    [
                        'expected_total_lkr' => [$checkout['expected_total_lkr']],
                        'current_total_lkr' => [$this->centsToMoney($grandTotalCents)],
                    ]
                );
            }

            $setupStatement = $this->db->prepare(
                'SELECT id, public_id
                 FROM hexbot_setups
                 WHERE customer_user_id=:customer_id
                   AND cart_id=:cart_id AND status="in_cart"
                 ORDER BY public_id
                 FOR UPDATE'
            );
            $setupStatement->execute([
                'customer_id' => $customerUserId,
                'cart_id' => $cartId,
            ]);
            $activeSetups = $setupStatement->fetchAll();
            $activeSetupPublicIds = array_map(
                static fn (array $setup): string => (string) $setup['public_id'],
                $activeSetups
            );
            if ($activeSetupPublicIds !== $checkout['setup_public_ids']) {
                throw new HttpException(
                    409,
                    'Your saved HexBot setups changed. Review the refreshed checkout before continuing.',
                    ['setup_public_ids' => ['The setup list no longer matches this checkout.']]
                );
            }
            $activeSetupIds = array_map('intval', array_column($activeSetups, 'id'));
            if ($activeSetupIds !== []) {
                $placeholders = implode(',', array_fill(0, count($activeSetupIds), '?'));
                $setupItems = $this->db->prepare(
                    'SELECT setup_id, listing_id, quantity, product_name_snapshot
                     FROM hexbot_setup_items
                     WHERE setup_id IN (' . $placeholders . ')
                     ORDER BY setup_id, sort_order, id
                     FOR UPDATE'
                );
                $setupItems->execute($activeSetupIds);
                $requiredByListing = [];
                $productNames = [];
                foreach ($setupItems->fetchAll() as $setupItem) {
                    $listingId = (int) $setupItem['listing_id'];
                    $requiredByListing[$listingId] = ($requiredByListing[$listingId] ?? 0)
                        + (int) $setupItem['quantity'];
                    $productNames[$listingId] = (string) $setupItem['product_name_snapshot'];
                }
                $cartQuantityByListing = [];
                foreach ($cartLines as $cartLine) {
                    $cartQuantityByListing[(int) $cartLine['listing_id']]
                        = (int) $cartLine['quantity'];
                }
                $missingSetupItems = [];
                foreach ($requiredByListing as $listingId => $requiredQuantity) {
                    $cartQuantity = $cartQuantityByListing[$listingId] ?? 0;
                    if ($cartQuantity < $requiredQuantity) {
                        $missingSetupItems[(string) $listingId] = [
                            ($productNames[$listingId] ?? 'A setup product')
                                . " requires {$requiredQuantity}; the cart has {$cartQuantity}.",
                        ];
                    }
                }
                if ($missingSetupItems !== []) {
                    throw new HttpException(
                        409,
                        'A saved HexBot setup is incomplete. Restore its missing products before checkout.',
                        ['setup_items' => $missingSetupItems]
                    );
                }
            }
            $orderNumber = 'HB-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $orderInsert = $this->db->prepare(
                'INSERT INTO orders
                    (order_number, customer_user_id, address_snapshot_json,
                     grand_total, payment_method, payment_status,
                     simulated_payment_notice_accepted_at, status)
                 VALUES (:number, :customer_id, :address, :total,
                         :payment_method, :payment_status,
                         CURRENT_TIMESTAMP, "pending")'
            );
            $orderInsert->execute([
                'number' => $orderNumber,
                'customer_id' => $customerUserId,
                'address' => json_encode(
                    $address,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'total' => $this->centsToMoney($grandTotalCents),
                'payment_method' => $checkout['payment_method'],
                'payment_status' => $checkout['payment_status'],
            ]);
            $orderId = (int) $this->db->lastInsertId();
            $subOrderInsert = $this->db->prepare(
                'INSERT INTO vendor_sub_orders
                    (order_id, shop_id, sub_order_number, gross_total,
                     commission_rule_id, commission_rate_snapshot)
                 VALUES
                    (:order_id, :shop_id, :number, :gross_total,
                     :commission_rule_id, :commission_rate)'
            );
            $itemInsert = $this->db->prepare(
                'INSERT INTO order_items
                    (sub_order_id, listing_id, canonical_product_id,
                     product_name_snapshot, sku_snapshot,
                     specification_snapshot_json, quantity, unit_price, line_total)
                 VALUES
                    (:sub_order_id, :listing_id, :product_id, :product_name,
                     :sku, :specifications, :quantity, :unit_price, :line_total)'
            );
            $inventoryUpdate = $this->db->prepare(
                'UPDATE inventory
                 SET quantity_on_hand=quantity_on_hand-:quantity, version=version+1
                 WHERE id=:id
                   AND (quantity_on_hand-quantity_reserved)>=:available_quantity'
            );
            $movement = $this->db->prepare(
                'INSERT INTO inventory_movements
                    (inventory_id, movement_type, quantity_delta, quantity_after,
                     reference_type, reference_id, reason, actor_user_id)
                 VALUES
                    (:inventory_id, "sale", :delta, :after,
                     "order_item", :reference_id, :reason, :actor)'
            );
            foreach ($groups as $shopId => $shopLines) {
                $shopTotalCents = array_sum(array_column($shopLines, 'line_total_cents'));
                $subOrderInsert->execute([
                    'order_id' => $orderId,
                    'shop_id' => $shopId,
                    'number' => $orderNumber . '-S' . $shopId,
                    'gross_total' => $this->centsToMoney($shopTotalCents),
                    'commission_rule_id' => $commission['id'],
                    'commission_rate' => $commission['percentage'],
                ]);
                $subOrderId = (int) $this->db->lastInsertId();
                foreach ($shopLines as $line) {
                    $itemInsert->execute([
                        'sub_order_id' => $subOrderId,
                        'listing_id' => $line['listing_id'],
                        'product_id' => $line['canonical_product_id'],
                        'product_name' => $line['product_name'],
                        'sku' => $line['sku'],
                        'specifications' => $this->specificationSnapshot(
                            (int) $line['canonical_product_id']
                        ),
                        'quantity' => $line['quantity'],
                        'unit_price' => $this->centsToMoney($line['unit_price_cents']),
                        'line_total' => $this->centsToMoney($line['line_total_cents']),
                    ]);
                    $orderItemId = (int) $this->db->lastInsertId();
                    $inventoryUpdate->execute([
                        'quantity' => $line['quantity'],
                        'id' => $line['inventory_id'],
                        'available_quantity' => $line['quantity'],
                    ]);
                    if ($inventoryUpdate->rowCount() !== 1) {
                        throw new HttpException(
                            409,
                            $line['product_name'] . ' sold out during checkout.'
                        );
                    }
                    $after = (int) $line['quantity_on_hand'] - (int) $line['quantity'];
                    $movement->execute([
                        'inventory_id' => $line['inventory_id'],
                        'delta' => -(int) $line['quantity'],
                        'after' => $after,
                        'reference_id' => $orderItemId,
                        'reason' => 'Stock sold through ' . $orderNumber,
                        'actor' => $customerUserId,
                    ]);
                    $this->recordInteraction(
                        $customerUserId,
                        (int) $line['listing_id'],
                        'purchase',
                        6.0,
                        ['order_id' => $orderId]
                    );
                }
                $this->notify(
                    (int) $shopLines[0]['owner_user_id'],
                    'new_order',
                    'New Hexbay order',
                    $orderNumber . ' includes products from your shop.',
                    'vendor_sub_order',
                    $subOrderId
                );
            }
            if ($activeSetupIds !== []) {
                $placeholders = implode(',', array_fill(0, count($activeSetupIds), '?'));
                $linkSetups = $this->db->prepare(
                    'UPDATE hexbot_setups
                     SET order_id=?, status="ordered"
                     WHERE id IN (' . $placeholders . ')
                       AND cart_id=? AND status="in_cart"'
                );
                $linkSetups->execute([
                    $orderId,
                    ...$activeSetupIds,
                    $cartId,
                ]);
                if ($linkSetups->rowCount() !== count($activeSetupIds)) {
                    throw new HttpException(
                        409,
                        'A saved HexBot setup changed during checkout. Please try again.'
                    );
                }
            }
            $converted = $this->db->prepare(
                'UPDATE carts SET status="converted" WHERE id=:id AND status="active"'
            );
            $converted->execute(['id' => $cartId]);
            $this->notify(
                $customerUserId,
                'order_placed',
                'Order placed successfully',
                $orderNumber . ' has been sent to ' . count($groups) . ' seller(s).',
                'order',
                $orderId
            );
            $this->users->audit(
                $customerUserId,
                'buyer.order_placed',
                'order',
                $orderId,
                [
                    'order_number' => $orderNumber,
                    'seller_count' => count($groups),
                    'setup_count' => count($activeSetupIds),
                    'payment_method' => $checkout['payment_method'],
                    'payment_status' => $checkout['payment_status'],
                ],
                $ipAddress
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->order($customerUserId, $orderId);
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(int $customerUserId): array
    {
        $statement = $this->db->prepare(
            'SELECT o.id, o.order_number, o.currency_code, o.grand_total,
                    o.status, o.placed_at, o.completed_at, o.cancelled_at,
                    COUNT(DISTINCT so.id) seller_count,
                    COUNT(DISTINCT oi.id) item_count,
                    COUNT(DISTINCT hs.id) setup_count,
                    MAX(hs.name) primary_setup_name,
                    COUNT(DISTINCT CASE WHEN so.status="pending" THEN so.id END)
                        pending_delivery_count,
                    COUNT(DISTINCT CASE WHEN so.status="processing" THEN so.id END)
                        processing_delivery_count,
                    COUNT(DISTINCT CASE WHEN so.status="shipped" THEN so.id END)
                        shipped_delivery_count,
                    COUNT(DISTINCT CASE WHEN so.status="completed" THEN so.id END)
                        completed_delivery_count,
                    COUNT(DISTINCT CASE WHEN so.status="cancelled" THEN so.id END)
                        cancelled_delivery_count,
                    MIN(CASE WHEN so.status="shipped"
                        THEN so.estimated_delivery_date ELSE NULL END)
                        next_estimated_delivery_date
             FROM orders o
             LEFT JOIN vendor_sub_orders so ON so.order_id=o.id
             LEFT JOIN order_items oi ON oi.sub_order_id=so.id
             LEFT JOIN hexbot_setups hs ON hs.order_id=o.id AND hs.status="ordered"
             WHERE o.customer_user_id=:customer_id
             GROUP BY o.id, o.order_number, o.currency_code, o.grand_total,
                      o.status, o.placed_at, o.completed_at, o.cancelled_at
             ORDER BY o.placed_at DESC, o.id DESC'
        );
        $statement->execute(['customer_id' => $customerUserId]);
        $orders = $statement->fetchAll();
        if ($orders === []) {
            return [];
        }
        $orderIds = array_map('intval', array_column($orders, 'id'));
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $deliveries = $this->db->prepare(
            'SELECT so.id, so.order_id, so.sub_order_number, so.status,
                    so.gross_total, so.delivery_method, so.delivery_partner,
                    so.tracking_reference, so.estimated_delivery_date,
                    so.shipped_at, so.completed_at, s.id shop_id,
                    s.name shop_name, COUNT(oi.id) item_count
             FROM vendor_sub_orders so
             INNER JOIN shops s ON s.id=so.shop_id
             LEFT JOIN order_items oi ON oi.sub_order_id=so.id
             WHERE so.order_id IN (' . $placeholders . ')
             GROUP BY so.id, so.order_id, so.sub_order_number, so.status,
                      so.gross_total, so.delivery_method, so.delivery_partner,
                      so.tracking_reference, so.estimated_delivery_date,
                      so.shipped_at, so.completed_at, s.id, s.name
             ORDER BY so.order_id, so.id'
        );
        $deliveries->execute($orderIds);
        $deliveriesByOrder = [];
        foreach ($deliveries->fetchAll() as $delivery) {
            $deliveriesByOrder[(int) $delivery['order_id']][] = $delivery;
        }
        foreach ($orders as &$order) {
            $order['deliveries'] = $deliveriesByOrder[(int) $order['id']] ?? [];
        }
        unset($order);
        return $orders;
    }

    /** @return array<string, mixed> */
    public function order(int $customerUserId, int $orderId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, order_number, address_snapshot_json, currency_code,
                    grand_total, payment_method, payment_status,
                    simulated_payment_notice_accepted_at,
                    status, placed_at, completed_at, cancelled_at
             FROM orders
             WHERE id=:id AND customer_user_id=:customer_id'
        );
        $statement->execute(['id' => $orderId, 'customer_id' => $customerUserId]);
        $order = $statement->fetch();
        if ($order === false) {
            throw new HttpException(404, 'Order not found.');
        }
        $subOrders = $this->db->prepare(
            'SELECT so.id, so.sub_order_number, so.status, so.gross_total,
                    so.commission_rate_snapshot, so.processing_at,
                    so.shipped_at, so.completed_at, so.cancelled_at,
                    so.cancellation_reason, so.delivery_method,
                    so.delivery_partner, so.tracking_reference,
                    so.estimated_delivery_date, so.shipment_note,
                    s.id shop_id, s.name shop_name
             FROM vendor_sub_orders so
             INNER JOIN shops s ON s.id=so.shop_id
             WHERE so.order_id=:order_id
             ORDER BY so.id'
        );
        $subOrders->execute(['order_id' => $orderId]);
        $order['sub_orders'] = $subOrders->fetchAll();
        $items = $this->db->prepare(
            'SELECT oi.id, oi.sub_order_id, oi.listing_id,
                    oi.canonical_product_id product_id,
                    oi.product_name_snapshot product_name,
                    oi.sku_snapshot sku, oi.specification_snapshot_json,
                    oi.quantity, oi.unit_price, oi.line_total,
                    r.id review_id, r.rating review_rating,
                    r.title review_title, r.review_text, r.status review_status,
                    (
                        SELECT pi.stored_filename FROM product_images pi
                        WHERE pi.listing_id=oi.listing_id
                        ORDER BY pi.sort_order, pi.id LIMIT 1
                    ) image_filename
             FROM order_items oi
             LEFT JOIN reviews r ON r.order_item_id=oi.id
             INNER JOIN vendor_sub_orders so ON so.id=oi.sub_order_id
             WHERE so.order_id=:order_id
             ORDER BY oi.id'
        );
        $items->execute(['order_id' => $orderId]);
        $itemsBySubOrder = [];
        foreach ($items->fetchAll() as $item) {
            $item['specifications'] = json_decode(
                (string) ($item['specification_snapshot_json'] ?? ''),
                true
            ) ?: [];
            unset($item['specification_snapshot_json']);
            $itemsBySubOrder[(int) $item['sub_order_id']][] = $item;
        }
        $history = $this->db->prepare(
            'SELECT sub_order_id, previous_status, new_status, reason, created_at
             FROM order_status_history
             WHERE sub_order_id IN (
                SELECT id FROM vendor_sub_orders WHERE order_id=:order_id
             )
             ORDER BY created_at, id'
        );
        $history->execute(['order_id' => $orderId]);
        $historyBySubOrder = [];
        foreach ($history->fetchAll() as $event) {
            $historyBySubOrder[(int) $event['sub_order_id']][] = $event;
        }
        foreach ($order['sub_orders'] as &$subOrder) {
            $subOrder['items'] = $itemsBySubOrder[(int) $subOrder['id']] ?? [];
            $subOrder['history'] = $historyBySubOrder[(int) $subOrder['id']] ?? [];
        }
        unset($subOrder);
        $order['setups'] = $this->orderSetups($orderId);
        $order['delivery_address'] = json_decode(
            (string) $order['address_snapshot_json'],
            true
        ) ?: [];
        unset($order['address_snapshot_json']);
        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    private function orderSetups(int $orderId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, public_id, name, build_rank, setup_scope, status,
                    source_recommendation_public_id, target_budget_lkr,
                    max_budget_lkr, selected_total_lkr, currency_code,
                    requirements_json, scores_json, compatibility_json,
                    created_at, updated_at
             FROM hexbot_setups
             WHERE order_id=:order_id AND status="ordered"
             ORDER BY created_at, id'
        );
        $statement->execute(['order_id' => $orderId]);
        $setups = $statement->fetchAll();
        if ($setups === []) {
            return [];
        }
        $setupIds = array_map('intval', array_column($setups, 'id'));
        $placeholders = implode(',', array_fill(0, count($setupIds), '?'));
        $items = $this->db->prepare(
            'SELECT id, setup_id, component_group, component_code, sort_order,
                    canonical_product_id product_id, listing_id, shop_id,
                    product_name_snapshot product_name,
                    shop_name_snapshot shop_name, quantity,
                    unit_price_snapshot unit_price,
                    line_total_snapshot line_total
             FROM hexbot_setup_items
             WHERE setup_id IN (' . $placeholders . ')
             ORDER BY setup_id, sort_order, id'
        );
        $items->execute($setupIds);
        $itemsBySetup = [];
        foreach ($items->fetchAll() as $item) {
            $itemsBySetup[(int) $item['setup_id']][] = $item;
        }
        foreach ($setups as &$setup) {
            foreach (['requirements', 'scores', 'compatibility'] as $field) {
                $setup[$field] = json_decode(
                    (string) $setup[$field . '_json'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                unset($setup[$field . '_json']);
            }
            $setup['items'] = $itemsBySetup[(int) $setup['id']] ?? [];
            $setup['item_count'] = count($setup['items']);
            $setup['shop_count'] = count(array_unique(array_column($setup['items'], 'shop_id')));
        }
        unset($setup);
        return $setups;
    }

    /** @return array<string, mixed> */
    public function confirmReceipt(
        int $customerUserId,
        int $subOrderId,
        string $ipAddress
    ): array {
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT so.id, so.order_id, so.shop_id, so.sub_order_number,
                        so.status, so.gross_total, so.commission_rate_snapshot,
                        s.owner_user_id
                 FROM vendor_sub_orders so
                 INNER JOIN orders o ON o.id=so.order_id
                 INNER JOIN shops s ON s.id=so.shop_id
                 WHERE so.id=:id AND o.customer_user_id=:customer_id
                 FOR UPDATE'
            );
            $statement->execute([
                'id' => $subOrderId,
                'customer_id' => $customerUserId,
            ]);
            $subOrder = $statement->fetch();
            if ($subOrder === false) {
                throw new HttpException(404, 'Seller order not found.');
            }
            if ($subOrder['status'] === 'completed') {
                $this->db->commit();
                return $this->order($customerUserId, (int) $subOrder['order_id']);
            }
            if ($subOrder['status'] !== 'shipped') {
                throw new HttpException(
                    409,
                    'Receipt can only be confirmed after the seller ships this order.'
                );
            }
            $grossCents = $this->moneyToCents($subOrder['gross_total']);
            $commissionCents = (int) round(
                $grossCents * (float) $subOrder['commission_rate_snapshot'] / 100,
                0,
                PHP_ROUND_HALF_UP
            );
            $netCents = $grossCents - $commissionCents;
            $update = $this->db->prepare(
                'UPDATE vendor_sub_orders
                 SET status="completed", completed_at=CURRENT_TIMESTAMP,
                     commission_amount=:commission, vendor_net_amount=:net
                 WHERE id=:id AND status="shipped"'
            );
            $update->execute([
                'commission' => $this->centsToMoney($commissionCents),
                'net' => $this->centsToMoney($netCents),
                'id' => $subOrderId,
            ]);
            $history = $this->db->prepare(
                'INSERT INTO order_status_history
                    (sub_order_id, previous_status, new_status, actor_user_id)
                 VALUES (:sub_order_id, "shipped", "completed", :actor)'
            );
            $history->execute([
                'sub_order_id' => $subOrderId,
                'actor' => $customerUserId,
            ]);
            $ledger = $this->db->prepare(
                'INSERT IGNORE INTO ledger_entries
                    (event_key, shop_id, order_id, sub_order_id, entry_type,
                     amount, description, created_by_user_id)
                 VALUES
                    (:event_key, :shop_id, :order_id, :sub_order_id, :entry_type,
                     :amount, :description, :actor)'
            );
            $ledger->execute([
                'event_key' => 'completion.sale.' . $subOrderId,
                'shop_id' => $subOrder['shop_id'],
                'order_id' => $subOrder['order_id'],
                'sub_order_id' => $subOrderId,
                'entry_type' => 'sale',
                'amount' => $this->centsToMoney($grossCents),
                'description' => 'Completed sale ' . $subOrder['sub_order_number'],
                'actor' => $customerUserId,
            ]);
            $ledger->execute([
                'event_key' => 'completion.commission.' . $subOrderId,
                'shop_id' => $subOrder['shop_id'],
                'order_id' => $subOrder['order_id'],
                'sub_order_id' => $subOrderId,
                'entry_type' => 'commission',
                'amount' => $this->centsToMoney(-$commissionCents),
                'description' => $subOrder['commission_rate_snapshot']
                    . '% Hexbay commission for ' . $subOrder['sub_order_number'],
                'actor' => $customerUserId,
            ]);
            $this->refreshParentOrderStatus((int) $subOrder['order_id']);
            $this->notify(
                (int) $subOrder['owner_user_id'],
                'order_completed',
                'Buyer confirmed delivery',
                $subOrder['sub_order_number'] . ' is complete and its balance is now available.',
                'vendor_sub_order',
                $subOrderId
            );
            $this->notify(
                $customerUserId,
                'delivery_confirmed',
                'Delivery confirmed',
                $subOrder['sub_order_number']
                    . ' is complete. You can now review its purchased products.',
                'vendor_sub_order',
                $subOrderId
            );
            $this->users->audit(
                $customerUserId,
                'buyer.receipt_confirmed',
                'vendor_sub_order',
                $subOrderId,
                ['commission' => $this->centsToMoney($commissionCents)],
                $ipAddress
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->order($customerUserId, (int) $subOrder['order_id']);
    }

    /** @return array<string, mixed> */
    public function createReview(
        int $customerUserId,
        int $orderItemId,
        array $input,
        string $ipAddress
    ): array {
        $data = BuyerValidator::review($input);
        $eligible = $this->db->prepare(
            'SELECT oi.id, oi.canonical_product_id, so.shop_id,
                    so.status, s.owner_user_id, cp.name product_name
             FROM order_items oi
             INNER JOIN vendor_sub_orders so ON so.id=oi.sub_order_id
             INNER JOIN orders o ON o.id=so.order_id
             INNER JOIN shops s ON s.id=so.shop_id
             INNER JOIN canonical_products cp ON cp.id=oi.canonical_product_id
             WHERE oi.id=:item_id AND o.customer_user_id=:customer_id'
        );
        $eligible->execute([
            'item_id' => $orderItemId,
            'customer_id' => $customerUserId,
        ]);
        $item = $eligible->fetch();
        if ($item === false) {
            throw new HttpException(404, 'Purchased order item not found.');
        }
        if ($item['status'] !== 'completed') {
            throw new HttpException(409, 'Reviews are available after delivery is confirmed.');
        }
        $existing = $this->db->prepare(
            'SELECT id FROM reviews WHERE order_item_id=:item_id'
        );
        $existing->execute(['item_id' => $orderItemId]);
        if ($existing->fetchColumn() !== false) {
            throw new HttpException(409, 'This purchased item has already been reviewed.');
        }
        try {
            $this->db->beginTransaction();
            $insert = $this->db->prepare(
                'INSERT INTO reviews
                    (order_item_id, customer_user_id, canonical_product_id,
                     shop_id, rating, title, review_text,
                     is_verified_purchase, status)
                 VALUES
                    (:item_id, :customer_id, :product_id, :shop_id,
                     :rating, :title, :review_text, TRUE, "published")'
            );
            $insert->execute([
                'item_id' => $orderItemId,
                'customer_id' => $customerUserId,
                'product_id' => $item['canonical_product_id'],
                'shop_id' => $item['shop_id'],
                'rating' => $data['rating'],
                'title' => $data['title'],
                'review_text' => $data['review_text'],
            ]);
            $reviewId = (int) $this->db->lastInsertId();
            $this->refreshShopRating((int) $item['shop_id']);
            $this->recordInteraction(
                $customerUserId,
                null,
                'review',
                5.0,
                ['product_id' => (int) $item['canonical_product_id']]
            );
            $this->notify(
                (int) $item['owner_user_id'],
                'verified_review',
                'New verified buyer review',
                $item['product_name'] . ' received a verified '
                    . $data['rating'] . '-star review.',
                'review',
                $reviewId
            );
            $this->users->audit(
                $customerUserId,
                'buyer.review_created',
                'review',
                $reviewId,
                ['order_item_id' => $orderItemId, 'rating' => $data['rating']],
                $ipAddress
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return [
            'id' => $reviewId,
            'rating' => $data['rating'],
            'title' => $data['title'],
            'review_text' => $data['review_text'],
            'status' => 'published',
            'is_verified_purchase' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function createComplaint(
        int $customerUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = BuyerValidator::complaint($input);
        if ($data['order_id'] !== null) {
            $this->requireOwnedOrder($customerUserId, $data['order_id']);
        }
        if ($data['sub_order_id'] !== null) {
            $this->requireOwnedSubOrder($customerUserId, $data['sub_order_id']);
        }
        $target = $this->resolveComplaintTarget($data);
        $statement = $this->db->prepare(
            'INSERT INTO complaints
                (customer_user_id, order_id, sub_order_id, listing_id, shop_id,
                 subject, description)
             VALUES
                (:customer_id, :order_id, :sub_order_id, :listing_id, :shop_id,
                 :subject, :description)'
        );
        $statement->execute([
            'customer_id' => $customerUserId,
            'order_id' => $data['order_id'],
            'sub_order_id' => $data['sub_order_id'],
            'listing_id' => $data['listing_id'],
            'shop_id' => $target['shop_id'],
            'subject' => $data['subject'],
            'description' => $data['description'],
        ]);
        $complaintId = (int) $this->db->lastInsertId();
        if ($target['owner_user_id'] !== null) {
            $this->notify(
                (int) $target['owner_user_id'],
                'complaint_opened',
                'Customer support case opened',
                'A customer opened a support case for administrator review.',
                'complaint',
                $complaintId
            );
        }
        $this->notifyAdministrators(
            'complaint_opened',
            'New customer complaint',
            $data['subject'],
            'complaint',
            $complaintId
        );
        $this->users->audit(
            $customerUserId,
            'buyer.complaint_created',
            'complaint',
            $complaintId,
            [],
            $ipAddress
        );
        return ['id' => $complaintId, 'status' => 'open'] + $data;
    }

    /** @return array<string, mixed> */
    public function createCounterfeitReport(
        int $customerUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = BuyerValidator::counterfeitReport($input);
        $listing = $this->listingForReport($data['listing_id']);
        if ($data['order_item_id'] !== null) {
            $ownedItem = $this->db->prepare(
                'SELECT 1 FROM order_items oi
                 INNER JOIN vendor_sub_orders so ON so.id=oi.sub_order_id
                 INNER JOIN orders o ON o.id=so.order_id
                 WHERE oi.id=:item_id AND oi.listing_id=:listing_id
                   AND o.customer_user_id=:customer_id'
            );
            $ownedItem->execute([
                'item_id' => $data['order_item_id'],
                'listing_id' => $data['listing_id'],
                'customer_id' => $customerUserId,
            ]);
            if ($ownedItem->fetchColumn() === false) {
                throw new HttpException(
                    422,
                    'The selected purchase does not match this seller listing.'
                );
            }
        }
        $duplicate = $this->db->prepare(
            'SELECT id FROM counterfeit_reports
             WHERE reporter_user_id=:customer_id AND listing_id=:listing_id
               AND status IN ("open","under_review")
             LIMIT 1'
        );
        $duplicate->execute([
            'customer_id' => $customerUserId,
            'listing_id' => $data['listing_id'],
        ]);
        if ($duplicate->fetchColumn() !== false) {
            throw new HttpException(409, 'You already have an open report for this listing.');
        }
        $statement = $this->db->prepare(
            'INSERT INTO counterfeit_reports
                (reporter_user_id, listing_id, order_item_id,
                 reason_code, description)
             VALUES
                (:customer_id, :listing_id, :order_item_id,
                 :reason_code, :description)'
        );
        $statement->execute([
            'customer_id' => $customerUserId,
            'listing_id' => $data['listing_id'],
            'order_item_id' => $data['order_item_id'],
            'reason_code' => $data['reason_code'],
            'description' => $data['description'],
        ]);
        $reportId = (int) $this->db->lastInsertId();
        $this->notifyAdministrators(
            'counterfeit_report_opened',
            'New product authenticity report',
            'A customer submitted a report concerning ' . $listing['product_name'] . '.',
            'counterfeit_report',
            $reportId
        );
        $this->users->audit(
            $customerUserId,
            'buyer.counterfeit_report_created',
            'counterfeit_report',
            $reportId,
            ['listing_id' => $data['listing_id']],
            $ipAddress
        );
        return ['id' => $reportId, 'status' => 'open'] + $data;
    }

    /** @param array<string, mixed> $input */
    public function captureInteraction(int $customerUserId, array $input): void
    {
        $eventType = strtolower(trim((string) ($input['event_type'] ?? '')));
        $weights = ['view' => 1.0, 'search' => 1.0, 'compare' => 2.0];
        if (!isset($weights[$eventType])) {
            throw new HttpException(422, 'Interaction type is invalid.');
        }
        $listingId = (int) ($input['listing_id'] ?? 0);
        $productId = (int) ($input['product_id'] ?? 0);
        if ($listingId > 0) {
            $this->requirePublicListing($listingId);
        } elseif ($productId > 0) {
            $statement = $this->db->prepare(
                'SELECT 1 FROM canonical_products WHERE id=:id'
            );
            $statement->execute(['id' => $productId]);
            if ($statement->fetchColumn() === false) {
                throw new HttpException(404, 'Marketplace product not found.');
            }
        } elseif ($eventType !== 'search') {
            throw new HttpException(422, 'Choose a product for this interaction.');
        }
        $context = [];
        if ($productId > 0) {
            $context['product_id'] = $productId;
        }
        $query = substr(trim((string) ($input['query'] ?? '')), 0, 100);
        if ($query !== '') {
            $context['query'] = $query;
        }
        $this->recordInteraction(
            $customerUserId,
            $listingId > 0 ? $listingId : null,
            $eventType,
            $weights[$eventType],
            $context
        );
    }

    /** @return array<string, mixed> */
    private function addressById(int $customerUserId, int $addressId): array
    {
        foreach ($this->addresses($customerUserId) as $address) {
            if ((int) $address['id'] === $addressId) {
                return $address;
            }
        }
        throw new \RuntimeException('Saved address could not be loaded.');
    }

    private function wishlistId(int $customerUserId, bool $create): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id FROM wishlists
             WHERE customer_user_id=:customer_id AND name="My Wishlist"
             LIMIT 1'
        );
        $statement->execute(['customer_id' => $customerUserId]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        if (!$create) {
            return null;
        }
        $insert = $this->db->prepare(
            'INSERT INTO wishlists (customer_user_id, name)
             VALUES (:customer_id, "My Wishlist")'
        );
        $insert->execute(['customer_id' => $customerUserId]);
        return (int) $this->db->lastInsertId();
    }

    private function cartId(
        int $customerUserId,
        bool $create,
        bool $lock = false
    ): ?int {
        $statement = $this->db->prepare(
            'SELECT id FROM carts
             WHERE customer_user_id=:customer_id AND status="active"
             ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['customer_id' => $customerUserId]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        if (!$create) {
            return null;
        }
        $insert = $this->db->prepare(
            'INSERT INTO carts (customer_user_id) VALUES (:customer_id)'
        );
        $insert->execute(['customer_id' => $customerUserId]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function requirePublicListing(int $listingId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.id, l.canonical_product_id, l.shop_id, l.price,
                    GREATEST(i.quantity_on_hand-i.quantity_reserved,0)
                        available_quantity
             FROM shop_product_listings l
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN inventory i ON i.listing_id=l.id
             WHERE l.id=:id AND l.status="active" AND s.status="approved"'
        );
        $statement->execute(['id' => $listingId]);
        $listing = $statement->fetch();
        if ($listing === false) {
            throw new HttpException(404, 'Active seller offer not found.');
        }
        return $listing;
    }

    private function specificationSnapshot(int $productId): string
    {
        $statement = $this->db->prepare(
            'SELECT sd.display_name,
                    COALESCE(so.display_value, ps.value_text,
                             CAST(ps.value_number AS CHAR),
                             CAST(ps.value_boolean AS CHAR),
                             CAST(ps.value_json AS CHAR)) specification_value,
                    sd.unit
             FROM product_specifications ps
             INNER JOIN specification_definitions sd ON sd.id=ps.definition_id
             LEFT JOIN specification_options so ON so.id=ps.option_id
             WHERE ps.canonical_product_id=:product_id
             ORDER BY sd.sort_order, sd.display_name'
        );
        $statement->execute(['product_id' => $productId]);
        return json_encode(
            $statement->fetchAll(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function moneyToCents(mixed $amount): int
    {
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /** @param array<string, mixed> $context */
    private function recordInteraction(
        int $userId,
        ?int $listingId,
        string $eventType,
        float $weight,
        array $context = []
    ): void {
        $productId = isset($context['product_id'])
            ? (int) $context['product_id']
            : null;
        if ($productId === null && $listingId !== null) {
            $statement = $this->db->prepare(
                'SELECT canonical_product_id FROM shop_product_listings WHERE id=:id'
            );
            $statement->execute(['id' => $listingId]);
            $value = $statement->fetchColumn();
            $productId = $value === false ? null : (int) $value;
        }
        $statement = $this->db->prepare(
            'INSERT INTO user_interactions
                (user_id, canonical_product_id, listing_id,
                 event_type, event_weight, context_json)
             VALUES
                (:user_id, :product_id, :listing_id,
                 :event_type, :event_weight, :context_json)'
        );
        $statement->execute([
            'user_id' => $userId,
            'product_id' => $productId,
            'listing_id' => $listingId,
            'event_type' => $eventType,
            'event_weight' => number_format($weight, 3, '.', ''),
            'context_json' => $context === []
                ? null
                : json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }

    private function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $resourceType,
        ?int $resourceId
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO notifications
                (user_id, type, title, message,
                 related_resource_type, related_resource_id)
             VALUES
                (:user_id, :type, :title, :message, :resource_type, :resource_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    private function notifyAdministrators(
        string $type,
        string $title,
        string $message,
        string $resourceType,
        int $resourceId
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO notifications
                (user_id, type, title, message,
                 related_resource_type, related_resource_id)
             SELECT u.id, :type, :title, :message, :resource_type, :resource_id
             FROM users u
             INNER JOIN roles r ON r.id=u.role_id
             WHERE r.name="administrator" AND u.status="active"'
        );
        $statement->execute([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    private function refreshParentOrderStatus(int $orderId): void
    {
        $statement = $this->db->prepare(
            'SELECT status, COUNT(*) total
             FROM vendor_sub_orders
             WHERE order_id=:order_id GROUP BY status'
        );
        $statement->execute(['order_id' => $orderId]);
        $counts = [];
        $total = 0;
        foreach ($statement->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
            $total += (int) $row['total'];
        }
        if (($counts['cancelled'] ?? 0) === $total) {
            $status = 'cancelled';
        } elseif (($counts['completed'] ?? 0) === $total) {
            $status = 'completed';
        } elseif (($counts['cancelled'] ?? 0) > 0) {
            $status = 'partially_cancelled';
        } elseif (($counts['completed'] ?? 0) > 0) {
            $status = 'partially_completed';
        } elseif (($counts['shipped'] ?? 0) === $total) {
            $status = 'shipped';
        } elseif (($counts['shipped'] ?? 0) > 0) {
            $status = 'partially_shipped';
        } elseif (($counts['processing'] ?? 0) > 0) {
            $status = 'processing';
        } else {
            $status = 'pending';
        }
        $update = $this->db->prepare(
            'UPDATE orders
             SET status=:status,
                 completed_at=CASE WHEN :completed="completed"
                    THEN CURRENT_TIMESTAMP ELSE completed_at END,
                 cancelled_at=CASE WHEN :cancelled="cancelled"
                    THEN CURRENT_TIMESTAMP ELSE cancelled_at END
             WHERE id=:id'
        );
        $update->execute([
            'status' => $status,
            'completed' => $status,
            'cancelled' => $status,
            'id' => $orderId,
        ]);
    }

    private function refreshShopRating(int $shopId): void
    {
        $statement = $this->db->prepare(
            'UPDATE shops s
             SET rating_average=(
                    SELECT COALESCE(AVG(r.rating),0) FROM reviews r
                    WHERE r.shop_id=s.id AND r.status="published"
                 ),
                 rating_count=(
                    SELECT COUNT(*) FROM reviews r
                    WHERE r.shop_id=s.id AND r.status="published"
                 )
             WHERE s.id=:shop_id'
        );
        $statement->execute(['shop_id' => $shopId]);
    }

    private function requireOwnedOrder(int $customerUserId, int $orderId): void
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM orders WHERE id=:id AND customer_user_id=:customer_id'
        );
        $statement->execute(['id' => $orderId, 'customer_id' => $customerUserId]);
        if ($statement->fetchColumn() === false) {
            throw new HttpException(404, 'Order not found.');
        }
    }

    private function requireOwnedSubOrder(int $customerUserId, int $subOrderId): void
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM vendor_sub_orders so
             INNER JOIN orders o ON o.id=so.order_id
             WHERE so.id=:id AND o.customer_user_id=:customer_id'
        );
        $statement->execute(['id' => $subOrderId, 'customer_id' => $customerUserId]);
        if ($statement->fetchColumn() === false) {
            throw new HttpException(404, 'Seller order not found.');
        }
    }

    /** @param array<string, mixed> $data
     *  @return array{shop_id: ?int, owner_user_id: ?int}
     */
    private function resolveComplaintTarget(array $data): array
    {
        $shopId = $data['shop_id'];
        if ($data['sub_order_id'] !== null) {
            $statement = $this->db->prepare(
                'SELECT shop_id FROM vendor_sub_orders WHERE id=:id'
            );
            $statement->execute(['id' => $data['sub_order_id']]);
            $shopId = (int) $statement->fetchColumn();
        } elseif ($data['listing_id'] !== null) {
            $statement = $this->db->prepare(
                'SELECT shop_id FROM shop_product_listings WHERE id=:id'
            );
            $statement->execute(['id' => $data['listing_id']]);
            $value = $statement->fetchColumn();
            if ($value === false) {
                throw new HttpException(404, 'Seller listing not found.');
            }
            $shopId = (int) $value;
        }
        if ($shopId === null) {
            return ['shop_id' => null, 'owner_user_id' => null];
        }
        $statement = $this->db->prepare(
            'SELECT owner_user_id FROM shops WHERE id=:id'
        );
        $statement->execute(['id' => $shopId]);
        $ownerId = $statement->fetchColumn();
        if ($ownerId === false) {
            throw new HttpException(404, 'Shop not found.');
        }
        return ['shop_id' => $shopId, 'owner_user_id' => (int) $ownerId];
    }

    /** @return array<string, mixed> */
    private function listingForReport(int $listingId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.id, cp.name product_name
             FROM shop_product_listings l
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             WHERE l.id=:id'
        );
        $statement->execute(['id' => $listingId]);
        $listing = $statement->fetch();
        if ($listing === false) {
            throw new HttpException(404, 'Seller listing not found.');
        }
        return $listing;
    }
}
