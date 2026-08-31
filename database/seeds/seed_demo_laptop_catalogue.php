<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__, 2) . '/backend/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This demo seed must be run from the command line.\n");
    exit(1);
}

$assetRoot = __DIR__ . DIRECTORY_SEPARATOR . 'demo-assets';
$storageRoot = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'backend'
    . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'product-images';
$assetFiles = [
    'everyday' => $assetRoot . DIRECTORY_SEPARATOR . 'laptop-everyday.png',
    'gaming' => $assetRoot . DIRECTORY_SEPARATOR . 'laptop-gaming.png',
    'creator' => $assetRoot . DIRECTORY_SEPARATOR . 'laptop-creator.png',
    'business' => $assetRoot . DIRECTORY_SEPARATOR . 'laptop-business.png',
];
foreach ($assetFiles as $asset) {
    if (!is_file($asset) || filesize($asset) < 1) {
        fwrite(STDERR, "Missing demo image: {$asset}\n");
        exit(1);
    }
}
if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
    fwrite(STDERR, "Product-image storage is unavailable.\n");
    exit(1);
}

$shops = [
    'metrotech' => [
        'email' => 'seller.metrotech@hexbay.test',
        'first_name' => 'Nimal',
        'last_name' => 'Perera',
        'phone' => '+94 77 510 2001',
        'business_name' => 'MetroTech Lanka',
        'shop_name' => 'MetroTech Lanka',
        'slug' => 'demo-metrotech-lanka',
        'description' => 'Demo technology shop specialising in laptops for students, professionals and gamers.',
        'address' => 'Unity Plaza, Colombo 04, Sri Lanka',
        'rating' => 4.72,
        'rating_count' => 86,
    ],
    'bytehub' => [
        'email' => 'seller.bytehub@hexbay.test',
        'first_name' => 'Tharushi',
        'last_name' => 'Silva',
        'phone' => '+94 76 410 3002',
        'business_name' => 'ByteHub Computers',
        'shop_name' => 'ByteHub Computers',
        'slug' => 'demo-bytehub-computers',
        'description' => 'Demo computer retailer offering work, creative and performance laptops with local support.',
        'address' => 'Galle Road, Dehiwala, Sri Lanka',
        'rating' => 4.58,
        'rating_count' => 61,
    ],
];

