<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__, 2) . '/backend/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The final catalogue importer must be run from the command line.\n");
    exit(1);
}

$data = require __DIR__ . '/final_catalogue_data.php';
$credentialPath = __DIR__ . '/final_catalogue_credentials.local.php';
if (!is_file($credentialPath)) {
    fwrite(STDERR, "Missing gitignored final catalogue credentials file.\n");
    exit(1);
}
$credentials = require $credentialPath;
$shops = $data['shops'];
$products = $data['products'];
$assetRoot = __DIR__ . DIRECTORY_SEPARATOR . 'final-assets';
$downloadRoot = $assetRoot . DIRECTORY_SEPARATOR . 'products';
$productStorageRoot = dirname(__DIR__, 2) . '/backend/storage/product-images';
$logoStorageRoot = dirname(__DIR__, 2) . '/backend/storage/shop-logos';

foreach ([$downloadRoot, $productStorageRoot, $logoStorageRoot] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create asset directory {$directory}.");
    }
}

/** @return int */
function finalCatalogueId(PDO $db, string $sql, array $params, string $message): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $id = (int) $statement->fetchColumn();
    if ($id < 1) {
        throw new RuntimeException($message);
    }
    return $id;
}

function finalAssetExtension(string $url): string
{
    $extension = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true) ? $extension : 'jpg';
}

/** @return array{path:string,mime:string,extension:string} */
function finalDownloadAsset(string $key, string $url, string $downloadRoot): array
{
    $extension = finalAssetExtension($url);
    $path = $downloadRoot . DIRECTORY_SEPARATOR . $key . '.' . $extension;
    if (!is_file($path) || filesize($path) < 1000) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 45,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'HEXBAY Final Catalogue Importer/1.0',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $bytes = @file_get_contents($url, false, $context);
        if ($bytes === false || strlen($bytes) < 1000) {
            throw new RuntimeException("Could not download image for {$key}.");
        }
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Could not save image for {$key}.");
        }
    }
    $image = @getimagesize($path);
    if ($image === false || !str_starts_with((string) ($image['mime'] ?? ''), 'image/')) {
        throw new RuntimeException("Downloaded asset for {$key} is not a valid image.");
    }
    return ['path' => $path, 'mime' => (string) $image['mime'], 'extension' => $extension];
}

/** @return array{0:float,1:float} */
function finalDerivedScores(array $product): array
{
    if (isset($product['score'])) {
        return [(float) $product['score'][0], (float) $product['score'][1]];
    }
    $specs = $product['specs'];
    $overall = match ($product['category']) {
        'memory' => min(90, 25 + ((float) $specs['capacity_gb'] * 2.8) + (((float) $specs['speed_mhz'] - 1333) / 120)),
        'storage' => match ($specs['storage_type']) { 'nvme_ssd' => 72, 'sata_ssd' => 52, default => 34 },
        'power-supplies' => min(82, 35 + (((float) $specs['wattage'] - 350) / 12)),
        'computer-cases' => min(80, 42 + (((float) ($specs['max_gpu_length_mm'] ?? 280) - 280) / 4)),
        'cpu-coolers' => min(85, 35 + ((float) $specs['cooling_capacity_watts'] / 6)),
        default => 55,
    };
    return [max(20, $overall), max(45, min(85, 95 - ($overall * 0.28)))];
}

