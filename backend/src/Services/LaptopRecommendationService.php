<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Contracts\LaptopRankingClient;
use Hexbay\Repositories\LaptopRecommendationRepository;
use Hexbay\Validation\LaptopRecommendationValidator;

final class LaptopRecommendationService
{
    public function __construct(
        private readonly LaptopRecommendationRepository $repository,
        private readonly LaptopRankingClient $ranker
    ) {
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function recommend(array $input): array
    {
        $validated = LaptopRecommendationValidator::request($input);
        $candidates = $this->repository->eligibleCandidates(500);
        if ($candidates === []) {
            $emptyResponse = [
                'algorithm_version' => null,
                'candidate_count' => 0,
                'eligible_candidate_count' => 0,
                'recommendations' => [],
                'gaming_capable_alternative_count' => 0,
                'gaming_capable_alternatives' => [],
                'filtered_out_count' => 0,
                'relaxation_suggestions' => [],
                'notice' => 'No approved in-stock laptops with complete specifications are available yet.',
            ];
            $this->repository->log(
                [
                    'requirements' => $validated['requirements'],
                    'candidate_count' => 0,
                ],
                ['product_ids' => [], 'scores' => []],
                'catalog-empty-v1'
            );
            return $emptyResponse;
        }

        $rankerCandidates = array_map(
            static fn (array $candidate): array => [
                'product_id' => $candidate['product_id'],
                'listing_id' => $candidate['listing_id'],
                'name' => $candidate['name'],
                'brand' => $candidate['brand'],
                'price_lkr' => $candidate['price_lkr'],
                'ram_gb' => $candidate['ram_gb'],
                'storage_gb' => $candidate['storage_gb'],
                'cpu' => $candidate['cpu'],
                'gpu' => $candidate['gpu'],
                'screen_size_inches' => $candidate['screen_size_inches'],
                'tags' => $candidate['tags'],
                'rating_average' => $candidate['rating_average'],
                'rating_count' => $candidate['rating_count'],
                'vendor_rating' => $candidate['vendor_rating'],
                'stock_quantity' => $candidate['stock_quantity'],
                'eligible' => true,
            ],
            $candidates
        );
        $ranked = $this->ranker->rank([
            'requirements' => $validated['requirements'],
            'candidates' => $rankerCandidates,
            'limit' => $validated['limit'],
        ]);

        $authoritative = [];
        foreach ($candidates as $candidate) {
            $authoritative[(int) $candidate['product_id']] = $candidate;
        }
        $recommendations = self::revalidateRankings(
            is_array($ranked['recommendations'] ?? null)
                ? $ranked['recommendations']
                : [],
            $authoritative,
            $validated['limit']
        );
        $gamingCapableAlternatives = self::revalidateRankings(
            is_array($ranked['gaming_capable_alternatives'] ?? null)
                ? $ranked['gaming_capable_alternatives']
                : [],
            $authoritative,
            3
        );
        $algorithmVersion = (string) (
            $ranked['algorithm_version'] ?? 'unknown'
        );
        $response = [
            'algorithm_version' => $algorithmVersion,
            'candidate_count' => count($candidates),
            'eligible_candidate_count' =>
                (int) ($ranked['eligible_candidate_count'] ?? count($recommendations)),
            'recommendations' => $recommendations,
            'gaming_capable_alternative_count' =>
                (int) (
                    $ranked['gaming_capable_alternative_count']
                    ?? count($gamingCapableAlternatives)
                ),
            'gaming_capable_alternatives' => $gamingCapableAlternatives,
            'filtered_out_count' => is_array($ranked['filtered_out'] ?? null)
                ? count($ranked['filtered_out'])
                : 0,
            'filter_summary' => is_array($ranked['filter_summary'] ?? null)
                ? $ranked['filter_summary']
                : [],
            'relaxation_suggestions' =>
                is_array($ranked['relaxation_suggestions'] ?? null)
                    ? array_values($ranked['relaxation_suggestions'])
                    : [],
            'authority' => [
                'catalogue' => 'Hexbay PHP/MySQL',
                'ranking' => 'Hexbay Flask intelligence service',
            ],
        ];

        $this->repository->log(
            [
                'requirements' => $validated['requirements'],
                'candidate_count' => count($candidates),
            ],
            [
                'product_ids' => array_column($recommendations, 'product_id'),
                'scores' => array_column($recommendations, 'score'),
                'gaming_capable_alternative_product_ids' =>
                    array_column($gamingCapableAlternatives, 'product_id'),
            ],
            $algorithmVersion
        );
        return $response;
    }

    /**
     * @param array<int, mixed> $rankings
     * @param array<int, array<string, mixed>> $authoritative
     * @return array<int, array<string, mixed>>
     */
    public static function revalidateRankings(
        array $rankings,
        array $authoritative,
        int $limit
    ): array {
        $results = [];
        $seen = [];
        foreach ($rankings as $ranking) {
            if (!is_array($ranking)) {
                continue;
            }
            $productId = (int) ($ranking['product_id'] ?? 0);
            if (
                $productId < 1
                || isset($seen[$productId])
                || !isset($authoritative[$productId])
            ) {
                continue;
            }
            $candidate = $authoritative[$productId];
            if ((int) ($candidate['stock_quantity'] ?? 0) < 1) {
                continue;
            }
            $seen[$productId] = true;
            $score = (float) ($ranking['score'] ?? 0);
            if ($score > 1) {
                $score /= 100;
            }
            $results[] = [
                ...$candidate,
                'score' => max(0, min(1, $score)),
                'score_breakdown' => is_array($ranking['score_breakdown'] ?? null)
                    ? $ranking['score_breakdown']
                    : [],
                'reasons' => is_array($ranking['reasons'] ?? null)
                    ? array_values(array_slice($ranking['reasons'], 0, 5))
                    : [],
            ];
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }
}
