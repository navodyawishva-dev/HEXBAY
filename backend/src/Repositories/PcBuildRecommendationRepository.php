<?php
declare(strict_types=1);

namespace Hexbay\Repositories;

use PDO;
use RuntimeException;

final class PcBuildRecommendationRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly PcCompatibilityRepository $components
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function workloads(): array
    {
        $statement = $this->db->query(
            'SELECT wp.code, wp.display_name, wp.description,
                    COUNT(wr.id) requirement_count,
                    SUM(CASE WHEN wr.is_hard_requirement=TRUE THEN 1 ELSE 0 END)
                        hard_requirement_count
             FROM pc_workload_profiles wp
             LEFT JOIN pc_workload_requirements wr
                ON wr.workload_profile_id=wp.id
             WHERE wp.is_active=TRUE
             GROUP BY wp.id, wp.code, wp.display_name, wp.description
             ORDER BY wp.display_name'
        );
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['display_name'],
            'description' => (string) $row['description'],
            'requirement_count' => (int) $row['requirement_count'],
            'hard_requirement_count' => (int) $row['hard_requirement_count'],
        ], $statement->fetchAll());
    }

    /** @param array<int, string> $codes
     *  @return array{profiles: array<string, array<string, mixed>>, requirements: array<int, array<string, mixed>>}
     */
    public function workloadData(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $profileStatement = $this->db->prepare(
            "SELECT id, code, display_name, description
             FROM pc_workload_profiles
             WHERE is_active=TRUE AND code IN ({$placeholders})"
        );
        $profileStatement->execute($codes);
        $profiles = [];
        foreach ($profileStatement->fetchAll() as $row) {
            $profiles[(string) $row['code']] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['display_name'],
                'description' => (string) $row['description'],
            ];
        }
        $missing = array_values(array_diff($codes, array_keys($profiles)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Unknown or inactive PC workload: ' . implode(', ', $missing) . '.'
            );
        }

        $requirementStatement = $this->db->prepare(
            "SELECT wp.code workload_code, c.slug category_slug,
                    wr.metric_code, wr.comparison_operator,
                    wr.minimum_value, wr.recommended_value, wr.ideal_value,
                    wr.option_value, wr.weight, wr.is_hard_requirement,
                    wr.rationale
             FROM pc_workload_requirements wr
             INNER JOIN pc_workload_profiles wp
                ON wp.id=wr.workload_profile_id
             LEFT JOIN categories c ON c.id=wr.component_category_id
             WHERE wp.code IN ({$placeholders})
             ORDER BY wp.code, wr.sort_order, wr.id"
        );
        $requirementStatement->execute($codes);
        $requirements = array_map(static fn (array $row): array => [
            'workload_code' => (string) $row['workload_code'],
            'category_slug' => $row['category_slug'] === null ? null : (string) $row['category_slug'],
            'metric_code' => (string) $row['metric_code'],
            'operator' => (string) $row['comparison_operator'],
            'minimum' => $row['minimum_value'] === null ? null : (float) $row['minimum_value'],
            'recommended' => $row['recommended_value'] === null ? null : (float) $row['recommended_value'],
            'ideal' => $row['ideal_value'] === null ? null : (float) $row['ideal_value'],
            'option_value' => $row['option_value'] === null ? null : (string) $row['option_value'],
            'weight' => (float) $row['weight'],
            'is_hard' => (bool) $row['is_hard_requirement'],
            'rationale' => (string) $row['rationale'],
        ], $requirementStatement->fetchAll());

        return ['profiles' => $profiles, 'requirements' => $requirements];
    }

    /** @param array<int, string> $workloadCodes
     *  @return array<string, array<int, array<string, mixed>>>
     */
    public function catalogue(array $workloadCodes): array
    {
        $categoryMap = [
            'processor' => 'processors',
            'motherboard' => 'motherboards',
            'memory' => 'memory',
            'storage' => 'storage',
            'graphics_card' => 'graphics-cards',
            'power_supply' => 'power-supplies',
            'computer_case' => 'computer-cases',
            'cpu_cooler' => 'cpu-coolers',
        ];
        $catalogue = [];
        $allIds = [];
        foreach ($categoryMap as $field => $slug) {
            $catalogue[$field] = $this->components->candidatesForCategory($slug, [], 200);
            foreach ($catalogue[$field] as $product) {
                $allIds[] = (int) $product['product_id'];
            }
        }
        $allIds = array_values(array_unique($allIds));
        if ($allIds === []) {
            return $catalogue;
        }
        $productPlaceholders = implode(',', array_fill(0, count($allIds), '?'));
        $workloadPlaceholders = implode(',', array_fill(0, count($workloadCodes), '?'));

        $benchmarkStatement = $this->db->prepare(
            "SELECT pb.canonical_product_id, bd.code, pb.normalized_score
             FROM pc_product_benchmarks pb
             INNER JOIN pc_benchmark_definitions bd
                ON bd.id=pb.benchmark_definition_id AND bd.is_active=TRUE
             WHERE pb.canonical_product_id IN ({$productPlaceholders})"
        );
        $benchmarkStatement->execute($allIds);
        $benchmarks = [];
        foreach ($benchmarkStatement->fetchAll() as $row) {
            $benchmarks[(int) $row['canonical_product_id']][(string) $row['code']]
                = (float) $row['normalized_score'];
        }

        $scoreStatement = $this->db->prepare(
            "SELECT pws.canonical_product_id, wp.code, pws.suitability_score,
                    pws.rationale
             FROM pc_product_workload_scores pws
             INNER JOIN pc_workload_profiles wp ON wp.id=pws.workload_profile_id
             WHERE pws.canonical_product_id IN ({$productPlaceholders})
               AND wp.code IN ({$workloadPlaceholders})"
        );
        $scoreStatement->execute([...$allIds, ...$workloadCodes]);
        $workloadScores = [];
        $workloadRationales = [];
        foreach ($scoreStatement->fetchAll() as $row) {
            $id = (int) $row['canonical_product_id'];
            $code = (string) $row['code'];
            $workloadScores[$id][$code] = (float) $row['suitability_score'];
            $workloadRationales[$id][$code] = (string) $row['rationale'];
        }

        foreach ($catalogue as $field => $products) {
            foreach ($products as $index => $product) {
                $id = (int) $product['product_id'];
                $metrics = $product['specifications'];
                foreach ($benchmarks[$id] ?? [] as $code => $score) {
                    $metrics[$code] = $score;
                }
                $metrics['component_overall_index'] = $product['overall_score'];
                $metrics['component_value_index'] = $product['value_score'];
                $catalogue[$field][$index]['metrics'] = $metrics;
                $catalogue[$field][$index]['workload_scores'] = $workloadScores[$id] ?? [];
                $catalogue[$field][$index]['workload_rationales'] = $workloadRationales[$id] ?? [];
            }
        }
        $offers = $this->components->offersByProductIds($allIds);
        foreach ($catalogue as $field => $products) {
            foreach ($products as $index => $product) {
                $catalogue[$field][$index]['offers'] = $offers[(int) $product['product_id']] ?? [];
            }
        }
        return $catalogue;
    }

    /** @param array<string, mixed> $request
     *  @param array<string, mixed> $result
     */
    public function logRecommendation(
        string $publicId,
        array $request,
        array $result,
        ?int $userId = null
    ): void {
        $versionId = $this->optimizerVersionId((string) $result['optimizer_version']);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $statement = $this->db->prepare(
                'INSERT INTO pc_build_recommendation_requests
                    (public_id, user_id, optimizer_version_id,
                     target_budget_lkr, max_budget_lkr, workloads_json,
                     constraints_json, outcome_status,
                     generated_combination_count, compatible_build_count)
                 VALUES
                    (:public_id, :user_id, :version, :target, :maximum,
                     :workloads, :constraints, :outcome, :generated, :compatible)'
            );
            $statement->execute([
                'public_id' => $publicId,
                'user_id' => $userId,
                'version' => $versionId,
                'target' => $request['target_budget_lkr'],
                'maximum' => $request['max_budget_lkr'],
                'workloads' => json_encode($request['workloads'], JSON_THROW_ON_ERROR),
                'constraints' => json_encode([
                    'flexibility_percent' => $request['flexibility_percent'],
                    'priorities' => $request['priorities'],
                    'preferences' => $request['preferences'],
                    'locked_components' => $request['locked_components'],
                    'setup_scope' => $request['setup_scope'],
                    'include_headset' => $request['include_headset'],
                    'peripheral_categories' => $request['peripheral_categories'],
                    'limit' => $request['limit'],
                ], JSON_THROW_ON_ERROR),
                'outcome' => $result['outcome_status'],
                'generated' => $result['search_summary']['generated_combinations'],
                'compatible' => $result['search_summary']['compatible_builds'],
            ]);
            $requestId = (int) $this->db->lastInsertId();
            $resultStatement = $this->db->prepare(
                'INSERT INTO pc_build_recommendation_results
                    (request_id, result_rank, budget_tier, total_price_lkr,
                     composite_score, performance_score, value_score,
                     compatibility_status, selected_product_ids_json,
                     selected_listing_ids_json, explanation_summary_json)
                 VALUES
                    (:request_id, :rank, :tier, :total, :composite,
                     :performance, :value_score, :compatibility,
                     :products, :listings, :explanation)'
            );
            foreach ($result['recommendations'] as $rank => $build) {
                $productIds = [];
                $listingIds = [];
                foreach ($build['components'] as $component) {
                    $productIds[] = (int) $component['product_id'];
                    $listingIds[] = (int) $component['listing_id'];
                }
                foreach ($build['peripherals'] ?? [] as $component) {
                    $productIds[] = (int) $component['product_id'];
                    $listingIds[] = (int) $component['listing_id'];
                }
                $resultStatement->execute([
                    'request_id' => $requestId,
                    'rank' => $rank + 1,
                    'tier' => $build['budget_tier'],
                    'total' => $build['total_price_lkr'],
                    'composite' => $build['scores']['composite'],
                    'performance' => $build['scores']['performance'],
                    'value_score' => $build['scores']['value'],
                    'compatibility' => $build['compatibility']['status'],
                    'products' => json_encode($productIds, JSON_THROW_ON_ERROR),
                    'listings' => json_encode($listingIds, JSON_THROW_ON_ERROR),
                    'explanation' => json_encode([
                        'label' => $build['label'],
                        'why_recommended' => $build['why_recommended'],
                        'trade_offs' => $build['trade_offs'],
                    ], JSON_THROW_ON_ERROR),
                ]);
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function optimizerVersionId(string $version): int
    {
        $statement = $this->db->prepare(
            'SELECT id FROM pc_build_optimizer_versions
             WHERE version=:version AND status="active" LIMIT 1'
        );
        $statement->execute(['version' => $version]);
        $id = (int) $statement->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException("Active PC optimizer version {$version} is missing.");
        }
        return $id;
    }
}
