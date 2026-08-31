<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\PcBuildRecommendationRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\PcBuildRecommendationValidator;
use Hexbay\Validation\PcCompatibilityValidator;

final class PcBuildRecommendationService
{
    public function __construct(
        private readonly PcBuildRecommendationRepository $repository,
        private readonly PcBuildOptimizer $optimizer,
        private readonly ?PeripheralRecommendationService $peripherals = null
    ) {
    }

    /** @return array<string, mixed> */
    public function workloads(): array
    {
        $workloads = $this->repository->workloads();
        return [
            'workloads' => $workloads,
            'count' => count($workloads),
            'default' => 'balanced_general',
            'selection_guidance' => 'Choose the main use case and optionally up to two secondary uses. Technical socket and DDR questions are handled by the compatibility engine.',
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function recommend(array $input, ?int $userId = null): array
    {
        $request = PcBuildRecommendationValidator::request($input);
        $available = array_column($this->repository->workloads(), 'code');
        $unknown = array_values(array_diff(array_keys($request['workloads']), $available));
        if ($unknown !== []) {
            throw new HttpException(422, 'One or more PC use cases are not supported.', [
                'workloads' => array_map(
                    static fn (string $code): string => "Unknown use case: {$code}.",
                    $unknown
                ),
            ]);
        }

        $workloadData = $this->repository->workloadData(array_keys($request['workloads']));
        $catalogue = $this->repository->catalogue(array_keys($request['workloads']));
        if (
            $request['preferences']['dedicated_graphics'] === 'avoid'
            && isset($request['locked_components']['graphics_card'])
        ) {
            throw new HttpException(422, 'PC build preferences conflict.', [
                'preferences' => [
                    'dedicated_graphics' => 'A graphics card is locked while dedicated graphics is set to avoid.',
                ],
            ]);
        }
        $this->validateLockedComponents($request['locked_components'], $catalogue);
        $primaryWorkload = (string) array_key_first($request['workloads']);
        $setup = $this->peripherals?->recommendSetup(
            (string) $request['setup_scope'],
            $primaryWorkload,
            (float) $request['target_budget_lkr'],
            (bool) $request['include_headset'],
            (array) $request['peripheral_categories']
        ) ?? [
            'setup_scope' => $request['setup_scope'],
            'include_headset' => $request['include_headset'],
            'requested_categories' => $request['peripheral_categories'],
            'peripherals' => [],
            'peripheral_total_price_lkr' => 0.0,
            'selected_category_count' => 0,
            'complete' => $request['setup_scope'] === 'pc_only',
            'algorithm_version' => null,
            'data_mode' => 'unavailable',
            'notices' => $request['setup_scope'] === 'pc_only'
                ? [] : ['Peripheral ranking is not configured.'],
            'budget_policy' => null,
        ];
        $optimizerRequest = $request;
        $peripheralTotal = (float) $setup['peripheral_total_price_lkr'];
        if ($peripheralTotal > 0) {
            $optimizerRequest['target_budget_lkr'] = max(
                50000,
                (float) $request['target_budget_lkr'] - $peripheralTotal
            );
            $optimizerRequest['max_budget_lkr'] = max(
                $optimizerRequest['target_budget_lkr'],
                (float) $request['max_budget_lkr'] - $peripheralTotal
            );
        }
        $result = $this->optimizer->recommend(
            $optimizerRequest,
            $catalogue,
            $workloadData
        );
        $result = $this->assembleSetup($result, $request, $setup);
        $publicId = $this->uuid();
        $this->repository->logRecommendation($publicId, $request, $result, $userId);
        return [
            'recommendation_id' => $publicId,
            'request' => $request,
            ...$result,
        ];
    }

    /** @param array<string, mixed> $result
     *  @param array<string, mixed> $request
     *  @param array<string, mixed> $setup
     *  @return array<string, mixed>
     */
    private function assembleSetup(array $result, array $request, array $setup): array
    {
        $target = (float) $request['target_budget_lkr'];
        $maximum = (float) $request['max_budget_lkr'];
        $peripheralTotal = (float) $setup['peripheral_total_price_lkr'];
        $within = 0;
        $stretch = 0;
        $over = 0;
        foreach ($result['recommendations'] as $index => $build) {
            $pcTotal = (float) $build['total_price_lkr'];
            $total = $pcTotal + $peripheralTotal;
            $tier = $total <= $target
                ? 'within_target'
                : ($total <= $maximum ? 'stretch' : 'nearest_available');
            match ($tier) {
                'within_target' => $within++,
                'stretch' => $stretch++,
                default => $over++,
            };
            $build['pc_total_price_lkr'] = round($pcTotal, 2);
            $build['peripheral_total_price_lkr'] = round($peripheralTotal, 2);
            $build['total_price_lkr'] = round($total, 2);
            $build['target_difference_lkr'] = round($total - $target, 2);
            $build['target_difference_percent'] = round((($total - $target) / max(1, $target)) * 100, 2);
            $build['budget_tier'] = $tier;
            $build['peripherals'] = $setup['peripherals'];
            $build['setup_scope'] = $request['setup_scope'];
            $build['setup_complete'] = $setup['complete'];
            $build['setup_data_mode'] = $setup['data_mode'];
            if ($request['setup_scope'] !== 'pc_only') {
                $build['label'] = ($setup['complete'] ? 'Complete setup · ' : 'PC plus partial setup · ')
                    . $build['label'];
                $build['why_recommended'][] = sprintf(
                    '%d purpose-matched peripheral%s add LKR %s using live seller offers.',
                    (int) $setup['selected_category_count'],
                    (int) $setup['selected_category_count'] === 1 ? '' : 's',
                    number_format($peripheralTotal, 0)
                );
                if (!$setup['complete']) {
                    $build['trade_offs'][] = 'The peripheral bundle is incomplete: '
                        . implode(' ', (array) $setup['notices']);
                }
            }
            $result['recommendations'][$index] = $build;
        }
        $minimumPc = $result['budget_analysis']['minimum_viable_budget_lkr'] ?? null;
        $minimum = $minimumPc === null ? null : (float) $minimumPc + $peripheralTotal;
        $result['budget_analysis'] = [
            ...$result['budget_analysis'],
            'target_budget_lkr' => round($target, 2),
            'max_budget_lkr' => round($maximum, 2),
            'minimum_viable_budget_lkr' => $minimum === null ? null : round($minimum, 2),
            'within_target_count' => $within,
            'stretch_count' => $stretch,
            'over_maximum_count' => $over,
            'shortfall_lkr' => $minimum === null ? null : round(max(0, $minimum - $maximum), 2),
            'pc_target_budget_lkr' => round(max(50000, $target - $peripheralTotal), 2),
            'reserved_for_peripherals_lkr' => round($peripheralTotal, 2),
        ];
        if (($result['recommendations'] ?? []) !== []) {
            $result['outcome_status'] = $within > 0
                ? 'recommended' : ($stretch > 0 ? 'stretch_only' : 'nearest_only');
        }
        $result['setup'] = $setup;
        $result['scope']['prices'] = 'Canonical products are ranked once; every active in-stock seller offer is returned for buyer selection. Delivery and operating-system costs are excluded.';
        $result['scope']['peripherals'] = $setup['data_mode'] === 'demonstration'
            ? 'Peripheral results use explicitly labelled local demonstration catalogue records; production mode requires verified records.'
            : 'Peripheral results require verified canonical records and live approved seller offers.';
        return $result;
    }

    /** @param array<string, int> $locked
     *  @param array<string, array<int, array<string, mixed>>> $catalogue
     */
    private function validateLockedComponents(array $locked, array $catalogue): void
    {
        foreach ($locked as $field => $productId) {
            $found = false;
            foreach ($catalogue[$field] ?? [] as $candidate) {
                if ((int) $candidate['product_id'] === $productId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $category = PcCompatibilityValidator::COMPONENT_CATEGORIES[$field];
                throw new HttpException(422, 'A locked PC component cannot be used.', [
                    'locked_components' => [
                        $field => sprintf(
                            'Product %d is not an active, approved, in-stock %s option.',
                            $productId,
                            str_replace('-', ' ', $category)
                        ),
                    ],
                ]);
            }
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4),
            substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)
        );
    }
}
