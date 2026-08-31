<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Contracts\PeripheralRankingClient;
use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Support\HttpException;

final class PeripheralRecommendationService
{
    private const BUDGET_SHARES = [
        'monitor' => 0.18,
        'keyboard' => 0.03,
        'mouse' => 0.02,
        'headset' => 0.04,
    ];

    public function __construct(
        private readonly PcCompatibilityRepository $products,
        private readonly PeripheralRankingClient $ranker,
        private readonly bool $allowExperimental = false
    ) {
    }

    /** @return array<string, mixed> */
    public function recommendSetup(
        string $scope,
        string $workload,
        float $targetBudget,
        bool $includeHeadset = false,
        array $requestedCategories = []
    ): array {
        $categories = $requestedCategories !== []
            ? array_values(array_unique($requestedCategories))
            : match ($scope) {
                'pc_monitor' => ['monitor'],
                'complete_setup' => ['monitor', 'keyboard', 'mouse'],
                default => [],
            };
        if ($includeHeadset && $scope !== 'pc_only' && !in_array('headset', $categories, true)) {
            $categories[] = 'headset';
        }
        if ($categories === []) {
            return $this->emptySetup($scope);
        }

        $catalogue = $this->products->candidatesForCategory('accessories', [], 200);
        $offers = $this->products->offersByProductIds(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $catalogue
        ));
        $selected = [];
        $notices = [];
        $algorithmVersions = [];
        foreach ($categories as $category) {
            $categoryCandidates = array_values(array_filter(
                $catalogue,
                static fn (array $item): bool =>
                    ($item['specifications']['accessory_type'] ?? null) === $category
            ));
            if ($categoryCandidates === []) {
                $notices[] = "No active in-stock {$category} candidate is available.";
                continue;
            }
            $payloadCandidates = [];
            $byIdentity = [];
            foreach ($categoryCandidates as $candidate) {
                $productId = (int) $candidate['product_id'];
                $identity = 'product:' . $productId;
                $candidateOffers = array_values($offers[$productId] ?? []);
                if ($candidateOffers === []) {
                    continue;
                }
                $record = [
                    'source_record_id' => (string) $productId,
                    'identity_key' => $identity,
                    'accessory_type' => $category,
                    'raw_name' => (string) $candidate['name'],
                    'brand' => (string) $candidate['brand'],
                    'model' => (string) $candidate['model'],
                    'source_variant_count' => count($candidateOffers),
                    'completeness_score' => (float) (
                        $candidate['data_completeness_score']
                        ?? ($candidate['specification_completeness'] === 'complete' ? 100 : 65)
                    ),
                    'review_status' => (string) ($candidate['data_quality_status'] ?? 'needs_review'),
                    'recommendation_eligible' =>
                        ($candidate['data_quality_status'] ?? null) === 'verified',
                    ...$candidate['specifications'],
                ];
                $payloadCandidates[] = $record;
                $candidate['offers'] = $candidateOffers;
                $byIdentity[$identity] = $candidate;
            }
            if ($payloadCandidates === []) {
                $notices[] = "No buyable {$category} offer is available.";
                continue;
            }
            $profile = $this->profileFor($category, $workload);
            try {
                $ranked = $this->ranker->rank([
                    'category' => $category,
                    'profile' => $profile,
                    'candidates' => $payloadCandidates,
                    'limit' => min(20, count($payloadCandidates)),
                    'allow_unverified' => $this->allowExperimental,
                ]);
            } catch (HttpException $exception) {
                $notices[] = "{$category}: {$exception->getMessage()}";
                continue;
            }
            $algorithmVersions[] = (string) ($ranked['algorithm_version'] ?? 'unknown');
            $cap = $targetBudget * self::BUDGET_SHARES[$category];
            $choice = $this->selectLiveOfferCandidate(
                (array) ($ranked['recommendations'] ?? []),
                $byIdentity,
                $cap,
                $category,
                $profile
            );
            if ($choice === null) {
                $notices[] = $this->allowExperimental
                    ? "The model returned no usable {$category} result."
                    : "No verified {$category} is recommendation-eligible yet.";
                continue;
            }
            $selected[$category] = $choice;
        }

