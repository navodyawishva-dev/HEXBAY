<?php
declare(strict_types=1);

namespace Hexbay\Services;

final class PcCompatibilityEngine
{
    public const RULE_SET_VERSION = 'pc-compat-v1.0.0';

    /** @var array<string, string> */
    private const LABELS = [
        'processor' => 'Processor',
        'motherboard' => 'Motherboard',
        'memory' => 'Memory',
        'graphics_card' => 'Graphics card',
        'power_supply' => 'Power supply',
        'storage' => 'Storage',
        'computer_case' => 'Computer case',
        'cpu_cooler' => 'CPU cooler',
    ];

    /** @param array<string, array<string, mixed>> $components
     *  @return array<string, mixed>
     */
    public function validate(array $components, string $mode = 'partial'): array
    {
        $checks = [];
        foreach ($components as $field => $component) {
            $available = (int) ($component['available_quantity'] ?? 0);
            $this->check(
                $checks,
                'live_offer_available',
                $available > 0 ? 'pass' : 'warning',
                $available > 0
                    ? sprintf('%s is available from an approved seller.', $component['name'])
                    : sprintf('%s currently has no approved in-stock offer.', $component['name']),
                [$field]
            );
        }

        $cpu = $components['processor'] ?? null;
        $board = $components['motherboard'] ?? null;
        $memory = $components['memory'] ?? null;
        $gpu = $components['graphics_card'] ?? null;
        $psu = $components['power_supply'] ?? null;
        $storage = $components['storage'] ?? null;
        $case = $components['computer_case'] ?? null;
        $cooler = $components['cpu_cooler'] ?? null;

        if ($cpu !== null && $board !== null) {
            $this->equalRule(
                $checks, 'cpu_motherboard_socket', $cpu, 'socket',
                $board, 'cpu_socket', 'processor socket',
                'The processor and motherboard use %s.',
                'The processor uses %s, but the motherboard uses %s.',
                ['processor', 'motherboard']
            );
            $this->containsRule(
                $checks, 'cpu_motherboard_family',
                $board, 'supported_cpu_families', $cpu, 'architecture_family',
                'The motherboard supports the processor architecture family %s.',
                'The motherboard does not list processor architecture family %s as supported.',
                ['processor', 'motherboard']
            );
            $this->containsRule(
                $checks, 'cpu_motherboard_chipset',
                $cpu, 'supported_chipsets', $board, 'chipset',
                'The processor platform supports the %s chipset.',
                'The processor platform does not list the %s chipset as supported.',
                ['processor', 'motherboard']
            );
            $family = (string) $this->spec($cpu, 'architecture_family');
            $biosNote = trim((string) $this->spec($board, 'bios_support_note'));
            if ($biosNote === '') {
                $this->unknown(
                    $checks, 'motherboard_bios_review',
                    'No BIOS support note is available for this motherboard.',
                    ['processor', 'motherboard']
                );
            } elseif ($family === 'raptor_lake_refresh') {
                $this->check(
                    $checks, 'motherboard_bios_review', 'warning',
                    'Confirm the installed motherboard BIOS supports this later LGA1700 processor before assembly.',
                    ['processor', 'motherboard']
                );
            } else {
                $this->check(
                    $checks, 'motherboard_bios_review', 'pass',
                    'No additional BIOS warning is identified by the current structured platform facts.',
                    ['processor', 'motherboard']
                );
            }
        }

        if ($board !== null && $memory !== null) {
            $this->equalRule(
                $checks, 'motherboard_memory_generation', $board,
                'ram_generation', $memory, 'ddr_generation', 'memory generation',
                'The motherboard and memory both use %s.',
                'The motherboard requires %s, but the memory kit is %s.',
                ['motherboard', 'memory']
            );
            $this->maximumRule(
                $checks, 'motherboard_memory_capacity', $memory, 'capacity_gb',
                $board, 'max_memory_capacity_gb', 'GB',
                'The %s GB memory kit is within the motherboard limit of %s GB.',
                'The %s GB memory kit exceeds the motherboard limit of %s GB.',
                ['motherboard', 'memory']
            );
            $this->maximumRule(
                $checks, 'motherboard_memory_slots', $memory, 'module_count',
                $board, 'memory_slots', 'slots',
                'The memory kit uses %s modules and the motherboard has %s slots.',
                'The memory kit requires %s modules, but the motherboard has only %s slots.',
                ['motherboard', 'memory']
            );
            $speed = $this->numberSpec($memory, 'speed_mhz');
            $maximumSpeed = $this->numberSpec($board, 'max_memory_speed_mhz');
            if ($speed === null || $maximumSpeed === null) {
                $this->unknown(
                    $checks, 'motherboard_memory_speed',
                    'Memory speed support cannot be confirmed because a speed fact is missing.',
                    ['motherboard', 'memory']
                );
            } elseif ($speed > $maximumSpeed) {
                $this->check(
                    $checks, 'motherboard_memory_speed', 'warning',
                    sprintf(
                        'The %s MHz memory may run at the motherboard limit of %s MHz.',
                        $this->formatNumber($speed), $this->formatNumber($maximumSpeed)
                    ),
                    ['motherboard', 'memory']
                );
            } else {
                $this->check(
                    $checks, 'motherboard_memory_speed', 'pass',
                    sprintf(
                        'The motherboard supports the selected %s MHz memory speed.',
                        $this->formatNumber($speed)
                    ),
                    ['motherboard', 'memory']
                );
            }
        }

        if ($board !== null && $case !== null) {
            $this->containsRule(
                $checks, 'motherboard_case_form_factor', $case,
                'motherboard_form_factors', $board, 'form_factor',
                'The case supports the %s motherboard form factor.',
                'The case does not support the %s motherboard form factor.',
                ['motherboard', 'computer_case']
            );
        }

        if ($gpu !== null && $case !== null) {
            $this->maximumRule(
                $checks, 'gpu_case_length', $gpu, 'gpu_length_mm',
                $case, 'max_gpu_length_mm', 'mm',
                'The %s mm graphics card fits within the %s mm case clearance.',
                'The %s mm graphics card exceeds the %s mm case clearance.',
                ['graphics_card', 'computer_case']
            );
            $this->maximumRule(
                $checks, 'gpu_case_thickness', $gpu, 'gpu_thickness_slots',
                $case, 'max_gpu_thickness_slots', 'slots',
                'The %s-slot graphics card fits the case limit of %s slots.',
                'The %s-slot graphics card exceeds the case limit of %s slots.',
                ['graphics_card', 'computer_case']
            );
        }

        if ($psu !== null && $case !== null) {
            $this->containsRule(
                $checks, 'psu_case_form_factor', $case, 'psu_form_factors',
                $psu, 'form_factor',
                'The case supports the %s PSU form factor.',
                'The case does not support the %s PSU form factor.',
                ['power_supply', 'computer_case']
            );
        }

        if ($gpu !== null && $psu !== null) {
            $this->minimumRule(
                $checks, 'gpu_psu_wattage', $psu, 'wattage',
                $gpu, 'recommended_psu_watts', 'W',
                'The %s W PSU meets the graphics-card recommendation of %s W.',
                'The %s W PSU is below the graphics-card recommendation of %s W.',
                ['graphics_card', 'power_supply']
            );
            $requiredConnectors = $this->listSpec($gpu, 'power_connectors');
            $availableConnectors = $this->listSpec($psu, 'available_connectors');
            if ($requiredConnectors === null || $availableConnectors === null) {
                $this->unknown(
                    $checks, 'gpu_psu_connectors',
                    'Graphics power connectors cannot be confirmed because connector data is missing.',
                    ['graphics_card', 'power_supply']
                );
            } else {
                // "none" is the controlled catalogue value for a slot-powered
                // graphics card; it is not a connector the PSU must provide.
                $requiredConnectors = array_values(array_filter(
                    $requiredConnectors,
                    static fn (string $connector): bool => $connector !== 'none'
                ));
                $missing = array_values(array_diff($requiredConnectors, $availableConnectors));
                foreach ($requiredConnectors as $connector) {
                    $countCode = match ($connector) {
                        'six_pin' => 'six_pin_connector_count',
                        'eight_pin' => 'eight_pin_connector_count',
                        'twelve_vhpwr' => 'twelve_vhpwr_connector_count',
                        default => null,
                    };
                    if ($countCode !== null && ($this->numberSpec($psu, $countCode) ?? 0) < 1) {
                        $missing[] = $connector;
                    }
                }
                $missing = array_values(array_unique($missing));
                $this->check(
                    $checks, 'gpu_psu_connectors', $missing === [] ? 'pass' : 'fail',
                    $missing === []
                        ? 'The PSU provides the connector types required by the graphics card.'
                        : 'The PSU is missing required graphics connector(s): '
                            . implode(', ', array_map([$this, 'humanCode'], $missing)) . '.',
                    ['graphics_card', 'power_supply']
                );
            }
        }

        if ($storage !== null && $board !== null) {
            $storageType = (string) $this->spec($storage, 'storage_type');
            $interface = (string) $this->spec($storage, 'interface');
            $m2Slots = $this->numberSpec($board, 'm2_slots');
            $interfaces = $this->listSpec($board, 'm2_interfaces');
            if ($storageType === '' || $interface === '' || $m2Slots === null || $interfaces === null) {
                $this->unknown(
                    $checks, 'storage_motherboard_interface',
                    'Storage compatibility cannot be confirmed because interface or slot data is missing.',
                    ['storage', 'motherboard']
                );
            } elseif ($storageType === 'nvme_ssd') {
                $supported = $m2Slots >= 1 && $this->pcieInterfaceSupported($interface, $interfaces);
                $this->check(
                    $checks, 'storage_motherboard_interface', $supported ? 'pass' : 'fail',
                    $supported
                        ? sprintf('The motherboard has a compatible M.2 slot for the %s drive.', $this->humanCode($interface))
                        : sprintf('The motherboard has no compatible M.2 interface for the %s drive.', $this->humanCode($interface)),
                    ['storage', 'motherboard']
                );
            } else {
                $this->check(
                    $checks, 'storage_motherboard_interface', 'unknown',
                    'This storage type requires SATA-port and bay data that is not yet present.',
                    ['storage', 'motherboard']
                );
            }
        }

        if ($cpu !== null && $cooler !== null) {
            $this->containsRule(
                $checks, 'cpu_cooler_socket', $cooler, 'supported_sockets',
                $cpu, 'socket',
                'The CPU cooler supports the %s processor socket.',
                'The CPU cooler does not support the %s processor socket.',
                ['processor', 'cpu_cooler']
            );
            $thermalTarget = $this->numberSpec($cpu, 'peak_power_watts')
                ?? $this->numberSpec($cpu, 'tdp_watts');
            $capacity = $this->numberSpec($cooler, 'cooling_capacity_watts');
            if ($thermalTarget === null || $capacity === null) {
                $this->unknown(
                    $checks, 'cpu_cooler_capacity',
                    'Cooling capacity cannot be confirmed because thermal data is missing.',
                    ['processor', 'cpu_cooler']
                );
            } else {
                $this->check(
                    $checks, 'cpu_cooler_capacity',
                    $capacity >= $thermalTarget ? 'pass' : 'fail',
                    $capacity >= $thermalTarget
                        ? sprintf('The cooler capacity of %s W covers the processor target of %s W.', $this->formatNumber($capacity), $this->formatNumber($thermalTarget))
                        : sprintf('The cooler capacity of %s W is below the processor target of %s W.', $this->formatNumber($capacity), $this->formatNumber($thermalTarget)),
                    ['processor', 'cpu_cooler']
                );
            }
        }

        if ($cooler !== null && $case !== null) {
            $coolerType = (string) $this->spec($cooler, 'cooler_type');
            if ($coolerType === 'air') {
                $this->maximumRule(
                    $checks, 'cooler_case_height', $cooler, 'cooler_height_mm',
                    $case, 'max_cpu_cooler_height_mm', 'mm',
                    'The %s mm air cooler fits within the %s mm case limit.',
                    'The %s mm air cooler exceeds the %s mm case limit.',
                    ['cpu_cooler', 'computer_case']
                );
            } elseif ($coolerType === 'aio') {
                $this->containsRule(
                    $checks, 'cooler_case_radiator', $case,
                    'supported_radiator_sizes', $cooler, 'radiator_size',
                    'The case supports the selected %s radiator.',
                    'The case does not support the selected %s radiator.',
                    ['cpu_cooler', 'computer_case']
                );
            } else {
                $this->unknown(
                    $checks, 'cooler_case_height',
                    'The cooler type is missing, so physical case fit cannot be confirmed.',
                    ['cpu_cooler', 'computer_case']
                );
            }
        }

        if ($cpu !== null && $psu !== null) {
            $cpuPower = $this->numberSpec($cpu, 'peak_power_watts');
            $gpuPower = $gpu === null ? 0 : $this->numberSpec($gpu, 'total_board_power_watts');
            $psuWattage = $this->numberSpec($psu, 'wattage');
            if ($cpuPower === null || $gpuPower === null || $psuWattage === null) {
                $this->unknown(
                    $checks, 'system_power_headroom',
                    'Whole-system power headroom cannot be estimated because a power fact is missing.',
                    array_values(array_filter(['processor', $gpu ? 'graphics_card' : null, 'power_supply']))
                );
            } else {
                $estimatedLoad = $cpuPower + $gpuPower + 80;
                $recommended = (int) ceil($estimatedLoad * 1.25 / 10) * 10;
                $status = $psuWattage < $estimatedLoad
                    ? 'fail'
                    : ($psuWattage < $recommended ? 'warning' : 'pass');
                $this->check(
                    $checks, 'system_power_headroom', $status,
                    $status === 'pass'
                        ? sprintf('The %s W PSU provides headroom above the estimated %s W load.', $this->formatNumber($psuWattage), $this->formatNumber($estimatedLoad))
                        : ($status === 'warning'
                            ? sprintf('The %s W PSU covers the estimated %s W load but is below the recommended %s W headroom target.', $this->formatNumber($psuWattage), $this->formatNumber($estimatedLoad), $this->formatNumber($recommended))
                            : sprintf('The %s W PSU is below the estimated %s W system load.', $this->formatNumber($psuWattage), $this->formatNumber($estimatedLoad))),
                    array_values(array_filter(['processor', $gpu ? 'graphics_card' : null, 'power_supply']))
                );
            }
        }

        $missing = $this->missingComponents($components);
        if ($mode === 'complete') {
            if ($cpu !== null && $gpu === null) {
                $integrated = $this->spec($cpu, 'integrated_graphics');
                if ($integrated === null) {
                    $this->unknown(
                        $checks, 'display_output_available',
                        'No graphics card is selected and integrated graphics data is missing.',
                        ['processor']
                    );
                } elseif ($integrated === false || $integrated === 0) {
                    $this->check(
                        $checks, 'display_output_available', 'fail',
                        'This processor has no integrated graphics, so a graphics card is required.',
                        ['processor']
                    );
                    if (!in_array('graphics_card', $missing, true)) {
                        $missing[] = 'graphics_card';
                    }
                } else {
                    $this->check(
                        $checks, 'display_output_available', 'pass',
                        'The processor provides integrated graphics, so a separate graphics card is optional.',
                        ['processor']
                    );
                }
            } elseif ($gpu !== null) {
                $this->check(
                    $checks, 'display_output_available', 'pass',
                    'A graphics card is selected for display output.',
                    ['graphics_card']
                );
            }
            if ($cpu !== null && $cooler === null) {
                $included = $this->spec($cpu, 'cooler_included');
                if ($included === false || $included === 0) {
                    if (!in_array('cpu_cooler', $missing, true)) {
                        $missing[] = 'cpu_cooler';
                    }
                }
            }
            if ($missing !== []) {
                $this->unknown(
                    $checks, 'build_completeness',
                    'A complete compatibility guarantee needs: '
                        . implode(', ', array_map(fn (string $field): string => self::LABELS[$field], $missing)) . '.',
                    $missing
                );
            } else {
                $this->check(
                    $checks, 'build_completeness', 'pass',
                    'All required component groups are present for complete validation.',
                    array_keys($components)
                );
            }
        }

        $overallStatus = $this->overallStatus($checks);
        return [
            'rule_set_version' => self::RULE_SET_VERSION,
            'mode' => $mode,
            'overall_status' => $overallStatus,
            'is_compatible' => in_array($overallStatus, ['compatible', 'warning'], true),
            'complete' => $mode === 'complete' && $missing === [],
            'missing_components' => array_values(array_unique($missing)),
            'summary' => $this->summary($checks),
            'checks' => $checks,
        ];
    }

