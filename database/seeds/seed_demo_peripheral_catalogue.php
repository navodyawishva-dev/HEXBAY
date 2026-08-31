<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__, 2) . '/backend/src/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This demo seed must be run from the command line.\n");
    exit(1);
}

$fixture = json_decode(
    (string) file_get_contents(__DIR__ . '/demo_peripheral_catalogue.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);
$db = Database::connection();

function peripheralSeedId(PDO $db, string $sql, array $params, string $error): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $id = (int) $statement->fetchColumn();
    if ($id < 1) {
        throw new RuntimeException($error);
    }
    return $id;
}

try {
    $db->beginTransaction();
    $categoryId = peripheralSeedId(
        $db,
        'SELECT id FROM categories WHERE slug="accessories" AND is_active=TRUE',
        [],
        'The active accessories category is missing.'
    );
    $adminId = peripheralSeedId(
        $db,
        'SELECT u.id FROM users u INNER JOIN roles r ON r.id=u.role_id
         WHERE r.name="administrator" AND u.status="active" ORDER BY u.id LIMIT 1',
        [],
        'Create an active administrator before seeding peripherals.'
    );
    $definitionsStatement = $db->prepare(
        'SELECT id, code, data_type FROM specification_definitions
         WHERE category_id=:category AND is_active=TRUE'
    );
    $definitionsStatement->execute(['category' => $categoryId]);
    $definitions = [];
    foreach ($definitionsStatement->fetchAll() as $row) {
        $definitions[(string) $row['code']] = $row;
    }
    if (!isset($definitions['accessory_type'], $definitions['screen_size_inches'])) {
        throw new RuntimeException('Apply migrations 003 and 007 before this seed.');
    }
    $optionsStatement = $db->prepare(
        'SELECT sd.code, so.value_code, so.id
         FROM specification_options so
         INNER JOIN specification_definitions sd ON sd.id=so.definition_id
         WHERE sd.category_id=:category AND so.is_active=TRUE'
    );
    $optionsStatement->execute(['category' => $categoryId]);
    $options = [];
    foreach ($optionsStatement->fetchAll() as $row) {
        $options[(string) $row['code']][(string) $row['value_code']] = (int) $row['id'];
    }
    $shopStatement = $db->query(
        'SELECT s.id, s.slug, s.owner_user_id FROM shops s
         WHERE s.slug IN ("demo-novacore-systems", "demo-bytecraft-technologies")
           AND s.status="approved"'
    );
    $shops = [];
    foreach ($shopStatement->fetchAll() as $row) {
        $shops[(string) $row['slug']] = $row;
    }
    if (count($shops) !== 2) {
        throw new RuntimeException('Run seed_demo_pc_catalogue.php before this seed.');
    }

    foreach ($fixture['products'] as $product) {
        $db->prepare(
            'INSERT INTO brands (name, slug, is_active) VALUES (:name, :slug, TRUE)
             ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=TRUE'
        )->execute(['name' => $product['brand'], 'slug' => $product['brand_slug']]);
        $brandId = peripheralSeedId(
            $db,
            'SELECT id FROM brands WHERE slug=:slug',
            ['slug' => $product['brand_slug']],
            'A demo peripheral brand could not be loaded.'
        );
        $firstShop = array_key_first($product['offers']);
        $creatorId = (int) $shops[$firstShop]['owner_user_id'];
        $existing = $db->prepare(
            'SELECT id FROM canonical_products
             WHERE category_id=:category AND brand_id=:brand AND model=:model'
        );
        $existing->execute([
            'category' => $categoryId, 'brand' => $brandId, 'model' => $product['model'],
        ]);
        $productId = (int) $existing->fetchColumn();
        if ($productId < 1) {
            $insert = $db->prepare(
                'INSERT INTO canonical_products
                    (category_id, brand_id, name, model, manufacturer_part_number,
                     specification_completeness, is_active, created_by_user_id)
                 VALUES (:category, :brand, :name, :model, :mpn, "complete", TRUE, :creator)'
            );
            $insert->execute([
                'category' => $categoryId, 'brand' => $brandId,
                'name' => $product['name'], 'model' => $product['model'],
                'mpn' => 'HB-DEMO-' . $product['sku'], 'creator' => $creatorId,
            ]);
            $productId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE canonical_products SET name=:name,
                    manufacturer_part_number=:mpn,
                    specification_completeness="complete", is_active=TRUE WHERE id=:id'
            )->execute([
                'name' => $product['name'], 'mpn' => 'HB-DEMO-' . $product['sku'],
                'id' => $productId,
            ]);
        }

        $db->prepare('DELETE FROM product_specifications WHERE canonical_product_id=:product')
            ->execute(['product' => $productId]);
        $insertSpec = $db->prepare(
            'INSERT INTO product_specifications
                (canonical_product_id, definition_id, option_id, value_text,
                 value_number, value_boolean, value_json, source_note,
                 updated_by_user_id)
             VALUES (:product, :definition, :option_id, :value_text,
                     :value_number, :value_boolean, :value_json,
                     "Fictional local peripheral demonstration fixture", :updater)'
        );
        foreach ($product['specs'] as $code => $value) {
            if (!isset($definitions[$code])) {
                throw new RuntimeException("Unknown accessories.{$code} definition.");
            }
            $definition = $definitions[$code];
            $row = [
                'product' => $productId, 'definition' => $definition['id'],
                'option_id' => null, 'value_text' => null, 'value_number' => null,
                'value_boolean' => null, 'value_json' => null, 'updater' => $adminId,
            ];
            if (in_array($definition['data_type'], ['integer', 'decimal'], true)) {
                $row['value_number'] = $value;
            } elseif ($definition['data_type'] === 'boolean') {
                $row['value_boolean'] = (int) (bool) $value;
            } elseif ($definition['data_type'] === 'option') {
                $row['option_id'] = $options[$code][(string) $value] ?? null;
                if ($row['option_id'] === null) {
                    throw new RuntimeException("Unknown accessories.{$code}={$value} option.");
                }
            } elseif ($definition['data_type'] === 'multi_option') {
                foreach ((array) $value as $option) {
                    if (!isset($options[$code][(string) $option])) {
                        throw new RuntimeException("Unknown accessories.{$code}={$option} option.");
                    }
                }
                $row['value_json'] = json_encode(array_values((array) $value), JSON_THROW_ON_ERROR);
            } else {
                $row['value_text'] = (string) $value;
            }
            $insertSpec->execute($row);
        }
        $db->prepare(
            'INSERT INTO pc_product_data_quality
                (canonical_product_id, review_status, completeness_score,
                 confidence_score, review_notes)
             VALUES (:product, "needs_review", 100, 60,
                     "Fictional local demo fixture; production recommendation gate remains closed")
             ON DUPLICATE KEY UPDATE review_status="needs_review",
                 completeness_score=100, confidence_score=60,
                 reviewed_by_user_id=NULL, verified_at=NULL,
                 review_notes=VALUES(review_notes)'
        )->execute(['product' => $productId]);

        foreach ($product['offers'] as $shopSlug => [$price, $stock]) {
            $shop = $shops[$shopSlug];
            $listing = $db->prepare(
                'SELECT id FROM shop_product_listings
                 WHERE shop_id=:shop AND canonical_product_id=:product AND condition_type="new"'
            );
            $listing->execute(['shop' => $shop['id'], 'product' => $productId]);
            $listingId = (int) $listing->fetchColumn();
            $params = [
                'shop' => $shop['id'], 'product' => $productId,
                'sku' => strtoupper(substr($shopSlug, 5, 2)) . '-PER-' . $product['sku'],
                'price' => $price,
                'description' => $fixture['catalogue_notice'],
                'approver' => $adminId,
            ];
            if ($listingId < 1) {
                $db->prepare(
                    'INSERT INTO shop_product_listings
                        (shop_id, canonical_product_id, sku, condition_type, price,
                         vendor_description, warranty_summary, status,
                         approved_by_user_id, approved_at, published_at)
                     VALUES (:shop, :product, :sku, "new", :price, :description,
                             "Demo warranty only", "active", :approver,
                             CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                )->execute($params);
                $listingId = (int) $db->lastInsertId();
            } else {
                $params['id'] = $listingId;
                $db->prepare(
                    'UPDATE shop_product_listings SET sku=:sku, price=:price,
                        vendor_description=:description, warranty_summary="Demo warranty only",
                        status="active", approved_by_user_id=:approver,
                        approved_at=COALESCE(approved_at, CURRENT_TIMESTAMP),
                        published_at=COALESCE(published_at, CURRENT_TIMESTAMP)
                     WHERE id=:id'
                )->execute([
                    'sku' => $params['sku'], 'price' => $params['price'],
                    'description' => $params['description'], 'approver' => $params['approver'],
                    'id' => $params['id'],
                ]);
            }
            $db->prepare(
                'INSERT INTO inventory
                    (listing_id, quantity_on_hand, quantity_reserved, low_stock_threshold, version)
                 VALUES (:listing, :stock, 0, 3, 1)
                 ON DUPLICATE KEY UPDATE quantity_on_hand=GREATEST(VALUES(quantity_on_hand), quantity_reserved),
                    low_stock_threshold=3, version=version+1'
            )->execute(['listing' => $listingId, 'stock' => $stock]);
        }
    }
    $db->commit();
    fwrite(STDOUT, "Peripheral demo catalogue seeded: " . count($fixture['products']) . " canonical products.\n");
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Peripheral demo seed failed: {$exception->getMessage()}\n");
    exit(1);
}
