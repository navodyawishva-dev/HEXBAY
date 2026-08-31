<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__, 2) . '/backend/src/bootstrap.php';

$db = Database::connection();
$failures = [];

function finalCheck(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$shops = $db->query(
    'SELECT s.id,s.name,s.slug,s.logo_path,COUNT(l.id) active_offers
     FROM shops s LEFT JOIN shop_product_listings l ON l.shop_id=s.id AND l.status="active"
     WHERE s.status="approved" GROUP BY s.id,s.name,s.slug,s.logo_path ORDER BY s.slug'
)->fetchAll();
finalCheck(count($shops) === 3, 'Exactly three approved shops must remain.');
$expected = ['finora-tech'=>15,'tech-shark'=>34,'tech-venom'=>14];
foreach ($shops as $shop) {
    finalCheck(isset($expected[$shop['slug']]), "Unexpected approved shop {$shop['slug']}.");
    finalCheck((int) $shop['active_offers'] === ($expected[$shop['slug']] ?? -1), "Wrong offer count for {$shop['slug']}.");
    $logo = dirname(__DIR__, 2) . '/backend/storage/shop-logos/' . $shop['logo_path'];
    finalCheck(
        preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})\.(?:jpg|png|webp)$/', (string) $shop['logo_path']) === 1,
        "Invalid public logo filename for {$shop['slug']}."
    );
    finalCheck(is_file($logo) && filesize($logo) > 1000 && @getimagesize($logo) !== false, "Missing or invalid logo for {$shop['slug']}.");
}

$activeOffers = (int) $db->query(
    'SELECT COUNT(*) FROM shop_product_listings l INNER JOIN shops s ON s.id=l.shop_id
     WHERE l.status="active" AND s.status="approved"'
)->fetchColumn();
finalCheck($activeOffers === 63, 'The final catalogue must expose 63 active offers.');

$invalidOffers = (int) $db->query(
    'SELECT COUNT(*) FROM shop_product_listings l
     INNER JOIN shops s ON s.id=l.shop_id
     INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
     LEFT JOIN inventory i ON i.listing_id=l.id
     LEFT JOIN product_images pi ON pi.listing_id=l.id
     WHERE l.status="active" AND s.status="approved"
       AND (cp.is_active=FALSE OR i.id IS NULL OR i.quantity_on_hand<=i.quantity_reserved
            OR pi.id IS NULL OR l.price<=0)'
)->fetchColumn();
finalCheck($invalidOffers === 0, 'Every active offer must have an active product, positive price, stock and image.');

$activeImages = $db->query(
    'SELECT cp.id canonical_product_id,MIN(pi.stored_filename) stored_filename
     FROM canonical_products cp
     INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id AND l.status="active"
     INNER JOIN shops s ON s.id=l.shop_id AND s.status="approved"
     INNER JOIN product_images pi ON pi.listing_id=l.id
     GROUP BY cp.id'
)->fetchAll();
finalCheck(count($activeImages) === 54, 'Every active canonical product must have a storefront image.');
foreach ($activeImages as $activeImage) {
    $storedFilename = (string) $activeImage['stored_filename'];
    finalCheck(
        preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})\.(?:jpg|png|webp)$/', $storedFilename) === 1,
        "Invalid public product image filename {$storedFilename}."
    );
    $imagePath = dirname(__DIR__, 2) . '/backend/storage/product-images/' . $storedFilename;
    finalCheck(
        is_file($imagePath) && filesize($imagePath) > 1000 && @getimagesize($imagePath) !== false,
        "Missing or invalid product image {$storedFilename}."
    );
}

$unapprovedActive = (int) $db->query(
    'SELECT COUNT(*) FROM shop_product_listings l INNER JOIN shops s ON s.id=l.shop_id
     WHERE l.status="active" AND s.status<>"approved"'
)->fetchColumn();
finalCheck($unapprovedActive === 0, 'No inactive/replaced shop may retain active listings.');

