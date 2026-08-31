<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\SellerValidator;
use PDO;
use PDOException;

final class SellerModuleService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(int $ownerUserId): array
    {
        $shop = $this->requireApprovedShop($ownerUserId);
        $shopId = (int) $shop['id'];
        $counts = [];
        foreach (
            [
                'products' => 'SELECT COUNT(*) FROM shop_product_listings WHERE shop_id = :shop_id',
                'active_products' => 'SELECT COUNT(*) FROM shop_product_listings WHERE shop_id = :shop_id AND status = "active"',
                'low_stock' => 'SELECT COUNT(*) FROM inventory i INNER JOIN shop_product_listings l ON l.id=i.listing_id WHERE l.shop_id=:shop_id AND i.quantity_on_hand <= i.low_stock_threshold',
                'open_orders' => 'SELECT COUNT(*) FROM vendor_sub_orders WHERE shop_id=:shop_id AND status IN ("pending", "processing", "shipped")',
                'reviews' => 'SELECT COUNT(*) FROM reviews WHERE shop_id=:shop_id AND status="published"',
            ] as $key => $sql
        ) {
            $statement = $this->db->prepare($sql);
            $statement->execute(['shop_id' => $shopId]);
            $counts[$key] = (int) $statement->fetchColumn();
        }

        $financial = $this->db->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN status="completed" THEN gross_total ELSE 0 END), 0) AS gross_sales,
                COALESCE(SUM(CASE WHEN status="completed" THEN commission_amount ELSE 0 END), 0) AS commission,
                COALESCE(SUM(CASE WHEN status="completed" THEN vendor_net_amount ELSE 0 END), 0) AS net_sales
             FROM vendor_sub_orders
             WHERE shop_id = :shop_id'
        );
        $financial->execute(['shop_id' => $shopId]);

        return [
            'shop' => $shop,
            'counts' => $counts,
            'financial' => $financial->fetch(),
        ];
    }

    /** @return array<string, mixed> */
    public function profile(int $ownerUserId): array
    {
        return $this->requireApprovedShop($ownerUserId);
    }

    /** @return array<string, mixed> */
    public function updateProfile(
        int $ownerUserId,
        array $input,
        string $ipAddress
    ): array {
        $shop = $this->requireApprovedShop($ownerUserId);
        $data = SellerValidator::profile($input);
        $statement = $this->db->prepare(
            'UPDATE shops
             SET name = :name,
                 description = :description,
                 address_text = :address_text,
                 contact_phone = :contact_phone,
                 contact_email = :contact_email
             WHERE id = :id AND owner_user_id = :owner_user_id'
        );
        $statement->execute([
            ...$data,
            'id' => $shop['id'],
            'owner_user_id' => $ownerUserId,
        ]);
        $this->users->audit(
            $ownerUserId,
            'seller.shop_profile_updated',
            'shop',
            (int) $shop['id'],
            ['name' => $data['name']],
            $ipAddress
        );
        return $this->shopForOwner($ownerUserId);
    }

    /** @return array<string, mixed> */
    public function catalogueOptions(int $ownerUserId): array
    {
        $this->requireApprovedShop($ownerUserId);
        $categories = $this->db->query(
            'SELECT id, name, slug, requires_listing_approval
             FROM categories
             WHERE is_active = TRUE
             ORDER BY sort_order, name'
        )->fetchAll();
        $definitions = $this->db->query(
            'SELECT id, category_id, code, display_name, data_type, unit,
                    is_required, minimum_value, maximum_value, sort_order
             FROM specification_definitions
             WHERE is_active = TRUE
             ORDER BY category_id, sort_order, display_name'
        )->fetchAll();
        $options = $this->db->query(
            'SELECT id, definition_id, value_code, display_value, sort_order
             FROM specification_options
             WHERE is_active = TRUE
             ORDER BY definition_id, sort_order, display_value'
        )->fetchAll();
        $optionsByDefinition = [];
        foreach ($options as $option) {
            $optionsByDefinition[(int) $option['definition_id']][] = $option;
        }
        $definitionsByCategory = [];
        foreach ($definitions as $definition) {
            $definition['options'] = $optionsByDefinition[(int) $definition['id']] ?? [];
            $definitionsByCategory[(int) $definition['category_id']][] = $definition;
        }
        foreach ($categories as &$category) {
            $category['specifications'] = $definitionsByCategory[(int) $category['id']] ?? [];
        }
        unset($category);
        $brands = $this->db->query(
            'SELECT id, name FROM brands WHERE is_active=TRUE ORDER BY name LIMIT 300'
        )->fetchAll();
        return ['categories' => $categories, 'brands' => $brands];
    }

    /** @return array<int, array<string, mixed>> */
    public function listings(int $ownerUserId): array
    {
        $shop = $this->requireApprovedShop($ownerUserId);
        $statement = $this->db->prepare(
            'SELECT l.id, l.sku, l.condition_type, l.price, l.vendor_description,
                    l.warranty_summary, l.status, l.status_reason, l.created_at,
                    l.updated_at, cp.id AS canonical_product_id,
                    cp.name AS product_name, cp.model, cp.category_id,
                    b.name AS brand_name, c.name AS category_name,
                    COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                    COALESCE(i.quantity_reserved, 0) AS quantity_reserved,
                    COALESCE(i.low_stock_threshold, 3) AS low_stock_threshold
             FROM shop_product_listings l
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN categories c ON c.id=cp.category_id
             LEFT JOIN inventory i ON i.listing_id=l.id
             WHERE l.shop_id=:shop_id
             ORDER BY l.updated_at DESC'
        );
        $statement->execute(['shop_id' => $shop['id']]);
        $listings = $statement->fetchAll();
        foreach ($listings as &$listing) {
            $listing['images'] = $this->productImages((int) $listing['id']);
        }
        unset($listing);
        return $listings;
    }

    /** @return array<string, mixed> */
    public function listing(int $ownerUserId, int $listingId): array
    {
        $this->requireApprovedShop($ownerUserId);
        $listing = $this->listingForOwner($ownerUserId, $listingId);
        $specifications = $this->db->prepare(
            'SELECT sd.code, sd.data_type, so.value_code,
                    ps.value_text, ps.value_number, ps.value_boolean, ps.value_json
             FROM product_specifications ps
             INNER JOIN specification_definitions sd ON sd.id=ps.definition_id
             LEFT JOIN specification_options so ON so.id=ps.option_id
             WHERE ps.canonical_product_id=:product_id'
        );
        $specifications->execute(['product_id' => $listing['canonical_product_id']]);
        $values = [];
        foreach ($specifications->fetchAll() as $specification) {
            $values[$specification['code']] = match ($specification['data_type']) {
                'integer', 'decimal' => $specification['value_number'],
                'boolean' => (bool) $specification['value_boolean'],
                'option' => $specification['value_code'],
                'multi_option' => json_decode(
                    (string) $specification['value_json'],
                    true
                ) ?: [],
                default => $specification['value_text'],
            };
        }
        $listing['specifications'] = $values;
        $listing['images'] = $this->productImages($listingId);
        return $listing;
    }

    /** @return array<string, mixed> */
    public function saveListing(
        int $ownerUserId,
        ?int $listingId,
        array $input,
        string $ipAddress
    ): array {
        $shop = $this->requireApprovedShop($ownerUserId);
        $this->requireCurrentCommissionAcceptance($ownerUserId, (int) $shop['id']);
        $data = SellerValidator::listing($input);
        $existingListing = null;
        if ($listingId !== null) {
            $existingListing = $this->listingForOwner($ownerUserId, $listingId);
            $data['category_id'] = (int) $existingListing['category_id'];
            $data['brand_name'] = (string) $existingListing['brand_name'];
            $data['product_name'] = (string) $existingListing['product_name'];
            $data['model'] = (string) $existingListing['model'];
        }
        $definitions = $this->validateSpecifications(
            $data['category_id'],
            $data['specifications']
        );

        try {
            $this->db->beginTransaction();
            if ($listingId === null) {
                $brandId = $this->findOrCreateBrand($data['brand_name']);
                [$productId, $productCreated] = $this->findOrCreateProduct(
                    $ownerUserId,
                    $data,
                    $brandId
                );
                if ($productCreated) {
                    $this->saveProductSpecifications(
                        $productId,
                        $ownerUserId,
                        $definitions,
                        $data['specifications']
                    );
                }
                $approval = $this->db->prepare(
                    'SELECT requires_listing_approval
                     FROM categories WHERE id=:id AND is_active=TRUE'
                );
                $approval->execute(['id' => $data['category_id']]);
                $status = (bool) $approval->fetchColumn()
                    ? 'pending_approval'
                    : 'active';
                $statement = $this->db->prepare(
                    'INSERT INTO shop_product_listings
                        (shop_id, canonical_product_id, sku, condition_type,
                         price, vendor_description, warranty_summary, status,
                         published_at)
                     VALUES
                        (:shop_id, :product_id, :sku, :condition_type,
                         :price, :description, :warranty, :status,
                         CASE WHEN :publish_status="active" THEN CURRENT_TIMESTAMP ELSE NULL END)'
                );
                $statement->execute([
                    'shop_id' => $shop['id'],
                    'product_id' => $productId,
                    'sku' => $data['sku'],
                    'condition_type' => $data['condition_type'],
                    'price' => $data['price'],
                    'description' => $data['vendor_description'] ?: null,
                    'warranty' => $data['warranty_summary'] ?: null,
                    'status' => $status,
                    'publish_status' => $status,
                ]);
                $listingId = (int) $this->db->lastInsertId();
                $inventory = $this->db->prepare(
                    'INSERT INTO inventory
                        (listing_id, quantity_on_hand, low_stock_threshold)
                     VALUES (:listing_id, :quantity, 3)'
                );
                $inventory->execute([
                    'listing_id' => $listingId,
                    'quantity' => $data['initial_stock'],
                ]);
                $inventoryId = (int) $this->db->lastInsertId();
                if ($data['initial_stock'] > 0) {
                    $movement = $this->db->prepare(
                        'INSERT INTO inventory_movements
                            (inventory_id, movement_type, quantity_delta,
                             quantity_after, reason, actor_user_id)
                         VALUES
                            (:inventory_id, "initial", :delta, :after,
                             "Initial listing stock", :actor)'
                    );
                    $movement->execute([
                        'inventory_id' => $inventoryId,
                        'delta' => $data['initial_stock'],
                        'after' => $data['initial_stock'],
                        'actor' => $ownerUserId,
                    ]);
                }
                $action = 'seller.listing_created';
            } else {
                $existing = $this->listingForOwner($ownerUserId, $listingId, true);
                $resubmitStatus = in_array(
                    $existing['status'],
                    ['rejected', 'hidden', 'flagged', 'inactive', 'draft'],
                    true
                ) ? 'pending_approval' : $existing['status'];
                $statement = $this->db->prepare(
                    'UPDATE shop_product_listings
                     SET sku=:sku, condition_type=:condition_type, price=:price,
                         vendor_description=:description,
                         warranty_summary=:warranty, status=:status,
                         status_reason=NULL
                     WHERE id=:id AND shop_id=:shop_id'
                );
                $statement->execute([
                    'sku' => $data['sku'],
                    'condition_type' => $data['condition_type'],
                    'price' => $data['price'],
                    'description' => $data['vendor_description'] ?: null,
                    'warranty' => $data['warranty_summary'] ?: null,
                    'status' => $resubmitStatus,
                    'id' => $listingId,
                    'shop_id' => $shop['id'],
                ]);
                $creator = $this->db->prepare(
                    'SELECT cp.created_by_user_id,
                            COUNT(DISTINCT CASE
                                WHEN l.shop_id <> :shop_id THEN l.shop_id
                                ELSE NULL
                            END) AS other_shop_count
                     FROM canonical_products cp
                     LEFT JOIN shop_product_listings l
                       ON l.canonical_product_id=cp.id
                     WHERE cp.id=:id
                     GROUP BY cp.id, cp.created_by_user_id'
                );
                $creator->execute([
                    'id' => $existing['canonical_product_id'],
                    'shop_id' => $shop['id'],
                ]);
                $productOwnership = $creator->fetch();
                if (
                    $productOwnership !== false
                    && (int) $productOwnership['created_by_user_id'] === $ownerUserId
                    && (int) $productOwnership['other_shop_count'] === 0
                ) {
                    $this->saveProductSpecifications(
                        (int) $existing['canonical_product_id'],
                        $ownerUserId,
                        $definitions,
                        $data['specifications']
                    );
                }
                $action = 'seller.listing_updated';
            }
            $this->users->audit(
                $ownerUserId,
                $action,
                'shop_product_listing',
                $listingId,
                ['shop_id' => $shop['id'], 'sku' => $data['sku']],
                $ipAddress
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(
                    409,
                    'That SKU or product/condition combination already exists for your shop.'
                );
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->listing($ownerUserId, $listingId);
    }

    /** @return array<int, array<string, mixed>> */
    public function inventory(int $ownerUserId): array
    {
        $shop = $this->requireApprovedShop($ownerUserId);
        $statement = $this->db->prepare(
            'SELECT i.id AS inventory_id, i.listing_id, i.quantity_on_hand,
                    i.quantity_reserved, i.low_stock_threshold, i.version,
                    l.sku, l.status AS listing_status, cp.name AS product_name,
                    cp.model
             FROM inventory i
             INNER JOIN shop_product_listings l ON l.id=i.listing_id
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             WHERE l.shop_id=:shop_id
             ORDER BY
                CASE WHEN i.quantity_on_hand <= i.low_stock_threshold THEN 0 ELSE 1 END,
                cp.name'
        );
        $statement->execute(['shop_id' => $shop['id']]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function adjustInventory(
        int $ownerUserId,
        int $listingId,
        array $input,
        string $ipAddress
    ): array {
        $shop = $this->requireApprovedShop($ownerUserId);
        $data = SellerValidator::stockAdjustment($input);
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT i.id, i.quantity_on_hand, i.quantity_reserved
                 FROM inventory i
                 INNER JOIN shop_product_listings l ON l.id=i.listing_id
                 WHERE i.listing_id=:listing_id AND l.shop_id=:shop_id
                 FOR UPDATE'
            );
            $statement->execute([
                'listing_id' => $listingId,
                'shop_id' => $shop['id'],
            ]);
            $inventory = $statement->fetch();
            if ($inventory === false) {
                throw new HttpException(404, 'Inventory record not found.');
            }
            $after = (int) $inventory['quantity_on_hand'] + $data['quantity_delta'];
            if ($after < (int) $inventory['quantity_reserved']) {
                throw new HttpException(
                    409,
                    'Stock cannot be reduced below the quantity reserved for orders.'
                );
            }
            $update = $this->db->prepare(
                'UPDATE inventory
                 SET quantity_on_hand=:quantity, version=version+1
                 WHERE id=:id'
            );
            $update->execute(['quantity' => $after, 'id' => $inventory['id']]);
            $movement = $this->db->prepare(
                'INSERT INTO inventory_movements
                    (inventory_id, movement_type, quantity_delta, quantity_after,
                     reason, actor_user_id)
                 VALUES
                    (:inventory_id, :movement_type, :delta, :after, :reason, :actor)'
            );
            $movement->execute([
                'inventory_id' => $inventory['id'],
                'movement_type' => $data['quantity_delta'] > 0 ? 'restock' : 'adjustment',
                'delta' => $data['quantity_delta'],
                'after' => $after,
                'reason' => $data['reason'],
                'actor' => $ownerUserId,
            ]);
            $this->users->audit(
                $ownerUserId,
                'seller.inventory_adjusted',
                'inventory',
                (int) $inventory['id'],
                [
                    'listing_id' => $listingId,
                    'before' => $inventory['quantity_on_hand'],
                    'after' => $after,
                    'reason' => $data['reason'],
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
        foreach ($this->inventory($ownerUserId) as $item) {
            if ((int) $item['listing_id'] === $listingId) {
                return $item;
            }
        }
        throw new \RuntimeException('Updated inventory could not be loaded.');
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(int $ownerUserId, string $status): array
    {
        $shop = $this->requireApprovedShop($ownerUserId);
        $allowed = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
        $where = in_array($status, $allowed, true) ? 'AND so.status=:status' : '';
        $statement = $this->db->prepare(
            "SELECT so.id, so.sub_order_number, so.status, so.gross_total,
                    so.commission_rate_snapshot, so.commission_amount,
                    so.vendor_net_amount, so.created_at, so.processing_at,
                    so.shipped_at, so.completed_at, so.cancelled_at,
                    so.cancellation_reason, so.delivery_method,
                    so.delivery_partner, so.tracking_reference,
                    so.estimated_delivery_date, so.shipment_note,
                    o.order_number, o.address_snapshot_json, o.placed_at,
                    u.email AS customer_email
             FROM vendor_sub_orders so
             INNER JOIN orders o ON o.id=so.order_id
             INNER JOIN users u ON u.id=o.customer_user_id
             WHERE so.shop_id=:shop_id {$where}
             ORDER BY so.created_at DESC"
        );
        $params = ['shop_id' => $shop['id']];
        if ($where !== '') {
            $params['status'] = $status;
        }
        $statement->execute($params);
        $orders = $statement->fetchAll();
        if ($orders === []) {
            return [];
        }
        $ids = array_map(static fn (array $order): int => (int) $order['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $items = $this->db->prepare(
            "SELECT sub_order_id, product_name_snapshot, sku_snapshot,
                    quantity, unit_price, line_total
             FROM order_items
             WHERE sub_order_id IN ({$placeholders})
             ORDER BY id"
        );
        $items->execute($ids);
        $byOrder = [];
        foreach ($items->fetchAll() as $item) {
            $byOrder[(int) $item['sub_order_id']][] = $item;
        }
        $checkpoints = $this->db->prepare(
            "SELECT sub_order_id, checkpoint_code, completed_at
             FROM vendor_fulfilment_checkpoints
             WHERE sub_order_id IN ({$placeholders})
             ORDER BY completed_at, id"
        );
        $checkpoints->execute($ids);
        $checkpointsByOrder = [];
        foreach ($checkpoints->fetchAll() as $checkpoint) {
            $checkpointsByOrder[(int) $checkpoint['sub_order_id']][
                (string) $checkpoint['checkpoint_code']
            ] = $checkpoint['completed_at'];
        }
        $history = $this->db->prepare(
            "SELECT sub_order_id, previous_status, new_status, reason, created_at
             FROM order_status_history
             WHERE sub_order_id IN ({$placeholders})
             ORDER BY created_at, id"
        );
        $history->execute($ids);
        $historyByOrder = [];
        foreach ($history->fetchAll() as $event) {
            $historyByOrder[(int) $event['sub_order_id']][] = $event;
        }
        $requiredCheckpoints = [
            'stock_verified',
            'items_packed',
            'delivery_address_verified',
        ];
        foreach ($orders as &$order) {
            $order['items'] = $byOrder[(int) $order['id']] ?? [];
            $completed = $checkpointsByOrder[(int) $order['id']] ?? [];
            $order['fulfilment_checkpoints'] = array_map(
                static fn (string $code): array => [
                    'code' => $code,
                    'is_complete' => isset($completed[$code]),
                    'completed_at' => $completed[$code] ?? null,
                ],
                $requiredCheckpoints
            );
            $order['fulfilment_ready_to_ship'] = count($completed)
                === count($requiredCheckpoints);
            $order['history'] = $historyByOrder[(int) $order['id']] ?? [];
            $order['delivery_address'] = json_decode(
                (string) $order['address_snapshot_json'],
                true
            ) ?: [];
            unset($order['address_snapshot_json']);
        }
        unset($order);
        return $orders;
    }

    /** @return array<string, mixed> */
    public function completeFulfilmentCheckpoint(
        int $ownerUserId,
        int $subOrderId,
        array $input,
        string $ipAddress
    ): array {
        $shop = $this->requireApprovedShop($ownerUserId);
        $checkpoint = SellerValidator::fulfilmentCheckpoint($input);
        try {
            $this->db->beginTransaction();
            $order = $this->db->prepare(
                'SELECT so.id, so.status, so.sub_order_number
                 FROM vendor_sub_orders so
                 WHERE so.id=:id AND so.shop_id=:shop_id
                 FOR UPDATE'
            );
            $order->execute(['id' => $subOrderId, 'shop_id' => $shop['id']]);
            $owned = $order->fetch();
            if ($owned === false) {
                throw new HttpException(404, 'Seller order not found.');
            }
            if ($owned['status'] !== 'processing') {
                throw new HttpException(
                    409,
                    'Fulfilment checks can be completed only while an order is processing.'
                );
            }
            $insert = $this->db->prepare(
                'INSERT IGNORE INTO vendor_fulfilment_checkpoints
                    (sub_order_id, checkpoint_code, completed_by_user_id)
                 VALUES (:sub_order_id, :checkpoint, :actor)'
            );
            $insert->execute([
                'sub_order_id' => $subOrderId,
                'checkpoint' => $checkpoint,
                'actor' => $ownerUserId,
            ]);
            if ($insert->rowCount() === 1) {
                $this->users->audit(
                    $ownerUserId,
                    'seller.fulfilment_checkpoint_completed',
                    'vendor_sub_order',
                    $subOrderId,
                    ['checkpoint_code' => $checkpoint],
                    $ipAddress
                );
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        foreach ($this->orders($ownerUserId, '') as $order) {
            if ((int) $order['id'] === $subOrderId) {
                return $order;
            }
        }
        throw new \RuntimeException('Updated order could not be loaded.');
    }

    /** @return array<string, mixed> */
    public function updateOrderStatus(
        int $ownerUserId,
        int $subOrderId,
        array $input,
        string $ipAddress
    ): array {
        $shop = $this->requireApprovedShop($ownerUserId);
        $data = SellerValidator::orderStatus($input);
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT so.id, so.order_id, so.status, so.sub_order_number,
                        o.customer_user_id
                 FROM vendor_sub_orders so
                 INNER JOIN orders o ON o.id=so.order_id
                 WHERE so.id=:id AND so.shop_id=:shop_id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $subOrderId, 'shop_id' => $shop['id']]);
            $order = $statement->fetch();
            if ($order === false) {
                throw new HttpException(404, 'Seller order not found.');
            }
            $allowed = [
                'pending' => ['processing', 'cancelled'],
                'processing' => ['shipped', 'cancelled'],
                'shipped' => [],
                'completed' => [],
                'cancelled' => [],
            ];
            if (!in_array($data['status'], $allowed[$order['status']] ?? [], true)) {
                throw new HttpException(
                    409,
                    sprintf(
                        'Order cannot move from %s to %s.',
                        $order['status'],
                        $data['status']
                    )
                );
            }
            if ($data['status'] === 'shipped') {
                $checkpointStatement = $this->db->prepare(
                    'SELECT checkpoint_code
                     FROM vendor_fulfilment_checkpoints
                     WHERE sub_order_id=:sub_order_id
                     FOR UPDATE'
                );
                $checkpointStatement->execute(['sub_order_id' => $subOrderId]);
                $completedCheckpoints = $checkpointStatement->fetchAll(PDO::FETCH_COLUMN);
                $requiredCheckpoints = [
                    'stock_verified',
                    'items_packed',
                    'delivery_address_verified',
                ];
                $missingCheckpoints = array_values(array_diff(
                    $requiredCheckpoints,
                    $completedCheckpoints
                ));
                if ($missingCheckpoints !== []) {
                    throw new HttpException(
                        409,
                        'Complete every fulfilment check before marking this order as shipped.',
                        ['fulfilment_checkpoints' => $missingCheckpoints]
                    );
                }
            }
            if ($data['status'] === 'cancelled') {
                $items = $this->db->prepare(
                    'SELECT oi.id order_item_id, oi.quantity,
                            i.id inventory_id, i.quantity_on_hand
                     FROM order_items oi
                     INNER JOIN inventory i ON i.listing_id=oi.listing_id
                     WHERE oi.sub_order_id=:sub_order_id
                     ORDER BY i.id
                     FOR UPDATE'
                );
                $items->execute(['sub_order_id' => $subOrderId]);
                $restore = $this->db->prepare(
                    'UPDATE inventory
                     SET quantity_on_hand=quantity_on_hand+:quantity,
                         version=version+1
                     WHERE id=:id'
                );
                $movement = $this->db->prepare(
                    'INSERT INTO inventory_movements
                        (inventory_id, movement_type, quantity_delta,
                         quantity_after, reference_type, reference_id,
                         reason, actor_user_id)
                     VALUES
                        (:inventory_id, "cancellation", :delta, :after,
                         "order_item", :reference_id, :reason, :actor)'
                );
                foreach ($items->fetchAll() as $item) {
                    $after = (int) $item['quantity_on_hand'] + (int) $item['quantity'];
                    $restore->execute([
                        'quantity' => $item['quantity'],
                        'id' => $item['inventory_id'],
                    ]);
                    $movement->execute([
                        'inventory_id' => $item['inventory_id'],
                        'delta' => $item['quantity'],
                        'after' => $after,
                        'reference_id' => $item['order_item_id'],
                        'reason' => $data['reason'],
                        'actor' => $ownerUserId,
                    ]);
                }
            }
            $update = $this->db->prepare(
                'UPDATE vendor_sub_orders
                 SET status=:status,
                     processing_at=CASE WHEN :processing_status="processing" THEN CURRENT_TIMESTAMP ELSE processing_at END,
                     shipped_at=CASE WHEN :shipped_status="shipped" THEN CURRENT_TIMESTAMP ELSE shipped_at END,
                     cancelled_at=CASE WHEN :cancelled_status="cancelled" THEN CURRENT_TIMESTAMP ELSE cancelled_at END,
                     cancellation_reason=CASE WHEN :cancelled_reason_status="cancelled" THEN :reason ELSE cancellation_reason END,
                     delivery_method=CASE WHEN :delivery_method_status="shipped" THEN :delivery_method ELSE delivery_method END,
                     delivery_partner=CASE WHEN :delivery_partner_status="shipped" THEN :delivery_partner ELSE delivery_partner END,
                     tracking_reference=CASE WHEN :tracking_status="shipped" THEN :tracking_reference ELSE tracking_reference END,
                     estimated_delivery_date=CASE WHEN :estimated_date_status="shipped" THEN :estimated_delivery_date ELSE estimated_delivery_date END,
                     shipment_note=CASE WHEN :shipment_note_status="shipped" THEN :shipment_note ELSE shipment_note END
                 WHERE id=:id'
            );
            $update->execute([
                'status' => $data['status'],
                'processing_status' => $data['status'],
                'shipped_status' => $data['status'],
                'cancelled_status' => $data['status'],
                'cancelled_reason_status' => $data['status'],
                'reason' => $data['reason'] ?: null,
                'delivery_method_status' => $data['status'],
                'delivery_partner_status' => $data['status'],
                'tracking_status' => $data['status'],
                'estimated_date_status' => $data['status'],
                'shipment_note_status' => $data['status'],
                'delivery_method' => $data['delivery_method'],
                'delivery_partner' => $data['delivery_partner'],
                'tracking_reference' => $data['tracking_reference'],
                'estimated_delivery_date' => $data['estimated_delivery_date'],
                'shipment_note' => $data['shipment_note'],
                'id' => $subOrderId,
            ]);
            $history = $this->db->prepare(
                'INSERT INTO order_status_history
                    (sub_order_id, previous_status, new_status, reason, actor_user_id)
                 VALUES (:sub_order_id, :previous, :new, :reason, :actor)'
            );
            $history->execute([
                'sub_order_id' => $subOrderId,
                'previous' => $order['status'],
                'new' => $data['status'],
                'reason' => $data['reason'] ?: null,
                'actor' => $ownerUserId,
            ]);
            $this->refreshParentOrderStatus((int) $order['order_id']);
            $notification = $this->db->prepare(
                'INSERT INTO notifications
                    (user_id, type, title, message,
                     related_resource_type, related_resource_id)
                 VALUES
                    (:user_id, :type, :title, :message,
                     "vendor_sub_order", :resource_id)'
            );
            $statusMessages = [
                'processing' => [
                    'order_processing',
                    'Seller is preparing your order',
                    $order['sub_order_number'] . ' is now being prepared.',
                ],
                'shipped' => [
                    'order_shipped',
                    'Your order has shipped',
                    $order['sub_order_number']
                        . ' is on its way via '
                        . ($data['delivery_partner'] ?? 'seller delivery')
                        . ($data['tracking_reference']
                            ? ' (reference ' . $data['tracking_reference'] . ')' : '')
                        . '. Estimated delivery: ' . $data['estimated_delivery_date']
                        . '. Confirm receipt after delivery.',
                ],
                'cancelled' => [
                    'order_cancelled',
                    'Seller order cancelled',
                    $order['sub_order_number'] . ' was cancelled: ' . $data['reason'],
                ],
            ];
            [$notificationType, $notificationTitle, $notificationMessage]
                = $statusMessages[$data['status']];
            $notification->execute([
                'user_id' => $order['customer_user_id'],
                'type' => $notificationType,
                'title' => $notificationTitle,
                'message' => $notificationMessage,
                'resource_id' => $subOrderId,
            ]);
            $this->users->audit(
                $ownerUserId,
                'seller.order_' . $data['status'],
                'vendor_sub_order',
                $subOrderId,
                [
                    'before' => $order['status'],
                    'reason' => $data['reason'],
                    'delivery_method' => $data['delivery_method'],
                    'delivery_partner' => $data['delivery_partner'],
                    'tracking_reference' => $data['tracking_reference'],
                    'estimated_delivery_date' => $data['estimated_delivery_date'],
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
        foreach ($this->orders($ownerUserId, '') as $item) {
            if ((int) $item['id'] === $subOrderId) {
                return $item;
            }
        }
        throw new \RuntimeException('Updated order could not be loaded.');
    }

    /** @return array<int, array<string, mixed>> */
    public function reviews(int $ownerUserId): array
    {
        $shop = $this->requireApprovedShop($ownerUserId);
        $statement = $this->db->prepare(
            'SELECT r.id, r.rating, r.title, r.review_text,
                    r.is_verified_purchase, r.status, r.created_at,
                    cp.name AS product_name, u.email AS customer_email
             FROM reviews r
             INNER JOIN canonical_products cp ON cp.id=r.canonical_product_id
             INNER JOIN users u ON u.id=r.customer_user_id
             WHERE r.shop_id=:shop_id
             ORDER BY r.created_at DESC
             LIMIT 100'
        );
        $statement->execute(['shop_id' => $shop['id']]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function finance(int $ownerUserId): array
    {
        $shop = $this->requireApprovedShop($ownerUserId);
        $shopId = (int) $shop['id'];
        $summary = $this->db->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN status="completed" THEN gross_total ELSE 0 END),0) gross_sales,
                COALESCE(SUM(CASE WHEN status="completed" THEN commission_amount ELSE 0 END),0) commission,
                COALESCE(SUM(CASE WHEN status="completed" THEN vendor_net_amount ELSE 0 END),0) net_sales,
                COALESCE(SUM(CASE WHEN status IN ("pending","processing","shipped") THEN vendor_net_amount ELSE 0 END),0) pending_balance
             FROM vendor_sub_orders WHERE shop_id=:shop_id'
        );
        $summary->execute(['shop_id' => $shopId]);
        $financial = $summary->fetch();
        $reserved = $this->db->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM payouts
             WHERE shop_id=:shop_id AND status IN ("pending","approved","paid")'
        );
        $reserved->execute(['shop_id' => $shopId]);
        $reservedAmount = (float) $reserved->fetchColumn();
        $financial['available_balance'] = number_format(
            max(0, (float) $financial['net_sales'] - $reservedAmount),
            2,
            '.',
            ''
        );
        $ledger = $this->db->prepare(
            'SELECT id, event_key, entry_type, amount, currency_code,
                    description, created_at
             FROM ledger_entries WHERE shop_id=:shop_id
             ORDER BY created_at DESC LIMIT 100'
        );
        $ledger->execute(['shop_id' => $shopId]);
        $payouts = $this->db->prepare(
            'SELECT id, payout_reference, amount, currency_code, status,
                    decision_reason, requested_at, paid_at
             FROM payouts WHERE shop_id=:shop_id
             ORDER BY requested_at DESC LIMIT 100'
        );
        $payouts->execute(['shop_id' => $shopId]);
        return [
            'summary' => $financial,
            'ledger' => $ledger->fetchAll(),
            'payouts' => $payouts->fetchAll(),
        ];
    }

    /** @return array<string, mixed> */
    public function requestPayout(
        int $ownerUserId,
        array $input,
        string $ipAddress
    ): array {
        $shop = $this->requireApprovedShop($ownerUserId);
        $amount = SellerValidator::payoutAmount($input);
        $finance = $this->finance($ownerUserId);
        if ((float) $amount > (float) $finance['summary']['available_balance']) {
            throw new HttpException(409, 'Requested payout exceeds the available balance.');
        }
        $reference = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $statement = $this->db->prepare(
            'INSERT INTO payouts
                (payout_reference, shop_id, requested_by_user_id, amount, status)
             VALUES (:reference, :shop_id, :requested_by, :amount, "pending")'
        );
        $statement->execute([
            'reference' => $reference,
            'shop_id' => $shop['id'],
            'requested_by' => $ownerUserId,
            'amount' => $amount,
        ]);
        $payoutId = (int) $this->db->lastInsertId();
        $this->users->audit(
            $ownerUserId,
            'seller.payout_requested',
            'payout',
            $payoutId,
            ['amount' => $amount, 'reference' => $reference],
            $ipAddress
        );
        foreach ($this->finance($ownerUserId)['payouts'] as $payout) {
            if ((int) $payout['id'] === $payoutId) {
                return $payout;
            }
        }
        throw new \RuntimeException('Requested payout could not be loaded.');
    }

    /** @return array<string, mixed> */
    private function shopForOwner(int $ownerUserId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, owner_user_id, name, slug, description, address_text,
                    contact_phone, contact_email, logo_path, status,
                    status_reason, rating_average, rating_count, approved_at,
                    created_at, updated_at
             FROM shops WHERE owner_user_id=:owner_user_id LIMIT 1'
        );
        $statement->execute(['owner_user_id' => $ownerUserId]);
        $shop = $statement->fetch();
        if ($shop === false) {
            throw new HttpException(409, 'Submit a shop application first.');
        }
        return $shop;
    }

    /** @return array<string, mixed> */
    private function requireApprovedShop(int $ownerUserId): array
    {
        $shop = $this->shopForOwner($ownerUserId);
        if ($shop['status'] !== 'approved') {
            throw new HttpException(403, 'An approved shop is required for this action.');
        }
        return $shop;
    }

    private function requireCurrentCommissionAcceptance(int $ownerUserId, int $shopId): void
    {
        $statement = $this->db->prepare(
            'SELECT ca.id
             FROM commission_acceptances ca
             INNER JOIN commission_rules cr ON cr.id=ca.commission_rule_id
             WHERE ca.shop_owner_user_id=:owner_user_id
               AND ca.shop_id=:shop_id
               AND ca.superseded_at IS NULL
               AND cr.effective_from <= CURRENT_TIMESTAMP
               AND (cr.effective_to IS NULL OR cr.effective_to > CURRENT_TIMESTAMP)
             ORDER BY ca.id DESC LIMIT 1'
        );
        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'shop_id' => $shopId,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new HttpException(
                409,
                'Accept the current commission policy before managing listings.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function listingForOwner(
        int $ownerUserId,
        int $listingId,
        bool $lock = false
    ): array {
        $statement = $this->db->prepare(
            'SELECT l.id, l.shop_id, l.canonical_product_id, l.sku,
                    l.condition_type, l.price, l.vendor_description,
                    l.warranty_summary, l.status, l.status_reason,
                    cp.name AS product_name, cp.model, cp.category_id,
                    b.name AS brand_name, c.name AS category_name,
                    COALESCE(i.quantity_on_hand,0) quantity_on_hand,
                    COALESCE(i.quantity_reserved,0) quantity_reserved,
                    COALESCE(i.low_stock_threshold,3) low_stock_threshold
             FROM shop_product_listings l
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             INNER JOIN brands b ON b.id=cp.brand_id
             INNER JOIN categories c ON c.id=cp.category_id
             LEFT JOIN inventory i ON i.listing_id=l.id
             WHERE l.id=:id AND s.owner_user_id=:owner_user_id'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $listingId, 'owner_user_id' => $ownerUserId]);
        $listing = $statement->fetch();
        if ($listing === false) {
            throw new HttpException(404, 'Seller product listing not found.');
        }
        return $listing;
    }

    private function findOrCreateBrand(string $name): int
    {
        $statement = $this->db->prepare(
            'SELECT id FROM brands WHERE LOWER(name)=LOWER(:name) LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $brandId = $statement->fetchColumn();
        if ($brandId !== false) {
            return (int) $brandId;
        }
        $slug = $this->slug($name) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $insert = $this->db->prepare(
            'INSERT INTO brands (name, slug) VALUES (:name, :slug)'
        );
        $insert->execute(['name' => $name, 'slug' => $slug]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data
     *  @return array{0: int, 1: bool}
     */
    private function findOrCreateProduct(
        int $ownerUserId,
        array $data,
        int $brandId
    ): array {
        $statement = $this->db->prepare(
            'SELECT id FROM canonical_products
             WHERE category_id=:category_id AND brand_id=:brand_id
               AND LOWER(model)=LOWER(:model)
             LIMIT 1'
        );
        $statement->execute([
            'category_id' => $data['category_id'],
            'brand_id' => $brandId,
            'model' => $data['model'],
        ]);
        $productId = $statement->fetchColumn();
        if ($productId !== false) {
            return [(int) $productId, false];
        }
        $insert = $this->db->prepare(
            'INSERT INTO canonical_products
                (category_id, brand_id, name, model,
                 specification_completeness, created_by_user_id)
             VALUES
                (:category_id, :brand_id, :name, :model, "partial", :created_by)'
        );
        $insert->execute([
            'category_id' => $data['category_id'],
            'brand_id' => $brandId,
            'name' => $data['product_name'],
            'model' => $data['model'],
            'created_by' => $ownerUserId,
        ]);
        return [(int) $this->db->lastInsertId(), true];
    }

    /** @param array<string, mixed> $values
     *  @return array<int, array<string, mixed>>
     */
    private function validateSpecifications(int $categoryId, array $values): array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, display_name, data_type, is_required,
                    minimum_value, maximum_value
             FROM specification_definitions
             WHERE category_id=:category_id AND is_active=TRUE
             ORDER BY sort_order'
        );
        $statement->execute(['category_id' => $categoryId]);
        $definitions = $statement->fetchAll();
        if ($definitions === []) {
            throw new HttpException(
                409,
                'This category has no structured specifications yet. Ask an administrator to configure it.'
            );
        }
        foreach ($definitions as &$definition) {
            $value = $values[$definition['code']] ?? null;
            $empty = $value === null || $value === '' || $value === [];
            if ((bool) $definition['is_required'] && $empty) {
                throw new HttpException(422, 'Required specifications are missing.', [
                    $definition['code'] => [$definition['display_name'] . ' is required.'],
                ]);
            }
            if ($empty) {
                continue;
            }
            if (in_array($definition['data_type'], ['integer', 'decimal'], true)) {
                if (!is_numeric($value)) {
                    throw new HttpException(422, 'A numeric specification is invalid.', [
                        $definition['code'] => ['Enter a number.'],
                    ]);
                }
                if (
                    $definition['minimum_value'] !== null
                    && (float) $value < (float) $definition['minimum_value']
                ) {
                    throw new HttpException(422, 'A specification is below its minimum.', [
                        $definition['code'] => ['Value is below the allowed minimum.'],
                    ]);
                }
                if (
                    $definition['maximum_value'] !== null
                    && (float) $value > (float) $definition['maximum_value']
                ) {
                    throw new HttpException(422, 'A specification exceeds its maximum.', [
                        $definition['code'] => ['Value exceeds the allowed maximum.'],
                    ]);
                }
            }
            if (in_array($definition['data_type'], ['option', 'multi_option'], true)) {
                $selected = $definition['data_type'] === 'multi_option'
                    ? (array) $value
                    : [$value];
                $placeholders = implode(',', array_fill(0, count($selected), '?'));
                $options = $this->db->prepare(
                    "SELECT id, value_code
                     FROM specification_options
                     WHERE definition_id=? AND is_active=TRUE
                       AND value_code IN ({$placeholders})"
                );
                $options->execute([(int) $definition['id'], ...array_values($selected)]);
                $found = $options->fetchAll();
                if (count($found) !== count(array_unique($selected))) {
                    throw new HttpException(422, 'A controlled specification is invalid.', [
                        $definition['code'] => ['Choose only the provided options.'],
                    ]);
                }
                $definition['selected_options'] = $found;
            }
        }
        unset($definition);
        return $definitions;
    }

    /** @param array<int, array<string, mixed>> $definitions
     *  @param array<string, mixed> $values
     */
    private function saveProductSpecifications(
        int $productId,
        int $ownerUserId,
        array $definitions,
        array $values
    ): void {
        $delete = $this->db->prepare(
            'DELETE FROM product_specifications
             WHERE canonical_product_id=:product_id'
        );
        $delete->execute(['product_id' => $productId]);
        $insert = $this->db->prepare(
            'INSERT INTO product_specifications
                (canonical_product_id, definition_id, option_id, value_text,
                 value_number, value_boolean, value_json, updated_by_user_id)
             VALUES
                (:product_id, :definition_id, :option_id, :value_text,
                 :value_number, :value_boolean, :value_json, :updated_by)'
        );
        foreach ($definitions as $definition) {
            $value = $values[$definition['code']] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $row = [
                'product_id' => $productId,
                'definition_id' => $definition['id'],
                'option_id' => null,
                'value_text' => null,
                'value_number' => null,
                'value_boolean' => null,
                'value_json' => null,
                'updated_by' => $ownerUserId,
            ];
            match ($definition['data_type']) {
                'integer', 'decimal' => $row['value_number'] = $value,
                'boolean' => $row['value_boolean'] = filter_var(
                    $value,
                    FILTER_VALIDATE_BOOLEAN
                ),
                'option' => $row['option_id'] = $definition['selected_options'][0]['id'],
                'multi_option' => $row['value_json'] = json_encode(
                    array_values((array) $value),
                    JSON_THROW_ON_ERROR
                ),
                default => $row['value_text'] = substr((string) $value, 0, 500),
            };
            $insert->execute($row);
        }
    }

    private function refreshParentOrderStatus(int $orderId): void
    {
        $statement = $this->db->prepare(
            'SELECT status, COUNT(*) total
             FROM vendor_sub_orders
             WHERE order_id=:order_id
             GROUP BY status'
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
                 cancelled_at=CASE WHEN :cancelled_status="cancelled" THEN CURRENT_TIMESTAMP ELSE cancelled_at END,
                 completed_at=CASE WHEN :completed_status="completed" THEN CURRENT_TIMESTAMP ELSE completed_at END
             WHERE id=:id'
        );
        $update->execute([
            'status' => $status,
            'cancelled_status' => $status,
            'completed_status' => $status,
            'id' => $orderId,
        ]);
    }

    private function slug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
        return $slug === '' ? 'brand' : substr($slug, 0, 110);
    }

    /** @return array<int, array<string, mixed>> */
    private function productImages(int $listingId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, listing_id, original_filename, stored_filename,
                    mime_type, byte_size, alt_text, sort_order, created_at
             FROM product_images
             WHERE listing_id=:listing_id
             ORDER BY sort_order, id'
        );
        $statement->execute(['listing_id' => $listingId]);
        return $statement->fetchAll();
    }
}
