<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\PcBuildRecommendationRepository;
use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Services\PcBuildOptimizer;
use Hexbay\Services\PcBuildRecommendationService;
use Hexbay\Services\PcCompatibilityEngine;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = Database::connection();
    $components = new PcCompatibilityRepository($db);
    $repository = new PcBuildRecommendationRepository($db, $components);
    $service = new PcBuildRecommendationService(
        $repository,
        new PcBuildOptimizer(new PcCompatibilityEngine())
    );

    $workloads = $service->workloads();
    if ($workloads['count'] !== 18 || $workloads['default'] !== 'balanced_general') {
        throw new RuntimeException('The PC workload catalogue is incomplete.');
    }

    $beforeRequests = (int) $db->query(
        'SELECT COUNT(*) FROM pc_build_recommendation_requests'
    )->fetchColumn();
    $beforeResults = (int) $db->query(
        'SELECT COUNT(*) FROM pc_build_recommendation_results'
    )->fetchColumn();

    $gaming = $service->recommend([
        'target_budget_lkr' => 300000,
        'workloads' => ['gaming_1080p'],
        'limit' => 3,
    ]);
    if (
        $gaming['outcome_status'] !== 'recommended'
        || $gaming['recommendations'] === []
        || $gaming['recommendations'][0]['total_price_lkr'] > 300000
    ) {
        throw new RuntimeException('The optimizer did not find the expected sub-LKR 300,000 gaming build.');
    }
    foreach ($gaming['recommendations'] as $build) {
        if (
            !isset($build['components']['graphics_card'])
            || !in_array($build['compatibility']['status'], ['compatible', 'warning'], true)
            || $build['requirements']['attainment_score'] <= 0
        ) {
            throw new RuntimeException('An unsafe or unsuitable gaming build escaped filtering.');
        }
    }

    $minimumViableBudget = (int) ($gaming['budget_analysis']['minimum_viable_budget_lkr'] ?? 0);
    if ($minimumViableBudget <= 0) {
        throw new RuntimeException('The optimizer did not report a minimum viable gaming budget.');
    }
    $stretchTargetBudget = max(100000, $minimumViableBudget - 5000);
    $stretchMaximumBudget = $minimumViableBudget + 5000;
    $stretch = $service->recommend([
        'target_budget_lkr' => $stretchTargetBudget,
        'max_budget_lkr' => $stretchMaximumBudget,
        'workloads' => ['gaming_1080p'],
        'limit' => 2,
    ]);
    if (
        $stretch['outcome_status'] !== 'stretch_only'
        || $stretch['recommendations'][0]['budget_tier'] !== 'stretch'
        || $stretch['recommendations'][0]['total_price_lkr'] > $stretchMaximumBudget
    ) {
        throw new RuntimeException('Flexible-budget stretch behavior is incorrect: ' . json_encode([
            'outcome' => $stretch['outcome_status'],
            'budget' => $stretch['budget_analysis'],
            'first' => $stretch['recommendations'][0] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    $catalogueGap = $service->recommend([
        'target_budget_lkr' => 200000,
        'workloads' => ['gaming_1080p'],
        'limit' => 1,
    ]);
    if (
        $catalogueGap['outcome_status'] !== 'nearest_only'
        || $catalogueGap['budget_analysis']['shortfall_lkr'] <= 0
        || $catalogueGap['recommendations'][0]['budget_tier'] !== 'nearest_available'
    ) {
        throw new RuntimeException('The optimizer hid an honest catalogue budget gap.');
    }

    $specifiedBuild = $service->recommend([
        'target_budget_lkr' => 150000,
        'workloads' => ['balanced_general'],
        'preferences' => [
            'minimum_memory_gb' => 8,
            'maximum_memory_gb' => 8,
            'minimum_vram_gb' => 2,
            'processor_family' => 'intel_core_i5',
        ],
        'limit' => 1,
    ]);
    $specifiedRecommendation = $specifiedBuild['recommendations'][0] ?? null;
    if (
        !is_array($specifiedRecommendation)
        || !in_array($specifiedBuild['outcome_status'], ['recommended', 'stretch_only', 'nearest_only'], true)
        || !str_contains(
            strtolower((string) ($specifiedRecommendation['components']['processor']['model'] ?? '')),
            'i5'
        )
    ) {
        throw new RuntimeException('The optimizer did not preserve the requested Intel Core i5 specification.');
    }
    $buyerDetails = array_values(array_filter(
        (array) ($specifiedRecommendation['requirements']['evaluations'] ?? []),
        static fn (array $detail): bool => ($detail['workload'] ?? '') === 'buyer_preference'
    ));
    if (
        count($buyerDetails) < 2
        || array_filter(
            $buyerDetails,
            static fn (array $detail): bool => ($detail['status'] ?? '') !== 'matched'
        ) !== []
    ) {
        throw new RuntimeException(
            'RAM and VGA buyer constraints were not enforced transparently: '
            . json_encode($buyerDetails, JSON_THROW_ON_ERROR)
        );
    }

    $models = ['Ryzen 5 7600', 'A520M DS3H WIFI6E'];
    $statement = $db->prepare(
        'SELECT model, id FROM canonical_products WHERE model IN (?, ?)'
    );
    $statement->execute($models);
    $ids = [];
    foreach ($statement->fetchAll() as $row) {
        $ids[(string) $row['model']] = (int) $row['id'];
    }
    $incompatibleLocks = $service->recommend([
        'target_budget_lkr' => 500000,
        'workloads' => ['balanced_general'],
        'locked_components' => [
            'processor' => $ids['Ryzen 5 7600'],
            'motherboard' => $ids['A520M DS3H WIFI6E'],
        ],
    ]);
    if (
        $incompatibleLocks['outcome_status'] !== 'no_solution'
        || $incompatibleLocks['recommendations'] !== []
    ) {
        throw new RuntimeException('Incompatible locked components were not rejected safely.');
    }

    $afterRequests = (int) $db->query(
        'SELECT COUNT(*) FROM pc_build_recommendation_requests'
    )->fetchColumn();
    $afterResults = (int) $db->query(
        'SELECT COUNT(*) FROM pc_build_recommendation_results'
    )->fetchColumn();
    $expectedResults = count($gaming['recommendations'])
        + count($stretch['recommendations'])
        + count($catalogueGap['recommendations'])
        + count($specifiedBuild['recommendations']);
    if (
        $afterRequests !== $beforeRequests + 5
        || $afterResults !== $beforeResults + $expectedResults
    ) {
        throw new RuntimeException('Recommendation persistence counts are incorrect.');
    }

    $latest = $db->query(
        'SELECT workloads_json, constraints_json
         FROM pc_build_recommendation_requests ORDER BY id DESC LIMIT 1'
    )->fetch();
    $constraints = json_decode((string) $latest['constraints_json'], true);
    if (
        !is_array($constraints)
        || array_key_exists('specifications', $constraints)
        || array_key_exists('conversation', $constraints)
    ) {
        throw new RuntimeException('Recommendation logging is not privacy-minimised.');
    }

    fwrite(
        STDOUT,
        sprintf(
            "PC build recommendation integration passed (%d gaming builds, LKR %.0f minimum, %d generated paths).\n",
            count($gaming['recommendations']),
            $gaming['budget_analysis']['minimum_viable_budget_lkr'],
            $gaming['search_summary']['generated_combinations']
        )
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "PC build recommendation integration failed: {$exception->getMessage()}\n");
    exit(1);
}
