<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$suffix = trim((string) getenv('HEX_TEST_SUFFIX'));
$sellerEmail = strtolower(trim((string) getenv('HEX_TEST_SELLER_EMAIL')));
$buyerEmail = strtolower(trim((string) getenv('HEX_TEST_BUYER_EMAIL')));
if (
    PHP_SAPI !== 'cli'
    || !preg_match('/^[a-f0-9]{32}$/', $suffix)
    || filter_var($sellerEmail, FILTER_VALIDATE_EMAIL) === false
    || filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) === false
) {
    fwrite(STDERR, "Invalid moderation fixture environment.\n");
    exit(1);
}

$db = Database::connection();
try {
    $db->beginTransaction();
    $user = $db->prepare('SELECT id FROM users WHERE email = :email');
    $user->execute(['email' => $sellerEmail]);
    $sellerId = (int) $user->fetchColumn();
    $user->execute(['email' => $buyerEmail]);
    $buyerId = (int) $user->fetchColumn();
    $shop = $db->prepare(
        'SELECT id FROM shops WHERE owner_user_id = :owner_user_id'
    );
    $shop->execute(['owner_user_id' => $sellerId]);
    $shopId = (int) $shop->fetchColumn();
    $categoryId = (int) $db->query(
        'SELECT id FROM categories WHERE slug = "laptops" LIMIT 1'
    )->fetchColumn();
    if ($sellerId === 0 || $buyerId === 0 || $shopId === 0 || $categoryId === 0) {
        throw new RuntimeException('Required moderation fixture parents were not found.');
    }

    $brand = $db->prepare(
        'INSERT INTO brands (name, slug) VALUES (:name, :slug)'
    );
    $brand->execute([
        'name' => 'Sprint Two ' . $suffix,
        'slug' => 'sprint-two-' . $suffix,
    ]);
    $brandId = (int) $db->lastInsertId();

    $product = $db->prepare(
        'INSERT INTO canonical_products
            (category_id, brand_id, name, model, specification_completeness,
             created_by_user_id)
         VALUES
            (:category_id, :brand_id, :name, :model, "partial", :created_by)'
    );
    $product->execute([
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'name' => 'Sprint Two Test Laptop',
        'model' => 'TEST-' . $suffix,
        'created_by' => $sellerId,
    ]);
    $productId = (int) $db->lastInsertId();

    $listing = $db->prepare(
        'INSERT INTO shop_product_listings
            (shop_id, canonical_product_id, sku, price, vendor_description,
             status)
         VALUES
            (:shop_id, :product_id, :sku, 125000.00,
             "Temporary integration-test listing.", "pending_approval")'
    );
    $listing->execute([
        'shop_id' => $shopId,
        'product_id' => $productId,
        'sku' => 'SMOKE-' . $suffix,
    ]);
    $listingId = (int) $db->lastInsertId();

    $inventory = $db->prepare(
        'INSERT INTO inventory (listing_id, quantity_on_hand, low_stock_threshold)
         VALUES (:listing_id, 4, 2)'
    );
    $inventory->execute(['listing_id' => $listingId]);

    $flag = $db->prepare(
        'INSERT INTO listing_flags
            (listing_id, rule_code, rule_version, severity, observed_value,
             explanation)
         VALUES
            (:listing_id, "unusually_low_price", "test-v1", "medium",
             "125000.00", "Temporary explainable integration-test flag.")'
    );
    $flag->execute(['listing_id' => $listingId]);
    $flagId = (int) $db->lastInsertId();

    $complaint = $db->prepare(
        'INSERT INTO complaints
            (customer_user_id, listing_id, shop_id, subject, description)
         VALUES
            (:customer_id, :listing_id, :shop_id,
             "Sprint 2 test complaint",
             "Temporary complaint used to test administrator resolution.")'
    );
    $complaint->execute([
        'customer_id' => $buyerId,
        'listing_id' => $listingId,
        'shop_id' => $shopId,
    ]);
    $complaintId = (int) $db->lastInsertId();

    $report = $db->prepare(
        'INSERT INTO counterfeit_reports
            (reporter_user_id, listing_id, reason_code, description)
         VALUES
            (:reporter_id, :listing_id, "suspicious_model_information",
             "Temporary report used to test administrator review.")'
    );
    $report->execute([
        'reporter_id' => $buyerId,
        'listing_id' => $listingId,
    ]);
    $reportId = (int) $db->lastInsertId();

    $db->commit();
    echo json_encode([
        'listing_id' => $listingId,
        'flag_id' => $flagId,
        'complaint_id' => $complaintId,
        'report_id' => $reportId,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
