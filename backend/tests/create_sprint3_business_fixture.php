<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$suffix = trim((string) getenv('HEX_TEST_SUFFIX'));
$sellerEmail = strtolower(trim((string) getenv('HEX_TEST_SELLER_EMAIL')));
$buyerEmail = strtolower(trim((string) getenv('HEX_TEST_BUYER_EMAIL')));
$listingId = (int) getenv('HEX_TEST_LISTING_ID');
if (
    PHP_SAPI !== 'cli'
    || !preg_match('/^[a-f0-9]{32}$/', $suffix)
    || filter_var($sellerEmail, FILTER_VALIDATE_EMAIL) === false
    || filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) === false
    || $listingId < 1
) {
    fwrite(STDERR, "Invalid Sprint 3 business fixture environment.\n");
    exit(1);
}

$db = Database::connection();
try {
    $db->beginTransaction();
    $listing = $db->prepare(
        'SELECT l.id, l.shop_id, l.canonical_product_id, l.sku, l.price,
                cp.name product_name, s.owner_user_id
         FROM shop_product_listings l
         INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
         INNER JOIN shops s ON s.id=l.shop_id
         INNER JOIN users u ON u.id=s.owner_user_id
         WHERE l.id=:listing_id AND u.email=:seller_email'
    );
    $listing->execute([
        'listing_id' => $listingId,
        'seller_email' => $sellerEmail,
    ]);
    $product = $listing->fetch();
    $buyer = $db->prepare('SELECT id FROM users WHERE email=:email');
    $buyer->execute(['email' => $buyerEmail]);
    $buyerId = (int) $buyer->fetchColumn();
    $commissionRuleId = (int) $db->query(
        'SELECT id FROM commission_rules
         WHERE effective_from <= CURRENT_TIMESTAMP
           AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
         ORDER BY effective_from DESC, id DESC LIMIT 1'
    )->fetchColumn();
    if ($product === false || $buyerId < 1 || $commissionRuleId < 1) {
        throw new RuntimeException('Required Sprint 3 fixture parents were not found.');
    }

    $insertOrder = $db->prepare(
        'INSERT INTO orders
            (order_number, customer_user_id, address_snapshot_json,
             grand_total, status, completed_at)
         VALUES
            (:number, :customer, :address, :total, :status,
             CASE WHEN :completed_status="completed" THEN CURRENT_TIMESTAMP ELSE NULL END)'
    );
    $insertSubOrder = $db->prepare(
        'INSERT INTO vendor_sub_orders
            (order_id, shop_id, sub_order_number, status, gross_total,
             commission_rule_id, commission_rate_snapshot, commission_amount,
             vendor_net_amount, completed_at)
         VALUES
            (:order_id, :shop_id, :number, :status, :gross,
             :rule_id, 5.00, :commission, :net,
             CASE WHEN :completed_status="completed" THEN CURRENT_TIMESTAMP ELSE NULL END)'
    );
    $insertItem = $db->prepare(
        'INSERT INTO order_items
            (sub_order_id, listing_id, canonical_product_id,
             product_name_snapshot, sku_snapshot, specification_snapshot_json,
             quantity, unit_price, line_total)
         VALUES
            (:sub_order_id, :listing_id, :product_id,
             :product_name, :sku, JSON_OBJECT("fixture", true),
             1, :unit_price, :line_total)'
    );

    $address = json_encode([
        'recipient_name' => 'Buyer Tester',
        'line_1' => '10 Sprint Three Test Street',
        'city' => 'Colombo',
        'telephone' => '+94 77 000 0000',
    ], JSON_THROW_ON_ERROR);

    $insertOrder->execute([
        'number' => 'HB-PENDING-' . substr($suffix, 0, 12),
        'customer' => $buyerId,
        'address' => $address,
        'total' => '125000.00',
        'status' => 'pending',
        'completed_status' => 'pending',
    ]);
    $pendingOrderId = (int) $db->lastInsertId();
    $insertSubOrder->execute([
        'order_id' => $pendingOrderId,
        'shop_id' => $product['shop_id'],
        'number' => 'HB-SUB-PENDING-' . substr($suffix, 0, 12),
        'status' => 'pending',
        'gross' => '125000.00',
        'rule_id' => $commissionRuleId,
        'commission' => '6250.00',
        'net' => '118750.00',
        'completed_status' => 'pending',
    ]);
    $pendingSubOrderId = (int) $db->lastInsertId();
    $insertItem->execute([
        'sub_order_id' => $pendingSubOrderId,
        'listing_id' => $listingId,
        'product_id' => $product['canonical_product_id'],
        'product_name' => $product['product_name'],
        'sku' => $product['sku'],
        'unit_price' => '125000.00',
        'line_total' => '125000.00',
    ]);

    $insertOrder->execute([
        'number' => 'HB-COMPLETE-' . substr($suffix, 0, 12),
        'customer' => $buyerId,
        'address' => $address,
        'total' => '100000.00',
        'status' => 'completed',
        'completed_status' => 'completed',
    ]);
    $completedOrderId = (int) $db->lastInsertId();
    $insertSubOrder->execute([
        'order_id' => $completedOrderId,
        'shop_id' => $product['shop_id'],
        'number' => 'HB-SUB-COMPLETE-' . substr($suffix, 0, 12),
        'status' => 'completed',
        'gross' => '100000.00',
        'rule_id' => $commissionRuleId,
        'commission' => '5000.00',
        'net' => '95000.00',
        'completed_status' => 'completed',
    ]);
    $completedSubOrderId = (int) $db->lastInsertId();
    $insertItem->execute([
        'sub_order_id' => $completedSubOrderId,
        'listing_id' => $listingId,
        'product_id' => $product['canonical_product_id'],
        'product_name' => $product['product_name'],
        'sku' => $product['sku'],
        'unit_price' => '100000.00',
        'line_total' => '100000.00',
    ]);
    $completedItemId = (int) $db->lastInsertId();

    $review = $db->prepare(
        'INSERT INTO reviews
            (order_item_id, customer_user_id, canonical_product_id, shop_id,
             rating, title, review_text, is_verified_purchase, status)
         VALUES
            (:item_id, :customer_id, :product_id, :shop_id, 5,
             "Excellent seller experience",
             "Verified Sprint 3 seller review fixture.",
             TRUE, "published")'
    );
    $review->execute([
        'item_id' => $completedItemId,
        'customer_id' => $buyerId,
        'product_id' => $product['canonical_product_id'],
        'shop_id' => $product['shop_id'],
    ]);
    $reviewId = (int) $db->lastInsertId();

    $ledger = $db->prepare(
        'INSERT INTO ledger_entries
            (event_key, shop_id, order_id, sub_order_id, entry_type,
             amount, description, created_by_user_id)
         VALUES
            (:event_key, :shop_id, :order_id, :sub_order_id, :entry_type,
             :amount, :description, :created_by)'
    );
    $ledger->execute([
        'event_key' => 'test.sale.' . $suffix,
        'shop_id' => $product['shop_id'],
        'order_id' => $completedOrderId,
        'sub_order_id' => $completedSubOrderId,
        'entry_type' => 'sale',
        'amount' => '100000.00',
        'description' => 'Completed Sprint 3 fixture sale',
        'created_by' => $product['owner_user_id'],
    ]);
    $ledger->execute([
        'event_key' => 'test.commission.' . $suffix,
        'shop_id' => $product['shop_id'],
        'order_id' => $completedOrderId,
        'sub_order_id' => $completedSubOrderId,
        'entry_type' => 'commission',
        'amount' => '-5000.00',
        'description' => 'Five percent Hexbay fixture commission',
        'created_by' => $product['owner_user_id'],
    ]);

    $inventory = $db->prepare(
        'UPDATE inventory SET quantity_reserved=1
         WHERE listing_id=:listing_id AND quantity_on_hand >= 1'
    );
    $inventory->execute(['listing_id' => $listingId]);
    $shopRating = $db->prepare(
        'UPDATE shops SET rating_average=5.00, rating_count=1 WHERE id=:shop_id'
    );
    $shopRating->execute(['shop_id' => $product['shop_id']]);

    $db->commit();
    echo json_encode([
        'pending_order_id' => $pendingOrderId,
        'pending_sub_order_id' => $pendingSubOrderId,
        'completed_order_id' => $completedOrderId,
        'completed_sub_order_id' => $completedSubOrderId,
        'review_id' => $reviewId,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
