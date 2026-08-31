<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class SellerValidator
{
    /** @param array<string, mixed> $input
     *  @return array<string, string>
     */
    public static function profile(array $input): array
    {
        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'address_text' => trim((string) ($input['address_text'] ?? '')),
            'contact_phone' => trim((string) ($input['contact_phone'] ?? '')),
            'contact_email' => strtolower(trim((string) ($input['contact_email'] ?? ''))),
        ];
        $errors = [];
        if (strlen($data['name']) < 2 || strlen($data['name']) > 160) {
            $errors['name'] = ['Use between 2 and 160 characters.'];
        }
        if (strlen($data['description']) > 3000) {
            $errors['description'] = ['Description must not exceed 3,000 characters.'];
        }
        if (strlen($data['address_text']) < 5 || strlen($data['address_text']) > 500) {
            $errors['address_text'] = ['Use between 5 and 500 characters.'];
        }
        if (strlen($data['contact_phone']) < 7 || strlen($data['contact_phone']) > 30) {
            $errors['contact_phone'] = ['Use a valid contact telephone number.'];
        }
        if (filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['contact_email'] = ['Use a valid contact email address.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Shop profile details are invalid.', $errors);
        }
        return $data;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function listing(array $input): array
    {
        $data = [
            'category_id' => (int) ($input['category_id'] ?? 0),
            'brand_name' => trim((string) ($input['brand_name'] ?? '')),
            'product_name' => trim((string) ($input['product_name'] ?? '')),
            'model' => trim((string) ($input['model'] ?? '')),
            'sku' => strtoupper(trim((string) ($input['sku'] ?? ''))),
            'condition_type' => strtolower(trim((string) ($input['condition_type'] ?? 'new'))),
            'price' => trim((string) ($input['price'] ?? '')),
            'vendor_description' => trim((string) ($input['vendor_description'] ?? '')),
            'warranty_summary' => trim((string) ($input['warranty_summary'] ?? '')),
            'initial_stock' => (int) ($input['initial_stock'] ?? 0),
            'specifications' => is_array($input['specifications'] ?? null)
                ? $input['specifications']
                : [],
        ];
        $errors = [];
        if ($data['category_id'] < 1) {
            $errors['category_id'] = ['Choose a product category.'];
        }
        foreach (
            [
                'brand_name' => [2, 100],
                'product_name' => [3, 190],
                'model' => [1, 150],
                'sku' => [2, 100],
            ] as $field => [$minimum, $maximum]
        ) {
            $length = strlen((string) $data[$field]);
            if ($length < $minimum || $length > $maximum) {
                $errors[$field] = ["Use between {$minimum} and {$maximum} characters."];
            }
        }
        if (!preg_match('/^[A-Z0-9._-]+$/', $data['sku'])) {
            $errors['sku'] = ['Use letters, numbers, dots, hyphens or underscores.'];
        }
        if (!in_array($data['condition_type'], ['new', 'used', 'refurbished'], true)) {
            $errors['condition_type'] = ['Choose New, Used, or Refurbished.'];
        }
        if (!is_numeric($data['price']) || (float) $data['price'] < 0 || (float) $data['price'] > 99999999999) {
            $errors['price'] = ['Enter a valid non-negative price.'];
        }
        if (strlen($data['vendor_description']) > 5000) {
            $errors['vendor_description'] = ['Description must not exceed 5,000 characters.'];
        }
        if (strlen($data['warranty_summary']) > 255) {
            $errors['warranty_summary'] = ['Warranty summary must not exceed 255 characters.'];
        }
        if ($data['initial_stock'] < 0 || $data['initial_stock'] > 1000000) {
            $errors['initial_stock'] = ['Initial stock must be between 0 and 1,000,000.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Product listing details are invalid.', $errors);
        }
        $data['price'] = number_format((float) $data['price'], 2, '.', '');
        return $data;
    }

    /** @param array<string, mixed> $input
     *  @return array{quantity_delta: int, reason: string}
     */
    public static function stockAdjustment(array $input): array
    {
        $delta = (int) ($input['quantity_delta'] ?? 0);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($delta === 0 || abs($delta) > 1000000) {
            throw new HttpException(422, 'Stock adjustment is invalid.', [
                'quantity_delta' => ['Use a non-zero quantity within 1,000,000 units.'],
            ]);
        }
        if (strlen($reason) < 5 || strlen($reason) > 500) {
            throw new HttpException(422, 'A stock adjustment reason is required.', [
                'reason' => ['Use between 5 and 500 characters.'],
            ]);
        }
        return ['quantity_delta' => $delta, 'reason' => $reason];
    }

    /** @param array<string, mixed> $input
     *  @return array{status: string, reason: string, delivery_method: ?string, delivery_partner: ?string, tracking_reference: ?string, estimated_delivery_date: ?string, shipment_note: ?string}
     */
    public static function orderStatus(array $input): array
    {
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));
        $deliveryMethod = strtolower(trim((string) ($input['delivery_method'] ?? '')));
        $deliveryPartner = trim((string) ($input['delivery_partner'] ?? ''));
        $trackingReference = trim((string) ($input['tracking_reference'] ?? ''));
        $estimatedDeliveryDate = trim((string) ($input['estimated_delivery_date'] ?? ''));
        $shipmentNote = trim((string) ($input['shipment_note'] ?? ''));
        if (!in_array($status, ['processing', 'shipped', 'cancelled'], true)) {
            throw new HttpException(422, 'Order status is invalid.');
        }
        if ($status === 'cancelled' && (strlen($reason) < 5 || strlen($reason) > 500)) {
            throw new HttpException(422, 'A cancellation reason is required.', [
                'reason' => ['Use between 5 and 500 characters.'],
            ]);
        }
        $errors = [];
        if ($status === 'shipped') {
            if (!in_array($deliveryMethod, ['seller_delivery', 'third_party_courier'], true)) {
                $errors['delivery_method'] = ['Choose seller delivery or a third-party courier.'];
            }
            if ($deliveryMethod === 'third_party_courier') {
                if (strlen($deliveryPartner) < 2 || strlen($deliveryPartner) > 120) {
                    $errors['delivery_partner'] = ['Use between 2 and 120 characters.'];
                }
                if (strlen($trackingReference) < 3 || strlen($trackingReference) > 120) {
                    $errors['tracking_reference'] = ['Use between 3 and 120 characters.'];
                }
            } elseif (strlen($deliveryPartner) > 120 || strlen($trackingReference) > 120) {
                $errors['delivery'] = ['Delivery partner and reference must use no more than 120 characters.'];
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $estimatedDeliveryDate);
            $today = new \DateTimeImmutable('today');
            if (
                $date === false
                || $date->format('Y-m-d') !== $estimatedDeliveryDate
                || $date < $today
                || $date > $today->modify('+60 days')
            ) {
                $errors['estimated_delivery_date'] = [
                    'Choose a valid estimated delivery date within the next 60 days.',
                ];
            }
            if (strlen($shipmentNote) > 500) {
                $errors['shipment_note'] = ['Use no more than 500 characters.'];
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Shipment details are invalid.', $errors);
        }
        return [
            'status' => $status,
            'reason' => $reason,
            'delivery_method' => $status === 'shipped' ? $deliveryMethod : null,
            'delivery_partner' => $status === 'shipped' && $deliveryPartner !== ''
                ? $deliveryPartner : null,
            'tracking_reference' => $status === 'shipped' && $trackingReference !== ''
                ? $trackingReference : null,
            'estimated_delivery_date' => $status === 'shipped'
                ? $estimatedDeliveryDate : null,
            'shipment_note' => $status === 'shipped' && $shipmentNote !== ''
                ? $shipmentNote : null,
        ];
    }

    public static function fulfilmentCheckpoint(array $input): string
    {
        $checkpoint = strtolower(trim((string) ($input['checkpoint_code'] ?? '')));
        if (!in_array($checkpoint, [
            'stock_verified',
            'items_packed',
            'delivery_address_verified',
        ], true)) {
            throw new HttpException(422, 'Fulfilment checkpoint is invalid.', [
                'checkpoint_code' => ['Choose one of the required fulfilment checks.'],
            ]);
        }
        return $checkpoint;
    }

    /** @param array<string, mixed> $input */
    public static function payoutAmount(array $input): string
    {
        $amount = trim((string) ($input['amount'] ?? ''));
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 99999999999) {
            throw new HttpException(422, 'Payout amount is invalid.', [
                'amount' => ['Enter a positive amount within the available balance.'],
            ]);
        }
        return number_format((float) $amount, 2, '.', '');
    }
}