$verified = (int) $db->query(
    'SELECT COUNT(DISTINCT cp.id) FROM canonical_products cp
     INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id AND l.status="active"
     INNER JOIN pc_product_data_quality q ON q.canonical_product_id=cp.id AND q.review_status="verified"
     INNER JOIN pc_product_provenance p ON p.canonical_product_id=cp.id
       AND p.evidence_type="specification" AND p.source_url IS NOT NULL'
)->fetchColumn();
finalCheck($verified === 54, 'All 54 active canonical products need verified quality and provenance rows.');

$ram = $db->query(
    'SELECT CAST(ps.value_number AS UNSIGNED) capacity,COUNT(DISTINCT cp.id) total
     FROM shops s
     INNER JOIN shop_product_listings l ON l.shop_id=s.id AND l.status="active"
     INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
     INNER JOIN categories c ON c.id=cp.category_id AND c.slug="memory"
     INNER JOIN product_specifications ps ON ps.canonical_product_id=cp.id
     INNER JOIN specification_definitions sd ON sd.id=ps.definition_id AND sd.code="capacity_gb"
     WHERE s.slug="tech-shark" GROUP BY ps.value_number ORDER BY ps.value_number'
)->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ([2=>2,4=>2,8=>2,16=>2] as $capacity => $count) {
    finalCheck((int) ($ram[$capacity] ?? 0) === $count, "Tech Shark needs exactly two {$capacity}GB RAM products.");
}

$accessoryCounts = $db->query(
    'SELECT s.slug,so.value_code,COUNT(DISTINCT cp.id) total
     FROM shops s
     INNER JOIN shop_product_listings l ON l.shop_id=s.id AND l.status="active"
     INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
     INNER JOIN categories c ON c.id=cp.category_id AND c.slug="accessories"
     INNER JOIN product_specifications ps ON ps.canonical_product_id=cp.id
     INNER JOIN specification_definitions sd ON sd.id=ps.definition_id AND sd.code="accessory_type"
     INNER JOIN specification_options so ON so.id=ps.option_id
     GROUP BY s.slug,so.value_code'
)->fetchAll();
$actualAccessories = [];
foreach ($accessoryCounts as $row) {
    $actualAccessories[$row['slug']][$row['value_code']] = (int) $row['total'];
}
foreach (['mouse'=>3,'headset'=>2,'controller'=>2] as $type => $count) {
    finalCheck(($actualAccessories['tech-shark'][$type] ?? 0) === $count, "Tech Shark {$type} count is wrong.");
}
foreach (['keyboard'=>3,'gaming_chair'=>3,'headset'=>2,'controller'=>2] as $type => $count) {
    finalCheck(($actualAccessories['finora-tech'][$type] ?? 0) === $count, "Finora Tech {$type} count is wrong.");
}

$finoraLaptops = (int) $db->query(
    'SELECT COUNT(*) FROM shop_product_listings l
     INNER JOIN shops s ON s.id=l.shop_id AND s.slug="finora-tech"
     INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
     INNER JOIN categories c ON c.id=cp.category_id AND c.slug="laptops"
     WHERE l.status="active"'
)->fetchColumn();
finalCheck($finoraLaptops === 5, 'Finora Tech must have exactly five laptops.');

$acerPrice = (float) $db->query(
    'SELECT l.price FROM shop_product_listings l
     INNER JOIN shops s ON s.id=l.shop_id AND s.slug="finora-tech"
     INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id AND cp.model="A515-51G-515J"
     WHERE l.status="active" LIMIT 1'
)->fetchColumn();
finalCheck(abs($acerPrice - 120000.0) < 0.01, 'The Acer MX150 laptop must remain exactly LKR 120,000.');

$result = [
    'success' => $failures === [],
    'approved_shops' => array_map(static fn (array $shop): array => [
        'name' => $shop['name'], 'slug' => $shop['slug'], 'active_offers' => (int) $shop['active_offers'],
    ], $shops),
    'active_offers' => $activeOffers,
    'active_canonical_products_with_provenance' => $verified,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);
