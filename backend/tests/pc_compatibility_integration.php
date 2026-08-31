<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Services\PcCompatibilityEngine;
use Hexbay\Services\PcCompatibilityService;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = Database::connection();
    $repository = new PcCompatibilityRepository($db);
    $service = new PcCompatibilityService($repository, new PcCompatibilityEngine());

    $models = [
        'Ryzen 5 5500', 'A520M DS3H WIFI6E',
        'B650M Gaming WIFI', 'Prism Pro RGB 16GB 3200',
        'GV-R76GAMING-OC-8GD', 'FP-650', 'SN580 500GB',
        'IDUN', 'AG400 PLUS',
    ];
    $statement = $db->prepare(
        'SELECT model, id FROM canonical_products
         WHERE model IN (' . implode(',', array_fill(0, count($models), '?')) . ')'
    );
    $statement->execute($models);
    $ids = [];
    foreach ($statement->fetchAll() as $row) {
        $ids[(string) $row['model']] = (int) $row['id'];
    }
    if (count($ids) !== count($models)) {
        throw new RuntimeException('Run the PC catalogue and knowledge seeds first.');
    }

    $beforeLogs = (int) $db->query(
        'SELECT COUNT(*) FROM pc_compatibility_validations'
    )->fetchColumn();
    $compatible = $service->validate([
        'mode' => 'complete',
        'components' => [
            'processor' => $ids['Ryzen 5 5500'],
            'motherboard' => $ids['A520M DS3H WIFI6E'],
            'memory' => $ids['Prism Pro RGB 16GB 3200'],
            'graphics_card' => $ids['GV-R76GAMING-OC-8GD'],
            'power_supply' => $ids['FP-650'],
            'storage' => $ids['SN580 500GB'],
            'computer_case' => $ids['IDUN'],
            'cpu_cooler' => $ids['AG400 PLUS'],
        ],
    ]);
    if (
        $compatible['overall_status'] !== 'compatible'
        || $compatible['complete'] !== true
        || $compatible['summary']['failed'] !== 0
        || count($compatible['checks']) < 20
    ) {
        throw new RuntimeException('Known-good AM5 build was not approved.');
    }

    $incompatible = $service->validate([
        'mode' => 'partial',
        'components' => [
            'processor' => $ids['Ryzen 5 5500'],
            'motherboard' => $ids['B650M Gaming WIFI'],
            'memory' => $ids['Prism Pro RGB 16GB 3200'],
        ],
    ]);
    $failedCodes = array_column(array_filter(
        $incompatible['checks'],
        static fn (array $check): bool => $check['status'] === 'fail'
    ), 'rule_code');
    foreach ([
        'cpu_motherboard_socket', 'cpu_motherboard_family',
        'cpu_motherboard_chipset', 'motherboard_memory_generation',
    ] as $expectedCode) {
        if (!in_array($expectedCode, $failedCodes, true)) {
            throw new RuntimeException("Expected failure {$expectedCode} was not returned.");
        }
    }

    $alternatives = $service->alternatives([
        'mode' => 'partial',
        'target_component' => 'motherboard',
        'limit' => 5,
        'components' => [
            'processor' => $ids['Ryzen 5 5500'],
            'motherboard' => $ids['B650M Gaming WIFI'],
            'memory' => $ids['Prism Pro RGB 16GB 3200'],
            'computer_case' => $ids['IDUN'],
        ],
    ]);
    if ($alternatives['alternative_count'] < 1) {
        throw new RuntimeException('No compatible replacement motherboard was suggested.');
    }
    foreach ($alternatives['alternatives'] as $alternative) {
        if (!in_array($alternative['compatibility_status'], ['compatible', 'warning'], true)) {
            throw new RuntimeException('An unsafe alternative escaped compatibility filtering.');
        }
        if ($alternative['component']['category_slug'] !== 'motherboards') {
            throw new RuntimeException('An alternative came from the wrong category.');
        }
    }

    $afterLogs = (int) $db->query(
        'SELECT COUNT(*) FROM pc_compatibility_validations'
    )->fetchColumn();
    if ($afterLogs !== $beforeLogs + 2) {
        throw new RuntimeException('Compatibility validation logs were not recorded exactly once.');
    }
    $latest = $db->query(
        'SELECT selected_product_ids_json, result_summary_json
         FROM pc_compatibility_validations ORDER BY id DESC LIMIT 1'
    )->fetch();
    $selected = json_decode((string) $latest['selected_product_ids_json'], true);
    $summary = json_decode((string) $latest['result_summary_json'], true);
    if (
        !is_array($selected) || !array_is_list($selected)
        || !is_array($summary)
        || array_key_exists('specifications', $summary)
    ) {
        throw new RuntimeException('Compatibility log is not privacy-minimised.');
    }

    $ruleCount = (int) $db->query(
        'SELECT COUNT(*) FROM pc_compatibility_rules r
         INNER JOIN pc_compatibility_rule_sets rs ON rs.id=r.rule_set_id
         WHERE rs.version="pc-compat-v1.0.0" AND rs.status="active"
           AND r.is_active=TRUE'
    )->fetchColumn();
    if ($ruleCount !== 22) {
        throw new RuntimeException('Compatibility rule metadata is incomplete.');
    }

    fwrite(
        STDOUT,
        sprintf(
            "PC compatibility integration passed (%d checks, %d failed mismatch rules, %d safe alternatives).\n",
            count($compatible['checks']),
            count($failedCodes),
            $alternatives['alternative_count']
        )
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "PC compatibility integration failed: {$exception->getMessage()}\n");
    exit(1);
}