        $total = array_sum(array_map(
            static fn (array $item): float => (float) $item['price_lkr'],
            $selected
        ));
        return [
            'setup_scope' => $scope,
            'include_headset' => $includeHeadset,
            'peripherals' => $selected,
            'peripheral_total_price_lkr' => round($total, 2),
            'requested_categories' => $categories,
            'selected_category_count' => count($selected),
            'complete' => count($selected) === count($categories),
            'algorithm_version' => implode(', ', array_values(array_unique($algorithmVersions))),
            'data_mode' => $this->allowExperimental ? 'demonstration' : 'verified_production',
            'notices' => $notices,
            'budget_policy' => 'Purpose-aware product fit first; live seller price is applied afterwards within category budget shares.',
        ];
    }

    /** @param array<int, array<string, mixed>> $ranked
     *  @param array<string, array<string, mixed>> $candidates
     *  @return array<string, mixed>|null
     */
    private function selectLiveOfferCandidate(
        array $ranked,
        array $candidates,
        float $budgetCap,
        string $category,
        string $profile
    ): ?array {
        $best = null;
        foreach ($ranked as $recommendation) {
            $identity = (string) ($recommendation['identity_key'] ?? '');
            $candidate = $candidates[$identity] ?? null;
            if ($candidate === null || ($candidate['offers'] ?? []) === []) {
                continue;
            }
            $offer = $candidate['offers'][0];
            $price = (float) $offer['price_lkr'];
            $modelScore = max(0, min(100, (float) ($recommendation['score'] ?? 0)));
            $budgetFit = $price <= $budgetCap
                ? 100 - (($price / max(1, $budgetCap)) * 20)
                : max(0, 100 - ((($price - $budgetCap) / max(1, $budgetCap)) * 100));
            $marketScore = ($modelScore * 0.80) + ($budgetFit * 0.20);
            if ($best !== null && $marketScore <= $best['_market_score']) {
                continue;
            }
            $best = [
                'product_id' => (int) $candidate['product_id'],
                'listing_id' => (int) $offer['listing_id'],
                'category' => $category,
                'name' => (string) $candidate['name'],
                'model' => (string) $candidate['model'],
                'brand' => (string) $candidate['brand'],
                'price_lkr' => round($price, 2),
                'shop_id' => (int) $offer['shop_id'],
                'shop_name' => (string) $offer['shop_name'],
                'available_quantity' => (int) $offer['available_quantity'],
                'offers' => array_values($candidate['offers']),
                'profile' => $profile,
                'fit_score' => round($modelScore, 2),
                'budget_cap_lkr' => round($budgetCap, 2),
                'data_quality_status' => (string) ($candidate['data_quality_status'] ?? 'needs_review'),
                'reasons' => array_values((array) ($recommendation['reasons'] ?? [])),
                '_market_score' => $marketScore,
            ];
        }
        if ($best !== null) {
            unset($best['_market_score']);
        }
        return $best;
    }

    private function profileFor(string $category, string $workload): string
    {
        $gaming = str_starts_with($workload, 'gaming_') || $workload === 'live_streaming';
        return match ($category) {
            'monitor' => $gaming ? 'gaming' : (in_array($workload, [
                'graphic_design', 'video_editing', 'three_d_rendering', 'cad_engineering',
            ], true) ? 'visual_creative' : ($workload === 'balanced_general' ? 'general' : 'productivity')),
            'keyboard' => $gaming ? 'gaming' : 'productivity',
            'mouse' => $gaming ? 'gaming' : 'productivity',
            'headset' => $gaming ? 'gaming' : ($workload === 'music_production' ? 'music_creation' : 'communication'),
            default => 'general',
        };
    }

    /** @return array<string, mixed> */
    private function emptySetup(string $scope): array
    {
        return [
            'setup_scope' => $scope,
            'include_headset' => false,
            'peripherals' => [],
            'peripheral_total_price_lkr' => 0.0,
            'requested_categories' => [],
            'selected_category_count' => 0,
            'complete' => true,
            'algorithm_version' => null,
            'data_mode' => 'not_applicable',
            'notices' => [],
            'budget_policy' => null,
        ];
    }
}