$products = [
    'idea-slim-3' => [
        'brand' => 'Lenovo', 'brand_slug' => 'lenovo',
        'name' => 'Lenovo IdeaPad Slim 3 15', 'model' => '15IRH8-Demo',
        'mpn' => 'HB-LEN-SLIM3', 'cpu' => 'Intel Core i5-13420H',
        'ram' => 16, 'storage' => 512, 'gpu' => 'Intel UHD Graphics',
        'screen' => 15.6, 'tags' => ['study', 'office', 'programming', 'general'],
        'image' => 'everyday',
    ],
    'tuf-a15' => [
        'brand' => 'Asus', 'brand_slug' => 'asus',
        'name' => 'Asus TUF Gaming A15', 'model' => 'FA507-Demo',
        'mpn' => 'HB-ASU-TUFA15', 'cpu' => 'AMD Ryzen 7 7735HS',
        'ram' => 16, 'storage' => 512, 'gpu' => 'NVIDIA GeForce RTX 4050',
        'screen' => 15.6, 'tags' => ['gaming', 'programming'],
        'image' => 'gaming',
    ],
    'inspiron-14' => [
        'brand' => 'Dell', 'brand_slug' => 'dell',
        'name' => 'Dell Inspiron 14', 'model' => '5430-Demo',
        'mpn' => 'HB-DEL-INS14', 'cpu' => 'Intel Core i5-1335U',
        'ram' => 16, 'storage' => 512, 'gpu' => 'Intel Iris Xe Graphics',
        'screen' => 14.0, 'tags' => ['study', 'office', 'programming', 'general'],
        'image' => 'business',
    ],
    'victus-16' => [
        'brand' => 'HP', 'brand_slug' => 'hp',
        'name' => 'HP Victus 16', 'model' => 'S100-Demo',
        'mpn' => 'HB-HP-VIC16', 'cpu' => 'AMD Ryzen 7 7840HS',
        'ram' => 16, 'storage' => 1024, 'gpu' => 'NVIDIA GeForce RTX 4060',
        'screen' => 16.1, 'tags' => ['gaming', 'content_creation', 'video_editing'],
        'image' => 'gaming',
    ],
    'aspire-5' => [
        'brand' => 'Acer', 'brand_slug' => 'acer',
        'name' => 'Acer Aspire 5', 'model' => 'A515-Demo',
        'mpn' => 'HB-ACE-ASP5', 'cpu' => 'Intel Core i5-12450H',
        'ram' => 8, 'storage' => 512, 'gpu' => 'NVIDIA GeForce RTX 2050',
        'screen' => 15.6, 'tags' => ['study', 'office', 'general'],
        'image' => 'everyday',
    ],
    'creator-m16' => [
        'brand' => 'MSI', 'brand_slug' => 'msi',
        'name' => 'MSI Creator M16', 'model' => 'B13V-Demo',
        'mpn' => 'HB-MSI-CRM16', 'cpu' => 'Intel Core i7-13700H',
        'ram' => 32, 'storage' => 1024, 'gpu' => 'NVIDIA GeForce RTX 4060',
        'screen' => 16.0,
        'tags' => ['content_creation', 'video_editing', 'graphic_design', 'programming'],
        'image' => 'creator',
    ],
    'loq-15' => [
        'brand' => 'Lenovo', 'brand_slug' => 'lenovo',
        'name' => 'Lenovo LOQ 15', 'model' => '15IRX9-Demo',
        'mpn' => 'HB-LEN-LOQ15', 'cpu' => 'Intel Core i5-13450HX',
        'ram' => 16, 'storage' => 512, 'gpu' => 'NVIDIA GeForce RTX 4050',
        'screen' => 15.6, 'tags' => ['gaming', 'programming', 'engineering'],
        'image' => 'gaming',
    ],
    'vivobook-16x' => [
        'brand' => 'Asus', 'brand_slug' => 'asus',
        'name' => 'Asus Vivobook 16X', 'model' => 'K3605-Demo',
        'mpn' => 'HB-ASU-VB16X', 'cpu' => 'Intel Core i7-13700H',
        'ram' => 16, 'storage' => 1024, 'gpu' => 'NVIDIA GeForce RTX 3050',
        'screen' => 16.0,
        'tags' => ['content_creation', 'graphic_design', 'programming', 'engineering'],
        'image' => 'creator',
    ],
];

$offers = [
    ['shop' => 'metrotech', 'product' => 'idea-slim-3', 'sku' => 'MT-SLIM3-16', 'price' => 229000, 'stock' => 12],
    ['shop' => 'bytehub', 'product' => 'idea-slim-3', 'sku' => 'BH-SLIM3-16', 'price' => 224500, 'stock' => 7],
    ['shop' => 'metrotech', 'product' => 'tuf-a15', 'sku' => 'MT-TUFA15-4050', 'price' => 389000, 'stock' => 6],
    ['shop' => 'bytehub', 'product' => 'tuf-a15', 'sku' => 'BH-TUFA15-4050', 'price' => 382500, 'stock' => 4],
    ['shop' => 'metrotech', 'product' => 'inspiron-14', 'sku' => 'MT-INS14-I5', 'price' => 276000, 'stock' => 9],
    ['shop' => 'bytehub', 'product' => 'inspiron-14', 'sku' => 'BH-INS14-I5', 'price' => 269500, 'stock' => 5],
    ['shop' => 'metrotech', 'product' => 'victus-16', 'sku' => 'MT-VIC16-4060', 'price' => 468000, 'stock' => 3],
    ['shop' => 'bytehub', 'product' => 'aspire-5', 'sku' => 'BH-ASP5-2050', 'price' => 247500, 'stock' => 8],
    ['shop' => 'bytehub', 'product' => 'creator-m16', 'sku' => 'BH-CRM16-4060', 'price' => 589000, 'stock' => 2],
    ['shop' => 'metrotech', 'product' => 'loq-15', 'sku' => 'MT-LOQ15-4050', 'price' => 398000, 'stock' => 5],
    ['shop' => 'bytehub', 'product' => 'vivobook-16x', 'sku' => 'BH-VB16X-3050', 'price' => 419000, 'stock' => 4],
];

$db = Database::connection();
$newFiles = [];

/** @return int */
function requiredId(PDO $db, string $sql, array $params, string $message): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $id = (int) $statement->fetchColumn();
    if ($id < 1) {
        throw new RuntimeException($message);
    }
    return $id;
}

