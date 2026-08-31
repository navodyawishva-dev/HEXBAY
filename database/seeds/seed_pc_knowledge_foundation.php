<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__, 2) . '/backend/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This knowledge seed must be run from the command line.\n");
    exit(1);
}

$sources = [
    'hexbay_demo_catalogue' => [
        'name' => 'HEXBAY local demonstration catalogue',
        'type' => 'internal_curated',
        'url' => null,
        'licence' => 'Local development fixture; not a substitute for manufacturer verification.',
    ],
    'hexbay_performance_v1' => [
        'name' => 'HEXBAY normalized component scoring model v1',
        'type' => 'internal_curated',
        'url' => null,
        'licence' => 'Explainable normalized demonstration scores maintained by the HEXBAY project.',
    ],
    'hexbay_workloads_v1' => [
        'name' => 'HEXBAY workload requirement model v1',
        'type' => 'internal_curated',
        'url' => null,
        'licence' => 'Project-owned workload profiles for local evaluation and refinement.',
    ],
    'novacore_demo_seller' => [
        'name' => 'NovaCore Systems demonstration seller feed',
        'type' => 'seller',
        'url' => null,
        'licence' => 'Fictional local price and stock fixture.',
    ],
    'bytecraft_demo_seller' => [
        'name' => 'ByteCraft Technologies demonstration seller feed',
        'type' => 'seller',
        'url' => null,
        'licence' => 'Fictional local price and stock fixture.',
    ],
];

$benchmarkDefinitions = [
    'component_overall_index' => [null, 'Overall component capability', 'score', true,
        'Normalized category-relative capability used for explainable build balancing.'],
    'component_value_index' => [null, 'Component value index', 'score', true,
        'Normalized capability relative to the local demonstration price tier.'],
    'cpu_single_core_index' => ['processors', 'CPU single-core index', 'score', true,
        'Normalized single-thread responsiveness proxy.'],
    'cpu_multi_core_index' => ['processors', 'CPU multi-core index', 'score', true,
        'Normalized sustained multi-thread capability proxy.'],
    'gpu_raster_index' => ['graphics-cards', 'GPU raster performance index', 'score', true,
        'Normalized conventional graphics performance proxy.'],
    'gpu_compute_index' => ['graphics-cards', 'GPU compute capability index', 'score', true,
        'Normalized compute-workload capability proxy including VRAM and API support.'],
    'storage_responsiveness_index' => ['storage', 'Storage responsiveness index', 'score', true,
        'Normalized storage responsiveness proxy using interface and sequential performance.'],
];

$workloads = [
    'balanced_general' => ['Balanced general use',
        'A responsive, versatile desktop when the customer provides a budget but no specialist workload.'],
    'office_study' => ['Office and study',
        'Documents, browsing, online learning, communication and normal productivity applications.'],
    'gaming_1080p' => ['1080p gaming',
        'Modern games at Full HD with balanced CPU and graphics performance.'],
    'gaming_1440p' => ['1440p gaming',
        'Modern games at 2560x1440 with stronger graphics and sufficient VRAM.'],
    'gaming_4k' => ['4K gaming',
        'GPU-intensive gaming at 3840x2160 with high graphics and power requirements.'],
    'programming' => ['Programming and development',
        'Web, desktop and mobile development with editors, local services and normal compilation.'],
    'software_compilation' => ['Large software compilation',
        'Large codebases and frequent builds benefiting from multi-core CPU performance and memory.'],
    'virtual_machines' => ['Virtual machines and containers',
        'Concurrent virtual machines, containers and local infrastructure environments.'],
    'graphic_design' => ['Graphic design and photography',
        'Layered image editing, illustration and colour-sensitive creative applications.'],
    'video_editing' => ['Video editing',
        'Timeline editing, effects, encoding and export with high memory and fast storage needs.'],
    'live_streaming' => ['Live streaming',
        'Simultaneous content capture, hardware encoding, gaming or presentation and broadcast.'],
    'music_production' => ['Music production',
        'Digital audio workstations, instruments, effects and low-latency multi-track projects.'],
    'three_d_rendering' => ['3D modelling and rendering',
        'Complex scenes, GPU or CPU rendering, simulation and large project assets.'],
    'cad_engineering' => ['CAD and engineering',
        'Computer-aided design, engineering applications, simulation and technical modelling.'],
    'ai_ml' => ['AI and machine learning',
        'Local model experimentation, GPU acceleration, data preparation and memory-heavy notebooks.'],
    'home_server_nas' => ['Home server and NAS',
        'Always-on services, storage, backups, media hosting and efficient multi-user operation.'],
    'quiet_efficiency' => ['Quiet and energy-efficient',
        'Low-noise, low-power desktop operation without unnecessary performance overhead.'],
    'upgrade_focused' => ['Upgrade-focused build',
        'A platform selected for future component expansion and replacement flexibility.'],
];

