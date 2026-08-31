<?php
declare(strict_types=1);

namespace Hexbay\Services;

use RuntimeException;

final class PcBuildOptimizer
{
    public const VERSION = 'pc-build-v1.1.0';
    private const BEAM_WIDTH = 800;

    /** @var array<string, string> */
    private const CATEGORY_FIELDS = [
        'processors' => 'processor',
        'motherboards' => 'motherboard',
        'memory' => 'memory',
        'graphics-cards' => 'graphics_card',
        'power-supplies' => 'power_supply',
        'storage' => 'storage',
        'computer-cases' => 'computer_case',
        'cpu-coolers' => 'cpu_cooler',
    ];

    /** @var array<string, float> */
    private const BASE_CATEGORY_WEIGHTS = [
        'processor' => 0.20,
        'motherboard' => 0.10,
        'memory' => 0.12,
        'graphics_card' => 0.25,
        'power_supply' => 0.08,
        'storage' => 0.10,
        'computer_case' => 0.06,
        'cpu_cooler' => 0.09,
    ];

    public function __construct(private readonly PcCompatibilityEngine $compatibility)
    {
    }

    /** @param array<string, mixed> $request
     *  @param array<string, array<int, array<string, mixed>>> $catalogue
     *  @param array{profiles: array<string, array<string, mixed>>, requirements: array<int, array<string, mixed>>} $workloadData
     *  @return array<string, mixed>
     */
    public function recommend(array $request, array $catalogue, array $workloadData): array
    {
        $graphicsMode = $this->graphicsMode($request, $workloadData['requirements']);
        $order = [
            'processor', 'motherboard', 'memory', 'storage',
            'graphics_card', 'power_supply', 'computer_case', 'cpu_cooler',
        ];
        $beam = [[
            'components' => [],
            'total' => 0.0,
            'heuristic' => 0.0,
        ]];
        $generated = 0;
        $conflicts = $this->preferenceConflicts($request, $workloadData['requirements']);
        if ($conflicts !== []) {
            return $this->emptyResult(
                $request,
                0,
                $this->conflictNotice($conflicts),
                $conflicts
            );
        }

        foreach ($order as $field) {
            $options = $this->options(
                $field,
                $catalogue[$field] ?? [],
                $request['locked_components'],
                $graphicsMode,
                $request
            );
            if ($options === []) {
                return $this->emptyResult(
                    $request,
                    $generated,
                    $this->missingCandidateNotice($field, $request)
                );
            }
            $expanded = [];
            foreach ($beam as $state) {
                foreach ($options as $candidate) {
                    $components = $state['components'];
                    if ($candidate !== null) {
                        $components[$field] = $candidate;
                    }
                    $total = $state['total'] + (float) ($candidate['price_lkr'] ?? 0);
                    $generated++;
                    $compatibility = $this->compatibility->validate($components, 'partial');
                    if (!in_array($compatibility['overall_status'], ['compatible', 'warning'], true)) {
                        continue;
                    }
                    $requirements = $this->evaluateRequirements(
                        $components,
                        $request,
                        $workloadData['requirements'],
                        true
                    );
                    if ($requirements['hard_failures'] !== []) {
                        continue;
                    }
                    $expanded[] = [
                        'components' => $components,
                        'total' => $total,
                        'heuristic' => $this->partialHeuristic(
                            $components,
                            $request,
                            $total
                        ),
                    ];
                }
            }
            if ($expanded === []) {
                return $this->emptyResult(
                    $request,
                    $generated,
                    'No compatible path remains after applying the selected requirements.'
                );
            }
            $beam = $this->prune($expanded);
        }

        $valid = [];
        foreach ($beam as $state) {
            $components = $state['components'];
            if ($graphicsMode === 'required' && !isset($components['graphics_card'])) {
                continue;
            }
            if ($graphicsMode === 'avoid' && isset($components['graphics_card'])) {
                continue;
            }
            $compatibility = $this->compatibility->validate($components, 'complete');
            if (!$compatibility['is_compatible'] || !$compatibility['complete']) {
                continue;
            }
            $requirements = $this->evaluateRequirements(
                $components,
                $request,
                $workloadData['requirements'],
                false
            );
            if ($requirements['hard_failures'] !== []) {
                continue;
            }
            $scores = $this->scoreBuild(
                $components,
                (float) $state['total'],
                $request,
                $workloadData['requirements'],
                $requirements,
                $compatibility
            );
            $signature = $this->signature($components);
            $valid[$signature] = [
                'components' => $components,
                'total' => (float) $state['total'],
                'compatibility' => $compatibility,
                'requirements' => $requirements,
                'scores' => $scores,
            ];
        }

        if ($valid === []) {
            return $this->emptyResult(
                $request,
                $generated,
                'No complete build satisfies both the compatibility rules and hard workload requirements.'
            );
        }

        $valid = array_values($valid);
        $target = (float) $request['target_budget_lkr'];
        $maximum = (float) $request['max_budget_lkr'];
        $within = array_values(array_filter(
            $valid,
            static fn (array $build): bool => $build['total'] <= $target
        ));
        $stretch = array_values(array_filter(
            $valid,
            static fn (array $build): bool => $build['total'] > $target
                && $build['total'] <= $maximum
        ));
        $nearest = array_values(array_filter(
            $valid,
            static fn (array $build): bool => $build['total'] > $maximum
        ));
        $this->sortByScore($within);
        $this->sortByScore($stretch);
        usort($nearest, static fn (array $left, array $right): int =>
            ($left['total'] <=> $right['total'])
            ?: ($right['scores']['composite'] <=> $left['scores']['composite'])
        );

        $selected = $this->selectResults(
            $within,
            $stretch,
            $nearest,
            (int) $request['limit']
        );
        $bestWithin = $within[0] ?? null;
        $recommendations = [];
        foreach ($selected as $rank => $selection) {
            $recommendations[] = $this->presentBuild(
                $selection['build'],
                $selection['tier'],
                $rank,
                $request,
                $workloadData['profiles'],
                $bestWithin
            );
        }

        $minimumViable = min(array_column($valid, 'total'));
        $outcome = $within !== []
            ? 'recommended'
            : ($stretch !== [] ? 'stretch_only' : 'nearest_only');
        return [
            'optimizer_version' => self::VERSION,
            'compatibility_rule_version' => PcCompatibilityEngine::RULE_SET_VERSION,
            'outcome_status' => $outcome,
            'budget_analysis' => [
                'target_budget_lkr' => round($target, 2),
                'max_budget_lkr' => round($maximum, 2),
                'minimum_viable_budget_lkr' => round($minimumViable, 2),
                'within_target_count' => count($within),
                'stretch_count' => count($stretch),
                'over_maximum_count' => count($nearest),
                'shortfall_lkr' => round(max(0, $minimumViable - $maximum), 2),
            ],
            'search_summary' => [
                'strategy' => 'compatibility_pruned_beam_search',
                'generated_combinations' => $generated,
                'compatible_builds' => count($valid),
                'returned_builds' => count($recommendations),
            ],
            'recommendations' => $recommendations,
            'notice' => $outcome === 'nearest_only'
                ? sprintf(
                    'The current catalogue has no valid build at or below LKR %s. The closest complete option starts at LKR %s.',
                    number_format($maximum, 0),
                    number_format($minimumViable, 0)
                )
                : null,
            'scope' => [
                'prices' => 'Current best approved in-stock seller offers; delivery and operating-system costs are excluded.',
                'data_quality' => 'Demonstration component evidence remains marked needs_review until independently verified.',
            ],
        ];
    }