$downloadedAssets = [];
try {
    foreach ($products as $key => $product) {
        $downloadedAssets[$key] = finalDownloadAsset($key, $product['image_url'], $downloadRoot);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Final catalogue image preparation failed: {$exception->getMessage()}\n");
    exit(1);
}

$db = Database::connection();
$newStorageFiles = [];
try {
    $db->beginTransaction();

    $sellerRoleId = finalCatalogueId($db,
        "SELECT id FROM roles WHERE name='shop_owner' AND is_active=TRUE", [],
        'The active shop-owner role is missing.');
    $commissionRuleId = finalCatalogueId($db,
        'SELECT id FROM commission_rules WHERE effective_from<=CURRENT_TIMESTAMP
         AND (effective_to IS NULL OR effective_to>CURRENT_TIMESTAMP)
         ORDER BY effective_from DESC, id DESC LIMIT 1', [],
        'An active commission rule is required.');
    $adminId = finalCatalogueId($db,
        "SELECT u.id FROM users u INNER JOIN roles r ON r.id=u.role_id
         WHERE r.name='administrator' AND u.status='active' ORDER BY u.id LIMIT 1", [],
        'An active administrator is required.');

    $categorySlugs = array_values(array_unique(array_column($products, 'category')));
    $categoryStatement = $db->prepare(
        'SELECT id, slug FROM categories WHERE is_active=TRUE AND slug IN ('
        . implode(',', array_fill(0, count($categorySlugs), '?')) . ')'
    );
    $categoryStatement->execute($categorySlugs);
    $categoryIds = [];
    foreach ($categoryStatement->fetchAll() as $category) {
        $categoryIds[(string) $category['slug']] = (int) $category['id'];
    }
    if (count($categoryIds) !== count($categorySlugs)) {
        throw new RuntimeException('One or more catalogue categories are missing.');
    }

    $accessoryTypeDefinition = finalCatalogueId($db,
        'SELECT sd.id FROM specification_definitions sd
         INNER JOIN categories c ON c.id=sd.category_id
         WHERE c.slug="accessories" AND sd.code="accessory_type"', [],
        'The accessories type specification is missing.');
    $accessoryOption = $db->prepare(
        'INSERT INTO specification_options
            (definition_id, value_code, display_value, sort_order, is_active)
         VALUES (:definition, :code, :display, :sort_order, TRUE)
         ON DUPLICATE KEY UPDATE display_value=VALUES(display_value),
            sort_order=VALUES(sort_order), is_active=TRUE'
    );
    foreach ([['controller', 'Controller', 50], ['gaming_chair', 'Gaming chair', 60]] as [$code, $display, $sort]) {
        $accessoryOption->execute(['definition' => $accessoryTypeDefinition, 'code' => $code, 'display' => $display, 'sort_order' => $sort]);
    }

    $definitionStatement = $db->prepare(
        'SELECT c.slug category_slug, sd.id, sd.code, sd.data_type, sd.is_required
         FROM specification_definitions sd INNER JOIN categories c ON c.id=sd.category_id
         WHERE c.slug IN (' . implode(',', array_fill(0, count($categorySlugs), '?')) . ')
           AND sd.is_active=TRUE'
    );
    $definitionStatement->execute($categorySlugs);
    $definitions = [];
    foreach ($definitionStatement->fetchAll() as $definition) {
        $definitions[(string) $definition['category_slug']][(string) $definition['code']] = [
            'id' => (int) $definition['id'], 'data_type' => (string) $definition['data_type'],
            'is_required' => (bool) $definition['is_required'],
        ];
    }
    $optionStatement = $db->prepare(
        'SELECT c.slug category_slug, sd.code, so.id, so.value_code
         FROM specification_options so
         INNER JOIN specification_definitions sd ON sd.id=so.definition_id
         INNER JOIN categories c ON c.id=sd.category_id
         WHERE c.slug IN (' . implode(',', array_fill(0, count($categorySlugs), '?')) . ')
           AND sd.is_active=TRUE AND so.is_active=TRUE'
    );
    $optionStatement->execute($categorySlugs);
    $options = [];
    foreach ($optionStatement->fetchAll() as $option) {
        $options[(string) $option['category_slug']][(string) $option['code']][(string) $option['value_code']] = (int) $option['id'];
    }

    $sellerIds = [];
    $shopIds = [];
    foreach ($shops as $key => $shop) {
        if (!isset($credentials[$key]) || strlen((string) $credentials[$key]) < 10) {
            throw new RuntimeException("A valid local password is missing for {$shop['shop_name']}.");
        }
        $passwordHash = password_hash((string) $credentials[$key], PASSWORD_DEFAULT);
        $existing = $db->prepare('SELECT id FROM users WHERE email=:email');
        $existing->execute(['email' => $shop['email']]);
        $userId = (int) $existing->fetchColumn();
        if ($userId < 1) {
            $insert = $db->prepare(
                'INSERT INTO users (role_id, email, password_hash, status, email_verified_at)
                 VALUES (:role, :email, :password, "active", CURRENT_TIMESTAMP)'
            );
            $insert->execute(['role' => $sellerRoleId, 'email' => $shop['email'], 'password' => $passwordHash]);
            $userId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE users SET role_id=:role, password_hash=:password, status="active",
                 email_verified_at=COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id=:id'
            )->execute(['role' => $sellerRoleId, 'password' => $passwordHash, 'id' => $userId]);
        }
        $sellerIds[$key] = $userId;
        $db->prepare(
            'INSERT INTO shop_owner_profiles (user_id, first_name, last_name, phone, business_name)
             VALUES (:user, :first, :last, :phone, :business)
             ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), last_name=VALUES(last_name),
                phone=VALUES(phone), business_name=VALUES(business_name)'
        )->execute(['user'=>$userId,'first'=>$shop['first_name'],'last'=>$shop['last_name'],'phone'=>$shop['phone'],'business'=>$shop['business_name']]);

        $logoSource = $assetRoot . DIRECTORY_SEPARATOR . $shop['logo'];
        if (!is_file($logoSource) || filesize($logoSource) < 1000) {
            throw new RuntimeException("The logo for {$shop['shop_name']} is missing.");
        }
        $logoFilename = hash('sha256', 'hexbay-final-logo-' . $key) . '.png';
        $logoDestination = $logoStorageRoot . DIRECTORY_SEPARATOR . $logoFilename;
        if (!is_file($logoDestination)) {
            if (!copy($logoSource, $logoDestination)) {
                throw new RuntimeException("Could not store the {$shop['shop_name']} logo.");
            }
            $newStorageFiles[] = $logoDestination;
        }

        $existingShop = $db->prepare('SELECT id FROM shops WHERE owner_user_id=:owner');
        $existingShop->execute(['owner' => $userId]);
        $shopId = (int) $existingShop->fetchColumn();
        if ($shopId < 1) {
            $db->prepare(
                'INSERT INTO shops
                    (owner_user_id, name, slug, description, address_text, contact_phone,
                     contact_email, logo_path, status, rating_average, rating_count, approved_at)
                 VALUES (:owner,:name,:slug,:description,:address,:phone,:email,:logo,"approved",:rating,:count,CURRENT_TIMESTAMP)'
            )->execute(['owner'=>$userId,'name'=>$shop['shop_name'],'slug'=>$shop['slug'],'description'=>$shop['description'],'address'=>$shop['address'],'phone'=>$shop['phone'],'email'=>$shop['email'],'logo'=>$logoFilename,'rating'=>$shop['rating'],'count'=>$shop['rating_count']]);
            $shopId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE shops SET name=:name, slug=:slug, description=:description,
                 address_text=:address, contact_phone=:phone, contact_email=:email,
                 logo_path=:logo, status="approved", status_reason=NULL,
                 rating_average=:rating, rating_count=:count,
                 approved_at=COALESCE(approved_at,CURRENT_TIMESTAMP) WHERE id=:id'
            )->execute(['name'=>$shop['shop_name'],'slug'=>$shop['slug'],'description'=>$shop['description'],'address'=>$shop['address'],'phone'=>$shop['phone'],'email'=>$shop['email'],'logo'=>$logoFilename,'rating'=>$shop['rating'],'count'=>$shop['rating_count'],'id'=>$shopId]);
        }
        $shopIds[$key] = $shopId;
        $db->prepare(
            'INSERT INTO vendor_verifications
                (shop_id, submission_number, legal_name, business_registration_reference,
                 status, submitted_at, reviewed_by_user_id, reviewed_at, review_notes)
             VALUES (:shop,1,:legal,:reference,"approved",CURRENT_TIMESTAMP,:admin,CURRENT_TIMESTAMP,
                     "Approved final project catalogue seller")
             ON DUPLICATE KEY UPDATE legal_name=VALUES(legal_name), status="approved",
                reviewed_by_user_id=VALUES(reviewed_by_user_id), reviewed_at=CURRENT_TIMESTAMP,
                review_notes=VALUES(review_notes), decision_reason=NULL'
        )->execute(['shop'=>$shopId,'legal'=>$shop['business_name'],'reference'=>'FINAL-'.strtoupper($key),'admin'=>$adminId]);
        $db->prepare(
            'INSERT INTO commission_acceptances
                (shop_owner_user_id, shop_id, commission_rule_id, percentage_snapshot,
                 terms_version, acceptance_text, ip_address, user_agent)
             SELECT :owner,:shop,cr.id,cr.percentage,"final-catalogue-2026-v1",
                    CONCAT("I accept the ",cr.percentage,"% HEXBAY platform commission."),
                    "127.0.0.1","HEXBAY final catalogue importer"
             FROM commission_rules cr WHERE cr.id=:rule
             ON DUPLICATE KEY UPDATE percentage_snapshot=VALUES(percentage_snapshot),
                acceptance_text=VALUES(acceptance_text), superseded_at=NULL'
        )->execute(['owner'=>$userId,'shop'=>$shopId,'rule'=>$commissionRuleId]);
    }

    $tagRows = [
        'study'=>'Study','office'=>'Office','programming'=>'Programming','general'=>'General use',
        'gaming'=>'Gaming','engineering'=>'Engineering','content_creation'=>'Content creation',
        'video_editing'=>'Video editing','graphic_design'=>'Graphic design',
        'competitive_gaming'=>'Competitive gaming','productivity'=>'Productivity',
        'ergonomic'=>'Ergonomic','communication'=>'Communication','visual_creative'=>'Visual creative work',
    ];
    foreach ($tagRows as $code => $display) {
        $type = in_array($code, ['ergonomic'], true) ? 'feature' : 'intended_use';
        $db->prepare(
            'INSERT INTO product_tags (code,display_name,tag_type,is_active)
             VALUES (:code,:display,:type,TRUE)
             ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),tag_type=VALUES(tag_type),is_active=TRUE'
        )->execute(['code'=>$code,'display'=>$display,'type'=>$type]);
    }

    $sourceId = (function () use ($db): int {
        $db->prepare(
            'INSERT INTO pc_data_sources (code,name,source_type,base_url,licence_notes,is_active)
             VALUES ("hexbay_final_retail_research_2026","HEXBAY final retail research 2026",
                     "internal_curated",NULL,"Retailer/manufacturer references captured for the final local project catalogue.",TRUE)
             ON DUPLICATE KEY UPDATE name=VALUES(name),licence_notes=VALUES(licence_notes),is_active=TRUE'
        )->execute();
        return finalCatalogueId($db, 'SELECT id FROM pc_data_sources WHERE code="hexbay_final_retail_research_2026"', [], 'Final catalogue data source is missing.');
    })();

    $productIds = [];
    foreach ($products as $key => $product) {
        $category = $product['category'];
        foreach ($definitions[$category] ?? [] as $code => $definition) {
            if ($definition['is_required'] && !array_key_exists($code, $product['specs'])) {
                throw new RuntimeException("Required {$category}.{$code} is missing for {$key}.");
            }
        }
        foreach ($product['specs'] as $code => $_value) {
            if (!isset($definitions[$category][$code])) {
                throw new RuntimeException("Unknown {$category}.{$code} specification for {$key}.");
            }
        }
        $db->prepare(
            'INSERT INTO brands (name,slug,is_active) VALUES (:name,:slug,TRUE)
             ON DUPLICATE KEY UPDATE name=VALUES(name),is_active=TRUE'
        )->execute(['name'=>$product['brand'],'slug'=>$product['brand_slug']]);
        $brandId = finalCatalogueId($db,
            'SELECT id FROM brands WHERE slug=:slug ORDER BY id LIMIT 1',
            ['slug'=>$product['brand_slug']], "Brand {$product['brand']} is missing.");
        $categoryId = $categoryIds[$category];
        $existing = $db->prepare(
            'SELECT id FROM canonical_products WHERE category_id=:category AND brand_id=:brand AND model=:model'
        );
        $existing->execute(['category'=>$categoryId,'brand'=>$brandId,'model'=>$product['model']]);
        $productId = (int) $existing->fetchColumn();
        $creatorId = $sellerIds[array_key_first($product['offers'])];
        if ($productId < 1) {
            $db->prepare(
                'INSERT INTO canonical_products
                    (category_id,brand_id,name,model,manufacturer_part_number,
                     specification_completeness,is_active,created_by_user_id)
                 VALUES (:category,:brand,:name,:model,:mpn,"complete",TRUE,:creator)'
            )->execute(['category'=>$categoryId,'brand'=>$brandId,'name'=>$product['name'],'model'=>$product['model'],'mpn'=>$product['mpn'],'creator'=>$creatorId]);
            $productId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE canonical_products SET name=:name,manufacturer_part_number=:mpn,
                 specification_completeness="complete",is_active=TRUE WHERE id=:id'
            )->execute(['name'=>$product['name'],'mpn'=>$product['mpn'],'id'=>$productId]);
        }
        $productIds[$key] = $productId;
        $db->prepare('DELETE FROM product_specifications WHERE canonical_product_id=:product')->execute(['product'=>$productId]);
        $insertSpec = $db->prepare(
            'INSERT INTO product_specifications
                (canonical_product_id,definition_id,option_id,value_text,value_number,
                 value_boolean,value_json,source_note,updated_by_user_id)
             VALUES (:product,:definition,:option_id,:value_text,:value_number,
                     :value_boolean,:value_json,:source_note,:updater)'
        );
        foreach ($product['specs'] as $code => $value) {
            $definition = $definitions[$category][$code];
            $row = ['product'=>$productId,'definition'=>$definition['id'],'option_id'=>null,
                'value_text'=>null,'value_number'=>null,'value_boolean'=>null,'value_json'=>null,
                'source_note'=>'Retail/manufacturer research checked '.$data['researched_at'],'updater'=>$adminId];
            if (in_array($definition['data_type'], ['integer','decimal'], true)) {
                $row['value_number'] = $value;
            } elseif ($definition['data_type'] === 'boolean') {
                $row['value_boolean'] = (bool) $value;
            } elseif ($definition['data_type'] === 'option') {
                $row['option_id'] = $options[$category][$code][(string) $value] ?? null;
                if ($row['option_id'] === null) {
                    throw new RuntimeException("Unknown option {$category}.{$code}={$value}.");
                }
            } elseif ($definition['data_type'] === 'multi_option') {
                foreach ((array) $value as $optionCode) {
                    if (!isset($options[$category][$code][(string) $optionCode])) {
                        throw new RuntimeException("Unknown multi-option {$category}.{$code}={$optionCode}.");
                    }
                }
                $row['value_json'] = json_encode(array_values((array) $value), JSON_THROW_ON_ERROR);
            } else {
                $row['value_text'] = substr((string) $value, 0, 500);
            }
            $insertSpec->execute($row);
        }
        $db->prepare('DELETE FROM canonical_product_tags WHERE canonical_product_id=:product')->execute(['product'=>$productId]);
        foreach ($product['tags'] ?? [] as $tagCode) {
            $db->prepare(
                'INSERT INTO canonical_product_tags (canonical_product_id,tag_id,weight)
                 SELECT :product,id,1.0000 FROM product_tags WHERE code=:code AND is_active=TRUE'
            )->execute(['product'=>$productId,'code'=>$tagCode]);
        }
        $db->prepare(
            'INSERT INTO pc_product_provenance
                (canonical_product_id,source_id,evidence_type,source_url,source_reference,
                 confidence,is_primary,verified_at,notes)
             VALUES (:product,:source,"specification",:url,:reference,"high",TRUE,CURRENT_TIMESTAMP,:notes)
             ON DUPLICATE KEY UPDATE source_url=VALUES(source_url),source_reference=VALUES(source_reference),
                confidence="high",is_primary=TRUE,verified_at=CURRENT_TIMESTAMP,notes=VALUES(notes)'
        )->execute(['product'=>$productId,'source'=>$sourceId,'url'=>$product['source_url'],'reference'=>'Checked '.$data['researched_at'],'notes'=>'Curated product identity, specification and listed-price reference.']);
        $db->prepare(
            'INSERT INTO pc_product_data_quality
                (canonical_product_id,review_status,completeness_score,confidence_score,
                 reviewed_by_user_id,verified_at,review_notes)
             VALUES (:product,"verified",100,86,:admin,CURRENT_TIMESTAMP,
                     "Required HEXBAY fields and source provenance were reviewed for the final project catalogue.")
             ON DUPLICATE KEY UPDATE review_status="verified",completeness_score=100,
                confidence_score=86,reviewed_by_user_id=:admin2,verified_at=CURRENT_TIMESTAMP,
                review_notes=VALUES(review_notes)'
        )->execute(['product'=>$productId,'admin'=>$adminId,'admin2'=>$adminId]);
    }

    $listingIds = [];
    $offerCounts = array_fill_keys(array_keys($shops), 0);
    foreach ($products as $productKey => $product) {
        foreach ($product['offers'] as $shopKey => [$price, $stock]) {
            $shopId = $shopIds[$shopKey];
            $productId = $productIds[$productKey];
            $sku = strtoupper(substr(str_replace('_','',$shopKey),0,3)) . '-' . strtoupper(substr(hash('sha256',$productKey),0,10));
            $existing = $db->prepare(
                'SELECT id FROM shop_product_listings
                 WHERE shop_id=:shop AND canonical_product_id=:product AND condition_type="new"'
            );
            $existing->execute(['shop'=>$shopId,'product'=>$productId]);
            $listingId = (int) $existing->fetchColumn();
            if ($listingId < 1) {
                $db->prepare(
                    'INSERT INTO shop_product_listings
                        (shop_id,canonical_product_id,sku,condition_type,price,vendor_description,
                         warranty_summary,status,approved_by_user_id,approved_at,published_at)
                     VALUES (:shop,:product,:sku,"new",:price,:description,
                             "Local seller warranty; duration varies by product","active",:admin,
                             CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
                )->execute(['shop'=>$shopId,'product'=>$productId,'sku'=>$sku,'price'=>$price,'description'=>$product['description'],'admin'=>$adminId]);
                $listingId = (int) $db->lastInsertId();
            } else {
                $db->prepare(
                    'UPDATE shop_product_listings SET sku=:sku,price=:price,
                     vendor_description=:description,
                     warranty_summary="Local seller warranty; duration varies by product",
                     status="active",status_reason=NULL,approved_by_user_id=:admin,
                     approved_at=COALESCE(approved_at,CURRENT_TIMESTAMP),
                     published_at=COALESCE(published_at,CURRENT_TIMESTAMP) WHERE id=:id'
                )->execute(['sku'=>$sku,'price'=>$price,'description'=>$product['description'],'admin'=>$adminId,'id'=>$listingId]);
            }
            $listingIds[] = $listingId;
            $offerCounts[$shopKey]++;
            $db->prepare(
                'INSERT INTO inventory (listing_id,quantity_on_hand,quantity_reserved,low_stock_threshold,version)
                 VALUES (:listing,:stock,0,2,1)
                 ON DUPLICATE KEY UPDATE quantity_on_hand=GREATEST(VALUES(quantity_on_hand),quantity_reserved),
                    low_stock_threshold=VALUES(low_stock_threshold),version=version+1'
            )->execute(['listing'=>$listingId,'stock'=>$stock]);
            $inventoryId = finalCatalogueId($db,'SELECT id FROM inventory WHERE listing_id=:listing',['listing'=>$listingId],'Inventory row is missing.');
            $movement = $db->prepare(
                'SELECT COUNT(*) FROM inventory_movements
                 WHERE inventory_id=:inventory AND reference_type="final_catalogue_seed"'
            );
            $movement->execute(['inventory'=>$inventoryId]);
            if ((int) $movement->fetchColumn() === 0) {
                $db->prepare(
                    'INSERT INTO inventory_movements
                        (inventory_id,movement_type,quantity_delta,quantity_after,reference_type,reason,actor_user_id)
                     VALUES (:inventory,"initial",:stock_delta,:stock_after,"final_catalogue_seed",
                             "Initial final catalogue stock",:actor)'
                )->execute(['inventory'=>$inventoryId,'stock_delta'=>$stock,'stock_after'=>$stock,'actor'=>$sellerIds[$shopKey]]);
            }
            $asset = $downloadedAssets[$productKey];
            $storedFilename = hash('sha256','hexbay-final-'.$shopKey.'-'.$productKey) . '.' . $asset['extension'];
            $storedPath = $productStorageRoot . DIRECTORY_SEPARATOR . $storedFilename;
            if (!is_file($storedPath)) {
                if (!copy($asset['path'], $storedPath)) {
                    throw new RuntimeException("Could not store product image for {$productKey}.");
                }
                $newStorageFiles[] = $storedPath;
            }
            $db->prepare(
                'INSERT INTO product_images
                    (listing_id,original_filename,stored_filename,mime_type,byte_size,alt_text,sort_order)
                 VALUES (:listing,:original,:stored,:mime,:bytes,:alt,0)
                 ON DUPLICATE KEY UPDATE listing_id=VALUES(listing_id),mime_type=VALUES(mime_type),
                    byte_size=VALUES(byte_size),alt_text=VALUES(alt_text),sort_order=0'
            )->execute(['listing'=>$listingId,'original'=>basename($asset['path']),'stored'=>$storedFilename,
                'mime'=>$asset['mime'],'bytes'=>filesize($storedPath),'alt'=>$product['name']]);
            $db->prepare(
                'INSERT INTO pc_listing_price_snapshots (listing_id,price,available_quantity,captured_at)
                 VALUES (:listing,:price,:stock,CURRENT_TIMESTAMP)'
            )->execute(['listing'=>$listingId,'price'=>$price,'stock'=>$stock]);
        }
    }

    $benchmarkCodes = [
        'component_overall_index','component_value_index','cpu_single_core_index',
        'cpu_multi_core_index','gpu_raster_index','gpu_compute_index',
        'storage_responsiveness_index',
    ];
    $benchmarkStatement = $db->prepare(
        'SELECT id,code FROM pc_benchmark_definitions WHERE is_active=TRUE AND code IN ('
        . implode(',', array_fill(0, count($benchmarkCodes), '?')) . ')'
    );
    $benchmarkStatement->execute($benchmarkCodes);
    $benchmarkIds = array_column($benchmarkStatement->fetchAll(), 'id', 'code');
    if (count($benchmarkIds) !== count($benchmarkCodes)) {
        throw new RuntimeException('Apply the PC intelligence foundation before importing final benchmark profiles.');
    }
    $insertBenchmark = $db->prepare(
        'INSERT INTO pc_product_benchmarks
            (canonical_product_id,benchmark_definition_id,source_id,raw_value,
             normalized_score,measured_at,notes)
         VALUES (:product,:definition,:source,:raw_value,:normalized,:measured_at,
                 "Transparent normalized final-project capability index derived from curated specifications.")
         ON DUPLICATE KEY UPDATE raw_value=VALUES(raw_value),normalized_score=VALUES(normalized_score),
            measured_at=VALUES(measured_at),notes=VALUES(notes)'
    );
    $coreCategories = ['processors','motherboards','memory','graphics-cards','storage','power-supplies','computer-cases','cpu-coolers'];
    $workloadIds = $db->query('SELECT id,code FROM pc_workload_profiles WHERE is_active=TRUE')->fetchAll();
    foreach ($products as $key => $product) {
        if (!in_array($product['category'], $coreCategories, true)) {
            continue;
        }
        [$overall, $value] = finalDerivedScores($product);
        $tier = $overall < 45 ? 'entry' : ($overall < 65 ? 'mainstream' : ($overall < 82 ? 'performance' : 'enthusiast'));
        $efficiency = max(40, min(92, 92 - ($overall * 0.22)));
        $upgrade = in_array($product['category'], ['motherboards','power-supplies','computer-cases'], true) ? min(90,$overall+8) : min(84,$overall+2);
        $db->prepare(
            'INSERT INTO pc_product_performance_profiles
                (canonical_product_id,performance_tier,overall_score,value_score,
                 efficiency_score,upgradeability_score,reliability_score,source_id,model_version,notes)
             VALUES (:product,:tier,:overall,:value_score,:efficiency,:upgrade,:reliability,:source,
                     "final-retail-v1.0","Transparent normalized project score backed by traceable product specifications.")
             ON DUPLICATE KEY UPDATE performance_tier=VALUES(performance_tier),overall_score=VALUES(overall_score),
                value_score=VALUES(value_score),efficiency_score=VALUES(efficiency_score),
                upgradeability_score=VALUES(upgradeability_score),reliability_score=VALUES(reliability_score),
                source_id=VALUES(source_id),model_version=VALUES(model_version),notes=VALUES(notes)'
        )->execute(['product'=>$productIds[$key],'tier'=>$tier,'overall'=>$overall,'value_score'=>$value,
            'efficiency'=>$efficiency,'upgrade'=>$upgrade,'reliability'=>78,'source'=>$sourceId]);
        $scores = [
            'component_overall_index' => $overall,
            'component_value_index' => $value,
        ];
        if ($product['category'] === 'processors') {
            $scores['cpu_single_core_index'] = min(100, $overall + 5);
            $scores['cpu_multi_core_index'] = $overall;
        } elseif ($product['category'] === 'graphics-cards') {
            $scores['gpu_raster_index'] = $overall;
            $scores['gpu_compute_index'] = max(0, min(100, $overall + 2));
        } elseif ($product['category'] === 'storage') {
            $scores['storage_responsiveness_index'] = $overall;
        }
        foreach ($scores as $code => $score) {
            $insertBenchmark->execute([
                'product'=>$productIds[$key],'definition'=>$benchmarkIds[$code],
                'source'=>$sourceId,'raw_value'=>$score,'normalized'=>$score,
                'measured_at'=>$data['researched_at'],
            ]);
        }
        foreach ($workloadIds as $workload) {
            $score = max(15, min(96, ($overall * .78) + 16));
            $db->prepare(
                'INSERT INTO pc_product_workload_scores
                    (canonical_product_id,workload_profile_id,suitability_score,source_id,model_version,rationale)
                 VALUES (:product,:workload,:score,:source,"final-retail-v1.0",
                         "Category-relative score; hard compatibility and requested specifications are evaluated separately.")
                 ON DUPLICATE KEY UPDATE suitability_score=VALUES(suitability_score),source_id=VALUES(source_id),
                    model_version=VALUES(model_version),rationale=VALUES(rationale)'
            )->execute(['product'=>$productIds[$key],'workload'=>$workload['id'],'score'=>$score,'source'=>$sourceId]);
        }
    }

    $shopPlaceholders = implode(',', array_fill(0, count($shopIds), '?'));
    $db->prepare("UPDATE shop_product_listings SET status='inactive', status_reason='Replaced by final catalogue' WHERE shop_id NOT IN ({$shopPlaceholders}) AND status<>'inactive'")
        ->execute(array_values($shopIds));
    $db->prepare("UPDATE shops SET status='inactive', status_reason='Replaced by the three final HEXBAY shops' WHERE id NOT IN ({$shopPlaceholders}) AND status<>'inactive'")
        ->execute(array_values($shopIds));
    if ($listingIds !== []) {
        $listingPlaceholders = implode(',', array_fill(0, count($listingIds), '?'));
        $db->prepare("UPDATE shop_product_listings SET status='inactive', status_reason='Not part of the final curated inventory' WHERE shop_id IN ({$shopPlaceholders}) AND id NOT IN ({$listingPlaceholders})")
            ->execute([...array_values($shopIds), ...$listingIds]);
    }
    $db->exec(
        "UPDATE canonical_products cp SET cp.is_active=FALSE
         WHERE NOT EXISTS (SELECT 1 FROM shop_product_listings l WHERE l.canonical_product_id=cp.id AND l.status='active')"
    );

    $db->exec(
        "UPDATE pc_workload_requirements wr
         INNER JOIN pc_workload_profiles wp ON wp.id=wr.workload_profile_id
         INNER JOIN categories c ON c.id=wr.component_category_id
         SET wr.minimum_value=8, wr.recommended_value=16, wr.ideal_value=32,
             wr.rationale='8 GB is workable for general use; 16 GB is preferred for smoother multitasking.'
         WHERE wp.code='balanced_general' AND c.slug='memory' AND wr.metric_code='capacity_gb'"
    );

    $db->commit();
    echo json_encode([
        'success'=>true,'researched_at'=>$data['researched_at'],
        'canonical_products'=>count($productIds),'active_offers'=>count($listingIds),
        'shops'=>array_map(static fn (string $key, array $shop): array => [
            'name'=>$shop['shop_name'],'active_offers'=>$offerCounts[$key],
        ], array_keys($shops), $shops),
        'product_images'=>count($listingIds),'shop_logos'=>count($shopIds),
        'other_shops_deactivated'=>true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($newStorageFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    fwrite(STDERR, "Final catalogue import failed at line {$exception->getLine()}: {$exception->getMessage()}\n");
    exit(1);
}
