<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\UserRepository;
use Hexbay\Repositories\MarketplaceRepository;
use Hexbay\Services\BuyerService;
use Hexbay\Services\SellerModuleService;
use Hexbay\Support\HttpException;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::connection();
$userId = null;
$orderId = null;
$orderedListingQuantities = [];

try {
    $roleId = (int) $db->query(
        "SELECT id FROM roles WHERE name='customer' AND is_active=1"
    )->fetchColumn();
    if ($roleId < 1) {
        throw new RuntimeException('Customer role is missing.');
    }

    $offers = $db->query(
        'SELECT l.id listing_id, l.canonical_product_id, l.shop_id, l.price
         FROM shop_product_listings l
         INNER JOIN shops s ON s.id=l.shop_id AND s.status="approved"
         INNER JOIN inventory i ON i.listing_id=l.id
         WHERE l.status="active"
           AND i.quantity_on_hand-i.quantity_reserved >= 2
         ORDER BY l.shop_id, l.id'
    )->fetchAll();
    $selected = [];
    $products = [];
    $shops = [];
    foreach ($offers as $offer) {
        $productId = (int) $offer['canonical_product_id'];
        $shopId = (int) $offer['shop_id'];
        if (isset($products[$productId]) || (count($selected) === 1 && isset($shops[$shopId]))) {
            continue;
        }
        $selected[] = $offer;
        $products[$productId] = true;
        $shops[$shopId] = true;
        if (count($selected) === 2) {
            break;
        }
    }
    if (count($selected) !== 2 || count($shops) !== 2) {
        throw new RuntimeException('Two in-stock offers from different shops are required.');
    }

    $insertUser = $db->prepare(
        'INSERT INTO users (role_id, email, password_hash, status)
         VALUES (:role_id, :email, :password_hash, "active")'
    );
    $insertUser->execute([
        'role_id' => $roleId,
        'email' => 'setup-cart-' . bin2hex(random_bytes(6)) . '@example.test',
        'password_hash' => password_hash('FixturePass123', PASSWORD_DEFAULT),
    ]);
    $userId = (int) $db->lastInsertId();

    $service = new BuyerService($db, new UserRepository($db));
    $payload = ['items' => array_map(
        static fn (array $offer, int $index): array => [
            'listing_id' => (int) $offer['listing_id'],
            'quantity' => 1,
            'expected_price_lkr' => (string) $offer['price'],
            'component_group' => 'pc',
            'component_code' => $index === 0 ? 'processor' : 'motherboard',
        ],
        $selected,
        array_keys($selected)
    ), 'setup' => [
        'build_rank' => 1,
        'setup_scope' => 'pc_only',
        'target_budget_lkr' => 300000,
        'max_budget_lkr' => 322500,
        'requirements' => ['workloads' => ['balanced_general' => 1]],
        'scores' => ['performance' => 75, 'value' => 80],
        'compatibility' => ['status' => 'compatible', 'rule_set_version' => 'test'],
    ]];
    $result = $service->addSetupToCart($userId, $payload);
    if (
        (int) $result['setup']['added_item_count'] !== 2
        || (int) $result['setup']['shop_count'] !== 2
        || count($result['cart']['items']) !== 2
        || count($result['cart']['setups']) !== 1
        || count($result['cart']['setups'][0]['items']) !== 2
        || $result['cart']['setups'][0]['name'] !== 'HexBot Balanced PC Build · Build 1'
    ) {
        throw new RuntimeException('The named setup identity was not preserved in the multi-shop cart.');
    }

    $cartId = (int) $result['cart']['id'];
    $before = (int) $db->query(
        "SELECT SUM(quantity) FROM cart_items WHERE cart_id={$cartId}"
    )->fetchColumn();
    $changedPricePayload = $payload;
    $changedPricePayload['items'][0]['expected_price_lkr'] = '0.01';
    try {
        $service->addSetupToCart($userId, $changedPricePayload);
        throw new RuntimeException('A stale displayed price was accepted.');
    } catch (HttpException $exception) {
        if ($exception->status !== 409) {
            throw $exception;
        }
    }
    $after = (int) $db->query(
        "SELECT SUM(quantity) FROM cart_items WHERE cart_id={$cartId}"
    )->fetchColumn();
    if ($before !== $after) {
        throw new RuntimeException('Price-check failure partially changed the cart.');
    }

    $removedCartItemId = (int) $result['cart']['items'][0]['id'];
    $incompleteCart = $service->removeCartItem($userId, $removedCartItemId);
    if (
        count($incompleteCart['items']) !== 1
        || count($incompleteCart['setups']) !== 1
        || $incompleteCart['setups'][0]['is_complete_in_cart']
    ) {
        throw new RuntimeException('Removing a setup product did not expose the incomplete setup state.');
    }
    $restoredCart = $service->restoreCartSetup(
        $userId,
        (string) $result['cart']['setups'][0]['public_id'],
        '127.0.0.1'
    );
    if (
        count($restoredCart['items']) !== 2
        || !$restoredCart['setups'][0]['is_complete_in_cart']
    ) {
        throw new RuntimeException('Missing HexBot setup products were not restored.');
    }
    $result['cart'] = $restoredCart;

    $address = $db->prepare(
        'INSERT INTO customer_addresses
            (customer_user_id, label, recipient_name, phone,
             address_line_1, city, district, country_code, is_default)
         VALUES (:user_id, "Test", "Setup Checkout Test", "0771234567",
                 "1 Test Lane", "Colombo", "Colombo", "LK", TRUE)'
    );
    $address->execute(['user_id' => $userId]);
    $addressId = (int) $db->lastInsertId();
    $setupPublicId = (string) $result['cart']['setups'][0]['public_id'];

    try {
        $service->checkout($userId, [
            'address_id' => $addressId,
            'payment_method' => 'card_simulation',
            'simulated_payment_acknowledged' => true,
            'expected_total_lkr' => '0.01',
            'setup_public_ids' => [$setupPublicId],
        ], '127.0.0.1');
        throw new RuntimeException('A stale checkout total was accepted.');
    } catch (HttpException $exception) {
        if ($exception->status !== 409) {
            throw $exception;
        }
    }
    $orderCount = $db->prepare('SELECT COUNT(*) FROM orders WHERE customer_user_id=:user_id');
    $orderCount->execute(['user_id' => $userId]);
    if ((int) $orderCount->fetchColumn() !== 0) {
        throw new RuntimeException('Stale checkout created a partial order.');
    }

    foreach ($selected as $offer) {
        $orderedListingQuantities[(int) $offer['listing_id']] = 1;
    }
    $order = $service->checkout($userId, [
        'address_id' => $addressId,
        'payment_method' => 'card_simulation',
        'simulated_payment_acknowledged' => true,
        'expected_total_lkr' => $result['cart']['summary']['subtotal'],
        'setup_public_ids' => [$setupPublicId],
    ], '127.0.0.1');
    $orderId = (int) $order['id'];
    if (
        $order['payment_method'] !== 'card_simulation'
        || $order['payment_status'] !== 'simulated_authorized'
        || count($order['setups']) !== 1
        || $order['setups'][0]['public_id'] !== $setupPublicId
        || count($order['sub_orders']) !== 2
    ) {
        throw new RuntimeException('Checkout did not preserve setup, payment, and seller delivery details.');
    }

    $sellerService = new SellerModuleService($db, new UserRepository($db));
    $ownerIds = [];
    foreach ($order['sub_orders'] as $subOrder) {
        $owner = $db->prepare('SELECT owner_user_id FROM shops WHERE id=:shop_id');
        $owner->execute(['shop_id' => $subOrder['shop_id']]);
        $ownerIds[(int) $subOrder['id']] = (int) $owner->fetchColumn();
    }
    $firstSubOrder = $order['sub_orders'][0];
    $secondSubOrder = $order['sub_orders'][1];
    try {
        $sellerService->updateOrderStatus(
            $ownerIds[(int) $secondSubOrder['id']],
            (int) $firstSubOrder['id'],
            ['status' => 'processing'],
            '127.0.0.1'
        );
        throw new RuntimeException('A seller changed another shop\'s sub-order.');
    } catch (HttpException $exception) {
        if ($exception->status !== 404) {
            throw $exception;
        }
    }
    foreach ($order['sub_orders'] as $subOrder) {
        $ownerUserId = $ownerIds[(int) $subOrder['id']];
        $sellerService->updateOrderStatus(
            $ownerUserId,
            (int) $subOrder['id'],
            ['status' => 'processing'],
            '127.0.0.1'
        );
        try {
            $sellerService->updateOrderStatus(
                $ownerUserId,
                (int) $subOrder['id'],
                [
                    'status' => 'shipped',
                    'delivery_method' => 'third_party_courier',
                    'delivery_partner' => 'Fixture Courier',
                    'tracking_reference' => 'FIX-' . $subOrder['id'],
                    'estimated_delivery_date' => date('Y-m-d', strtotime('+2 days')),
                ],
                '127.0.0.1'
            );
            throw new RuntimeException('Shipment skipped the required fulfilment checklist.');
        } catch (HttpException $exception) {
            if ($exception->status !== 409) {
                throw $exception;
            }
        }
        foreach ([
            'stock_verified',
            'items_packed',
            'delivery_address_verified',
        ] as $checkpoint) {
            $sellerService->completeFulfilmentCheckpoint(
                $ownerUserId,
                (int) $subOrder['id'],
                ['checkpoint_code' => $checkpoint],
                '127.0.0.1'
            );
        }
        $shipped = $sellerService->updateOrderStatus(
            $ownerUserId,
            (int) $subOrder['id'],
            [
                'status' => 'shipped',
                'delivery_method' => 'third_party_courier',
                'delivery_partner' => 'Fixture Courier',
                'tracking_reference' => 'FIX-' . $subOrder['id'],
                'estimated_delivery_date' => date('Y-m-d', strtotime('+2 days')),
                'shipment_note' => 'Integration test shipment.',
            ],
            '127.0.0.1'
        );
        if (
            $shipped['status'] !== 'shipped'
            || !$shipped['fulfilment_ready_to_ship']
            || $shipped['tracking_reference'] !== 'FIX-' . $subOrder['id']
        ) {
            throw new RuntimeException('Seller fulfilment evidence was not preserved.');
        }
    }
    $buyerOrder = $service->order($userId, $orderId);
    if (
        $buyerOrder['status'] !== 'shipped'
        || count(array_filter(
            $buyerOrder['sub_orders'],
            static fn (array $subOrder): bool => $subOrder['status'] === 'shipped'
        )) !== 2
    ) {
        throw new RuntimeException('Buyer tracking did not receive the seller shipment state.');
    }
    $trackingOrder = null;
    foreach ($service->orders($userId) as $candidate) {
        if ((int) $candidate['id'] === $orderId) {
            $trackingOrder = $candidate;
            break;
        }
    }
    if (
        $trackingOrder === null
        || count($trackingOrder['deliveries']) !== 2
        || (int) $trackingOrder['shipped_delivery_count'] !== 2
        || (int) $trackingOrder['setup_count'] !== 1
    ) {
        throw new RuntimeException('Customer tracking summary did not preserve deliveries and setup identity.');
    }
    $marketplace = new MarketplaceRepository($db);
    $shipmentNotifications = array_values(array_filter(
        $marketplace->notifications($userId, 50),
        static fn (array $notification): bool => $notification['type'] === 'order_shipped'
    ));
    if (
        count($shipmentNotifications) !== 2
        || count(array_filter(
            $shipmentNotifications,
            static fn (array $notification): bool => (int) $notification['order_id'] === $orderId
        )) !== 2
    ) {
        throw new RuntimeException('Shipment notifications did not deep-link to the parent order.');
    }
    $marketplace->markNotificationRead(
        (int) $shipmentNotifications[0]['id'],
        $userId
    );
    $marketplace->markAllNotificationsRead($userId);
    if ($marketplace->unreadNotificationCount($userId) !== 0) {
        throw new RuntimeException('Notification read state was not preserved.');
    }

    foreach ($buyerOrder['sub_orders'] as $subOrder) {
        $buyerOrder = $service->confirmReceipt(
            $userId,
            (int) $subOrder['id'],
            '127.0.0.1'
        );
    }
    if ($buyerOrder['status'] !== 'completed') {
        throw new RuntimeException('Parent order did not complete after both receipts.');
    }
    $completedNotifications = array_values(array_filter(
        $marketplace->notifications($userId, 50),
        static fn (array $notification): bool => $notification['type'] === 'delivery_confirmed'
    ));
    if (
        count($completedNotifications) !== 2
        || count(array_filter(
            $completedNotifications,
            static fn (array $notification): bool => (int) $notification['order_id'] === $orderId
        )) !== 2
    ) {
        throw new RuntimeException('Receipt notifications did not preserve tracking links.');
    }
    $ledgerCount = (int) $db->query(
        "SELECT COUNT(*) FROM ledger_entries WHERE order_id={$orderId}"
    )->fetchColumn();
    $service->confirmReceipt(
        $userId,
        (int) $buyerOrder['sub_orders'][0]['id'],
        '127.0.0.1'
    );
    $ledgerCountAfterRetry = (int) $db->query(
        "SELECT COUNT(*) FROM ledger_entries WHERE order_id={$orderId}"
    )->fetchColumn();
    if ($ledgerCount !== 4 || $ledgerCountAfterRetry !== $ledgerCount) {
        throw new RuntimeException('Receipt confirmation was not financially idempotent.');
    }

    fwrite(
        STDOUT,
        "Complete setup order integration passed (checkout, seller isolation, packing checks, shipment tracking, notification links/read state, receipt confirmation, idempotent ledger).\n"
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Setup cart integration test failed: {$exception->getMessage()}\n");
    $exitCode = 1;
} finally {
    if ($orderId !== null) {
        $subOrderIds = $db->query(
            "SELECT id FROM vendor_sub_orders WHERE order_id={$orderId}"
        )->fetchAll(PDO::FETCH_COLUMN);
        $orderItemIds = $db->query(
            "SELECT oi.id FROM order_items oi
             INNER JOIN vendor_sub_orders so ON so.id=oi.sub_order_id
             WHERE so.order_id={$orderId}"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orderedListingQuantities as $listingId => $quantity) {
            $restore = $db->prepare(
                'UPDATE inventory SET quantity_on_hand=quantity_on_hand+:quantity
                 WHERE listing_id=:listing_id'
            );
            $restore->execute(['quantity' => $quantity, 'listing_id' => $listingId]);
        }
        if ($orderItemIds !== []) {
            $ids = implode(',', array_map('intval', $orderItemIds));
            $db->exec("DELETE FROM inventory_movements WHERE reference_type='order_item' AND reference_id IN ({$ids})");
            $db->exec("DELETE FROM order_items WHERE id IN ({$ids})");
        }
        if ($subOrderIds !== []) {
            $ids = implode(',', array_map('intval', $subOrderIds));
            $db->exec("DELETE FROM ledger_entries WHERE order_id={$orderId}");
            $db->exec("DELETE FROM notifications WHERE related_resource_type='vendor_sub_order' AND related_resource_id IN ({$ids})");
            $db->exec("DELETE FROM audit_logs WHERE resource_type='vendor_sub_order' AND resource_id IN ({$ids})");
            $db->exec("DELETE FROM order_status_history WHERE sub_order_id IN ({$ids})");
            $db->exec("DELETE FROM vendor_sub_orders WHERE id IN ({$ids})");
        }
        $db->exec("DELETE FROM notifications WHERE related_resource_type='order' AND related_resource_id={$orderId}");
        $db->exec("DELETE FROM audit_logs WHERE resource_type='order' AND resource_id={$orderId}");
        $db->exec("DELETE FROM orders WHERE id={$orderId}");
    }
    if ($userId !== null) {
        $deleteInteractions = $db->prepare('DELETE FROM user_interactions WHERE user_id=:user_id');
        $deleteInteractions->execute(['user_id' => $userId]);
        $deleteUser = $db->prepare('DELETE FROM users WHERE id=:user_id');
        $deleteUser->execute(['user_id' => $userId]);
    }
}

exit($exitCode ?? 0);