    /** @param array<string, mixed> $request
     *  @param array<int, array<string, mixed>> $requirements
     */
    private function graphicsMode(array $request, array $requirements): string
    {
        $mode = (string) $request['preferences']['dedicated_graphics'];
        if ($mode !== 'auto') {
            return $mode;
        }
        if (
            (int) ($request['preferences']['minimum_vram_gb'] ?? 0) > 0
            || (int) ($request['preferences']['maximum_vram_gb'] ?? 0) > 0
            || trim((string) ($request['preferences']['gpu_model'] ?? '')) !== ''
        ) {
            return 'required';
        }
        foreach ($requirements as $requirement) {
            if (
                $requirement['category_slug'] === 'graphics-cards'
                && $requirement['is_hard']
                && (($request['workloads'][$requirement['workload_code']] ?? 0) > 0)
            ) {
                return 'required';
            }
        }
        return 'auto';
    }

    /** @param array<int, array<string, mixed>> $candidates
     *  @param array<string, int> $locked
     *  @return array<int, array<string, mixed>|null>
     */
    private function options(
        string $field,
        array $candidates,
        array $locked,
        string $graphicsMode,
        array $request
    ): array {
        $candidates = $this->filterCandidates($field, $candidates, $request);
        if (isset($locked[$field])) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate['product_id'] === (int) $locked[$field]) {
                    return [$candidate];
                }
            }
            return [];
        }
        if ($field === 'graphics_card') {
            return $graphicsMode === 'avoid'
                ? [null]
                : ($graphicsMode === 'required' ? $candidates : [null, ...$candidates]);
        }
        if ($field === 'cpu_cooler') {
            return [null, ...$candidates];
        }
        return $candidates;
    }

    /** @param array<int, array<string, mixed>> $candidates
     *  @param array<string, mixed> $request
     *  @return array<int, array<string, mixed>>
     */
    private function filterCandidates(string $field, array $candidates, array $request): array
    {
        $preferences = (array) ($request['preferences'] ?? []);
        return array_values(array_filter(
            $candidates,
            function (array $candidate) use ($field, $preferences): bool {
                $metrics = (array) ($candidate['metrics'] ?? []);
                $haystack = $this->searchText(
                    implode(' ', [
                        (string) ($candidate['brand'] ?? ''),
                        (string) ($candidate['name'] ?? ''),
                        (string) ($candidate['model'] ?? ''),
                    ])
                );
                if ($field === 'processor') {
                    $family = (string) ($preferences['processor_family'] ?? '');
                    if ($family !== '' && !$this->matchesProcessorFamily($candidate, $family)) {
                        return false;
                    }
                    $model = $this->searchText((string) ($preferences['processor_model'] ?? ''));
                    if ($model !== '' && !str_contains($haystack, $model)) {
                        return false;
                    }
                }
                if ($field === 'memory') {
                    $capacity = (float) ($metrics['capacity_gb'] ?? 0);
                    $minimum = (int) ($preferences['minimum_memory_gb'] ?? 0);
                    $maximum = (int) ($preferences['maximum_memory_gb'] ?? 0);
                    if (($minimum > 0 && $capacity < $minimum) || ($maximum > 0 && $capacity > $maximum)) {
                        return false;
                    }
                }
                if ($field === 'graphics_card') {
                    $vram = (float) ($metrics['vram_gb'] ?? 0);
                    $minimum = (int) ($preferences['minimum_vram_gb'] ?? 0);
                    $maximum = (int) ($preferences['maximum_vram_gb'] ?? 0);
                    if (($minimum > 0 && $vram < $minimum) || ($maximum > 0 && $vram > $maximum)) {
                        return false;
                    }
                    $model = $this->searchText((string) ($preferences['gpu_model'] ?? ''));
                    if ($model !== '' && !str_contains($haystack, $model)) {
                        return false;
                    }
                }
                if ($field === 'storage') {
                    $capacity = (float) ($metrics['capacity_gb'] ?? 0);
                    $minimum = (int) ($preferences['minimum_storage_gb'] ?? 0);
                    $maximum = (int) ($preferences['maximum_storage_gb'] ?? 0);
                    if (($minimum > 0 && $capacity < $minimum) || ($maximum > 0 && $capacity > $maximum)) {
                        return false;
                    }
                    $wanted = (string) ($preferences['storage_type'] ?? 'any');
                    $actual = (string) ($metrics['storage_type'] ?? '');
                    if ($wanted === 'ssd' && !in_array($actual, ['nvme_ssd', 'sata_ssd'], true)) {
                        return false;
                    }
                    if (!in_array($wanted, ['any', 'ssd'], true) && $actual !== $wanted) {
                        return false;
                    }
                }
                return true;
            }
        ));
    }

    /** @param array<string, mixed> $candidate */
    private function matchesProcessorFamily(array $candidate, string $family): bool
    {
        $brand = strtolower((string) ($candidate['brand'] ?? ''));
        $text = $this->searchText(
            (string) ($candidate['name'] ?? '') . ' ' . (string) ($candidate['model'] ?? '')
        );
        if (preg_match('/^intel_core_i([3579])$/', $family, $match) === 1) {
            return $brand === 'intel'
                && preg_match('/\b(?:core\s+)?i' . $match[1] . '\b/', $text) === 1;
        }
        if (preg_match('/^amd_ryzen_([3579])$/', $family, $match) === 1) {
            return $brand === 'amd'
                && preg_match('/\bryzen\s+' . $match[1] . '\b/', $text) === 1;
        }
        return false;
    }

    private function searchText(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    /** @param array<int, array<string, mixed>> $states
     *  @return array<int, array<string, mixed>>
     */
    private function prune(array $states): array
    {
        usort($states, static fn (array $left, array $right): int =>
            ($right['heuristic'] <=> $left['heuristic'])
            ?: ($left['total'] <=> $right['total'])
        );
        if (count($states) <= self::BEAM_WIDTH) {
            return $states;
        }
        $best = array_slice($states, 0, 600);
        usort($states, static fn (array $left, array $right): int =>
            ($left['total'] <=> $right['total'])
            ?: ($right['heuristic'] <=> $left['heuristic'])
        );
        $combined = [...$best, ...array_slice($states, 0, 200)];
        $unique = [];
        foreach ($combined as $state) {
            $unique[$this->signature($state['components'])] = $state;
        }
        return array_values($unique);
    }

    /** @param array<string, array<string, mixed>> $components
     *  @param array<string, mixed> $request
     */
    private function partialHeuristic(array $components, array $request, float $total): float
    {
        $score = 0.0;
        $count = 0;
        foreach ($components as $product) {
            $workloadScore = 0.0;
            foreach ($request['workloads'] as $code => $weight) {
                $workloadScore += (float) ($product['workload_scores'][$code]
                    ?? $product['overall_score'] ?? 0) * $weight;
            }
            $score += ($workloadScore * 0.55)
                + ((float) ($product['value_score'] ?? 0) * 0.30)
                + ((float) ($product['upgradeability_score'] ?? 0) * 0.15);
            $count++;
        }
        $average = $count > 0 ? $score / $count : 0;
        $maximum = (float) $request['max_budget_lkr'];
        $penalty = $total > $maximum
            ? min(35, (($total - $maximum) / max(1, $maximum)) * 100)
            : 0;
        return $average - $penalty;
    }

    /** @param array<string, array<string, mixed>> $components
     *  @param array<string, mixed> $request
     *  @param array<int, array<string, mixed>> $requirements
     *  @return array<string, mixed>
     */
    private function evaluateRequirements(
        array $components,
        array $request,
        array $requirements,
        bool $ignoreMissing
    ): array {
        $details = [];
        $hardFailures = [];
        $weighted = 0.0;
        $weightTotal = 0.0;
        foreach ($requirements as $requirement) {
            $workloadWeight = (float) ($request['workloads'][$requirement['workload_code']] ?? 0);
            if ($workloadWeight <= 0) {
                continue;
            }
            $field = self::CATEGORY_FIELDS[$requirement['category_slug']] ?? null;
            if ($field === null || !isset($components[$field])) {
                if ($ignoreMissing) {
                    continue;
                }
                $status = 'unavailable';
                $attainment = 0.0;
                $value = null;
            } else {
                $value = $components[$field]['metrics'][$requirement['metric_code']] ?? null;
                [$status, $attainment] = $this->attainment($value, $requirement);
            }
            $weight = (float) $requirement['weight'] * $workloadWeight;
            $weighted += $attainment * $weight;
            $weightTotal += $weight;
            $detail = [
                'workload' => $requirement['workload_code'],
                'component' => $field,
                'metric' => $requirement['metric_code'],
                'actual' => is_numeric($value) ? round((float) $value, 3) : $value,
                'minimum' => $requirement['minimum'],
                'recommended' => $requirement['recommended'],
                'ideal' => $requirement['ideal'],
                'status' => $status,
                'attainment_score' => round($attainment * 100, 2),
                'hard_requirement' => (bool) $requirement['is_hard'],
                'rationale' => $requirement['rationale'],
            ];
            $details[] = $detail;
            if ($requirement['is_hard'] && in_array($status, ['below_minimum', 'unavailable'], true)) {
                $hardFailures[] = $detail;
            }
        }

        foreach ([
            ['field' => 'memory', 'metric' => 'capacity_gb', 'minimum' => $request['preferences']['minimum_memory_gb'], 'maximum' => $request['preferences']['maximum_memory_gb']],
            ['field' => 'storage', 'metric' => 'capacity_gb', 'minimum' => $request['preferences']['minimum_storage_gb'], 'maximum' => $request['preferences']['maximum_storage_gb']],
            ['field' => 'graphics_card', 'metric' => 'vram_gb', 'minimum' => $request['preferences']['minimum_vram_gb'], 'maximum' => $request['preferences']['maximum_vram_gb']],
        ] as $preference) {
            if ($preference['minimum'] <= 0 && $preference['maximum'] <= 0) {
                continue;
            }
            if (!isset($components[$preference['field']])) {
                if (!$ignoreMissing) {
                    $detail = [
                        'workload' => 'buyer_preference',
                        'component' => $preference['field'],
                        'metric' => $preference['metric'],
                        'actual' => null,
                        'minimum' => $preference['minimum'],
                        'maximum' => $preference['maximum'],
                        'status' => 'unavailable',
                        'hard_requirement' => true,
                        'rationale' => 'This specification was requested by the buyer.',
                    ];
                    $details[] = $detail;
                    $hardFailures[] = $detail;
                }
                continue;
            }
            $value = (float) ($components[$preference['field']]['metrics'][$preference['metric']] ?? 0);
            $status = 'matched';
            if ($value < $preference['minimum']) {
                $status = 'below_minimum';
            } elseif ($preference['maximum'] > 0 && $value > $preference['maximum']) {
                $status = 'above_maximum';
            }
            $detail = [
                'workload' => 'buyer_preference',
                'component' => $preference['field'],
                'metric' => $preference['metric'],
                'actual' => $value,
                'minimum' => $preference['minimum'],
                'maximum' => $preference['maximum'],
                'status' => $status,
                'hard_requirement' => true,
                'rationale' => 'This specification was requested by the buyer.',
            ];
            $details[] = $detail;
            if ($status !== 'matched') {
                $hardFailures[] = $detail;
            }
        }

        return [
            'score' => round(($weightTotal > 0 ? $weighted / $weightTotal : 0.5) * 100, 3),
            'hard_failures' => $hardFailures,
            'details' => $details,
        ];
    }

    /** @param mixed $rawValue
     *  @param array<string, mixed> $requirement
     *  @return array{0: string, 1: float}
     */
    private function attainment(mixed $rawValue, array $requirement): array
    {
        if (!is_numeric($rawValue)) {
            return ['unavailable', 0.0];
        }
        $value = (float) $rawValue;
        $minimum = $requirement['minimum'];
        $recommended = $requirement['recommended'];
        $ideal = $requirement['ideal'];
        if ($requirement['operator'] === 'lte') {
            if ($ideal !== null && $value <= $ideal) {
                return ['ideal', 1.0];
            }
            if ($recommended !== null && $value <= $recommended) {
                $score = $ideal === null || $recommended === $ideal
                    ? 0.8
                    : 0.75 + (($recommended - $value) / ($recommended - $ideal)) * 0.25;
                return ['recommended', max(0.75, min(1, $score))];
            }
            return ['below_recommended', max(0, 0.75 - (($value - (float) $recommended) / max(1, (float) $recommended)))];
        }
        if ($minimum !== null && $value < $minimum) {
            return ['below_minimum', max(0, ($value / max(1, $minimum)) * 0.5)];
        }
        if ($recommended !== null && $value < $recommended) {
            $start = $minimum ?? 0;
            $score = 0.5 + (($value - $start) / max(1, $recommended - $start)) * 0.25;
            return ['minimum', max(0.5, min(0.75, $score))];
        }
        if ($ideal !== null && $value < $ideal) {
            $start = $recommended ?? $minimum ?? 0;
            $score = 0.75 + (($value - $start) / max(1, $ideal - $start)) * 0.25;
            return ['recommended', max(0.75, min(1, $score))];
        }
        return [$ideal === null ? 'recommended' : 'ideal', 1.0];
    }

    /** @param array<string, array<string, mixed>> $components
     *  @param array<string, mixed> $request
     *  @param array<int, array<string, mixed>> $requirements
     *  @param array<string, mixed> $evaluation
     *  @param array<string, mixed> $compatibility
     *  @return array<string, float>
     */
    private function scoreBuild(
        array $components,
        float $total,
        array $request,
        array $requirements,
        array $evaluation,
        array $compatibility
    ): array {
        $weights = $this->categoryWeights($components, $request, $requirements);
        $workload = 0.0;
        $value = 0.0;
        $efficiency = 0.0;
        $upgradeability = 0.0;
        foreach ($components as $field => $product) {
            $weight = $weights[$field] ?? 0;
            $productWorkload = 0.0;
            foreach ($request['workloads'] as $code => $workloadWeight) {
                $productWorkload += (float) ($product['workload_scores'][$code]
                    ?? $product['overall_score'] ?? 0) * $workloadWeight;
            }
            $workload += $productWorkload * $weight;
            $value += (float) ($product['value_score'] ?? 0) * $weight;
            $efficiency += (float) ($product['efficiency_score'] ?? 0) * $weight;
            $upgradeability += (float) ($product['upgradeability_score'] ?? 0) * $weight;
        }
        $performance = ($workload * 0.55) + ((float) $evaluation['score'] * 0.45);
        $balance = $this->balanceScore($components);
        $target = (float) $request['target_budget_lkr'];
        $maximum = (float) $request['max_budget_lkr'];
        if ($total <= $target) {
            $budgetFit = 100 - (($target - $total) / max(1, $target)) * 35;
        } elseif ($total <= $maximum) {
            $budgetFit = 96 - (($total - $target) / max(1, $maximum - $target)) * 8;
        } else {
            $budgetFit = max(0, 80 - (($total - $maximum) / max(1, $maximum)) * 100);
        }
        $priorityScore = ($performance * $request['priorities']['performance'])
            + ($value * $request['priorities']['value'])
            + ($efficiency * $request['priorities']['efficiency'])
            + ($upgradeability * $request['priorities']['upgradeability']);
        $composite = ($priorityScore * 0.80) + ($balance * 0.10) + ($budgetFit * 0.10);
        $composite -= (int) $compatibility['summary']['warnings'] * 0.5;
        return [
            'composite' => round(max(0, min(100, $composite)), 3),
            'performance' => round(max(0, min(100, $performance)), 3),
            'workload_suitability' => round(max(0, min(100, $workload)), 3),
            'requirement_attainment' => round((float) $evaluation['score'], 3),
            'value' => round(max(0, min(100, $value)), 3),
            'efficiency' => round(max(0, min(100, $efficiency)), 3),
            'upgradeability' => round(max(0, min(100, $upgradeability)), 3),
            'balance' => round($balance, 3),
            'budget_fit' => round(max(0, min(100, $budgetFit)), 3),
        ];
    }

    /** @param array<string, array<string, mixed>> $components
     *  @param array<string, mixed> $request
     *  @param array<int, array<string, mixed>> $requirements
     *  @return array<string, float>
     */
    private function categoryWeights(array $components, array $request, array $requirements): array
    {
        $weights = [];
        foreach ($components as $field => $_product) {
            $weights[$field] = (self::BASE_CATEGORY_WEIGHTS[$field] ?? 0.05) * 0.35;
        }
        foreach ($requirements as $requirement) {
            $field = self::CATEGORY_FIELDS[$requirement['category_slug']] ?? null;
            if ($field !== null && isset($components[$field])) {
                $weights[$field] += (float) $requirement['weight']
                    * (float) ($request['workloads'][$requirement['workload_code']] ?? 0);
            }
        }
        $total = array_sum($weights);
        foreach ($weights as $field => $weight) {
            $weights[$field] = $weight / max(0.0001, $total);
        }
        return $weights;
    }

    /** @param array<string, array<string, mixed>> $components */
    private function balanceScore(array $components): float
    {
        $scores = array_values(array_filter(array_map(
            static fn (array $product): ?float => $product['overall_score'] === null
                ? null : (float) $product['overall_score'],
            $components
        ), static fn (?float $score): bool => $score !== null));
        if ($scores === []) {
            return 50;
        }
        $spread = max($scores) - min($scores);
        $score = 100 - ($spread * 0.45);
        if (isset($components['processor'], $components['graphics_card'])) {
            $difference = abs(
                (float) $components['processor']['overall_score']
                - (float) $components['graphics_card']['overall_score']
            );
            $score -= $difference * 0.25;
        }
        return max(0, min(100, $score));
    }

    /** @param array<int, array<string, mixed>> $within
     *  @param array<int, array<string, mixed>> $stretch
     *  @param array<int, array<string, mixed>> $nearest
     *  @return array<int, array{build: array<string, mixed>, tier: string}>
     */
    private function selectResults(array $within, array $stretch, array $nearest, int $limit): array
    {
        $selected = [];
        if ($within !== []) {
            $bestWithin = array_shift($within);
            $selected[] = ['build' => $bestWithin, 'tier' => 'within_target'];
            $includeStretch = $stretch !== [] && $this->isWorthStretch($bestWithin, $stretch[0]);
            if (
                $stretch !== [] && count($selected) < $limit
                && $includeStretch
            ) {
                $selected[] = ['build' => array_shift($stretch), 'tier' => 'stretch'];
            }
            $remainder = [];
            foreach ($within as $build) {
                $remainder[] = ['build' => $build, 'tier' => 'within_target'];
            }
            if ($includeStretch) {
                foreach ($stretch as $build) {
                    $remainder[] = ['build' => $build, 'tier' => 'stretch'];
                }
            }
            usort($remainder, static fn (array $left, array $right): int =>
                $right['build']['scores']['composite'] <=> $left['build']['scores']['composite']
            );
            $selected = [...$selected, ...array_slice($remainder, 0, $limit - count($selected))];
        } elseif ($stretch !== []) {
            foreach (array_slice($stretch, 0, $limit) as $build) {
                $selected[] = ['build' => $build, 'tier' => 'stretch'];
            }
        } else {
            foreach (array_slice($nearest, 0, $limit) as $build) {
                $selected[] = ['build' => $build, 'tier' => 'nearest_available'];
            }
        }
        return $selected;
    }

    /** @param array<string, mixed> $within
     *  @param array<string, mixed> $stretch
     */
    private function isWorthStretch(array $within, array $stretch): bool
    {
        return $stretch['scores']['performance'] >= $within['scores']['performance'] + 2
            || $stretch['scores']['composite'] >= $within['scores']['composite'] + 1
            || $stretch['scores']['requirement_attainment']
                >= $within['scores']['requirement_attainment'] + 5
            || $stretch['scores']['upgradeability']
                >= $within['scores']['upgradeability'] + 5;
    }

    /** @param array<string, mixed> $build
     *  @param array<string, mixed> $request
     *  @param array<string, array<string, mixed>> $profiles
     *  @param array<string, mixed>|null $bestWithin
     *  @return array<string, mixed>
     */
    private function presentBuild(
        array $build,
        string $tier,
        int $rank,
        array $request,
        array $profiles,
        ?array $bestWithin
    ): array {
        $total = (float) $build['total'];
        $target = (float) $request['target_budget_lkr'];
        $delta = $total - $target;
        $label = match ($tier) {
            'within_target' => $rank === 0 ? 'Best balanced build within target' : 'Alternative within target',
            'stretch' => $bestWithin === null ? 'Closest worthwhile stretch build' : 'Worth considering above target',
            default => 'Nearest complete build currently available',
        };
        $workloadNames = array_map(
            static fn (string $code): string => (string) ($profiles[$code]['name'] ?? str_replace('_', ' ', $code)),
            array_keys($request['workloads'])
        );
        $why = [
            sprintf('Optimized for %s.', implode(' and ', $workloadNames)),
            sprintf(
                'Scores %.1f for workload performance and %.1f for value.',
                $build['scores']['performance'],
                $build['scores']['value']
            ),
        ];
        if ($delta <= 0) {
            $why[] = sprintf('Keeps LKR %s of the target budget unspent.', number_format(abs($delta), 0));
        } else {
            $why[] = sprintf('Costs LKR %s above the target budget.', number_format($delta, 0));
        }
        if ($tier === 'stretch' && $bestWithin !== null) {
            $gain = $build['scores']['performance'] - $bestWithin['scores']['performance'];
            $extra = $total - $bestWithin['total'];
            $why[] = sprintf(
                'Compared with the best within-target build, LKR %s extra changes the performance score by %+.1f points.',
                number_format($extra, 0),
                $gain
            );
        }

        $tradeOffs = [];
        foreach ($build['compatibility']['checks'] as $check) {
            if ($check['status'] === 'warning') {
                $tradeOffs[] = $check['message'];
            }
        }
        foreach ($build['requirements']['details'] as $detail) {
            if (in_array($detail['status'], ['minimum', 'below_recommended', 'unavailable'], true)) {
                $tradeOffs[] = sprintf(
                    '%s is at %s level for %s.',
                    $this->humanCode((string) $detail['metric']),
                    str_replace('_', ' ', (string) $detail['status']),
                    $this->humanCode((string) $detail['workload'])
                );
            }
        }
        $qualityStatuses = array_values(array_unique(array_map(
            static fn (array $product): string => (string) ($product['data_quality_status'] ?? 'unreviewed'),
            $build['components']
        )));
        if ($qualityStatuses !== ['verified']) {
            $tradeOffs[] = 'Some component evidence is awaiting independent data review.';
        }

        $components = [];
        foreach ($build['components'] as $field => $product) {
            $components[$field] = [
                'product_id' => (int) $product['product_id'],
                'listing_id' => (int) $product['listing_id'],
                'category_slug' => (string) $product['category_slug'],
                'name' => (string) $product['name'],
                'model' => (string) $product['model'],
                'brand' => (string) $product['brand'],
                'price_lkr' => round((float) $product['price_lkr'], 2),
                'shop_id' => (int) $product['shop_id'],
                'shop_name' => (string) $product['shop_name'],
                'available_quantity' => (int) $product['available_quantity'],
                'offers' => array_values($product['offers'] ?? []),
                'performance_tier' => $product['performance_tier'],
                'overall_score' => $product['overall_score'],
                'value_score' => $product['value_score'],
                'data_quality_status' => $product['data_quality_status'],
            ];
        }
        return [
            'rank' => $rank + 1,
            'label' => $label,
            'budget_tier' => $tier,
            'total_price_lkr' => round($total, 2),
            'target_difference_lkr' => round($delta, 2),
            'target_difference_percent' => round(($delta / max(1, $target)) * 100, 2),
            'scores' => $build['scores'],
            'components' => $components,
            'compatibility' => [
                'status' => $build['compatibility']['overall_status'],
                'rule_set_version' => $build['compatibility']['rule_set_version'],
                'passed' => $build['compatibility']['summary']['passed'],
                'warnings' => $build['compatibility']['summary']['warnings'],
            ],
            'requirements' => [
                'attainment_score' => $build['requirements']['score'],
                'evaluations' => $build['requirements']['details'],
            ],
            'why_recommended' => $why,
            'trade_offs' => array_values(array_unique($tradeOffs)),
        ];
    }

    /** @param array<int, array<string, mixed>> $builds */
    private function sortByScore(array &$builds): void
    {
        usort($builds, static fn (array $left, array $right): int =>
            ($right['scores']['composite'] <=> $left['scores']['composite'])
            ?: ($left['total'] <=> $right['total'])
        );
    }

    /** @param array<string, array<string, mixed>> $components */
    private function signature(array $components): string
    {
        ksort($components);
        return implode('-', array_map(
            static fn (array $product): string => (string) $product['product_id'],
            $components
        ));
    }

    /** @param array<string, mixed> $request
     *  @return array<string, mixed>
     */
    private function emptyResult(
        array $request,
        int $generated,
        string $notice,
        array $conflicts = []
    ): array
    {
        return [
            'optimizer_version' => self::VERSION,
            'compatibility_rule_version' => PcCompatibilityEngine::RULE_SET_VERSION,
            'outcome_status' => 'no_solution',
            'budget_analysis' => [
                'target_budget_lkr' => $request['target_budget_lkr'],
                'max_budget_lkr' => $request['max_budget_lkr'],
                'minimum_viable_budget_lkr' => null,
                'within_target_count' => 0,
                'stretch_count' => 0,
                'over_maximum_count' => 0,
                'shortfall_lkr' => null,
            ],
            'search_summary' => [
                'strategy' => 'compatibility_pruned_beam_search',
                'generated_combinations' => $generated,
                'compatible_builds' => 0,
                'returned_builds' => 0,
            ],
            'recommendations' => [],
            'notice' => $notice,
            'constraint_conflicts' => $conflicts,
            'scope' => [
                'prices' => 'Current best approved in-stock seller offers; delivery and operating-system costs are excluded.',
                'data_quality' => 'Demonstration component evidence remains marked needs_review until independently verified.',
            ],
        ];
    }

    private function humanCode(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    /** @param array<string, mixed> $request
     *  @param array<int, array<string, mixed>> $requirements
     *  @return array<int, array<string, mixed>>
     */
    private function preferenceConflicts(array $request, array $requirements): array
    {
        $caps = [
            'memory:capacity_gb' => (int) ($request['preferences']['maximum_memory_gb'] ?? 0),
            'storage:capacity_gb' => (int) ($request['preferences']['maximum_storage_gb'] ?? 0),
            'graphics_card:vram_gb' => (int) ($request['preferences']['maximum_vram_gb'] ?? 0),
        ];
        $conflicts = [];
        foreach ($requirements as $requirement) {
            $weight = (float) ($request['workloads'][$requirement['workload_code']] ?? 0);
            $field = self::CATEGORY_FIELDS[$requirement['category_slug']] ?? null;
            $key = $field . ':' . (string) $requirement['metric_code'];
            $maximum = $caps[$key] ?? 0;
            $minimum = (float) ($requirement['minimum'] ?? 0);
            if ($weight <= 0 || !$requirement['is_hard'] || $maximum <= 0 || $minimum <= $maximum) {
                continue;
            }
            $conflicts[] = [
                'workload' => (string) $requirement['workload_code'],
                'component' => $field,
                'metric' => (string) $requirement['metric_code'],
                'requested_maximum' => $maximum,
                'required_minimum' => $minimum,
            ];
        }
        return $conflicts;
    }

    /** @param array<int, array<string, mixed>> $conflicts */
    private function conflictNotice(array $conflicts): string
    {
        $conflict = $conflicts[0];
        $unit = $conflict['metric'] === 'capacity_gb' || $conflict['metric'] === 'vram_gb'
            ? ' GB' : '';
        return sprintf(
            'Your maximum of %s%s for %s conflicts with the %s workload, which requires at least %s%s.',
            number_format((float) $conflict['requested_maximum'], 0),
            $unit,
            $this->humanCode((string) $conflict['component']),
            $this->humanCode((string) $conflict['workload']),
            number_format((float) $conflict['required_minimum'], 0),
            $unit
        );
    }

    /** @param array<string, mixed> $request */
    private function missingCandidateNotice(string $field, array $request): string
    {
        $preferences = (array) ($request['preferences'] ?? []);
        if ($field === 'memory') {
            return $this->capacityCandidateNotice(
                'RAM',
                (int) ($preferences['minimum_memory_gb'] ?? 0),
                (int) ($preferences['maximum_memory_gb'] ?? 0)
            );
        }
        if ($field === 'processor') {
            $label = trim((string) ($preferences['processor_model'] ?? ''));
            if ($label === '') {
                $label = $this->processorFamilyLabel((string) ($preferences['processor_family'] ?? ''));
            }
            if ($label !== '') {
                return "No approved in-stock {$label} processor is available in the current catalogue.";
            }
        }
        if ($field === 'graphics_card') {
            $model = trim((string) ($preferences['gpu_model'] ?? ''));
            if ($model !== '') {
                return "No approved in-stock graphics card matches {$model}.";
            }
            $minimum = (int) ($preferences['minimum_vram_gb'] ?? 0);
            $maximum = (int) ($preferences['maximum_vram_gb'] ?? 0);
            if ($minimum > 0 && $maximum === $minimum) {
                return "No approved in-stock graphics card has exactly {$minimum} GB VRAM.";
            }
            if ($minimum > 0) {
                return "No approved in-stock graphics card has at least {$minimum} GB VRAM.";
            }
            if ($maximum > 0) {
                return "No approved in-stock graphics card has at most {$maximum} GB VRAM.";
            }
        }
        if ($field === 'storage') {
            $minimum = (int) ($preferences['minimum_storage_gb'] ?? 0);
            $maximum = (int) ($preferences['maximum_storage_gb'] ?? 0);
            $type = (string) ($preferences['storage_type'] ?? 'any');
            if ($minimum > 0 || $maximum > 0) {
                $notice = $this->capacityCandidateNotice('storage', $minimum, $maximum);
                if ($type !== 'any') {
                    return rtrim($notice, '.') . ' of the requested ' . strtoupper(str_replace('_', ' ', $type)) . ' type.';
                }
                return $notice;
            }
            if ($type !== 'any') {
                return 'No approved in-stock ' . strtoupper(str_replace('_', ' ', $type))
                    . ' storage candidate is available.';
            }
        }
        return "No in-stock {$this->humanCode($field)} candidate is available.";
    }

    private function capacityCandidateNotice(string $label, int $minimum, int $maximum): string
    {
        if ($minimum > 0 && $maximum === $minimum) {
            return "No approved in-stock {$label} candidate has exactly {$minimum} GB.";
        }
        if ($minimum > 0) {
            return "No approved in-stock {$label} candidate has at least {$minimum} GB.";
        }
        if ($maximum > 0) {
            return "No approved in-stock {$label} candidate has at most {$maximum} GB.";
        }
        return "No approved in-stock {$label} candidate is available.";
    }

    private function processorFamilyLabel(string $family): string
    {
        if (preg_match('/^intel_core_i([3579])$/', $family, $match) === 1) {
            return 'Intel Core i' . $match[1];
        }
        if (preg_match('/^amd_ryzen_([3579])$/', $family, $match) === 1) {
            return 'AMD Ryzen ' . $match[1];
        }
        return '';
    }
}
