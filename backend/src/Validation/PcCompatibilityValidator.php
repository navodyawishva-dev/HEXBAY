<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class PcCompatibilityValidator
{
    /** @var array<string, string> */
    public const COMPONENT_CATEGORIES = [
        'processor' => 'processors',
        'motherboard' => 'motherboards',
        'memory' => 'memory',
        'graphics_card' => 'graphics-cards',
        'power_supply' => 'power-supplies',
        'storage' => 'storage',
        'computer_case' => 'computer-cases',
        'cpu_cooler' => 'cpu-coolers',
    ];

    /** @param array<string, mixed> $input
     *  @return array{mode: string, components: array<string, int>}
     */
    public static function validationRequest(array $input): array
    {
        $mode = strtolower(trim((string) ($input['mode'] ?? 'partial')));
        $errors = [];
        if (!in_array($mode, ['partial', 'complete'], true)) {
            $errors['mode'][] = 'Use partial or complete validation mode.';
        }
        $components = self::components($input['components'] ?? null, $errors);
        if ($errors !== []) {
            throw new HttpException(422, 'PC compatibility input is invalid.', $errors);
        }
        return ['mode' => $mode, 'components' => $components];
    }

    /** @param array<string, mixed> $input
     *  @return array{mode: string, components: array<string, int>, target_component: string, limit: int}
     */
    public static function alternativesRequest(array $input): array
    {
        $validated = self::validationRequest($input);
        $errors = [];
        $target = strtolower(trim((string) ($input['target_component'] ?? '')));
        if (!array_key_exists($target, self::COMPONENT_CATEGORIES)) {
            $errors['target_component'][] = 'Choose a supported PC component group.';
        }
        $limit = filter_var(
            $input['limit'] ?? 8,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 20]]
        );
        if ($limit === false) {
            $errors['limit'][] = 'Choose between 1 and 20 alternatives.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Compatible-alternative input is invalid.', $errors);
        }
        return [
            ...$validated,
            'target_component' => $target,
            'limit' => (int) $limit,
        ];
    }

    /** @param mixed $raw
     *  @param array<string, array<int, string>> $errors
     *  @return array<string, int>
     */
    private static function components(mixed $raw, array &$errors): array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            $errors['components'][] = 'Provide component IDs by component group.';
            return [];
        }
        $components = [];
        $seen = [];
        foreach ($raw as $field => $value) {
            if (!is_string($field) || !array_key_exists($field, self::COMPONENT_CATEGORIES)) {
                $errors['components'][] = 'An unsupported component group was provided.';
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $id = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($id === false) {
                $errors['components'][$field] = 'Choose a valid catalogue product.';
                continue;
            }
            if (isset($seen[(int) $id])) {
                $errors['components'][$field] = 'A product cannot fill two component groups.';
                continue;
            }
            $components[$field] = (int) $id;
            $seen[(int) $id] = true;
        }
        if ($components === []) {
            $errors['components'][] = 'Select at least one PC component.';
        }
        return $components;
    }
}

