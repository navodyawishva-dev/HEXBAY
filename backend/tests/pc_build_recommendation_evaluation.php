<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\PcBuildRecommendationRepository;
use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Services\PcBuildOptimizer;
use Hexbay\Services\PcCompatibilityEngine;
use Hexbay\Validation\PcBuildRecommendationValidator;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = Database::connection();
    $componentRepository = new PcCompatibilityRepository($db);
    $repository = new PcBuildRecommendationRepository($db, $componentRepository);
    $optimizer = new PcBuildOptimizer(new PcCompatibilityEngine());

    $budgets = [
        'balanced_general' => 350000,
        'office_study' => 300000,
        'gaming_1080p' => 300000,
        'gaming_1440p' => 550000,
        'gaming_4k' => 900000,
        'programming' => 400000,
        'software_compilation' => 500000,
        'virtual_machines' => 650000,
        'graphic_design' => 550000,
        'video_editing' => 700000,
        'live_streaming' => 550000,
        'music_production' => 500000,
        'three_d_rendering' => 750000,
        'cad_engineering' => 600000,
        'ai_ml' => 800000,
        'home_server_nas' => 500000,
        'quiet_efficiency' => 550000,
        'upgrade_focused' => 600000,
    ];
    $knownWorkloads = array_column($repository->workloads(), 'code');
    if (array_diff($knownWorkloads, array_keys($budgets)) !== []) {
        throw new RuntimeException('The evaluation budget matrix does not cover every workload.');
    }

    $outcomes = [];
    $evaluatedBuilds = 0;
    $generatedPaths = 0;
    foreach ($budgets as $workload => $budget) {
        $input = [
            'target_budget_lkr' => $budget,
            'workloads' => [$workload],
            'limit' => 2,
        ];
        if ($workload === 'office_study') {
            $input['preferences'] = ['dedicated_graphics' => 'avoid'];
        }
        $request = PcBuildRecommendationValidator::request($input);
        $data = $repository->workloadData([$workload]);
        $catalogue = $repository->catalogue([$workload]);
        $result = $optimizer->recommend($request, $catalogue, $data);
        $outcomes[$result['outcome_status']] = ($outcomes[$result['outcome_status']] ?? 0) + 1;
        $generatedPaths += (int) $result['search_summary']['generated_combinations'];
        if ($result['outcome_status'] === 'no_solution') {
            if ($result['recommendations'] !== [] || trim((string) $result['notice']) === '') {
                throw new RuntimeException("The {$workload} catalogue gap was not explained safely.");
            }
            continue;
        }
        if ($result['recommendations'] === []) {
            throw new RuntimeException("The {$workload} outcome omitted its recommended builds.");
        }
        foreach ($result['recommendations'] as $build) {
            $evaluatedBuilds++;
            if (!in_array($build['compatibility']['status'], ['compatible', 'warning'], true)) {
                throw new RuntimeException("An incompatible {$workload} build was returned.");
            }
            $componentTotal = array_sum(array_column($build['components'], 'price_lkr'));
            if (abs($componentTotal - $build['total_price_lkr']) > 0.01) {
                throw new RuntimeException("The {$workload} build total is inconsistent.");
            }
            foreach ($build['components'] as $component) {
                if ($component['listing_id'] < 1 || $component['available_quantity'] < 1) {
                    throw new RuntimeException("The {$workload} build contains an unavailable offer.");
                }
            }
            foreach ($build['requirements']['evaluations'] as $requirement) {
                if (
                    $requirement['hard_requirement']
                    && in_array($requirement['status'], ['below_minimum', 'unavailable'], true)
                ) {
                    throw new RuntimeException("The {$workload} build violates a hard requirement.");
                }
            }
        }
    }
    if ($evaluatedBuilds < 1) {
        throw new RuntimeException('The final catalogue did not produce any safe build.');
    }

    fwrite(
        STDOUT,
        sprintf(
            "PC build recommendation evaluation passed (%d workloads, %d returned builds, %d generated paths, outcomes: %s).\n",
            count($budgets),
            $evaluatedBuilds,
            $generatedPaths,
            json_encode($outcomes, JSON_THROW_ON_ERROR)
        )
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "PC build recommendation evaluation failed: {$exception->getMessage()}\n");
    exit(1);
}
