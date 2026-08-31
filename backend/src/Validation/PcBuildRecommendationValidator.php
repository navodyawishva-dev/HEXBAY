<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class PcBuildRecommendationValidator
{
    private const DEFAULT_PRIORITIES = [
        'performance' => 0.45,
        'value' => 0.30,
        'efficiency' => 0.10,
        'upgradeability' => 0.15,
    ];

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function request(array $input): array
    {
        $errors = [];
        $target = self::money($input['target_budget_lkr'] ?? null);
        if ($target === null || $target < 50000 || $target > 10000000) {
            $errors['target_budget_lkr'][] = 'Choose a target budget from LKR 50,000 to LKR 10,000,000.';
        }

        $flexibility = self::number($input['flexibility_percent'] ?? 7.5);
        if ($flexibility === null || $flexibility < 0 || $flexibility > 30) {
            $errors['flexibility_percent'][] = 'Budget flexibility must be between 0% and 30%.';
            $flexibility = 7.5;
        }

        $maximum = self::money($input['max_budget_lkr'] ?? null);
        if ($maximum === null && $target !== null) {
            $maximum = ceil(($target * (1 + ($flexibility / 100))) / 100) * 100;
        }
        if (
            $target !== null && $maximum !== null
            && ($maximum < $target || $maximum > $target * 1.5)
        ) {
            $errors['max_budget_lkr'][] = 'Maximum budget must be between the target and 50% above it.';
        }

        $workloads = self::workloads($input['workloads'] ?? ['balanced_general'], $errors);
        $priorities = self::priorities($input['priorities'] ?? null, $errors);
        $preferences = self::preferences($input['preferences'] ?? null, $errors);
        $locked = self::lockedComponents($input['locked_components'] ?? null, $errors);
        $setupScope = strtolower(trim((string) ($input['setup_scope'] ?? 'pc_only')));
        if (!in_array($setupScope, ['pc_only', 'pc_monitor', 'complete_setup'], true)) {
            $errors['setup_scope'][] = 'Choose PC only, PC with monitor, or complete setup.';
        }
        $peripheralCategories = [];
        $rawPeripheralCategories = $input['peripheral_categories'] ?? [];
        if (!is_array($rawPeripheralCategories) || !array_is_list($rawPeripheralCategories)) {
            $errors['peripheral_categories'][] = 'Peripheral categories must be a list.';
        } else {
            foreach ($rawPeripheralCategories as $index => $category) {
                $category = strtolower(trim((string) $category));
                if (!in_array($category, ['monitor', 'keyboard', 'mouse', 'headset'], true)) {
                    $errors['peripheral_categories'][$index] = 'Choose monitor, keyboard, mouse, or headset.';
                    continue;
                }
                $peripheralCategories[] = $category;
            }
            $peripheralCategories = array_values(array_unique($peripheralCategories));
        }
        $includeHeadset = filter_var(
            $input['include_headset'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($includeHeadset === null) {
            $errors['include_headset'][] = 'Headset selection must be true or false.';
            $includeHeadset = false;
        }
        if ($includeHeadset && $setupScope === 'pc_only') {
            $errors['include_headset'][] = 'Choose a setup scope before adding a headset.';
        }
        if ($peripheralCategories !== [] && $setupScope === 'pc_only') {
            $errors['peripheral_categories'][] = 'Choose a setup scope before adding peripherals.';
        }

        $limit = filter_var(
            $input['limit'] ?? 3,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5]]
        );
        if ($limit === false) {
            $errors['limit'][] = 'Choose between 1 and 5 build recommendations.';
        }

        if ($errors !== []) {
            throw new HttpException(422, 'PC build recommendation input is invalid.', $errors);
        }

        return [
            'target_budget_lkr' => round((float) $target, 2),
            'max_budget_lkr' => round((float) $maximum, 2),
            'flexibility_percent' => round((float) $flexibility, 2),
            'workloads' => $workloads,
            'priorities' => $priorities,
            'preferences' => $preferences,
            'locked_components' => $locked,
            'setup_scope' => $setupScope,
            'include_headset' => $includeHeadset,
            'peripheral_categories' => $peripheralCategories,
            'limit' => (int) $limit,
        ];
    }

    /** @param mixed $raw
     *  @param array<string, array<int|string, string>> $errors
     *  @return array<string, float>
     */
    private static function workloads(mixed $raw, array &$errors): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 3) {
            $errors['workloads'][] = 'Choose one to three PC use cases.';
            return [];
        }
        $weights = [];
        foreach ($raw as $index => $item) {
            $code = is_string($item)
                ? strtolower(trim($item))
                : strtolower(trim((string) (is_array($item) ? ($item['code'] ?? '') : '')));
            $weight = is_array($item) ? self::number($item['weight'] ?? 1) : 1.0;
            if (!preg_match('/^[a-z][a-z0-9_]{2,99}$/', $code)) {
                $errors['workloads'][$index] = 'Use a valid workload code.';
                continue;
            }
            if ($weight === null || $weight <= 0 || $weight > 1) {
                $errors['workloads'][$index] = 'Workload weight must be greater than 0 and no more than 1.';
                continue;
            }
            if (isset($weights[$code])) {
                $errors['workloads'][$index] = 'Each use case may be selected only once.';
                continue;
            }
            $weights[$code] = (float) $weight;
        }
        $total = array_sum($weights);
        if ($total > 0) {
            foreach ($weights as $code => $weight) {
                $weights[$code] = $weight / $total;
            }
        }
        return $weights;
    }

    /** @param mixed $raw
     *  @param array<string, array<int|string, string>> $errors
     *  @return array<string, float>
     */
    private static function priorities(mixed $raw, array &$errors): array
    {
        if ($raw === null) {
            return self::DEFAULT_PRIORITIES;
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors['priorities'][] = 'Priorities must be an object.';
            return self::DEFAULT_PRIORITIES;
        }
        $priorities = [];
        foreach (self::DEFAULT_PRIORITIES as $code => $default) {
            $value = self::number($raw[$code] ?? $default);
            if ($value === null || $value < 0 || $value > 1) {
                $errors['priorities'][$code] = 'Priority values must be between 0 and 1.';
                continue;
            }
            $priorities[$code] = (float) $value;
        }
        foreach (array_keys($raw) as $code) {
            if (!is_string($code) || !array_key_exists($code, self::DEFAULT_PRIORITIES)) {
                $errors['priorities'][] = 'An unsupported build priority was provided.';
            }
        }
        $total = array_sum($priorities);
        if ($total <= 0) {
            $errors['priorities'][] = 'At least one build priority must be greater than zero.';
            return self::DEFAULT_PRIORITIES;
        }
        foreach ($priorities as $code => $value) {
            $priorities[$code] = $value / $total;
        }
        return $priorities;
    }

    /** @param mixed $raw
     *  @param array<string, array<int|string, string>> $errors
     *  @return array<string, mixed>
     */
    private static function preferences(mixed $raw, array &$errors): array
    {
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors['preferences'][] = 'Preferences must be an object.';
            $raw = [];
        }
        $graphics = strtolower(trim((string) ($raw['dedicated_graphics'] ?? 'auto')));
        if (!in_array($graphics, ['auto', 'required', 'avoid'], true)) {
            $errors['preferences']['dedicated_graphics'] = 'Use auto, required or avoid.';
        }
        $memory = filter_var(
            $raw['minimum_memory_gb'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 512]]
        );
        $maximumMemory = filter_var(
            $raw['maximum_memory_gb'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 512]]
        );
        $storage = filter_var(
            $raw['minimum_storage_gb'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 32000]]
        );
        $maximumStorage = filter_var(
            $raw['maximum_storage_gb'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 32000]]
        );
        $vram = filter_var(
            $raw['minimum_vram_gb'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 128]]
        );
        $maximumVram = filter_var(
            $raw['maximum_vram_gb'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 128]]
        );
        $processorFamily = strtolower(trim((string) ($raw['processor_family'] ?? '')));
        $processorFamilies = [
            '', 'intel_core_i3', 'intel_core_i5', 'intel_core_i7', 'intel_core_i9',
            'amd_ryzen_3', 'amd_ryzen_5', 'amd_ryzen_7', 'amd_ryzen_9',
        ];
        if (!in_array($processorFamily, $processorFamilies, true)) {
            $errors['preferences']['processor_family'] = 'Choose a supported Intel Core or AMD Ryzen processor family.';
        }
        $processorModel = trim((string) ($raw['processor_model'] ?? ''));
        $gpuModel = trim((string) ($raw['gpu_model'] ?? ''));
        foreach (['processor_model'=>$processorModel, 'gpu_model'=>$gpuModel] as $field => $value) {
            if ($value !== '' && preg_match('/^[A-Za-z0-9 .+_-]{2,80}$/', $value) !== 1) {
                $errors['preferences'][$field] = 'Use a valid model or product-family name.';
            }
        }
        $storageType = strtolower(trim((string) ($raw['storage_type'] ?? 'any')));
        if (!in_array($storageType, ['any', 'ssd', 'nvme_ssd', 'sata_ssd', 'hdd'], true)) {
            $errors['preferences']['storage_type'] = 'Choose any storage, SSD, NVMe SSD, SATA SSD, or HDD.';
        }
        if ($memory === false) {
            $errors['preferences']['minimum_memory_gb'] = 'Memory must be from 0 to 512 GB.';
        }
        if ($maximumMemory === false) {
            $errors['preferences']['maximum_memory_gb'] = 'Maximum memory must be from 0 to 512 GB.';
        } elseif (
            $memory !== false && $maximumMemory > 0
            && $maximumMemory < $memory
        ) {
            $errors['preferences']['maximum_memory_gb'] = 'Maximum memory cannot be below minimum memory.';
        }
        if ($storage === false) {
            $errors['preferences']['minimum_storage_gb'] = 'Storage must be from 0 to 32,000 GB.';
        }
        if ($maximumStorage === false) {
            $errors['preferences']['maximum_storage_gb'] = 'Maximum storage must be from 0 to 32,000 GB.';
        } elseif (
            $storage !== false && $maximumStorage > 0
            && $maximumStorage < $storage
        ) {
            $errors['preferences']['maximum_storage_gb'] = 'Maximum storage cannot be below minimum storage.';
        }
        if ($vram === false) {
            $errors['preferences']['minimum_vram_gb'] = 'Graphics memory must be from 0 to 128 GB.';
        }
        if ($maximumVram === false) {
            $errors['preferences']['maximum_vram_gb'] = 'Maximum graphics memory must be from 0 to 128 GB.';
        } elseif (
            $vram !== false && $maximumVram > 0
            && $maximumVram < $vram
        ) {
            $errors['preferences']['maximum_vram_gb'] = 'Maximum graphics memory cannot be below minimum graphics memory.';
        }
        foreach (array_keys($raw) as $code) {
            if (!in_array($code, [
                'dedicated_graphics', 'minimum_memory_gb',
                'maximum_memory_gb', 'minimum_storage_gb', 'maximum_storage_gb',
                'minimum_vram_gb', 'maximum_vram_gb', 'gpu_model',
                'processor_family', 'processor_model', 'storage_type',
            ], true)) {
                $errors['preferences'][] = 'An unsupported PC preference was provided.';
            }
        }
        return [
            'dedicated_graphics' => $graphics,
            'minimum_memory_gb' => (int) ($memory === false ? 0 : $memory),
            'maximum_memory_gb' => (int) ($maximumMemory === false ? 0 : $maximumMemory),
            'minimum_storage_gb' => (int) ($storage === false ? 0 : $storage),
            'maximum_storage_gb' => (int) ($maximumStorage === false ? 0 : $maximumStorage),
            'minimum_vram_gb' => (int) ($vram === false ? 0 : $vram),
            'maximum_vram_gb' => (int) ($maximumVram === false ? 0 : $maximumVram),
            'gpu_model' => $gpuModel,
            'processor_family' => $processorFamily,
            'processor_model' => $processorModel,
            'storage_type' => $storageType,
        ];
    }

    /** @param mixed $raw
     *  @param array<string, array<int|string, string>> $errors
     *  @return array<string, int>
     */
    private static function lockedComponents(mixed $raw, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors['locked_components'][] = 'Locked components must be provided by component group.';
            return [];
        }
        $locked = [];
        $seen = [];
        foreach ($raw as $field => $value) {
            if (!is_string($field) || !array_key_exists($field, PcCompatibilityValidator::COMPONENT_CATEGORIES)) {
                $errors['locked_components'][] = 'An unsupported locked component group was provided.';
                continue;
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $errors['locked_components'][$field] = 'Choose a valid catalogue product.';
                continue;
            }
            if (isset($seen[(int) $id])) {
                $errors['locked_components'][$field] = 'A product cannot fill two component groups.';
                continue;
            }
            $locked[$field] = (int) $id;
            $seen[(int) $id] = true;
        }
        return $locked;
    }

    private static function money(mixed $value): ?float
    {
        $number = self::number($value);
        return $number === null ? null : round($number, 2);
    }

    private static function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        $value = is_string($value) ? str_replace([',', ' '], '', trim($value)) : $value;
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }
}
