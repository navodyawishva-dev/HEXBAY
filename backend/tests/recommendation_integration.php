<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Contracts\LaptopRankingClient;
use Hexbay\Repositories\LaptopRecommendationRepository;
use Hexbay\Services\LaptopRecommendationService;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::connection();
$suffix = bin2hex(random_bytes(6));

try {
    $db->beginTransaction();

    $roleId = (int) $db->query(
        "SELECT id FROM roles WHERE name='shop_owner'"
    )->fetchColumn();
    $categoryId = (int) $db->query(
        "SELECT id FROM categories WHERE slug='laptops'"
    )->fetchColumn();
    if ($roleId < 1 || $categoryId < 1) {
        throw new RuntimeException('Required catalogue baseline is missing.');
    }

    $statement = $db->prepare(
        'INSERT INTO users (role_id, email, password_hash, status)
         VALUES (:role_id, :email, :password_hash, "active")'
    );
    $statement->execute([
        'role_id' => $roleId,
        'email' => "recommendation-{$suffix}@example.test",
        'password_hash' => password_hash('FixturePass123', PASSWORD_DEFAULT),
    ]);
    $userId = (int) $db->lastInsertId();

    $statement = $db->prepare(
        'INSERT INTO shops
            (owner_user_id, name, slug, status, rating_average, rating_count,
             approved_at)
         VALUES
            (:owner, :name, :slug, "approved", 4.80, 25, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        'owner' => $userId,
        'name' => "Fixture Technology {$suffix}",
        'slug' => "fixture-technology-{$suffix}",
    ]);
    $shopId = (int) $db->lastInsertId();

    $statement = $db->prepare(
        'INSERT INTO brands (name, slug, is_active)
         VALUES (:name, :slug, TRUE)'
    );
    $statement->execute([
        'name' => "Fixture Brand {$suffix}",
        'slug' => "fixture-brand-{$suffix}",
    ]);
    $brandId = (int) $db->lastInsertId();

    $statement = $db->prepare(
        'INSERT INTO canonical_products
            (category_id, brand_id, name, model, specification_completeness,
             is_active)
         VALUES
            (:category, :brand, :name, :model, "complete", TRUE)'
    );
    $statement->execute([
        'category' => $categoryId,
        'brand' => $brandId,
        'name' => 'Fixture Creator Laptop',
        'model' => "HX-{$suffix}",
    ]);
    $productId = (int) $db->lastInsertId();

    $definitions = $db->prepare(
        'SELECT id, code FROM specification_definitions
         WHERE category_id=:category_id
           AND code IN (
               "processor_model", "ram_capacity_gb", "gpu_model",
               "storage_capacity_gb", "screen_size_inches"
           )'
    );
    $definitions->execute(['category_id' => $categoryId]);
    $definitionIds = [];
    foreach ($definitions->fetchAll() as $definition) {
        $definitionIds[$definition['code']] = (int) $definition['id'];
    }
    if (count($definitionIds) !== 5) {
        throw new RuntimeException('Laptop specification definitions are incomplete.');
    }

    $insertText = $db->prepare(
        'INSERT INTO product_specifications
            (canonical_product_id, definition_id, value_text)
         VALUES (:product_id, :definition_id, :value_text)'
    );
    $insertNumber = $db->prepare(
        'INSERT INTO product_specifications
            (canonical_product_id, definition_id, value_number)
         VALUES (:product_id, :definition_id, :value_number)'
    );
    foreach ([
        'processor_model' => 'Intel Core i7-13620H',
        'gpu_model' => 'NVIDIA GeForce RTX 4060',
    ] as $code => $value) {
        $insertText->execute([
            'product_id' => $productId,
            'definition_id' => $definitionIds[$code],
            'value_text' => $value,
        ]);
    }
    foreach ([
        'ram_capacity_gb' => 32,
        'storage_capacity_gb' => 1024,
        'screen_size_inches' => 15.6,
    ] as $code => $value) {
        $insertNumber->execute([
            'product_id' => $productId,
            'definition_id' => $definitionIds[$code],
            'value_number' => $value,
        ]);
    }

    $statement = $db->prepare(
        'INSERT INTO shop_product_listings
            (shop_id, canonical_product_id, sku, price, status, approved_at,
             published_at)
         VALUES
            (:shop, :product, :sku, 425000.00, "active",
             CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        'shop' => $shopId,
        'product' => $productId,
        'sku' => "REC-{$suffix}",
    ]);
    $listingId = (int) $db->lastInsertId();

    $statement = $db->prepare(
        'INSERT INTO inventory (listing_id, quantity_on_hand, quantity_reserved)
         VALUES (:listing_id, 7, 0)'
    );
    $statement->execute(['listing_id' => $listingId]);

    $ranker = new class($productId) implements LaptopRankingClient {
        public function __construct(private readonly int $fixtureProductId)
        {
        }

        public function rank(array $payload): array
        {
            $candidate = null;
            foreach ($payload['candidates'] as $item) {
                if ((int) $item['product_id'] === $this->fixtureProductId) {
                    $candidate = $item;
                    break;
                }
            }
            if ($candidate === null) {
                throw new RuntimeException(
                    'The integration fixture was not included in the candidate set.'
                );
            }
            return [
                'algorithm_version' => 'integration-fake-v1',
                'eligible_candidate_count' => 1,
                'recommendations' => [[
                    'product_id' => $candidate['product_id'],
                    'listing_id' => $candidate['listing_id'],
                    'price_lkr' => 1,
                    'score' => 0.91,
                    'score_breakdown' => ['content_similarity' => 0.95],
                    'reasons' => ['Strong specifications for creative work.'],
                ]],
                'filtered_out' => [],
                'filter_summary' => [],
                'relaxation_suggestions' => [],
            ];
        }
    };
    $service = new LaptopRecommendationService(
        new LaptopRecommendationRepository($db),
        $ranker
    );
    $result = $service->recommend([
        'max_budget_lkr' => 500000,
        'intended_use' => 'content_creation',
        'minimum_ram_gb' => 16,
        'minimum_storage_gb' => 512,
        'require_dedicated_gpu' => true,
        'limit' => 5,
    ]);
    $recommendation = $result['recommendations'][0] ?? null;
    if (
        !is_array($recommendation)
        || $recommendation['product_id'] !== $productId
        || $recommendation['listing_id'] !== $listingId
        || $recommendation['price_lkr'] !== 425000.0
        || $recommendation['stock_quantity'] !== 7
    ) {
        throw new RuntimeException('Authoritative recommendation data was not preserved.');
    }
    $logs = $db->query(
        "SELECT COUNT(*) FROM recommendation_logs
         WHERE algorithm_version='integration-fake-v1'"
    )->fetchColumn();
    if ((int) $logs !== 1) {
        throw new RuntimeException('Recommendation log was not created.');
    }

    $db->rollBack();
    fwrite(
        STDOUT,
        "Recommendation integration test passed "
        . "(candidate query, revalidation, and logging).\n"
    );
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(
        STDERR,
        "Recommendation integration test failed: {$exception->getMessage()}\n"
    );
    exit(1);
}