    /** @param array<string, array<string, mixed>> $components
     *  @return array<int, string>
     */
    private function missingComponents(array $components): array
    {
        $required = [
            'processor', 'motherboard', 'memory', 'power_supply',
            'storage', 'computer_case',
        ];
        return array_values(array_filter(
            $required,
            static fn (string $field): bool => !isset($components[$field])
        ));
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @param array<int, string> $componentFields
     */
    private function check(
        array &$checks,
        string $code,
        string $status,
        string $message,
        array $componentFields
    ): void {
        $checks[] = [
            'rule_code' => $code,
            'status' => $status,
            'message' => $message,
            'component_fields' => array_values($componentFields),
        ];
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @param array<int, string> $fields
     */
    private function unknown(array &$checks, string $code, string $message, array $fields): void
    {
        $this->check($checks, $code, 'unknown', $message, $fields);
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @param array<string, mixed> $left
     *  @param array<string, mixed> $right
     *  @param array<int, string> $fields
     */
    private function equalRule(
        array &$checks,
        string $code,
        array $left,
        string $leftSpec,
        array $right,
        string $rightSpec,
        string $_label,
        string $passTemplate,
        string $failTemplate,
        array $fields
    ): void {
        $leftValue = $this->spec($left, $leftSpec);
        $rightValue = $this->spec($right, $rightSpec);
        if ($leftValue === null || $rightValue === null || $leftValue === '' || $rightValue === '') {
            $this->unknown($checks, $code, 'Compatibility cannot be confirmed because a required specification is missing.', $fields);
            return;
        }
        $matches = (string) $leftValue === (string) $rightValue;
        $this->check(
            $checks, $code, $matches ? 'pass' : 'fail',
            sprintf(
                $matches ? $passTemplate : $failTemplate,
                $this->humanCode((string) $leftValue),
                $this->humanCode((string) $rightValue)
            ),
            $fields
        );
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @param array<string, mixed> $container
     *  @param array<string, mixed> $item
     *  @param array<int, string> $fields
     */
    private function containsRule(
        array &$checks,
        string $code,
        array $container,
        string $listSpec,
        array $item,
        string $valueSpec,
        string $passTemplate,
        string $failTemplate,
        array $fields
    ): void {
        $list = $this->listSpec($container, $listSpec);
        $value = $this->spec($item, $valueSpec);
        if ($list === null || $value === null || $value === '') {
            $this->unknown($checks, $code, 'Compatibility cannot be confirmed because a required specification is missing.', $fields);
            return;
        }
        $matches = in_array((string) $value, $list, true);
        $this->check(
            $checks, $code, $matches ? 'pass' : 'fail',
            sprintf($matches ? $passTemplate : $failTemplate, $this->humanCode((string) $value)),
            $fields
        );
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @param array<string, mixed> $actualProduct
     *  @param array<string, mixed> $limitProduct
     *  @param array<int, string> $fields
     */
    private function maximumRule(
        array &$checks,
        string $code,
        array $actualProduct,
        string $actualSpec,
        array $limitProduct,
        string $limitSpec,
        string $_unit,
        string $passTemplate,
        string $failTemplate,
        array $fields
    ): void {
        $actual = $this->numberSpec($actualProduct, $actualSpec);
        $limit = $this->numberSpec($limitProduct, $limitSpec);
        if ($actual === null || $limit === null) {
            $this->unknown($checks, $code, 'Compatibility cannot be confirmed because a required numeric specification is missing.', $fields);
            return;
        }
        $passes = $actual <= $limit;
        $this->check(
            $checks, $code, $passes ? 'pass' : 'fail',
            sprintf($passes ? $passTemplate : $failTemplate, $this->formatNumber($actual), $this->formatNumber($limit)),
            $fields
        );
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @param array<string, mixed> $actualProduct
     *  @param array<string, mixed> $requiredProduct
     *  @param array<int, string> $fields
     */
    private function minimumRule(
        array &$checks,
        string $code,
        array $actualProduct,
        string $actualSpec,
        array $requiredProduct,
        string $requiredSpec,
        string $_unit,
        string $passTemplate,
        string $failTemplate,
        array $fields
    ): void {
        $actual = $this->numberSpec($actualProduct, $actualSpec);
        $required = $this->numberSpec($requiredProduct, $requiredSpec);
        if ($actual === null || $required === null) {
            $this->unknown($checks, $code, 'Compatibility cannot be confirmed because a required numeric specification is missing.', $fields);
            return;
        }
        $passes = $actual >= $required;
        $this->check(
            $checks, $code, $passes ? 'pass' : 'fail',
            sprintf($passes ? $passTemplate : $failTemplate, $this->formatNumber($actual), $this->formatNumber($required)),
            $fields
        );
    }

    /** @param array<string, mixed> $product */
    private function spec(array $product, string $code): mixed
    {
        return is_array($product['specifications'] ?? null)
            ? ($product['specifications'][$code] ?? null)
            : null;
    }

    /** @param array<string, mixed> $product */
    private function numberSpec(array $product, string $code): ?float
    {
        $value = $this->spec($product, $code);
        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $product
     *  @return array<int, string>|null
     */
    private function listSpec(array $product, string $code): ?array
    {
        $value = $this->spec($product, $code);
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        return array_values(array_map('strval', $value));
    }

    /** @param array<int, string> $boardInterfaces */
    private function pcieInterfaceSupported(string $driveInterface, array $boardInterfaces): bool
    {
        $generation = ['pcie_3' => 3, 'pcie_4' => 4, 'pcie_5' => 5][$driveInterface] ?? null;
        if ($generation === null) {
            return in_array($driveInterface, $boardInterfaces, true);
        }
        foreach ($boardInterfaces as $interface) {
            $boardGeneration = ['pcie_3' => 3, 'pcie_4' => 4, 'pcie_5' => 5][$interface] ?? null;
            // PCIe NVMe generations interoperate in either direction and run
            // at the highest generation supported by both endpoints.
            if ($boardGeneration !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, array<string, mixed>> $checks */
    private function overallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('fail', $statuses, true)) {
            return 'incompatible';
        }
        if (in_array('unknown', $statuses, true)) {
            return 'unknown';
        }
        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }
        return 'compatible';
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @return array<string, int>
     */
    private function summary(array $checks): array
    {
        $summary = ['passed' => 0, 'warnings' => 0, 'failed' => 0, 'unknown' => 0];
        foreach ($checks as $check) {
            $key = match ($check['status']) {
                'pass' => 'passed',
                'warning' => 'warnings',
                'fail' => 'failed',
                default => 'unknown',
            };
            $summary[$key]++;
        }
        return $summary;
    }

    private function humanCode(string $value): string
    {
        return strtoupper(str_replace(['_', '-'], [' ', ' '], $value));
    }

    private function formatNumber(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? number_format($value, 0, '.', ',')
            : rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
