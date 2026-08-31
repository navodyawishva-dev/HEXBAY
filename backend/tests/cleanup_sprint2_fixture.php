<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$emails = array_filter([
    strtolower(trim((string) getenv('HEX_TEST_ADMIN_EMAIL'))),
    strtolower(trim((string) getenv('HEX_TEST_SELLER_EMAIL'))),
    strtolower(trim((string) getenv('HEX_TEST_SELLER2_EMAIL'))),
    strtolower(trim((string) getenv('HEX_TEST_BUYER_EMAIL'))),
]);
$testCategorySlug = trim((string) getenv('HEX_TEST_CATEGORY_SLUG'));
$sellerBrandName = trim((string) getenv('HEX_TEST_SELLER_BRAND'));
$suffix = trim((string) getenv('HEX_TEST_SUFFIX'));
if (PHP_SAPI !== 'cli' || count($emails) < 3) {
    fwrite(STDERR, "Invalid Sprint 2 cleanup environment.\n");
    exit(1);
}

$db = Database::connection();
$storedFiles = [];
try {
    $db->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $users = $db->prepare("SELECT id FROM users WHERE email IN ({$placeholders})");
    $users->execute(array_values($emails));
    $userIds = array_map('intval', $users->fetchAll(PDO::FETCH_COLUMN));
    if ($userIds !== []) {
        $idPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        foreach (
            [
                "DELETE cr FROM complaint_responses cr
                   INNER JOIN complaints c ON c.id=cr.complaint_id
                   WHERE c.customer_user_id IN ({$idPlaceholders})",
                "DELETE FROM complaints
                   WHERE customer_user_id IN ({$idPlaceholders})",
                "DELETE FROM counterfeit_reports
                   WHERE reporter_user_id IN ({$idPlaceholders})",
                "DELETE FROM user_interactions
                   WHERE user_id IN ({$idPlaceholders})",
                "DELETE FROM carts
                   WHERE customer_user_id IN ({$idPlaceholders})",
                "DELETE FROM wishlists
                   WHERE customer_user_id IN ({$idPlaceholders})",
                "DELETE FROM customer_addresses
                   WHERE customer_user_id IN ({$idPlaceholders})",
            ] as $sql
        ) {
            $statement = $db->prepare($sql);
            $statement->execute($userIds);
        }
        $shops = $db->prepare(
            "SELECT id, logo_path FROM shops
             WHERE owner_user_id IN ({$idPlaceholders})"
        );
        $shops->execute($userIds);
        $shopRows = $shops->fetchAll();
        $shopIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $shopRows
        );
        foreach ($shopRows as $shopRow) {
            if ((string) ($shopRow['logo_path'] ?? '') !== '') {
                $storedFiles[] = ['shop-logos', (string) $shopRow['logo_path']];
            }
        }

        if ($shopIds !== []) {
            $shopPlaceholders = implode(',', array_fill(0, count($shopIds), '?'));
            $listings = $db->prepare(
                "SELECT id, canonical_product_id
                 FROM shop_product_listings
                 WHERE shop_id IN ({$shopPlaceholders})"
            );
            $listings->execute($shopIds);
            $listingRows = $listings->fetchAll();
            $listingIds = array_map(
                static fn (array $row): int => (int) $row['id'],
                $listingRows
            );
            $productIds = array_map(
                static fn (array $row): int => (int) $row['canonical_product_id'],
                $listingRows
            );
            $verificationFiles = $db->prepare(
                "SELECT vd.stored_filename
                 FROM verification_documents vd
                 INNER JOIN vendor_verifications vv
                   ON vv.id=vd.verification_id
                 WHERE vv.shop_id IN ({$shopPlaceholders})"
            );
            $verificationFiles->execute($shopIds);
            foreach ($verificationFiles->fetchAll(PDO::FETCH_COLUMN) as $filename) {
                $storedFiles[] = ['protected-verification', (string) $filename];
            }

            $productFiles = $db->prepare(
                "SELECT pi.stored_filename
                 FROM product_images pi
                 INNER JOIN shop_product_listings l ON l.id=pi.listing_id
                 WHERE l.shop_id IN ({$shopPlaceholders})"
            );
            $productFiles->execute($shopIds);
            foreach ($productFiles->fetchAll(PDO::FETCH_COLUMN) as $filename) {
                $storedFiles[] = ['product-images', (string) $filename];
            }

            $orders = $db->prepare(
                "SELECT DISTINCT o.id
                 FROM orders o
                 LEFT JOIN vendor_sub_orders so ON so.order_id=o.id
                 WHERE o.customer_user_id IN ({$idPlaceholders})
                    OR so.shop_id IN ({$shopPlaceholders})"
            );
            $orders->execute([...$userIds, ...$shopIds]);
            $orderIds = array_map('intval', $orders->fetchAll(PDO::FETCH_COLUMN));
            if ($orderIds !== []) {
                $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
                $subOrders = $db->prepare(
                    "SELECT id FROM vendor_sub_orders
                     WHERE order_id IN ({$orderPlaceholders})"
                );
                $subOrders->execute($orderIds);
                $subOrderIds = array_map(
                    'intval',
                    $subOrders->fetchAll(PDO::FETCH_COLUMN)
                );
                if ($subOrderIds !== []) {
                    $subOrderPlaceholders = implode(
                        ',',
                        array_fill(0, count($subOrderIds), '?')
                    );
                    $items = $db->prepare(
                        "SELECT id FROM order_items
                         WHERE sub_order_id IN ({$subOrderPlaceholders})"
                    );
                    $items->execute($subOrderIds);
                    $itemIds = array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN));
                    if ($itemIds !== []) {
                        $itemPlaceholders = implode(
                            ',',
                            array_fill(0, count($itemIds), '?')
                        );
                        $delete = $db->prepare(
                            "DELETE FROM reviews
                             WHERE order_item_id IN ({$itemPlaceholders})"
                        );
                        $delete->execute($itemIds);
                    }
                    foreach (
                        [
                            "DELETE FROM order_status_history
                             WHERE sub_order_id IN ({$subOrderPlaceholders})",
                            "DELETE FROM order_items
                             WHERE sub_order_id IN ({$subOrderPlaceholders})",
                        ] as $sql
                    ) {
                        $delete = $db->prepare($sql);
                        $delete->execute($subOrderIds);
                    }
                }
                foreach (
                    [
                        "DELETE FROM ledger_entries
                         WHERE order_id IN ({$orderPlaceholders})",
                        "DELETE FROM order_status_history
                         WHERE order_id IN ({$orderPlaceholders})",
                        "DELETE FROM vendor_sub_orders
                         WHERE order_id IN ({$orderPlaceholders})",
                        "DELETE FROM orders WHERE id IN ({$orderPlaceholders})",
                    ] as $sql
                ) {
                    $delete = $db->prepare($sql);
                    $delete->execute($orderIds);
                }
            }
            $deletePayouts = $db->prepare(
                "DELETE FROM payouts WHERE shop_id IN ({$shopPlaceholders})"
            );
            $deletePayouts->execute($shopIds);
            if ($listingIds !== []) {
                $listingPlaceholders = implode(',', array_fill(0, count($listingIds), '?'));
                foreach (
                    [
                        "DELETE im FROM inventory_movements im
                           INNER JOIN inventory i ON i.id = im.inventory_id
                           WHERE i.listing_id IN ({$listingPlaceholders})",
                        "DELETE FROM counterfeit_reports WHERE listing_id IN ({$listingPlaceholders})",
                        "DELETE FROM complaints WHERE listing_id IN ({$listingPlaceholders})",
                        "DELETE FROM listing_flags WHERE listing_id IN ({$listingPlaceholders})",
                        "DELETE FROM inventory WHERE listing_id IN ({$listingPlaceholders})",
                        "DELETE FROM shop_product_listings WHERE id IN ({$listingPlaceholders})",
                    ] as $sql
                ) {
                    $statement = $db->prepare($sql);
                    $statement->execute($listingIds);
                }
            }
            if ($productIds !== []) {
                $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
                $statement = $db->prepare(
                    "DELETE FROM canonical_products WHERE id IN ({$productPlaceholders})"
                );
                $statement->execute($productIds);
            }
            foreach (
                [
                    "DELETE FROM commission_acceptances WHERE shop_id IN ({$shopPlaceholders})",
                    "DELETE FROM vendor_verifications WHERE shop_id IN ({$shopPlaceholders})",
                    "DELETE FROM notifications WHERE related_resource_type = 'shop'
                       AND related_resource_id IN ({$shopPlaceholders})",
                    "DELETE FROM shops WHERE id IN ({$shopPlaceholders})",
                ] as $sql
            ) {
                $statement = $db->prepare($sql);
                $statement->execute($shopIds);
            }
        }

        foreach (
            [
                "DELETE FROM notifications WHERE user_id IN ({$idPlaceholders})",
                "DELETE FROM auth_revoked_tokens WHERE user_id IN ({$idPlaceholders})",
                "DELETE FROM audit_logs WHERE actor_user_id IN ({$idPlaceholders})",
                "DELETE FROM users WHERE id IN ({$idPlaceholders})",
            ] as $sql
        ) {
            $statement = $db->prepare($sql);
            $statement->execute($userIds);
        }
    }
    if ($suffix !== '') {
        $brand = $db->prepare('DELETE FROM brands WHERE slug = :slug');
        $brand->execute(['slug' => 'sprint-two-' . $suffix]);
    }
    if ($sellerBrandName !== '') {
        $brand = $db->prepare(
            'DELETE FROM brands
             WHERE name = :name
               AND NOT EXISTS (
                   SELECT 1 FROM canonical_products WHERE brand_id = brands.id
               )'
        );
        $brand->execute(['name' => $sellerBrandName]);
    }
    if ($testCategorySlug !== '') {
        $category = $db->prepare('DELETE FROM categories WHERE slug = :slug');
        $category->execute(['slug' => $testCategorySlug]);
    }
    $db->commit();
    $storageRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    foreach ($storedFiles as [$directory, $filename]) {
        if (
            in_array(
                $directory,
                ['shop-logos', 'product-images', 'protected-verification'],
                true
            )
            && preg_match('/^[a-f0-9]{32}\.(?:pdf|jpg|png|webp)$/', $filename)
        ) {
            $path = $storageRoot . DIRECTORY_SEPARATOR
                . $directory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    fwrite(STDOUT, "Sprint 2 test fixtures cleaned.\n");
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