/*
 * Requirements are explainable normalized targets. They are starting points
 * for evaluation, not claims about a specific game or commercial application.
 * [category slug|null, metric, minimum, recommended, ideal, weight, hard, rationale]
 */
$requirements = [
    'balanced_general' => [
        ['processors', 'component_overall_index', 35, 55, 75, 0.75, true, 'General responsiveness needs a capable modern processor.'],
        ['memory', 'capacity_gb', 8, 16, 32, 0.65, true, '8 GB is workable for general use; 16 GB is preferred for smoother multitasking.'],
        ['storage', 'capacity_gb', 500, 1000, 2000, 0.55, true, 'A practical system needs room for applications and user files.'],
        ['storage', 'storage_responsiveness_index', 35, 55, 80, 0.45, false, 'Responsive storage improves everyday startup and application loading.'],
    ],
    'office_study' => [
        ['processors', 'component_overall_index', 25, 45, 65, 0.60, true, 'Office work values responsive rather than extreme compute performance.'],
        ['memory', 'capacity_gb', 8, 16, 32, 0.70, true, 'Adequate memory supports browsers, documents and calls together.'],
        ['storage', 'capacity_gb', 500, 500, 1000, 0.45, true, 'A moderate SSD is sufficient for documents and applications.'],
    ],
    'gaming_1080p' => [
        ['processors', 'cpu_single_core_index', 40, 60, 80, 0.70, true, 'Game responsiveness depends on sufficient single-core CPU capability.'],
        ['graphics-cards', 'gpu_raster_index', 40, 60, 80, 1.00, true, 'The graphics card is the primary 1080p gaming performance driver.'],
        ['graphics-cards', 'vram_gb', 6, 8, 12, 0.70, true, 'Modern textures require adequate graphics memory.'],
        ['memory', 'capacity_gb', 16, 16, 32, 0.60, true, '16 GB is the baseline for a balanced gaming system.'],
    ],
    'gaming_1440p' => [
        ['processors', 'cpu_single_core_index', 50, 65, 85, 0.65, true, 'A capable CPU supports stable frame delivery.'],
        ['graphics-cards', 'gpu_raster_index', 60, 78, 95, 1.00, true, '1440p places greater demand on the graphics card.'],
        ['graphics-cards', 'vram_gb', 8, 12, 16, 0.80, true, 'Higher resolution benefits from additional graphics memory.'],
        ['memory', 'capacity_gb', 16, 32, 32, 0.60, true, '32 GB provides headroom for modern games and background tasks.'],
    ],
    'gaming_4k' => [
        ['processors', 'cpu_single_core_index', 55, 70, 90, 0.55, true, 'The CPU must avoid limiting a premium graphics card.'],
        ['graphics-cards', 'gpu_raster_index', 80, 92, 100, 1.00, true, '4K is primarily constrained by graphics performance.'],
        ['graphics-cards', 'vram_gb', 12, 16, 24, 0.90, true, 'High-resolution assets require substantial graphics memory.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.55, true, 'System memory must support the game and background workloads.'],
    ],
    'programming' => [
        ['processors', 'cpu_multi_core_index', 35, 60, 80, 0.80, true, 'Compilation and local services benefit from CPU parallelism.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.90, true, 'Development tools and local services consume significant memory.'],
        ['storage', 'capacity_gb', 500, 1000, 2000, 0.55, true, 'Projects, dependencies and toolchains require SSD capacity.'],
    ],
    'software_compilation' => [
        ['processors', 'cpu_multi_core_index', 55, 75, 95, 1.00, true, 'Large parallel builds scale with sustained multi-core CPU capability.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.75, true, 'Large builds and link steps need memory headroom.'],
        ['storage', 'storage_responsiveness_index', 50, 70, 90, 0.55, false, 'Fast source and dependency access improves repeated builds.'],
    ],
    'virtual_machines' => [
        ['processors', 'cpu_multi_core_index', 45, 70, 90, 0.85, true, 'Concurrent guests require multiple CPU cores and threads.'],
        ['memory', 'capacity_gb', 32, 64, 128, 1.00, true, 'Virtual machines reserve significant system memory.'],
        ['storage', 'capacity_gb', 1000, 2000, 4000, 0.70, true, 'Virtual disks require substantial fast storage.'],
    ],
    'graphic_design' => [
        ['processors', 'component_overall_index', 40, 60, 80, 0.55, true, 'Creative applications need responsive CPU performance.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.85, true, 'Large layered documents depend on memory capacity.'],
        ['graphics-cards', 'vram_gb', 4, 8, 12, 0.45, false, 'GPU-accelerated effects benefit from graphics memory.'],
        ['storage', 'capacity_gb', 1000, 2000, 4000, 0.55, true, 'Source assets and exports consume storage quickly.'],
    ],
    'video_editing' => [
        ['processors', 'cpu_multi_core_index', 50, 75, 95, 0.90, true, 'Effects and software encoding benefit from multi-core performance.'],
        ['graphics-cards', 'gpu_compute_index', 45, 70, 90, 0.80, false, 'GPU acceleration improves effects and supported encoders.'],
        ['graphics-cards', 'vram_gb', 6, 12, 16, 0.75, false, 'Higher-resolution timelines require more graphics memory.'],
        ['memory', 'capacity_gb', 32, 64, 128, 0.95, true, 'Video timelines and caches require substantial RAM.'],
        ['storage', 'capacity_gb', 1000, 2000, 4000, 0.70, true, 'Video media requires high-capacity storage.'],
    ],
    'live_streaming' => [
        ['processors', 'cpu_multi_core_index', 40, 65, 85, 0.65, true, 'Streaming adds capture and broadcast workloads.'],
        ['graphics-cards', 'gpu_compute_index', 40, 65, 85, 0.75, false, 'Hardware encoding can reduce broadcast overhead.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.65, true, 'Streaming applications and the primary workload run together.'],
    ],
    'music_production' => [
        ['processors', 'cpu_single_core_index', 45, 70, 90, 0.85, true, 'Real-time audio chains are sensitive to single-thread performance.'],
        ['processors', 'cpu_multi_core_index', 40, 65, 85, 0.70, true, 'Large projects distribute instruments and effects across cores.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.75, true, 'Sample libraries and instruments require memory.'],
        ['storage', 'capacity_gb', 1000, 2000, 4000, 0.65, true, 'Sample libraries require substantial SSD capacity.'],
    ],
    'three_d_rendering' => [
        ['processors', 'cpu_multi_core_index', 55, 80, 100, 0.85, true, 'CPU rendering and simulation scale with multi-core performance.'],
        ['graphics-cards', 'gpu_compute_index', 60, 82, 100, 1.00, false, 'GPU renderers benefit strongly from compute capability.'],
        ['graphics-cards', 'vram_gb', 8, 16, 24, 0.90, false, 'Complex scenes must fit in graphics memory.'],
        ['memory', 'capacity_gb', 32, 64, 128, 0.90, true, 'Large scenes and caches require substantial system memory.'],
    ],
    'cad_engineering' => [
        ['processors', 'cpu_single_core_index', 50, 75, 95, 0.85, true, 'Interactive modelling often depends on strong single-core performance.'],
        ['processors', 'cpu_multi_core_index', 40, 65, 90, 0.65, false, 'Simulation and rendering can use additional cores.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.80, true, 'Large assemblies require memory headroom.'],
        ['graphics-cards', 'vram_gb', 6, 12, 16, 0.65, false, 'Complex viewports benefit from graphics memory.'],
    ],
    'ai_ml' => [
        ['processors', 'cpu_multi_core_index', 45, 70, 90, 0.60, true, 'Data preparation and non-GPU work require capable CPU processing.'],
        ['graphics-cards', 'gpu_compute_index', 55, 80, 100, 1.00, false, 'Supported local training and inference benefit from GPU compute.'],
        ['graphics-cards', 'vram_gb', 8, 16, 24, 1.00, false, 'Model capacity is often constrained by graphics memory.'],
        ['memory', 'capacity_gb', 32, 64, 128, 0.90, true, 'Datasets and notebooks need substantial system memory.'],
        ['storage', 'capacity_gb', 1000, 2000, 4000, 0.65, true, 'Datasets, environments and checkpoints need storage.'],
    ],
    'home_server_nas' => [
        ['processors', 'component_overall_index', 25, 45, 65, 0.45, true, 'Many home services need efficiency more than peak performance.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.75, true, 'Services and filesystem caching benefit from memory.'],
        ['storage', 'capacity_gb', 2000, 4000, 8000, 1.00, true, 'A storage server requires meaningful usable capacity.'],
    ],
    'quiet_efficiency' => [
        ['processors', 'peak_power_watts', null, 88, 65, 0.80, false, 'Lower peak CPU power reduces heat and cooling noise.'],
        ['graphics-cards', 'total_board_power_watts', null, 165, 115, 0.75, false, 'Lower graphics power reduces system heat and noise.'],
        ['power-supplies', 'component_overall_index', 50, 70, 90, 0.55, true, 'A quality efficient PSU supports quiet operation.'],
    ],
    'upgrade_focused' => [
        ['motherboards', 'component_overall_index', 50, 70, 90, 0.80, true, 'Platform features determine future expansion options.'],
        ['memory', 'capacity_gb', 16, 32, 64, 0.50, true, 'A sensible initial capacity should leave room for expansion.'],
        ['power-supplies', 'component_overall_index', 55, 75, 95, 0.70, true, 'PSU headroom can accommodate later upgrades.'],
    ],
];

$performanceProfiles = [
    'Ryzen-5-5600-Demo' => ['mainstream', 52, 88, 82, 55, 82, 62, 55],
    'Ryzen-5-7600-Demo' => ['performance', 72, 82, 90, 90, 84, 82, 70],
    'Core-i5-13400F-Demo' => ['performance', 70, 84, 78, 62, 82, 78, 75],
    'Core-i7-14700K-Demo' => ['enthusiast', 94, 60, 50, 50, 84, 92, 98],
    'B550M-PRO-VDH-Demo' => ['mainstream', 52, 84, 78, 48, 80, null, null],
    'TUF-B650-PLUS-Demo' => ['performance', 78, 75, 80, 90, 86, null, null],
    'B760M-DS3H-AX-D4-Demo' => ['mainstream', 60, 78, 76, 55, 78, null, null],
    'PRO-B760-P-D5-Demo' => ['performance', 70, 76, 78, 70, 82, null, null],
    'Z790-AORUS-XTREME-X-Demo' => ['enthusiast', 96, 30, 55, 88, 90, null, null],
    'FURY-16-D4-3200-Demo' => ['entry', 42, 88, 82, 45, 82, null, null],
    'Vengeance-32-D4-3600-Demo' => ['mainstream', 60, 85, 80, 55, 84, null, null],
    'FURY-32-D5-6000-Demo' => ['performance', 78, 82, 82, 85, 84, null, null],
    'Vengeance-64-D5-6000-Demo' => ['enthusiast', 90, 68, 80, 88, 86, null, null],
    'RTX4060-Ventus-2X-Demo' => ['mainstream', 55, 82, 94, 60, 82, null, null],
    'RX7600-Gaming-OC-Demo' => ['mainstream', 52, 87, 82, 58, 80, null, null],
    'RTX4070S-Dual-Demo' => ['performance', 80, 76, 84, 72, 84, null, null],
    'RTX4080S-Gaming-OC-Demo' => ['enthusiast', 97, 48, 62, 78, 88, null, null],
    'CX550-Demo' => ['entry', 48, 82, 66, 45, 76, null, null],
    'MWE-650-Bronze-V2-Demo' => ['mainstream', 58, 84, 70, 58, 80, null, null],
    'RM750e-Demo' => ['performance', 82, 75, 88, 82, 90, null, null],
    'V850-SFX-Gold-Demo' => ['enthusiast', 90, 58, 90, 88, 90, null, null],
    'SN570-500-Demo' => ['entry', 45, 82, 86, 50, 80, null, null],
    'NV2-1TB-Demo' => ['mainstream', 58, 88, 85, 65, 78, null, null],
    '990-PRO-2TB-Demo' => ['enthusiast', 94, 62, 80, 80, 92, null, null],
    'MATREXX-40-Demo' => ['entry', 52, 86, 78, 55, 76, null, null],
    '4000D-Airflow-Demo' => ['performance', 78, 78, 86, 82, 88, null, null],
    'North-XL-Demo' => ['enthusiast', 94, 52, 78, 95, 90, null, null],
    'NR200P-Demo' => ['specialist', 76, 60, 82, 52, 86, null, null],
    'AG400-Demo' => ['entry', 55, 92, 86, 75, 80, null, null],
    'AK620-Demo' => ['performance', 84, 80, 85, 82, 88, null, null],
    'Hyper-212-Halo-Demo' => ['mainstream', 62, 82, 84, 75, 84, null, null],
    'Liquid-Freezer-III-240-Demo' => ['enthusiast', 92, 72, 78, 80, 88, null, null],
];

$db = Database::connection();

/** @return int */
function pcKnowledgeId(PDO $db, string $sql, array $params, string $message): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $id = (int) $statement->fetchColumn();
    if ($id < 1) {
        throw new RuntimeException($message);
    }
    return $id;
}

try {
    $db->beginTransaction();

    $sourceIds = [];
    foreach ($sources as $code => $source) {
        $statement = $db->prepare(
            'INSERT INTO pc_data_sources
                (code, name, source_type, base_url, licence_notes, is_active)
             VALUES (:code, :name, :type, :url, :licence, TRUE)
             ON DUPLICATE KEY UPDATE name=VALUES(name),
                source_type=VALUES(source_type), base_url=VALUES(base_url),
                licence_notes=VALUES(licence_notes), is_active=TRUE'
        );
        $statement->execute([
            'code' => $code, 'name' => $source['name'], 'type' => $source['type'],
            'url' => $source['url'], 'licence' => $source['licence'],
        ]);
        $sourceIds[$code] = pcKnowledgeId(
            $db, 'SELECT id FROM pc_data_sources WHERE code=:code',
            ['code' => $code], "Data source {$code} could not be loaded."
        );
    }

    $categoryRows = $db->query('SELECT id, slug FROM categories')->fetchAll();
    $categoryIds = [];
    foreach ($categoryRows as $category) {
        $categoryIds[(string) $category['slug']] = (int) $category['id'];
    }

    $benchmarkIds = [];
    foreach ($benchmarkDefinitions as $code => [$categorySlug, $name, $unit, $higher, $description]) {
        $statement = $db->prepare(
            'INSERT INTO pc_benchmark_definitions
                (category_id, code, display_name, unit, higher_is_better,
                 description, is_active)
             VALUES (:category, :code, :name, :unit, :higher, :description, TRUE)
             ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),
                display_name=VALUES(display_name), unit=VALUES(unit),
                higher_is_better=VALUES(higher_is_better),
                description=VALUES(description), is_active=TRUE'
        );
        $statement->execute([
            'category' => $categorySlug === null ? null : $categoryIds[$categorySlug],
            'code' => $code, 'name' => $name, 'unit' => $unit,
            'higher' => $higher, 'description' => $description,
        ]);
        $benchmarkIds[$code] = pcKnowledgeId(
            $db, 'SELECT id FROM pc_benchmark_definitions WHERE code=:code',
            ['code' => $code], "Benchmark {$code} could not be loaded."
        );
    }

    $workloadIds = [];
    foreach ($workloads as $code => [$name, $description]) {
        $statement = $db->prepare(
            'INSERT INTO pc_workload_profiles
                (code, display_name, description, default_priority, is_active)
             VALUES (:code, :name, :description, 1.0000, TRUE)
             ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),
                description=VALUES(description), default_priority=1.0000,
                is_active=TRUE'
        );
        $statement->execute(['code' => $code, 'name' => $name, 'description' => $description]);
        $workloadIds[$code] = pcKnowledgeId(
            $db, 'SELECT id FROM pc_workload_profiles WHERE code=:code',
            ['code' => $code], "Workload {$code} could not be loaded."
        );
    }

    $deleteRequirements = $db->prepare(
        'DELETE FROM pc_workload_requirements WHERE workload_profile_id=:profile'
    );
    $insertRequirement = $db->prepare(
        'INSERT INTO pc_workload_requirements
            (workload_profile_id, component_category_id, metric_code,
             comparison_operator, minimum_value, recommended_value,
             ideal_value, weight, is_hard_requirement, rationale, sort_order)
         VALUES
            (:profile, :category, :metric, :operator, :minimum,
             :recommended, :ideal, :weight, :hard, :rationale, :sort_order)'
    );
    $requirementCount = 0;
    foreach ($requirements as $workloadCode => $rows) {
        $deleteRequirements->execute(['profile' => $workloadIds[$workloadCode]]);
        foreach ($rows as $index => [$categorySlug, $metric, $minimum, $recommended, $ideal, $weight, $hard, $rationale]) {
            $operator = in_array($metric, ['peak_power_watts', 'total_board_power_watts'], true)
                && $workloadCode === 'quiet_efficiency' ? 'lte' : 'gte';
            $insertRequirement->execute([
                'profile' => $workloadIds[$workloadCode],
                'category' => $categorySlug === null ? null : $categoryIds[$categorySlug],
                'metric' => $metric, 'operator' => $operator,
                'minimum' => $minimum, 'recommended' => $recommended,
                'ideal' => $ideal, 'weight' => $weight, 'hard' => (int) $hard,
                'rationale' => $rationale, 'sort_order' => ($index + 1) * 10,
            ]);
            $requirementCount++;
        }
    }

    $productStatement = $db->prepare(
        'SELECT cp.id, cp.model, cp.name, c.slug category_slug
         FROM canonical_products cp
         INNER JOIN categories c ON c.id=cp.category_id
         WHERE cp.model IN (' . implode(',', array_fill(0, count($performanceProfiles), '?')) . ')'
    );
    $productStatement->execute(array_keys($performanceProfiles));
    $products = [];
    foreach ($productStatement->fetchAll() as $product) {
        $products[(string) $product['model']] = $product;
    }
    if (count($products) !== count($performanceProfiles)) {
        $missing = array_diff(array_keys($performanceProfiles), array_keys($products));
        throw new RuntimeException(
            'Run seed_demo_pc_catalogue.php first. Missing: ' . implode(', ', $missing)
        );
    }

    $profileStatement = $db->prepare(
        'INSERT INTO pc_product_performance_profiles
            (canonical_product_id, performance_tier, overall_score,
             value_score, efficiency_score, upgradeability_score,
             reliability_score, source_id, model_version, notes)
         VALUES
            (:product, :tier, :overall, :value_score, :efficiency,
             :upgradeability, :reliability, :source, "pc-curated-v1.0",
             "Normalized local demonstration profile; replace with licensed or independently verified evidence for production.")
         ON DUPLICATE KEY UPDATE performance_tier=VALUES(performance_tier),
            overall_score=VALUES(overall_score), value_score=VALUES(value_score),
            efficiency_score=VALUES(efficiency_score),
            upgradeability_score=VALUES(upgradeability_score),
            reliability_score=VALUES(reliability_score), source_id=VALUES(source_id),
            model_version=VALUES(model_version), notes=VALUES(notes)'
    );
    $benchmarkStatement = $db->prepare(
        'INSERT INTO pc_product_benchmarks
            (canonical_product_id, benchmark_definition_id, source_id,
             raw_value, normalized_score, measured_at, notes)
         VALUES
            (:product, :definition, :source, :raw_value, :normalized,
             CURRENT_DATE, :notes)
         ON DUPLICATE KEY UPDATE raw_value=VALUES(raw_value),
            normalized_score=VALUES(normalized_score),
            measured_at=VALUES(measured_at), notes=VALUES(notes)'
    );
    $provenanceStatement = $db->prepare(
        'INSERT INTO pc_product_provenance
            (canonical_product_id, source_id, evidence_type, source_reference,
             confidence, is_primary, verified_at, notes)
         VALUES
            (:product, :source, :type, :reference, :confidence,
             :primary_source, CURRENT_TIMESTAMP, :notes)
         ON DUPLICATE KEY UPDATE source_reference=VALUES(source_reference),
            confidence=VALUES(confidence), is_primary=VALUES(is_primary),
            verified_at=VALUES(verified_at), notes=VALUES(notes)'
    );
    $qualityStatement = $db->prepare(
        'INSERT INTO pc_product_data_quality
            (canonical_product_id, review_status, completeness_score,
             confidence_score, verified_at, review_notes)
         VALUES
            (:product, "needs_review", 100.000, 70.000, NULL,
             "All required HEXBAY fields are present; independent manufacturer and benchmark verification remains required before production use.")
         ON DUPLICATE KEY UPDATE review_status="needs_review",
            completeness_score=100.000, confidence_score=70.000,
            verified_at=NULL, review_notes=VALUES(review_notes)'
    );

    $categoryWorkloadEmphasis = [
        'balanced_general' => ['processors' => 0.75, 'memory' => 0.70, 'storage' => 0.60, 'graphics-cards' => 0.40],
        'office_study' => ['processors' => 0.60, 'memory' => 0.65, 'storage' => 0.55, 'graphics-cards' => 0.15],
        'gaming_1080p' => ['processors' => 0.70, 'graphics-cards' => 1.00, 'memory' => 0.60, 'storage' => 0.35],
        'gaming_1440p' => ['processors' => 0.65, 'graphics-cards' => 1.00, 'memory' => 0.65, 'storage' => 0.35],
        'gaming_4k' => ['processors' => 0.55, 'graphics-cards' => 1.00, 'memory' => 0.65, 'storage' => 0.35],
        'programming' => ['processors' => 0.85, 'memory' => 0.90, 'storage' => 0.65, 'graphics-cards' => 0.20],
        'software_compilation' => ['processors' => 1.00, 'memory' => 0.80, 'storage' => 0.60],
        'virtual_machines' => ['processors' => 0.85, 'memory' => 1.00, 'storage' => 0.70],
        'graphic_design' => ['processors' => 0.60, 'graphics-cards' => 0.55, 'memory' => 0.90, 'storage' => 0.60],
        'video_editing' => ['processors' => 0.90, 'graphics-cards' => 0.85, 'memory' => 0.95, 'storage' => 0.75],
        'live_streaming' => ['processors' => 0.75, 'graphics-cards' => 0.80, 'memory' => 0.70],
        'music_production' => ['processors' => 0.90, 'memory' => 0.80, 'storage' => 0.65, 'cpu-coolers' => 0.50],
        'three_d_rendering' => ['processors' => 0.90, 'graphics-cards' => 1.00, 'memory' => 0.90, 'storage' => 0.60],
        'cad_engineering' => ['processors' => 0.90, 'graphics-cards' => 0.75, 'memory' => 0.80],
        'ai_ml' => ['processors' => 0.65, 'graphics-cards' => 1.00, 'memory' => 0.90, 'storage' => 0.65],
        'home_server_nas' => ['processors' => 0.45, 'memory' => 0.75, 'storage' => 1.00, 'power-supplies' => 0.70],
        'quiet_efficiency' => ['processors' => 0.55, 'graphics-cards' => 0.45, 'power-supplies' => 0.85, 'cpu-coolers' => 0.90, 'computer-cases' => 0.75],
        'upgrade_focused' => ['motherboards' => 1.00, 'power-supplies' => 0.85, 'computer-cases' => 0.75, 'memory' => 0.55],
    ];
    $workloadScoreStatement = $db->prepare(
        'INSERT INTO pc_product_workload_scores
            (canonical_product_id, workload_profile_id, suitability_score,
             source_id, model_version, rationale)
         VALUES
            (:product, :workload, :score, :source, "workload-curated-v1.0", :rationale)
         ON DUPLICATE KEY UPDATE suitability_score=VALUES(suitability_score),
            source_id=VALUES(source_id), model_version=VALUES(model_version),
            rationale=VALUES(rationale)'
    );

    $profileCount = 0;
    $benchmarkCount = 0;
    $workloadScoreCount = 0;
    foreach ($performanceProfiles as $model => [$tier, $overall, $value, $efficiency, $upgradeability, $reliability, $single, $multi]) {
        $product = $products[$model];
        $productId = (int) $product['id'];
        $profileStatement->execute([
            'product' => $productId, 'tier' => $tier, 'overall' => $overall,
            'value_score' => $value, 'efficiency' => $efficiency,
            'upgradeability' => $upgradeability, 'reliability' => $reliability,
            'source' => $sourceIds['hexbay_performance_v1'],
        ]);
        $profileCount++;

        foreach ([
            'component_overall_index' => $overall,
            'component_value_index' => $value,
        ] as $benchmarkCode => $score) {
            $benchmarkStatement->execute([
                'product' => $productId, 'definition' => $benchmarkIds[$benchmarkCode],
                'source' => $sourceIds['hexbay_performance_v1'],
                'raw_value' => $score, 'normalized' => $score,
                'notes' => 'HEXBAY normalized demonstration index.',
            ]);
            $benchmarkCount++;
        }
        if ($product['category_slug'] === 'processors') {
            foreach (['cpu_single_core_index' => $single, 'cpu_multi_core_index' => $multi] as $benchmarkCode => $score) {
                $benchmarkStatement->execute([
                    'product' => $productId, 'definition' => $benchmarkIds[$benchmarkCode],
                    'source' => $sourceIds['hexbay_performance_v1'],
                    'raw_value' => $score, 'normalized' => $score,
                    'notes' => 'HEXBAY normalized CPU capability proxy.',
                ]);
                $benchmarkCount++;
            }
        } elseif ($product['category_slug'] === 'graphics-cards') {
            $compute = max(0, min(100, $overall + (str_contains($model, 'RTX') ? 5 : -3)));
            foreach (['gpu_raster_index' => $overall, 'gpu_compute_index' => $compute] as $benchmarkCode => $score) {
                $benchmarkStatement->execute([
                    'product' => $productId, 'definition' => $benchmarkIds[$benchmarkCode],
                    'source' => $sourceIds['hexbay_performance_v1'],
                    'raw_value' => $score, 'normalized' => $score,
                    'notes' => 'HEXBAY normalized GPU capability proxy.',
                ]);
                $benchmarkCount++;
            }
        } elseif ($product['category_slug'] === 'storage') {
            $benchmarkStatement->execute([
                'product' => $productId,
                'definition' => $benchmarkIds['storage_responsiveness_index'],
                'source' => $sourceIds['hexbay_performance_v1'],
                'raw_value' => $overall, 'normalized' => $overall,
                'notes' => 'HEXBAY normalized storage responsiveness proxy.',
            ]);
            $benchmarkCount++;
        }

        $provenanceStatement->execute([
            'product' => $productId, 'source' => $sourceIds['hexbay_demo_catalogue'],
            'type' => 'specification', 'reference' => $model,
            'confidence' => 'medium', 'primary_source' => true,
            'notes' => 'Complete local fixture with independent production verification pending.',
        ]);
        $provenanceStatement->execute([
            'product' => $productId, 'source' => $sourceIds['hexbay_performance_v1'],
            'type' => 'benchmark', 'reference' => 'pc-curated-v1.0',
            'confidence' => 'medium', 'primary_source' => true,
            'notes' => 'Normalized internal evaluation score; not a quoted third-party benchmark.',
        ]);
        $qualityStatement->execute(['product' => $productId]);

        foreach ($workloadIds as $workloadCode => $workloadId) {
            $emphasis = $categoryWorkloadEmphasis[$workloadCode][$product['category_slug']] ?? 0.30;
            $score = max(0, min(100,
                ($overall * $emphasis)
                + ($value * (1 - $emphasis) * 0.45)
                + ($efficiency * (1 - $emphasis) * 0.25)
                + ($upgradeability * (1 - $emphasis) * 0.30)
            ));
            $workloadScoreStatement->execute([
                'product' => $productId, 'workload' => $workloadId,
                'score' => round($score, 3),
                'source' => $sourceIds['hexbay_workloads_v1'],
                'rationale' => sprintf(
                    '%s emphasis %.2f combines normalized capability, value, efficiency and upgradeability.',
                    ucwords(str_replace('_', ' ', (string) $product['category_slug'])),
                    $emphasis
                ),
            ]);
            $workloadScoreCount++;
        }
    }

    $snapshotStatement = $db->prepare(
        'INSERT INTO pc_listing_price_snapshots
            (listing_id, price, available_quantity)
         SELECT l.id, l.price,
                GREATEST(i.quantity_on_hand-i.quantity_reserved, 0)
         FROM shop_product_listings l
         INNER JOIN inventory i ON i.listing_id=l.id
         INNER JOIN shops s ON s.id=l.shop_id
         INNER JOIN users u ON u.id=s.owner_user_id
         WHERE u.email IN (
            "seller.novacore@hexbay.test", "seller.bytecraft@hexbay.test"
         )
           AND NOT EXISTS (
               SELECT 1 FROM pc_listing_price_snapshots latest
               WHERE latest.listing_id=l.id
                 AND latest.id=(
                    SELECT MAX(previous.id) FROM pc_listing_price_snapshots previous
                    WHERE previous.listing_id=l.id
                 )
                 AND latest.price=l.price
                 AND latest.available_quantity=GREATEST(
                    i.quantity_on_hand-i.quantity_reserved, 0
                 )
           )'
    );
    $snapshotStatement->execute();
    $snapshotCount = $snapshotStatement->rowCount();

    $db->commit();
    echo json_encode([
        'success' => true,
        'data_sources' => count($sourceIds),
        'benchmark_definitions' => count($benchmarkIds),
        'workload_profiles' => count($workloadIds),
        'workload_requirements' => $requirementCount,
        'component_performance_profiles' => $profileCount,
        'product_benchmark_scores' => $benchmarkCount,
        'product_workload_scores' => $workloadScoreCount,
        'new_price_snapshots' => $snapshotCount,
        'quality_status' => 'needs_review',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "PC knowledge seed failed: {$exception->getMessage()}\n");
    exit(1);
}
