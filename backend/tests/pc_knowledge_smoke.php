<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = Database::connection();
    $expectedTables = [
        'pc_benchmark_definitions',
        'pc_build_optimizer_versions',
        'pc_build_recommendation_requests',
        'pc_build_recommendation_results',
        'pc_compatibility_rule_sets',
        'pc_compatibility_rules',
        'pc_compatibility_validations',
        'pc_data_sources',
        'pc_listing_price_snapshots',
        'pc_product_benchmarks',
        'pc_product_data_quality',
        'pc_product_performance_profiles',
        'pc_product_provenance',
        'pc_product_workload_scores',
        'pc_workload_profiles',
        'pc_workload_requirements',
    ];
    $tableStatement = $db->prepare(
        'SELECT table_name FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name LIKE "pc_%"
         ORDER BY table_name'
    );
    $tableStatement->execute();
    $actualTables = $tableStatement->fetchAll(PDO::FETCH_COLUMN);
    if ($actualTables !== $expectedTables) {
        throw new RuntimeException('PC-intelligence tables are incomplete.');
    }

    $catalogue = $db->query(
        'SELECT COUNT(DISTINCT cp.id) components, COUNT(DISTINCT l.id) offers,
                COUNT(DISTINCT CASE WHEN c.slug="cpu-coolers" THEN cp.id END) coolers,
                SUM(GREATEST(i.quantity_on_hand-i.quantity_reserved, 0)) stock
         FROM canonical_products cp
         INNER JOIN categories c ON c.id=cp.category_id
         INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id
         INNER JOIN inventory i ON i.listing_id=l.id
         INNER JOIN shops s ON s.id=l.shop_id
         WHERE s.slug IN ("tech-shark", "finora-tech", "tech-venom")
           AND c.slug IN (
             "processors", "motherboards", "memory", "graphics-cards",
             "power-supplies", "cpu-coolers", "storage", "computer-cases"
           )
           AND cp.is_active=TRUE
           AND l.status="active" AND s.status="approved"'
    )->fetch();
    if (
        (int) $catalogue['components'] !== 30
        || (int) $catalogue['offers'] !== 37
        || (int) $catalogue['coolers'] !== 2
        || (int) $catalogue['stock'] < 1
    ) {
        throw new RuntimeException('The enriched PC catalogue has unexpected totals.');
    }

    $missingRequired = (int) $db->query(
        'SELECT COUNT(*)
         FROM canonical_products cp
         INNER JOIN categories c ON c.id=cp.category_id
         INNER JOIN specification_definitions sd
            ON sd.category_id=c.id AND sd.is_active=TRUE AND sd.is_required=TRUE
         LEFT JOIN product_specifications ps
            ON ps.canonical_product_id=cp.id AND ps.definition_id=sd.id
         INNER JOIN shop_product_listings l
            ON l.canonical_product_id=cp.id AND l.status="active"
         INNER JOIN shops s
            ON s.id=l.shop_id AND s.status="approved"
         WHERE cp.is_active=TRUE
           AND s.slug IN ("tech-shark", "finora-tech", "tech-venom")
           AND c.slug IN (
             "processors", "motherboards", "memory", "graphics-cards",
             "power-supplies", "cpu-coolers", "storage", "computer-cases"
           )
           AND ps.id IS NULL'
    )->fetchColumn();
    if ($missingRequired !== 0) {
        throw new RuntimeException("{$missingRequired} required component facts are missing.");
    }

    $knowledge = [
        'sources' => (int) $db->query('SELECT COUNT(*) FROM pc_data_sources')->fetchColumn(),
        'benchmarks' => (int) $db->query('SELECT COUNT(*) FROM pc_product_benchmarks')->fetchColumn(),
        'profiles' => (int) $db->query('SELECT COUNT(*) FROM pc_product_performance_profiles')->fetchColumn(),
        'workloads' => (int) $db->query('SELECT COUNT(*) FROM pc_workload_profiles WHERE is_active=TRUE')->fetchColumn(),
        'requirements' => (int) $db->query('SELECT COUNT(*) FROM pc_workload_requirements')->fetchColumn(),
        'workload_scores' => (int) $db->query('SELECT COUNT(*) FROM pc_product_workload_scores')->fetchColumn(),
        'quality_rows' => (int) $db->query('SELECT COUNT(*) FROM pc_product_data_quality')->fetchColumn(),
        'snapshots' => (int) $db->query('SELECT COUNT(*) FROM pc_listing_price_snapshots')->fetchColumn(),
    ];
    $expectedMinimums = [
        'sources' => 5, 'benchmarks' => 83, 'profiles' => 32,
        'workloads' => 18, 'requirements' => 66, 'workload_scores' => 576,
        'quality_rows' => 32, 'snapshots' => 49,
    ];
    foreach ($expectedMinimums as $metric => $minimum) {
        if ($knowledge[$metric] < $minimum) {
            throw new RuntimeException("PC knowledge metric {$metric} is incomplete.");
        }
    }

    $missingQuality = (int) $db->query(
        'SELECT COUNT(DISTINCT cp.id)
         FROM canonical_products cp
         INNER JOIN shop_product_listings l
            ON l.canonical_product_id=cp.id AND l.status="active"
         INNER JOIN shops s
            ON s.id=l.shop_id AND s.status="approved"
         LEFT JOIN pc_product_data_quality q
            ON q.canonical_product_id=cp.id
         WHERE s.slug IN ("tech-shark", "finora-tech", "tech-venom")
           AND cp.is_active=TRUE AND q.canonical_product_id IS NULL'
    )->fetchColumn();
    if ($missingQuality !== 0) {
        throw new RuntimeException('A final-catalogue product is missing its data-quality record.');
    }

    fwrite(
        STDOUT,
        sprintf(
            "PC knowledge smoke test passed (%d components, %d offers, %d workloads, %d scores).\n",
            $catalogue['components'],
            $catalogue['offers'],
            $knowledge['workloads'],
            $knowledge['workload_scores']
        )
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "PC knowledge smoke test failed: {$exception->getMessage()}\n");
    exit(1);
}
