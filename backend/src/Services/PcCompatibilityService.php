<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\PcCompatibilityValidator;

final class PcCompatibilityService
{
    public function __construct(
        private readonly PcCompatibilityRepository $repository,
        private readonly PcCompatibilityEngine $engine
    ) {
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function validate(array $input, ?int $userId = null): array
    {
        $validated = PcCompatibilityValidator::validationRequest($input);
        $components = $this->loadComponents($validated['components']);
        $result = $this->engine->validate($components, $validated['mode']);
        $publicId = $this->uuid();
        $this->repository->logValidation(
            $publicId,
            $validated['mode'],
            array_values($validated['components']),
            $result,
            $userId
        );
        return [
            'validation_id' => $publicId,
            ...$result,
            'components' => $this->componentSummaries($components),
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function alternatives(array $input): array
    {
        $validated = PcCompatibilityValidator::alternativesRequest($input);
        $target = $validated['target_component'];
        $baseSelection = $validated['components'];
        $currentTargetId = $baseSelection[$target] ?? null;
        unset($baseSelection[$target]);
        $baseComponents = $baseSelection === []
            ? []
            : $this->loadComponents($baseSelection);
        $candidates = $this->repository->candidatesForCategory(
            PcCompatibilityValidator::COMPONENT_CATEGORIES[$target],
            $currentTargetId === null ? [] : [$currentTargetId],
            120
        );
        $alternatives = [];
        foreach ($candidates as $candidate) {
            $candidateBuild = [
                ...$baseComponents,
                $target => $candidate,
            ];
            $result = $this->engine->validate($candidateBuild, 'partial');
            if (!in_array($result['overall_status'], ['compatible', 'warning'], true)) {
                continue;
            }
            $alternatives[] = [
                'component' => $this->componentSummary($candidate),
                'compatibility_status' => $result['overall_status'],
                'warnings' => array_values(array_map(
                    static fn (array $check): string => (string) $check['message'],
                    array_filter(
                        $result['checks'],
                        static fn (array $check): bool => $check['status'] === 'warning'
                    )
                )),
                'passed_rule_count' => $result['summary']['passed'],
            ];
        }
        usort($alternatives, static function (array $left, array $right): int {
            $statusOrder = ['compatible' => 0, 'warning' => 1];
            return ($statusOrder[$left['compatibility_status']] <=> $statusOrder[$right['compatibility_status']])
                ?: (($right['component']['value_score'] ?? 0) <=> ($left['component']['value_score'] ?? 0))
                ?: (($left['component']['price_lkr'] ?? PHP_FLOAT_MAX) <=> ($right['component']['price_lkr'] ?? PHP_FLOAT_MAX));
        });
        $alternatives = array_slice($alternatives, 0, $validated['limit']);
        return [
            'rule_set_version' => PcCompatibilityEngine::RULE_SET_VERSION,
            'target_component' => $target,
            'current_product_id' => $currentTargetId,
            'alternative_count' => count($alternatives),
            'alternatives' => $alternatives,
            'notice' => $alternatives === []
                ? 'No guaranteed compatible in-stock alternative is available for the current selection.'
                : null,
        ];
    }

    /** @param array<string, int> $selection
     *  @return array<string, array<string, mixed>>
     */
    private function loadComponents(array $selection): array
    {
        $products = $this->repository->productsByIds(array_values($selection));
        $components = [];
        foreach ($selection as $field => $productId) {
            $product = $products[$productId] ?? null;
            if ($product === null) {
                throw new HttpException(404, "Selected {$field} product was not found.");
            }
            $expectedCategory = PcCompatibilityValidator::COMPONENT_CATEGORIES[$field];
            if ($product['category_slug'] !== $expectedCategory) {
                throw new HttpException(422, 'A product was assigned to the wrong component group.', [
                    'components' => [
                        $field => sprintf(
                            'Choose a product from the %s category.',
                            str_replace('-', ' ', $expectedCategory)
                        ),
                    ],
                ]);
            }
            $components[$field] = $product;
        }
        return $components;
    }

    /** @param array<string, array<string, mixed>> $components
     *  @return array<string, array<string, mixed>>
     */
    private function componentSummaries(array $components): array
    {
        $summaries = [];
        foreach ($components as $field => $component) {
            $summaries[$field] = $this->componentSummary($component);
        }
        return $summaries;
    }

    /** @param array<string, mixed> $component
     *  @return array<string, mixed>
     */
    private function componentSummary(array $component): array
    {
        return [
            'product_id' => $component['product_id'],
            'listing_id' => $component['listing_id'],
            'category_slug' => $component['category_slug'],
            'name' => $component['name'],
            'model' => $component['model'],
            'brand' => $component['brand'],
            'price_lkr' => $component['price_lkr'],
            'shop_id' => $component['shop_id'],
            'shop_name' => $component['shop_name'],
            'available_quantity' => $component['available_quantity'],
            'performance_tier' => $component['performance_tier'],
            'overall_score' => $component['overall_score'],
            'value_score' => $component['value_score'],
            'data_quality_status' => $component['data_quality_status'],
        ];
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

