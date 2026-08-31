<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class ShopApplicationValidator
{
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function submission(array $input): array
    {
        $data = [
            'shop_name' => trim((string) ($input['shop_name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'address' => trim((string) ($input['address'] ?? '')),
            'contact_phone' => trim((string) ($input['contact_phone'] ?? '')),
            'contact_email' => strtolower(trim((string) ($input['contact_email'] ?? ''))),
            'legal_name' => trim((string) ($input['legal_name'] ?? '')),
            'business_registration_reference' => trim(
                (string) ($input['business_registration_reference'] ?? '')
            ),
            'commission_rule_id' => (int) ($input['commission_rule_id'] ?? 0),
            'commission_accepted' => filter_var(
                $input['commission_accepted'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
        ];

        $errors = [];
        foreach (
            [
                'shop_name' => [2, 160],
                'address' => [5, 500],
                'legal_name' => [2, 190],
                'business_registration_reference' => [2, 190],
            ] as $field => [$minimum, $maximum]
        ) {
            $length = strlen((string) $data[$field]);
            if ($length < $minimum || $length > $maximum) {
                $errors[$field][] = "Use between {$minimum} and {$maximum} characters.";
            }
        }

        if (strlen($data['description']) > 2000) {
            $errors['description'][] = 'Description must not exceed 2,000 characters.';
        }
        if (
            $data['contact_email'] === ''
            || filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['contact_email'][] = 'Enter a valid shop email address.';
        }
        if (
            preg_match('/^\+?[0-9][0-9\s-]{6,19}$/', $data['contact_phone']) !== 1
        ) {
            $errors['contact_phone'][] = 'Enter a valid shop telephone number.';
        }
        if ($data['commission_rule_id'] < 1) {
            $errors['commission_rule_id'][] = 'The active commission rule is required.';
        }
        if (!$data['commission_accepted']) {
            $errors['commission_accepted'][] = 'You must accept the commission policy.';
        }

        if ($errors !== []) {
            throw new HttpException(422, 'Shop application validation failed.', $errors);
        }

        return $data;
    }
}

