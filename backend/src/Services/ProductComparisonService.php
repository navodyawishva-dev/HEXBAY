<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Contracts\ProductCatalogueGateway;

final class ProductComparisonService
{
    public function __construct(private readonly ProductCatalogueGateway $catalogue)
    {
    }

    /** @return array<string, mixed>|null */
    public function requestFromQuestion(string $question): ?array
    {
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        $concept = mb_strtolower($question);
        if (
            (str_contains($concept, 'rtx') && str_contains($concept, 'gtx')
                && preg_match('/\b(?:rtx|gtx)\s*\d{3,4}\b/u', $concept) !== 1)
            || (preg_match('/\bddr\s*[345]\b/u', $concept) === 1
                && preg_match_all('/\bddr\s*[345]\b/u', $concept) >= 2)
            || (str_contains($concept, 'ssd') && str_contains($concept, 'hdd'))
            || (str_contains($concept, 'intel') && str_contains($concept, 'amd')
                && preg_match('/\d/u', $concept) !== 1)
            || (str_contains($concept, 'integrated') && str_contains($concept, 'dedicated'))
        ) {
            return null;
        }
        $patterns = [
            '/^\s*compare\s+(.+?)\s+(?:with|against|and|versus|vs\.?)\s+(.+?)\s*\??$/iu',
            '/^\s*(?:which|what)\s+(?:one\s+)?is\s+better\s*[:,]?\s*(.+?)\s+(?:or|versus|vs\.?)\s+(.+?)\s*\??$/iu',
            '/^\s*(.+?)\s+(?:versus|vs\.?)\s+(.+?)\s*\??$/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $question, $match) !== 1) {
                continue;
            }
            $left = $this->cleanQuery((string) $match[1]);
            $right = $this->cleanQuery((string) $match[2]);
            if ($left === '' || $right === '') {
                return null;
            }
            return [
                'left_query' => $left,
                'right_query' => $right,
                'use_case' => $this->useCase($question),
            ];
        }
        if (preg_match('/\bcompare\s+(?:two|2)\s+products?\b/iu', $question) === 1) {
            return [
                'left_query' => '',
                'right_query' => '',
                'use_case' => $this->useCase($question),
            ];
        }
        return null;
    }

    /** @param array<string, mixed> $request
     *  @param array<string, int> $selectedIds
     *  @return array<string, mixed>
     */
    public function compare(array $request, array $selectedIds = []): array
    {
        $leftQuery = trim((string) ($request['left_query'] ?? ''));
        $rightQuery = trim((string) ($request['right_query'] ?? ''));
        $useCase = (string) ($request['use_case'] ?? 'general');
        $safeRequest = [
            'left_query' => mb_substr($leftQuery, 0, 120),
            'right_query' => mb_substr($rightQuery, 0, 120),
            'use_case' => $useCase,
        ];
        if ($leftQuery === '' || $rightQuery === '') {
            return [
                'status' => 'needs_input',
                'message' => 'Tell me the two exact products, for example “PointArc Everyday Mouse vs PointArc Pulse Gaming Mouse”.',
                'request' => $safeRequest,
            ];
        }

        $left = $this->resolve($leftQuery, $selectedIds['left'] ?? null);
        if (($left['status'] ?? '') !== 'resolved') {
            return $this->resolutionResult('left', $left, $safeRequest);
        }
        $right = $this->resolve($rightQuery, $selectedIds['right'] ?? null);
        if (($right['status'] ?? '') !== 'resolved') {
            return $this->resolutionResult('right', $right, $safeRequest, [
                'left' => (int) $left['product']['id'],
            ]);
        }

        $leftProduct = $left['product'];
        $rightProduct = $right['product'];
        if ((int) $leftProduct['id'] === (int) $rightProduct['id']) {
            return [
                'status' => 'same_product',
                'message' => 'Those names resolve to the same catalogue product. Please choose two different products.',
                'request' => $safeRequest,
            ];
        }
        $leftKind = $this->comparisonKind($leftProduct);
        $rightKind = $this->comparisonKind($rightProduct);
        if ($leftKind !== $rightKind) {
            return [
                'status' => 'category_mismatch',
                'message' => sprintf(
                    'I found both products, but one is %s and the other is %s. Choose two products of the same type for a meaningful comparison.',
                    $this->friendlyCode($leftKind),
                    $this->friendlyCode($rightKind)
                ),
                'request' => $safeRequest,
                'products' => [
                    $this->productSummary($leftProduct),
                    $this->productSummary($rightProduct),
                ],
            ];
        }

        return $this->buildComparison($leftProduct, $rightProduct, $leftKind, $useCase);
    }

    /** @return array<string, mixed> */
    private function resolve(string $query, ?int $selectedId): array
    {
        if ($selectedId !== null && $selectedId > 0) {
            $selected = $this->catalogue->product($selectedId);
            return $selected === null
                ? ['status' => 'not_found', 'query' => $query, 'candidates' => []]
                : ['status' => 'resolved', 'product' => $selected];
        }
        $result = $this->catalogue->catalogue([
            'search' => $query,
            'available' => true,
            'per_page' => 12,
            'sort' => 'featured',
        ]);
        $rows = array_values((array) ($result['products'] ?? []));
        if ($rows === []) {
            $tokens = array_values(array_filter(
                explode(' ', $this->normalize($query)),
                static fn (string $token): bool => mb_strlen($token) >= 3
            ));
            usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($tokens as $token) {
                $fallback = $this->catalogue->catalogue([
                    'search' => $token,
                    'available' => true,
                    'per_page' => 12,
                    'sort' => 'featured',
                ]);
                $rows = array_values((array) ($fallback['products'] ?? []));
                if ($rows !== []) {
                    break;
                }
            }
        }
        if ($rows === []) {
            return ['status' => 'not_found', 'query' => $query, 'candidates' => []];
        }
        $scored = [];
        foreach ($rows as $row) {
            $row['_match_score'] = $this->matchScore($query, $row);
            $scored[] = $row;
        }
        usort($scored, static fn (array $a, array $b): int =>
            ($b['_match_score'] <=> $a['_match_score'])
            ?: ((int) $b['available_quantity'] <=> (int) $a['available_quantity'])
        );
        $top = $scored[0];
        $secondScore = isset($scored[1]) ? (float) $scored[1]['_match_score'] : -1;
        if ((float) $top['_match_score'] < 35) {
            return ['status' => 'not_found', 'query' => $query, 'candidates' => []];
        }
        if (
            count($scored) > 1
            && (float) $top['_match_score'] < 98
            && ((float) $top['_match_score'] - $secondScore) < 18
        ) {
            return [
                'status' => 'ambiguous',
                'query' => $query,
                'candidates' => array_map(
                    fn (array $candidate): array => $this->candidateSummary($candidate),
                    array_slice($scored, 0, 4)
                ),
            ];
        }
        $product = $this->catalogue->product((int) $top['id']);
        return $product === null
            ? ['status' => 'not_found', 'query' => $query, 'candidates' => []]
            : ['status' => 'resolved', 'product' => $product];
    }

    /** @param array<string, mixed> $resolution
     *  @param array<string, mixed> $request
     *  @param array<string, int> $selectedIds
     *  @return array<string, mixed>
     */
    private function resolutionResult(
        string $side,
        array $resolution,
        array $request,
        array $selectedIds = []
    ): array {
        $query = (string) ($resolution['query'] ?? $request[$side . '_query'] ?? '');
        if (($resolution['status'] ?? '') === 'ambiguous') {
            return [
                'status' => 'needs_clarification',
                'message' => "I found several matches for “{$query}”. Which one did you mean?",
                'selection_side' => $side,
                'candidates' => $resolution['candidates'],
                'selected_ids' => $selectedIds,
                'request' => $request,
            ];
        }
        return [
            'status' => 'not_found',
            'message' => "I could not find an active in-stock catalogue product matching “{$query}”. Try its full product name or model.",
            'selection_side' => $side,
            'candidates' => [],
            'selected_ids' => $selectedIds,
            'request' => $request,
        ];
    }

    /** @return array<string, mixed> */
    private function buildComparison(
        array $left,
        array $right,
        string $kind,
        string $useCase
    ): array {
        $leftSummary = $this->productSummary($left);
        $rightSummary = $this->productSummary($right);
        $leftSpecs = $this->specificationMap($left);
        $rightSpecs = $this->specificationMap($right);
        $rows = [];
        $advantages = [(int) $left['id'] => 0, (int) $right['id'] => 0];
        foreach (array_values(array_unique([...array_keys($leftSpecs), ...array_keys($rightSpecs)])) as $code) {
            if ($code === 'accessory_type') {
                continue;
            }
            $leftSpec = $leftSpecs[$code] ?? null;
            $rightSpec = $rightSpecs[$code] ?? null;
            if ($leftSpec === null && $rightSpec === null) {
                continue;
            }
            $winnerId = $this->specificationWinner($kind, $code, $useCase, $leftSpec, $rightSpec, $left, $right);
            if ($winnerId !== null) {
                $advantages[$winnerId]++;
            }
            $rows[] = [
                'code' => $code,
                'label' => (string) ($leftSpec['label'] ?? $rightSpec['label'] ?? $this->friendlyCode($code)),
                'left_value' => $leftSpec['display'] ?? 'Not listed',
                'right_value' => $rightSpec['display'] ?? 'Not listed',
                'winner_product_id' => $winnerId,
            ];
        }
        $rows = array_slice($rows, 0, 12);

        $leftPrice = (float) ($leftSummary['price_lkr'] ?? 0);
        $rightPrice = (float) ($rightSummary['price_lkr'] ?? 0);
        $lowerPriceId = $leftPrice > 0 && $rightPrice > 0 && $leftPrice !== $rightPrice
            ? ($leftPrice < $rightPrice ? (int) $left['id'] : (int) $right['id'])
            : null;
        $listedLeaderId = null;
        if ($advantages[(int) $left['id']] !== $advantages[(int) $right['id']]) {
            $listedLeaderId = $advantages[(int) $left['id']] > $advantages[(int) $right['id']]
                ? (int) $left['id'] : (int) $right['id'];
        }
        $byId = [
            (int) $left['id'] => $leftSummary,
            (int) $right['id'] => $rightSummary,
        ];
        $headline = $listedLeaderId === null
            ? 'There is no universal winner from the verified fields.'
            : $byId[$listedLeaderId]['name'] . ' leads in more listed ' . str_replace('_', ' ', $useCase) . ' factors.';
        $guidance = $headline;
        if ($lowerPriceId !== null) {
            $guidance .= ' ' . $byId[$lowerPriceId]['name'] . ' is the lower-priced live option.';
        }
        $guidance .= ' Choose according to the factors that matter most to you, not the winner count alone.';

        return [
            'status' => 'ready',
            'title' => $leftSummary['name'] . ' vs ' . $rightSummary['name'],
            'category' => $this->friendlyCode($kind),
            'use_case' => $useCase,
            'related_search_query' => mb_strtolower($this->friendlyCode($kind)),
            'products' => [$leftSummary, $rightSummary],
            'rows' => $rows,
            'verdict' => [
                'headline' => $headline,
                'guidance' => $guidance,
                'listed_advantage_product_id' => $listedLeaderId,
                'lower_price_product_id' => $lowerPriceId,
                'advantage_counts' => $advantages,
            ],
            'message' => 'I compared the two live catalogue products using their listed specifications, current lowest in-stock offer and your stated use.',
            'limitations' => 'A listed-specification comparison is not a laboratory benchmark. Comfort, build quality and real-world performance may need trusted testing or hands-on evaluation.',
        ];
    }

    /** @return array<string, mixed> */
    private function productSummary(array $product): array
    {
        $offers = array_values((array) ($product['offers'] ?? []));
        $offer = null;
        foreach ($offers as $candidate) {
            if ((int) ($candidate['available_quantity'] ?? 0) > 0) {
                $offer = $candidate;
                break;
            }
        }
        $offer ??= $offers[0] ?? [];
        return [
            'product_id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'model' => (string) $product['model'],
            'brand' => (string) $product['brand_name'],
            'category' => (string) $product['category_name'],
            'category_slug' => (string) $product['category_slug'],
            'price_lkr' => isset($offer['price']) ? (float) $offer['price'] : null,
            'shop_name' => $offer['shop_name'] ?? null,
            'available_quantity' => (int) ($offer['available_quantity'] ?? 0),
            'image_filename' => $offer['image_filename'] ?? null,
            'rating_average' => round((float) ($product['rating_average'] ?? 0), 1),
            'rating_count' => (int) ($product['rating_count'] ?? 0),
            'specification_completeness' => (string) ($product['specification_completeness'] ?? 'incomplete'),
            'pc_component_group' => $this->pcComponentGroup((string) $product['category_slug']),
        ];
    }

    /** @return array<string, mixed> */
    private function candidateSummary(array $candidate): array
    {
        return [
            'product_id' => (int) $candidate['id'],
            'name' => (string) $candidate['name'],
            'model' => (string) $candidate['model'],
            'brand' => (string) $candidate['brand_name'],
            'category' => (string) $candidate['category_name'],
            'price_lkr' => (float) $candidate['starting_price'],
            'available_quantity' => (int) $candidate['available_quantity'],
            'image_filename' => $candidate['image_filename'] ?? null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function specificationMap(array $product): array
    {
        $map = [];
        foreach ((array) ($product['specifications'] ?? []) as $specification) {
            $code = (string) ($specification['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $raw = $specification['specification_value'] ?? null;
            $map[$code] = [
                'label' => (string) ($specification['display_name'] ?? $this->friendlyCode($code)),
                'raw' => $raw,
                'number' => is_numeric($raw) ? (float) $raw : null,
                'display' => $this->displayValue($specification),
            ];
        }
        return $map;
    }

    private function specificationWinner(
        string $kind,
        string $code,
        string $useCase,
        ?array $leftSpec,
        ?array $rightSpec,
        array $left,
        array $right
    ): ?int {
        if ($leftSpec === null || $rightSpec === null) {
            return null;
        }
        $leftNumber = $leftSpec['number'];
        $rightNumber = $rightSpec['number'];
        if ($leftNumber === null || $rightNumber === null || $leftNumber === $rightNumber) {
            return null;
        }
        $higherIsBetter = [
            'mouse' => ['max_dpi', 'programmable_buttons'],
            'monitor' => ['refresh_rate_hz', 'resolution_width_pixels', 'resolution_height_pixels'],
            'headset' => ['frequency_max_hz'],
            'processors' => ['core_count', 'thread_count', 'boost_clock_ghz'],
            'graphics_cards' => ['vram_gb', 'boost_clock_mhz'],
            'memory' => ['capacity_gb', 'speed_mhz'],
            'laptops' => ['ram_gb', 'storage_gb'],
        ];
        $lowerIsBetter = [
            'mouse' => $useCase === 'gaming' ? ['weight_grams'] : [],
            'monitor' => ['response_time_ms'],
            'memory' => ['cas_latency'],
        ];
        if (in_array($code, $higherIsBetter[$kind] ?? [], true)) {
            return $leftNumber > $rightNumber ? (int) $left['id'] : (int) $right['id'];
        }
        if (in_array($code, $lowerIsBetter[$kind] ?? [], true)) {
            return $leftNumber < $rightNumber ? (int) $left['id'] : (int) $right['id'];
        }
        return null;
    }

    private function comparisonKind(array $product): string
    {
        $category = (string) ($product['category_slug'] ?? 'product');
        if ($category !== 'accessories') {
            return $category;
        }
        foreach ((array) ($product['specifications'] ?? []) as $specification) {
            if (($specification['code'] ?? '') === 'accessory_type') {
                return mb_strtolower((string) ($specification['specification_value'] ?? 'accessory'));
            }
        }
        return 'accessory';
    }

    private function matchScore(string $query, array $candidate): float
    {
        $query = $this->normalize($query);
        $name = $this->normalize((string) $candidate['name']);
        $model = $this->normalize((string) $candidate['model']);
        $brand = $this->normalize((string) $candidate['brand_name']);
        if (in_array($query, [$name, $model, trim($brand . ' ' . $model)], true)) {
            return 100;
        }
        $label = trim($brand . ' ' . $name . ' ' . $model);
        if (str_contains($label, $query)) {
            return 88 + min(10, (mb_strlen($query) / max(1, mb_strlen($label))) * 10);
        }
        $queryTokens = array_values(array_unique(explode(' ', $query)));
        $labelTokens = array_values(array_unique(explode(' ', $label)));
        $overlap = count(array_intersect($queryTokens, $labelTokens));
        return ($overlap / max(1, count($queryTokens))) * 80;
    }

    private function displayValue(array $specification): string
    {
        $value = $specification['specification_value'] ?? null;
        $type = (string) ($specification['data_type'] ?? 'text');
        if ($type === 'boolean') {
            return in_array((string) $value, ['1', 'true'], true) ? 'Yes' : 'No';
        }
        if ($type === 'multi_option' && is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = implode(', ', array_map(fn ($item): string => $this->friendlyCode((string) $item), $decoded));
            }
        } elseif ($type === 'option') {
            $value = $this->friendlyCode((string) $value);
        } elseif (is_numeric($value)) {
            $number = (float) $value;
            $value = fmod($number, 1.0) === 0.0
                ? number_format($number, 0)
                : number_format($number, 2);
        }
        $unit = trim((string) ($specification['unit'] ?? ''));
        return trim((string) $value . ($unit !== '' ? ' ' . $unit : ''));
    }

    private function useCase(string $question): string
    {
        $text = mb_strtolower($question);
        return match (true) {
            str_contains($text, 'gaming'), str_contains($text, 'game') => 'gaming',
            str_contains($text, 'programming'), str_contains($text, 'coding') => 'programming',
            str_contains($text, 'office'), str_contains($text, 'productivity') => 'productivity',
            str_contains($text, 'editing'), str_contains($text, 'creative') => 'creative_work',
            str_contains($text, 'portable'), str_contains($text, 'travel') => 'portable_use',
            default => 'general',
        };
    }

    private function cleanQuery(string $query): string
    {
        $query = preg_replace('/\s+(?:for|when used for)\s+(?:gaming|programming|coding|office|productivity|editing|creative work|travel|portable use).*$/iu', '', $query);
        return trim((string) $query, " \t\n\r\0\x0B,.:;?!\"'“”");
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function friendlyCode(string $value): string
    {
        return ucwords(str_replace('_', ' ', mb_strtolower($value)));
    }

    private function pcComponentGroup(string $categorySlug): ?string
    {
        return match ($categorySlug) {
            'processors' => 'processor',
            'motherboards' => 'motherboard',
            'memory' => 'memory',
            'graphics-cards' => 'graphics_card',
            'power-supplies' => 'power_supply',
            'storage' => 'storage',
            'computer-cases' => 'computer_case',
            'cpu-coolers' => 'cpu_cooler',
            default => null,
        };
    }
}