try {
    $db->beginTransaction();
    $sellerRoleId = requiredId(
        $db,
        "SELECT id FROM roles WHERE name='shop_owner' AND is_active=TRUE",
        [],
        'The shop-owner role is missing.'
    );
    $laptopCategoryId = requiredId(
        $db,
        "SELECT id FROM categories WHERE slug='laptops' AND is_active=TRUE",
        [],
        'The laptops category is missing.'
    );
    $commissionRuleId = requiredId(
        $db,
        'SELECT id FROM commission_rules
         WHERE effective_from<=CURRENT_TIMESTAMP
           AND (effective_to IS NULL OR effective_to>CURRENT_TIMESTAMP)
         ORDER BY effective_from DESC, id DESC LIMIT 1',
        [],
        'An active commission rule is required.'
    );
    $adminStatement = $db->query(
        "SELECT u.id FROM users u INNER JOIN roles r ON r.id=u.role_id
         WHERE r.name='administrator' AND u.status='active' ORDER BY u.id LIMIT 1"
    );
    $adminId = ($adminStatement->fetchColumn() ?: null);

    $definitionsStatement = $db->prepare(
        'SELECT id, code FROM specification_definitions
         WHERE category_id=:category_id AND is_active=TRUE
           AND code IN (
               "processor_model", "ram_capacity_gb", "gpu_model",
               "storage_capacity_gb", "screen_size_inches"
           )'
    );
    $definitionsStatement->execute(['category_id' => $laptopCategoryId]);
    $definitionIds = [];
    foreach ($definitionsStatement->fetchAll() as $definition) {
        $definitionIds[(string) $definition['code']] = (int) $definition['id'];
    }
    if (count($definitionIds) !== 5) {
        throw new RuntimeException(
            'Laptop specification definitions are incomplete. Apply migration 003 first.'
        );
    }

    $sellerIds = [];
    $shopIds = [];
    $passwordHash = password_hash('DemoSeller123', PASSWORD_DEFAULT);
    foreach ($shops as $key => $shop) {
        $existingUser = $db->prepare('SELECT id FROM users WHERE email=:email');
        $existingUser->execute(['email' => $shop['email']]);
        $userId = (int) $existingUser->fetchColumn();
        if ($userId < 1) {
            $insertUser = $db->prepare(
                'INSERT INTO users
                    (role_id, email, password_hash, status, email_verified_at)
                 VALUES
                    (:role_id, :email, :password_hash, "active", CURRENT_TIMESTAMP)'
            );
            $insertUser->execute([
                'role_id' => $sellerRoleId,
                'email' => $shop['email'],
                'password_hash' => $passwordHash,
            ]);
            $userId = (int) $db->lastInsertId();
        } else {
            $updateUser = $db->prepare(
                'UPDATE users SET role_id=:role_id, password_hash=:password_hash,
                    status="active", email_verified_at=COALESCE(email_verified_at, CURRENT_TIMESTAMP)
                 WHERE id=:id'
            );
            $updateUser->execute([
                'role_id' => $sellerRoleId,
                'password_hash' => $passwordHash,
                'id' => $userId,
            ]);
        }
        $sellerIds[$key] = $userId;

        $profile = $db->prepare(
            'INSERT INTO shop_owner_profiles
                (user_id, first_name, last_name, phone, business_name)
             VALUES
                (:user_id, :first_name, :last_name, :phone, :business_name)
             ON DUPLICATE KEY UPDATE
                first_name=VALUES(first_name), last_name=VALUES(last_name),
                phone=VALUES(phone), business_name=VALUES(business_name)'
        );
        $profile->execute([
            'user_id' => $userId,
            'first_name' => $shop['first_name'],
            'last_name' => $shop['last_name'],
            'phone' => $shop['phone'],
            'business_name' => $shop['business_name'],
        ]);

        $existingShop = $db->prepare('SELECT id FROM shops WHERE owner_user_id=:owner');
        $existingShop->execute(['owner' => $userId]);
        $shopId = (int) $existingShop->fetchColumn();
        if ($shopId < 1) {
            $insertShop = $db->prepare(
                'INSERT INTO shops
                    (owner_user_id, name, slug, description, address_text,
                     contact_phone, contact_email, status, rating_average,
                     rating_count, approved_at)
                 VALUES
                    (:owner, :name, :slug, :description, :address,
                     :phone, :email, "approved", :rating, :rating_count,
                     CURRENT_TIMESTAMP)'
            );
            $insertShop->execute([
                'owner' => $userId,
                'name' => $shop['shop_name'],
                'slug' => $shop['slug'],
                'description' => $shop['description'],
                'address' => $shop['address'],
                'phone' => $shop['phone'],
                'email' => $shop['email'],
                'rating' => $shop['rating'],
                'rating_count' => $shop['rating_count'],
            ]);
            $shopId = (int) $db->lastInsertId();
        } else {
            $updateShop = $db->prepare(
                'UPDATE shops SET name=:name, slug=:slug, description=:description,
                    address_text=:address, contact_phone=:phone,
                    contact_email=:email, status="approved", status_reason=NULL,
                    rating_average=:rating, rating_count=:rating_count,
                    approved_at=COALESCE(approved_at, CURRENT_TIMESTAMP)
                 WHERE id=:id'
            );
            $updateShop->execute([
                'name' => $shop['shop_name'], 'slug' => $shop['slug'],
                'description' => $shop['description'], 'address' => $shop['address'],
                'phone' => $shop['phone'], 'email' => $shop['email'],
                'rating' => $shop['rating'], 'rating_count' => $shop['rating_count'],
                'id' => $shopId,
            ]);
        }
        $shopIds[$key] = $shopId;

        $verification = $db->prepare(
            'INSERT INTO vendor_verifications
                (shop_id, submission_number, legal_name,
                 business_registration_reference, status, submitted_at,
                 reviewed_by_user_id, reviewed_at, review_notes)
             VALUES
                (:shop_id, 1, :legal_name, :reference, "approved",
                 CURRENT_TIMESTAMP, :reviewer, CURRENT_TIMESTAMP,
                 "Approved local demonstration catalogue")
             ON DUPLICATE KEY UPDATE
                legal_name=VALUES(legal_name), status="approved",
                reviewed_by_user_id=VALUES(reviewed_by_user_id),
                reviewed_at=CURRENT_TIMESTAMP,
                review_notes=VALUES(review_notes), decision_reason=NULL'
        );
        $verification->execute([
            'shop_id' => $shopId,
            'legal_name' => $shop['business_name'],
            'reference' => 'DEMO-' . strtoupper($key),
            'reviewer' => $adminId,
        ]);

        $acceptance = $db->prepare(
            'INSERT INTO commission_acceptances
                (shop_owner_user_id, shop_id, commission_rule_id,
                 percentage_snapshot, terms_version, acceptance_text,
                 ip_address, user_agent)
             SELECT :owner, :shop, cr.id, cr.percentage, "demo-2026-v1",
                    CONCAT("I accept the ", cr.percentage,
                           "% HEXBAY platform commission for this local demonstration shop."),
                    "127.0.0.1", "Hexbay demo catalogue seed"
             FROM commission_rules cr WHERE cr.id=:rule
             ON DUPLICATE KEY UPDATE
                percentage_snapshot=VALUES(percentage_snapshot),
                acceptance_text=VALUES(acceptance_text), superseded_at=NULL'
        );
        $acceptance->execute([
            'owner' => $userId, 'shop' => $shopId, 'rule' => $commissionRuleId,
        ]);
    }

    $tagCodes = array_values(array_unique(array_merge(
        ...array_values(array_map(
            static fn (array $product): array => $product['tags'],
            $products
        ))
    )));
    $tagIds = [];
    foreach ($tagCodes as $tagCode) {
        $display = ucwords(str_replace('_', ' ', $tagCode));
        $tag = $db->prepare(
            'INSERT INTO product_tags (code, display_name, tag_type, is_active)
             VALUES (:code, :display, "intended_use", TRUE)
             ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),
                 tag_type="intended_use", is_active=TRUE'
        );
        $tag->execute(['code' => $tagCode, 'display' => $display]);
        $tagIds[$tagCode] = requiredId(
            $db,
            'SELECT id FROM product_tags WHERE code=:code',
            ['code' => $tagCode],
            "Product tag {$tagCode} could not be loaded."
        );
    }

    $productIds = [];
    foreach ($products as $key => $product) {
        $brand = $db->prepare(
            'INSERT INTO brands (name, slug, is_active)
             VALUES (:name, :slug, TRUE)
             ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=TRUE'
        );
        $brand->execute(['name' => $product['brand'], 'slug' => $product['brand_slug']]);
        $brandId = requiredId(
            $db,
            'SELECT id FROM brands
             WHERE slug=:slug OR name=:brand_name
             ORDER BY (name=:preferred_name) DESC LIMIT 1',
            [
                'slug' => $product['brand_slug'],
                'brand_name' => $product['brand'],
                'preferred_name' => $product['brand'],
            ],
            "Brand {$product['brand']} could not be loaded."
        );

        $existingProduct = $db->prepare(
            'SELECT id FROM canonical_products
             WHERE category_id=:category AND brand_id=:brand AND model=:model'
        );
        $existingProduct->execute([
            'category' => $laptopCategoryId, 'brand' => $brandId,
            'model' => $product['model'],
        ]);
        $productId = (int) $existingProduct->fetchColumn();
        $creatorId = $sellerIds[array_key_first($shops)];
        if ($productId < 1) {
            $insertProduct = $db->prepare(
                'INSERT INTO canonical_products
                    (category_id, brand_id, name, model,
                     manufacturer_part_number, specification_completeness,
                     is_active, created_by_user_id)
                 VALUES
                    (:category, :brand, :name, :model, :mpn,
                     "complete", TRUE, :creator)'
            );
            $insertProduct->execute([
                'category' => $laptopCategoryId, 'brand' => $brandId,
                'name' => $product['name'], 'model' => $product['model'],
                'mpn' => $product['mpn'], 'creator' => $creatorId,
            ]);
            $productId = (int) $db->lastInsertId();
        } else {
            $updateProduct = $db->prepare(
                'UPDATE canonical_products SET name=:name,
                    manufacturer_part_number=:mpn,
                    specification_completeness="complete", is_active=TRUE
                 WHERE id=:id'
            );
            $updateProduct->execute([
                'name' => $product['name'], 'mpn' => $product['mpn'],
                'id' => $productId,
            ]);
        }
        $productIds[$key] = $productId;

        foreach ([
            'processor_model' => ['text', $product['cpu']],
            'gpu_model' => ['text', $product['gpu']],
            'ram_capacity_gb' => ['number', $product['ram']],
            'storage_capacity_gb' => ['number', $product['storage']],
            'screen_size_inches' => ['number', $product['screen']],
        ] as $code => [$kind, $value]) {
            $specification = $db->prepare(
                'INSERT INTO product_specifications
                    (canonical_product_id, definition_id, value_text,
                     value_number, source_note, updated_by_user_id)
                 VALUES
                    (:product, :definition, :value_text, :value_number,
                     "Local demonstration seed", :updater)
                 ON DUPLICATE KEY UPDATE
                    value_text=VALUES(value_text), value_number=VALUES(value_number),
                    source_note=VALUES(source_note), updated_by_user_id=VALUES(updated_by_user_id)'
            );
            $specification->execute([
                'product' => $productId, 'definition' => $definitionIds[$code],
                'value_text' => $kind === 'text' ? $value : null,
                'value_number' => $kind === 'number' ? $value : null,
                'updater' => $creatorId,
            ]);
        }
        foreach ($product['tags'] as $tagCode) {
            $productTag = $db->prepare(
                'INSERT INTO canonical_product_tags
                    (canonical_product_id, tag_id, weight)
                 VALUES (:product, :tag, 1.0000)
                 ON DUPLICATE KEY UPDATE weight=VALUES(weight)'
            );
            $productTag->execute([
                'product' => $productId, 'tag' => $tagIds[$tagCode],
            ]);
        }
    }

    $listingIds = [];
    foreach ($offers as $offer) {
        $shopId = $shopIds[$offer['shop']];
        $productId = $productIds[$offer['product']];
        $existingListing = $db->prepare(
            'SELECT id FROM shop_product_listings
             WHERE shop_id=:shop AND canonical_product_id=:product
               AND condition_type="new"'
        );
        $existingListing->execute(['shop' => $shopId, 'product' => $productId]);
        $listingId = (int) $existingListing->fetchColumn();
        if ($listingId < 1) {
            $insertListing = $db->prepare(
                'INSERT INTO shop_product_listings
                    (shop_id, canonical_product_id, sku, condition_type,
                     price, vendor_description, warranty_summary, status,
                     approved_by_user_id, approved_at, published_at)
                 VALUES
                    (:shop, :product, :sku, "new", :price, :description,
                     "One-year local seller warranty", "active", :approver,
                     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insertListing->execute([
                'shop' => $shopId, 'product' => $productId,
                'sku' => $offer['sku'], 'price' => $offer['price'],
                'description' => 'Local demonstration stock with complete specifications for testing HEXBAY and HexBot.',
                'approver' => $adminId,
            ]);
            $listingId = (int) $db->lastInsertId();
        } else {
            $updateListing = $db->prepare(
                'UPDATE shop_product_listings SET sku=:sku, price=:price,
                    vendor_description=:description,
                    warranty_summary="One-year local seller warranty",
                    status="active", status_reason=NULL,
                    approved_by_user_id=:approver,
                    approved_at=COALESCE(approved_at, CURRENT_TIMESTAMP),
                    published_at=COALESCE(published_at, CURRENT_TIMESTAMP)
                 WHERE id=:id'
            );
            $updateListing->execute([
                'sku' => $offer['sku'], 'price' => $offer['price'],
                'description' => 'Local demonstration stock with complete specifications for testing HEXBAY and HexBot.',
                'approver' => $adminId, 'id' => $listingId,
            ]);
        }
        $listingIds[] = $listingId;

        $inventory = $db->prepare(
            'INSERT INTO inventory
                (listing_id, quantity_on_hand, quantity_reserved,
                 low_stock_threshold, version)
             VALUES (:listing, :stock, 0, 3, 1)
             ON DUPLICATE KEY UPDATE
                quantity_on_hand=GREATEST(VALUES(quantity_on_hand), quantity_reserved),
                low_stock_threshold=VALUES(low_stock_threshold), version=version+1'
        );
        $inventory->execute(['listing' => $listingId, 'stock' => $offer['stock']]);
        $inventoryId = requiredId(
            $db,
            'SELECT id FROM inventory WHERE listing_id=:listing',
            ['listing' => $listingId],
            'Inventory could not be loaded.'
        );
        $movementExists = $db->prepare(
            'SELECT COUNT(*) FROM inventory_movements
             WHERE inventory_id=:inventory AND reference_type="demo_seed"'
        );
        $movementExists->execute(['inventory' => $inventoryId]);
        if ((int) $movementExists->fetchColumn() === 0) {
            $movement = $db->prepare(
                'INSERT INTO inventory_movements
                    (inventory_id, movement_type, quantity_delta,
                     quantity_after, reference_type, reason, actor_user_id)
                 VALUES
                    (:inventory, "initial", :quantity_delta, :quantity_after,
                     "demo_seed", "Initial HexBot demonstration stock", :actor)'
            );
            $movement->execute([
                'inventory' => $inventoryId,
                'quantity_delta' => $offer['stock'],
                'quantity_after' => $offer['stock'],
                'actor' => $sellerIds[$offer['shop']],
            ]);
        }

        $product = $products[$offer['product']];
        $sourceImage = $assetFiles[$product['image']];
        $storageToken = md5(
            'hexbay-demo-' . $offer['shop'] . '-' . $offer['product']
        );
        $storedFilename = $storageToken . '.png';
        $storedPath = $storageRoot . DIRECTORY_SEPARATOR . $storedFilename;
        if (!is_file($storedPath)) {
            if (!copy($sourceImage, $storedPath)) {
                throw new RuntimeException("Could not store demo product image {$storedFilename}.");
            }
            $newFiles[] = $storedPath;
        }
        $image = $db->prepare(
            'INSERT INTO product_images
                (listing_id, original_filename, stored_filename, mime_type,
                 byte_size, alt_text, sort_order)
             VALUES
                (:listing, :original, :stored, "image/png", :bytes, :alt, 0)
             ON DUPLICATE KEY UPDATE
                listing_id=VALUES(listing_id), byte_size=VALUES(byte_size),
                alt_text=VALUES(alt_text), sort_order=0'
        );
        $image->execute([
            'listing' => $listingId,
            'original' => $offer['product'] . '-demo.png',
            'stored' => $storedFilename,
            'bytes' => filesize($storedPath),
            'alt' => $product['name'] . ' demonstration product photo',
        ]);
    }

    $outputShops = [];
    foreach (array_keys($shops) as $shopKey) {
        $outputShops[] = [
            'name' => $shops[$shopKey]['shop_name'],
            'email' => $shops[$shopKey]['email'],
            'shop_id' => $shopIds[$shopKey],
        ];
    }
    $output = json_encode([
        'success' => true,
        'demo_password' => 'DemoSeller123',
        'shops' => $outputShops,
        'canonical_laptops' => count($productIds),
        'active_offers' => count($listingIds),
        'product_images' => count($listingIds),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $db->commit();
    echo $output . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($newFiles as $newFile) {
        if (is_file($newFile)) {
            @unlink($newFile);
        }
    }
    fwrite(STDERR, "Demo catalogue seed failed: {$exception->getMessage()}\n");
    exit(1);
}
