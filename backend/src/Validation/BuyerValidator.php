<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class BuyerValidator
{
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function address(array $input): array
    {
        $data = [
            'label' => trim((string) ($input['label'] ?? '')),
            'recipient_name' => trim((string) ($input['recipient_name'] ?? '')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'address_line_1' => trim((string) ($input['address_line_1'] ?? '')),
            'address_line_2' => trim((string) ($input['address_line_2'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'district' => trim((string) ($input['district'] ?? '')),
            'postal_code' => trim((string) ($input['postal_code'] ?? '')),
            'country_code' => strtoupper(trim((string) ($input['country_code'] ?? 'LK'))),
            'is_default' => filter_var(
                $input['is_default'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
        $errors = [];
        foreach (
            [
                'label' => [2, 60],
                'recipient_name' => [2, 160],
                'phone' => [7, 30],
                'address_line_1' => [5, 190],
                'city' => [2, 100],
                'district' => [2, 100],
            ] as $field => [$minimum, $maximum]
        ) {
            $length = strlen((string) $data[$field]);
            if ($length < $minimum || $length > $maximum) {
                $errors[$field] = ["Use between {$minimum} and {$maximum} characters."];
            }
        }
        if (strlen($data['address_line_2']) > 190) {
            $errors['address_line_2'] = ['Use no more than 190 characters.'];
        }
        if (strlen($data['postal_code']) > 20) {
            $errors['postal_code'] = ['Use no more than 20 characters.'];
        }
        if (!preg_match('/^[A-Z]{2}$/', $data['country_code'])) {
            $errors['country_code'] = ['Use a two-letter country code.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Delivery address details are invalid.', $errors);
        }
        $data['address_line_2'] = $data['address_line_2'] ?: null;
        $data['postal_code'] = $data['postal_code'] ?: null;
        return $data;
    }

    public static function quantity(array $input): int
    {
        $quantity = filter_var(
            $input['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 99]]
        );
        if ($quantity === false) {
            throw new HttpException(422, 'Cart quantity is invalid.', [
                'quantity' => ['Choose between 1 and 99 units.'],
            ]);
        }
        return (int) $quantity;
    }

    /** @param array<string, mixed> $input
     *  @return array<int, array{listing_id: int, quantity: int, expected_price_lkr: string, component_group: string, component_code: string, sort_order: int}>
     */
    public static function setupCartItems(array $input): array
    {
        $rawItems = $input['items'] ?? null;
        if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 12) {
            throw new HttpException(422, 'Complete setup details are invalid.', [
                'items' => ['Choose between 1 and 12 seller offers.'],
            ]);
        }

        $items = [];
        $seenListings = [];
        $errors = [];
        foreach (array_values($rawItems) as $index => $rawItem) {
            if (!is_array($rawItem)) {
                $errors["items.{$index}"] = ['Each setup item must be an object.'];
                continue;
            }
            $listingId = filter_var(
                $rawItem['listing_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $quantity = filter_var(
                $rawItem['quantity'] ?? 1,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 99]]
            );
            $expectedPrice = $rawItem['expected_price_lkr'] ?? null;
            $validPrice = is_numeric($expectedPrice) && (float) $expectedPrice >= 0;
            $componentGroup = strtolower(trim((string) ($rawItem['component_group'] ?? '')));
            $componentCode = strtolower(trim((string) ($rawItem['component_code'] ?? '')));
            if ($listingId === false) {
                $errors["items.{$index}.listing_id"] = ['Choose a valid seller offer.'];
            } elseif (isset($seenListings[(int) $listingId])) {
                $errors["items.{$index}.listing_id"] = ['A seller offer can appear only once.'];
            }
            if ($quantity === false) {
                $errors["items.{$index}.quantity"] = ['Choose between 1 and 99 units.'];
            }
            if (!$validPrice) {
                $errors["items.{$index}.expected_price_lkr"] = [
                    'The displayed seller price is required for a final live-price check.',
                ];
            }
            if (!in_array($componentGroup, ['pc', 'peripheral'], true)) {
                $errors["items.{$index}.component_group"] = ['Choose pc or peripheral.'];
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $componentCode)) {
                $errors["items.{$index}.component_code"] = [
                    'Use a valid component role such as processor, memory, monitor, or mouse.',
                ];
            }
            if (
                $listingId === false
                || $quantity === false
                || !$validPrice
                || !in_array($componentGroup, ['pc', 'peripheral'], true)
                || !preg_match('/^[a-z][a-z0-9_]{1,59}$/', $componentCode)
            ) {
                continue;
            }
            $seenListings[(int) $listingId] = true;
            $items[] = [
                'listing_id' => (int) $listingId,
                'quantity' => (int) $quantity,
                'expected_price_lkr' => number_format((float) $expectedPrice, 2, '.', ''),
                'component_group' => $componentGroup,
                'component_code' => $componentCode,
                'sort_order' => $index,
            ];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Complete setup details are invalid.', $errors);
        }
        return $items;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function setupIdentity(array $input): array
    {
        $raw = $input['setup'] ?? null;
        if (!is_array($raw)) {
            throw new HttpException(422, 'HexBot setup identity is required.', [
                'setup' => ['Include the selected build identity and requirements.'],
            ]);
        }
        $rank = filter_var(
            $raw['build_rank'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 20]]
        );
        $scope = strtolower(trim((string) ($raw['setup_scope'] ?? '')));
        $target = $raw['target_budget_lkr'] ?? null;
        $maximum = $raw['max_budget_lkr'] ?? null;
        $sourceId = strtolower(trim((string) ($raw['source_recommendation_id'] ?? '')));
        $requirements = $raw['requirements'] ?? null;
        $scores = $raw['scores'] ?? null;
        $compatibility = $raw['compatibility'] ?? null;
        $errors = [];
        if ($rank === false) {
            $errors['setup.build_rank'] = ['Choose a valid recommended build number.'];
        }
        if (!in_array($scope, ['pc_only', 'pc_monitor', 'complete_setup'], true)) {
            $errors['setup.setup_scope'] = ['Choose a supported setup scope.'];
        }
        if (!is_numeric($target) || (float) $target <= 0) {
            $errors['setup.target_budget_lkr'] = ['Provide the confirmed target budget.'];
        }
        if (
            !is_numeric($maximum)
            || (float) $maximum < (float) ($target ?? 0)
        ) {
            $errors['setup.max_budget_lkr'] = ['Provide a maximum budget at or above the target.'];
        }
        if ($sourceId !== '' && !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sourceId
        )) {
            $errors['setup.source_recommendation_id'] = ['Use a valid recommendation identifier.'];
        }
        foreach (
            ['requirements' => $requirements, 'scores' => $scores, 'compatibility' => $compatibility]
            as $field => $value
        ) {
            if (!is_array($value)) {
                $errors["setup.{$field}"] = ['Provide a structured object.'];
                continue;
            }
            try {
                $json = json_encode($value, JSON_THROW_ON_ERROR);
                if (strlen($json) > 100000) {
                    $errors["setup.{$field}"] = ['The setup evidence is too large.'];
                }
            } catch (\JsonException) {
                $errors["setup.{$field}"] = ['The setup evidence is not valid JSON data.'];
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'HexBot setup identity is invalid.', $errors);
        }

        $workloadLabels = [
            'gaming_1080p' => '1080p Gaming',
            'gaming_1440p' => '1440p Gaming',
            'gaming_4k' => '4K Gaming',
            'programming' => 'Programming',
            'video_editing' => 'Video Editing',
            'content_creation' => 'Content Creation',
            'machine_learning' => 'AI and Machine Learning',
            'office_productivity' => 'Office and Study',
            'balanced_general' => 'Balanced',
        ];
        $workloads = is_array($requirements['workloads'] ?? null)
            ? $requirements['workloads'] : [];
        if (array_is_list($workloads)) {
            $mainWorkload = (string) ($workloads[0] ?? '');
        } else {
            arsort($workloads);
            $mainWorkload = (string) array_key_first($workloads);
        }
        $purpose = $workloadLabels[$mainWorkload] ?? 'Custom';
        $kind = $scope === 'complete_setup'
            ? 'Setup' : ($scope === 'pc_monitor' ? 'PC + Monitor' : 'PC Build');

        return [
            'source_recommendation_id' => $sourceId === '' ? null : $sourceId,
            'name' => "HexBot {$purpose} {$kind} · Build {$rank}",
            'build_rank' => (int) $rank,
            'setup_scope' => $scope,
            'target_budget_lkr' => number_format((float) $target, 2, '.', ''),
            'max_budget_lkr' => number_format((float) $maximum, 2, '.', ''),
            'requirements' => $requirements,
            'scores' => $scores,
            'compatibility' => $compatibility,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array{address_id: int, payment_method: string, payment_status: string, expected_total_lkr: string, setup_public_ids: array<int, string>}
     */
    public static function checkout(array $input): array
    {
        $addressId = (int) ($input['address_id'] ?? 0);
        $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? '')));
        $noticeAccepted = filter_var(
            $input['simulated_payment_acknowledged'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $expectedTotal = $input['expected_total_lkr'] ?? null;
        $rawSetupIds = $input['setup_public_ids'] ?? [];
        $errors = [];
        if ($addressId < 1) {
            $errors['address_id'] = ['A saved delivery address is required.'];
        }
        if ($paymentMethod !== 'card_simulation') {
            $errors['payment_method'] = ['Hexbay checkout supports card payment only.'];
        }
        if (!$noticeAccepted) {
            $errors['simulated_payment_acknowledged'] = [
                'Confirm that this demonstration does not collect a real payment.',
            ];
        }
        if (!is_numeric($expectedTotal) || (float) $expectedTotal < 0) {
            $errors['expected_total_lkr'] = ['The checkout total shown on screen is required.'];
        }
        if (!is_array($rawSetupIds) || count($rawSetupIds) > 10) {
            $errors['setup_public_ids'] = ['Provide the saved setup identifiers shown at checkout.'];
            $rawSetupIds = [];
        }
        $setupIds = [];
        foreach (array_values($rawSetupIds) as $index => $setupId) {
            $normalized = strtolower(trim((string) $setupId));
            if (!preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $normalized
            )) {
                $errors["setup_public_ids.{$index}"] = ['Use a valid saved setup identifier.'];
                continue;
            }
            if (in_array($normalized, $setupIds, true)) {
                $errors["setup_public_ids.{$index}"] = ['Each saved setup can appear only once.'];
                continue;
            }
            $setupIds[] = $normalized;
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Checkout details are invalid.', $errors);
        }
        sort($setupIds);
        return [
            'address_id' => $addressId,
            'payment_method' => $paymentMethod,
            'payment_status' => 'simulated_authorized',
            'expected_total_lkr' => number_format((float) $expectedTotal, 2, '.', ''),
            'setup_public_ids' => $setupIds,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array{rating: int, title: ?string, review_text: ?string}
     */
    public static function review(array $input): array
    {
        $rating = (int) ($input['rating'] ?? 0);
        $title = trim((string) ($input['title'] ?? ''));
        $reviewText = trim((string) ($input['review_text'] ?? ''));
        $errors = [];
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = ['Choose a rating from 1 to 5.'];
        }
        if (strlen($title) > 150) {
            $errors['title'] = ['Use no more than 150 characters.'];
        }
        if (strlen($reviewText) > 5000) {
            $errors['review_text'] = ['Use no more than 5,000 characters.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Review details are invalid.', $errors);
        }
        return [
            'rating' => $rating,
            'title' => $title ?: null,
            'review_text' => $reviewText ?: null,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function complaint(array $input): array
    {
        $data = [
            'order_id' => self::optionalId($input['order_id'] ?? null),
            'sub_order_id' => self::optionalId($input['sub_order_id'] ?? null),
            'listing_id' => self::optionalId($input['listing_id'] ?? null),
            'shop_id' => self::optionalId($input['shop_id'] ?? null),
            'subject' => trim((string) ($input['subject'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
        ];
        $errors = [];
        if (strlen($data['subject']) < 5 || strlen($data['subject']) > 190) {
            $errors['subject'] = ['Use between 5 and 190 characters.'];
        }
        if (strlen($data['description']) < 10 || strlen($data['description']) > 5000) {
            $errors['description'] = ['Use between 10 and 5,000 characters.'];
        }
        if (
            $data['order_id'] === null
            && $data['sub_order_id'] === null
            && $data['listing_id'] === null
            && $data['shop_id'] === null
        ) {
            $errors['target'] = ['Choose an order, seller, or product to report.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Complaint details are invalid.', $errors);
        }
        return $data;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function counterfeitReport(array $input): array
    {
        $allowedReasons = [
            'packaging_concern',
            'serial_mismatch',
            'misleading_brand',
            'suspicious_listing',
            'other',
        ];
        $data = [
            'listing_id' => (int) ($input['listing_id'] ?? 0),
            'order_item_id' => self::optionalId($input['order_item_id'] ?? null),
            'reason_code' => strtolower(trim((string) ($input['reason_code'] ?? ''))),
            'description' => trim((string) ($input['description'] ?? '')),
        ];
        $errors = [];
        if ($data['listing_id'] < 1) {
            $errors['listing_id'] = ['Choose the seller listing being reported.'];
        }
        if (!in_array($data['reason_code'], $allowedReasons, true)) {
            $errors['reason_code'] = ['Choose one of the provided report reasons.'];
        }
        if (strlen($data['description']) < 10 || strlen($data['description']) > 5000) {
            $errors['description'] = ['Use between 10 and 5,000 characters.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Report details are invalid.', $errors);
        }
        return $data;
    }

    private static function optionalId(mixed $value): ?int
    {
        $id = (int) ($value ?? 0);
        return $id > 0 ? $id : null;
    }
}
