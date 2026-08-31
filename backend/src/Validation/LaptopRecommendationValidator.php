<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class LaptopRecommendationValidator
{
    private const USES = [
        'any',
        'general',
        'office',
        'study',
        'programming',
        'gaming',
        'content_creation',
        'engineering',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{requirements: array<string, mixed>, limit: int}
     */
    public static function request(array $input): array
    {
        $errors = [];
        $requirements = [];

        $budget = self::number(
            $input['max_budget_lkr'] ?? null,
            'max_budget_lkr',
            1_000,
            100_000_000,
            $errors,
            true
        );
        if ($budget !== null) {
            $requirements['max_budget_lkr'] = $budget;
        }

        $minimumBudget = self::number(
            $input['minimum_budget_lkr'] ?? null,
            'minimum_budget_lkr',
            1_000,
            100_000_000,
            $errors,
            false
        );
        if ($minimumBudget !== null) {
            $requirements['minimum_budget_lkr'] = $minimumBudget;
        }
        if (
            $minimumBudget !== null
            && $budget !== null
            && $minimumBudget > $budget
        ) {
            $errors['minimum_budget_lkr'][] =
                'Minimum budget must not be greater than the maximum.';
        }

        $intendedUse = strtolower(trim((string) ($input['intended_use'] ?? '')));
        $intendedUse = str_replace(' ', '_', $intendedUse);
        if (!in_array($intendedUse, self::USES, true)) {
            $errors['intended_use'][] = 'Choose a supported use case.';
        } else {
            $requirements['intended_use'] = $intendedUse;
        }

        $optionalNumbers = [
            'minimum_ram_gb' => [1, 512],
            'maximum_ram_gb' => [1, 512],
            'minimum_storage_gb' => [16, 100_000],
            'minimum_screen_size_inches' => [8, 30],
            'maximum_screen_size_inches' => [8, 30],
            'preferred_screen_size_inches' => [8, 30],
        ];
        foreach ($optionalNumbers as $field => [$minimum, $maximum]) {
            $value = self::number(
                $input[$field] ?? null,
                $field,
                $minimum,
                $maximum,
                $errors,
                false
            );
            if ($value !== null) {
                $requirements[$field] = $value;
            }
        }
        if (
            isset($requirements['minimum_ram_gb'], $requirements['maximum_ram_gb'])
            && $requirements['minimum_ram_gb'] > $requirements['maximum_ram_gb']
        ) {
            $errors['maximum_ram_gb'][] =
                'Maximum RAM must not be smaller than the minimum.';
        }
        if (
            isset(
                $requirements['minimum_screen_size_inches'],
                $requirements['maximum_screen_size_inches']
            )
            && $requirements['minimum_screen_size_inches']
                > $requirements['maximum_screen_size_inches']
        ) {
            $errors['maximum_screen_size_inches'][] =
                'Maximum screen size must not be smaller than the minimum.';
        }

        foreach (['required_cpu', 'required_gpu'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if (mb_strlen($value) > 100) {
                $errors[$field][] = 'Use no more than 100 characters.';
            } elseif ($value !== '') {
                $requirements[$field] = $value;
            }
        }

        $dedicatedGpu = $input['require_dedicated_gpu'] ?? false;
        if (!is_bool($dedicatedGpu)) {
            $errors['require_dedicated_gpu'][] = 'Use true or false.';
        } else {
            $requirements['require_dedicated_gpu'] = $dedicatedGpu;
        }

        $brands = $input['preferred_brands'] ?? [];
        if (!is_array($brands) || !array_is_list($brands) || count($brands) > 10) {
            $errors['preferred_brands'][] = 'Choose no more than 10 brands.';
        } else {
            $cleanBrands = [];
            foreach ($brands as $brand) {
                if (!is_string($brand) || trim($brand) === '' || mb_strlen(trim($brand)) > 80) {
                    $errors['preferred_brands'][] =
                        'Each preferred brand must be a short name.';
                    break;
                }
                $normalised = trim($brand);
                if (!in_array(strtolower($normalised), array_map('strtolower', $cleanBrands), true)) {
                    $cleanBrands[] = $normalised;
                }
            }
            if ($cleanBrands !== []) {
                $requirements['preferred_brands'] = $cleanBrands;
            }
        }

        $limit = filter_var(
            $input['limit'] ?? 5,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 12]]
        );
        if ($limit === false) {
            $errors['limit'][] = 'Choose between 1 and 12 recommendations.';
        }

        if ($errors !== []) {
            throw new HttpException(
                422,
                'Laptop recommendation preferences are invalid.',
                $errors
            );
        }

        return [
            'requirements' => $requirements,
            'limit' => (int) $limit,
        ];
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private static function number(
        mixed $raw,
        string $field,
        float $minimum,
        float $maximum,
        array &$errors,
        bool $required
    ): ?float {
        if ($raw === null || $raw === '') {
            if ($required) {
                $errors[$field][] = 'This value is required.';
            }
            return null;
        }
        if (!is_numeric($raw)) {
            $errors[$field][] = 'Enter a valid number.';
            return null;
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value < $minimum || $value > $maximum) {
            $errors[$field][] = sprintf(
                'Enter a value between %s and %s.',
                number_format($minimum, 0, '.', ','),
                number_format($maximum, 0, '.', ',')
            );
            return null;
        }
        return $value;
    }
}
