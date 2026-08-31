USE hexbay;

-- ---------------------------------------------------------------------------
-- Sprint 5 PC-intelligence data foundation
--
-- This migration stores facts and evaluation inputs only. Compatibility rules,
-- build optimisation and conversational behaviour are implemented separately.
-- ---------------------------------------------------------------------------

INSERT INTO categories
    (name, slug, description, is_active, requires_listing_approval, sort_order)
VALUES
    ('CPU Coolers', 'cpu-coolers',
     'Desktop processor air coolers and all-in-one liquid coolers.',
     TRUE, TRUE, 65)
ON DUPLICATE KEY UPDATE
    name=VALUES(name), description=VALUES(description), is_active=TRUE,
    requires_listing_approval=TRUE, sort_order=VALUES(sort_order);

INSERT INTO specification_definitions
    (category_id, code, display_name, data_type, unit, is_required,
     is_filterable, is_compatibility_field, minimum_value, maximum_value,
     sort_order, is_active)
SELECT
    c.id, d.code, d.display_name, d.data_type, d.unit, d.is_required,
    d.is_filterable, d.is_compatibility_field, d.minimum_value,
    d.maximum_value, d.sort_order, TRUE
FROM categories c
INNER JOIN (
    SELECT 'processors' category_slug, 'architecture_family' code,
           'Architecture family' display_name, 'text' data_type, NULL unit,
           TRUE is_required, TRUE is_filterable, TRUE is_compatibility_field,
           NULL minimum_value, NULL maximum_value, 50 sort_order
    UNION ALL SELECT 'processors', 'supported_chipsets', 'Supported chipsets',
        'multi_option', NULL, TRUE, TRUE, TRUE, NULL, NULL, 60
    UNION ALL SELECT 'processors', 'base_clock_ghz', 'Base clock',
        'decimal', 'GHz', FALSE, TRUE, FALSE, 0.1, 10, 70
    UNION ALL SELECT 'processors', 'boost_clock_ghz', 'Maximum boost clock',
        'decimal', 'GHz', FALSE, TRUE, FALSE, 0.1, 10, 80
    UNION ALL SELECT 'processors', 'integrated_graphics', 'Integrated graphics',
        'boolean', NULL, TRUE, TRUE, TRUE, NULL, NULL, 90
    UNION ALL SELECT 'processors', 'cooler_included', 'Stock cooler included',
        'boolean', NULL, TRUE, TRUE, TRUE, NULL, NULL, 100
    UNION ALL SELECT 'processors', 'peak_power_watts', 'Estimated peak package power',
        'integer', 'W', FALSE, TRUE, TRUE, 1, 1000, 110

    UNION ALL SELECT 'motherboards', 'chipset', 'Motherboard chipset',
        'option', NULL, TRUE, TRUE, TRUE, NULL, NULL, 50
    UNION ALL SELECT 'motherboards', 'supported_cpu_families',
        'Supported CPU families', 'multi_option', NULL, TRUE, TRUE, TRUE,
        NULL, NULL, 60
    UNION ALL SELECT 'motherboards', 'memory_slots', 'Memory slot count',
        'integer', 'slots', TRUE, TRUE, TRUE, 1, 16, 70
    UNION ALL SELECT 'motherboards', 'max_memory_capacity_gb',
        'Maximum memory capacity', 'integer', 'GB', TRUE, TRUE, TRUE,
        4, 4096, 80
    UNION ALL SELECT 'motherboards', 'max_memory_speed_mhz',
        'Maximum supported memory speed', 'integer', 'MHz', FALSE, TRUE,
        TRUE, 400, 15000, 90
    UNION ALL SELECT 'motherboards', 'pcie_x16_generation',
        'Primary PCIe x16 generation', 'option', NULL, FALSE, TRUE, TRUE,
        NULL, NULL, 100
    UNION ALL SELECT 'motherboards', 'm2_interfaces',
        'Supported M.2 interfaces', 'multi_option', NULL, FALSE, TRUE, TRUE,
        NULL, NULL, 110
    UNION ALL SELECT 'motherboards', 'wifi_included', 'Integrated Wi-Fi',
        'boolean', NULL, FALSE, TRUE, FALSE, NULL, NULL, 120
    UNION ALL SELECT 'motherboards', 'bios_support_note', 'BIOS support note',
        'text', NULL, FALSE, FALSE, TRUE, NULL, NULL, 130

    UNION ALL SELECT 'memory', 'module_count', 'Module count',
        'integer', 'modules', TRUE, TRUE, TRUE, 1, 16, 40
    UNION ALL SELECT 'memory', 'capacity_per_module_gb', 'Capacity per module',
        'integer', 'GB', TRUE, TRUE, TRUE, 1, 512, 50
    UNION ALL SELECT 'memory', 'cas_latency', 'CAS latency',
        'integer', 'CL', FALSE, TRUE, FALSE, 1, 100, 60
    UNION ALL SELECT 'memory', 'memory_profiles', 'Memory profiles',
        'multi_option', NULL, FALSE, TRUE, TRUE, NULL, NULL, 70
    UNION ALL SELECT 'memory', 'ecc_memory', 'ECC memory',
        'boolean', NULL, FALSE, TRUE, TRUE, NULL, NULL, 80

    UNION ALL SELECT 'graphics-cards', 'gpu_height_mm', 'Graphics card height',
        'integer', 'mm', FALSE, TRUE, TRUE, 20, 500, 50
    UNION ALL SELECT 'graphics-cards', 'gpu_thickness_slots',
        'Graphics card slot thickness', 'decimal', 'slots', FALSE, TRUE,
        TRUE, 1, 8, 60
    UNION ALL SELECT 'graphics-cards', 'total_board_power_watts',
        'Typical board power', 'integer', 'W', TRUE, TRUE, TRUE,
        1, 2000, 70
    UNION ALL SELECT 'graphics-cards', 'pcie_generation',
        'PCIe generation', 'option', NULL, FALSE, TRUE, TRUE,
        NULL, NULL, 80
    UNION ALL SELECT 'graphics-cards', 'compute_capabilities',
        'Compute and graphics capabilities', 'multi_option', NULL,
        FALSE, TRUE, FALSE, NULL, NULL, 90

    UNION ALL SELECT 'power-supplies', 'atx_standard', 'ATX standard',
        'option', NULL, FALSE, TRUE, TRUE, NULL, NULL, 50
    UNION ALL SELECT 'power-supplies', 'modularity', 'Cable modularity',
        'option', NULL, FALSE, TRUE, FALSE, NULL, NULL, 60
    UNION ALL SELECT 'power-supplies', 'six_pin_connector_count',
        '6-pin PCIe connector count', 'integer', 'connectors', FALSE, TRUE,
        TRUE, 0, 20, 70
    UNION ALL SELECT 'power-supplies', 'eight_pin_connector_count',
        '6+2/8-pin PCIe connector count', 'integer', 'connectors', FALSE,
        TRUE, TRUE, 0, 20, 80
    UNION ALL SELECT 'power-supplies', 'twelve_vhpwr_connector_count',
        '12VHPWR/12V-2x6 connector count', 'integer', 'connectors', FALSE,
        TRUE, TRUE, 0, 10, 90
    UNION ALL SELECT 'power-supplies', 'warranty_years', 'Warranty period',
        'integer', 'years', FALSE, TRUE, FALSE, 0, 20, 100

    UNION ALL SELECT 'storage', 'sequential_read_mbps',
        'Sequential read speed', 'integer', 'MB/s', FALSE, TRUE, FALSE,
        1, 50000, 50
    UNION ALL SELECT 'storage', 'sequential_write_mbps',
        'Sequential write speed', 'integer', 'MB/s', FALSE, TRUE, FALSE,
        1, 50000, 60
    UNION ALL SELECT 'storage', 'endurance_tbw', 'Rated endurance',
        'integer', 'TBW', FALSE, TRUE, FALSE, 1, 100000, 70

    UNION ALL SELECT 'computer-cases', 'max_cpu_cooler_height_mm',
        'Maximum CPU cooler height', 'integer', 'mm', FALSE, TRUE, TRUE,
        30, 300, 40
    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes',
        'Supported radiator sizes', 'multi_option', NULL, FALSE, TRUE,
        TRUE, NULL, NULL, 50
    UNION ALL SELECT 'computer-cases', 'max_gpu_thickness_slots',
        'Maximum GPU slot thickness', 'decimal', 'slots', FALSE, TRUE,
        TRUE, 1, 8, 60

    UNION ALL SELECT 'cpu-coolers', 'cooler_type', 'Cooler type',
        'option', NULL, TRUE, TRUE, FALSE, NULL, NULL, 10
    UNION ALL SELECT 'cpu-coolers', 'supported_sockets', 'Supported CPU sockets',
        'multi_option', NULL, TRUE, TRUE, TRUE, NULL, NULL, 20
    UNION ALL SELECT 'cpu-coolers', 'cooling_capacity_watts',
        'Recommended cooling capacity', 'integer', 'W', TRUE, TRUE, TRUE,
        20, 1000, 30
    UNION ALL SELECT 'cpu-coolers', 'cooler_height_mm', 'Cooler height',
        'integer', 'mm', FALSE, TRUE, TRUE, 20, 300, 40
    UNION ALL SELECT 'cpu-coolers', 'radiator_size', 'Radiator size',
        'option', NULL, FALSE, TRUE, TRUE, NULL, NULL, 50
    UNION ALL SELECT 'cpu-coolers', 'noise_level_dba', 'Maximum noise level',
        'decimal', 'dBA', FALSE, TRUE, FALSE, 1, 100, 60
) d ON d.category_slug=c.slug
ON DUPLICATE KEY UPDATE
    display_name=VALUES(display_name), data_type=VALUES(data_type),
    unit=VALUES(unit), is_required=VALUES(is_required),
    is_filterable=VALUES(is_filterable),
    is_compatibility_field=VALUES(is_compatibility_field),
    minimum_value=VALUES(minimum_value), maximum_value=VALUES(maximum_value),
    sort_order=VALUES(sort_order), is_active=TRUE;

INSERT INTO specification_options
    (definition_id, value_code, display_value, sort_order, is_active)
SELECT sd.id, o.value_code, o.display_value, o.sort_order, TRUE
FROM categories c
INNER JOIN specification_definitions sd ON sd.category_id=c.id
INNER JOIN (
    SELECT 'processors' category_slug, 'supported_chipsets' definition_code,
           'a520' value_code, 'AMD A520' display_value, 10 sort_order
    UNION ALL SELECT 'processors', 'supported_chipsets', 'b450', 'AMD B450', 20
    UNION ALL SELECT 'processors', 'supported_chipsets', 'b550', 'AMD B550', 30
    UNION ALL SELECT 'processors', 'supported_chipsets', 'x570', 'AMD X570', 40
    UNION ALL SELECT 'processors', 'supported_chipsets', 'a620', 'AMD A620', 50
    UNION ALL SELECT 'processors', 'supported_chipsets', 'b650', 'AMD B650', 60
    UNION ALL SELECT 'processors', 'supported_chipsets', 'x670', 'AMD X670', 70
    UNION ALL SELECT 'processors', 'supported_chipsets', 'h610', 'Intel H610', 80
    UNION ALL SELECT 'processors', 'supported_chipsets', 'b660', 'Intel B660', 90
    UNION ALL SELECT 'processors', 'supported_chipsets', 'b760', 'Intel B760', 100
    UNION ALL SELECT 'processors', 'supported_chipsets', 'z690', 'Intel Z690', 110
    UNION ALL SELECT 'processors', 'supported_chipsets', 'z790', 'Intel Z790', 120

    UNION ALL SELECT 'motherboards', 'chipset', 'a520', 'AMD A520', 10
    UNION ALL SELECT 'motherboards', 'chipset', 'b450', 'AMD B450', 20
    UNION ALL SELECT 'motherboards', 'chipset', 'b550', 'AMD B550', 30
    UNION ALL SELECT 'motherboards', 'chipset', 'x570', 'AMD X570', 40
    UNION ALL SELECT 'motherboards', 'chipset', 'a620', 'AMD A620', 50
    UNION ALL SELECT 'motherboards', 'chipset', 'b650', 'AMD B650', 60
    UNION ALL SELECT 'motherboards', 'chipset', 'x670', 'AMD X670', 70
    UNION ALL SELECT 'motherboards', 'chipset', 'h610', 'Intel H610', 80
    UNION ALL SELECT 'motherboards', 'chipset', 'b660', 'Intel B660', 90
    UNION ALL SELECT 'motherboards', 'chipset', 'b760', 'Intel B760', 100
    UNION ALL SELECT 'motherboards', 'chipset', 'z690', 'Intel Z690', 110
    UNION ALL SELECT 'motherboards', 'chipset', 'z790', 'Intel Z790', 120
    UNION ALL SELECT 'motherboards', 'supported_cpu_families', 'zen3', 'AMD Zen 3', 10
    UNION ALL SELECT 'motherboards', 'supported_cpu_families', 'zen4', 'AMD Zen 4', 20
    UNION ALL SELECT 'motherboards', 'supported_cpu_families', 'alder_lake', 'Intel Alder Lake', 30
    UNION ALL SELECT 'motherboards', 'supported_cpu_families', 'raptor_lake', 'Intel Raptor Lake', 40
    UNION ALL SELECT 'motherboards', 'supported_cpu_families', 'raptor_lake_refresh', 'Intel Raptor Lake Refresh', 50
    UNION ALL SELECT 'motherboards', 'pcie_x16_generation', 'pcie_3', 'PCIe 3.0', 10
    UNION ALL SELECT 'motherboards', 'pcie_x16_generation', 'pcie_4', 'PCIe 4.0', 20
    UNION ALL SELECT 'motherboards', 'pcie_x16_generation', 'pcie_5', 'PCIe 5.0', 30
    UNION ALL SELECT 'motherboards', 'm2_interfaces', 'pcie_3', 'PCIe 3.0 NVMe', 10
    UNION ALL SELECT 'motherboards', 'm2_interfaces', 'pcie_4', 'PCIe 4.0 NVMe', 20
    UNION ALL SELECT 'motherboards', 'm2_interfaces', 'pcie_5', 'PCIe 5.0 NVMe', 30
    UNION ALL SELECT 'motherboards', 'm2_interfaces', 'sata', 'M.2 SATA', 40

    UNION ALL SELECT 'memory', 'memory_profiles', 'none', 'No overclock profile', 10
    UNION ALL SELECT 'memory', 'memory_profiles', 'xmp', 'Intel XMP', 20
    UNION ALL SELECT 'memory', 'memory_profiles', 'expo', 'AMD EXPO', 30

    UNION ALL SELECT 'graphics-cards', 'pcie_generation', 'pcie_3', 'PCIe 3.0', 10
    UNION ALL SELECT 'graphics-cards', 'pcie_generation', 'pcie_4', 'PCIe 4.0', 20
    UNION ALL SELECT 'graphics-cards', 'pcie_generation', 'pcie_5', 'PCIe 5.0', 30
    UNION ALL SELECT 'graphics-cards', 'compute_capabilities', 'directx_12', 'DirectX 12', 10
    UNION ALL SELECT 'graphics-cards', 'compute_capabilities', 'vulkan', 'Vulkan', 20
    UNION ALL SELECT 'graphics-cards', 'compute_capabilities', 'opencl', 'OpenCL', 30
    UNION ALL SELECT 'graphics-cards', 'compute_capabilities', 'cuda', 'CUDA', 40
    UNION ALL SELECT 'graphics-cards', 'compute_capabilities', 'av1_encode', 'AV1 encoding', 50

    UNION ALL SELECT 'power-supplies', 'atx_standard', 'atx_2_4', 'ATX 2.4', 10
    UNION ALL SELECT 'power-supplies', 'atx_standard', 'atx_2_52', 'ATX 2.52', 20
    UNION ALL SELECT 'power-supplies', 'atx_standard', 'atx_3_0', 'ATX 3.0', 30
    UNION ALL SELECT 'power-supplies', 'atx_standard', 'atx_3_1', 'ATX 3.1', 40
    UNION ALL SELECT 'power-supplies', 'modularity', 'non_modular', 'Non-modular', 10
    UNION ALL SELECT 'power-supplies', 'modularity', 'semi_modular', 'Semi-modular', 20
    UNION ALL SELECT 'power-supplies', 'modularity', 'fully_modular', 'Fully modular', 30

    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes', 'none', 'No radiator support', 10
    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes', 'rad_120', '120 mm', 20
    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes', 'rad_240', '240 mm', 30
    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes', 'rad_280', '280 mm', 40
    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes', 'rad_360', '360 mm', 50
    UNION ALL SELECT 'computer-cases', 'supported_radiator_sizes', 'rad_420', '420 mm', 60

    UNION ALL SELECT 'cpu-coolers', 'cooler_type', 'air', 'Air cooler', 10
    UNION ALL SELECT 'cpu-coolers', 'cooler_type', 'aio', 'All-in-one liquid cooler', 20
    UNION ALL SELECT 'cpu-coolers', 'supported_sockets', 'am4', 'AM4', 10
    UNION ALL SELECT 'cpu-coolers', 'supported_sockets', 'am5', 'AM5', 20
    UNION ALL SELECT 'cpu-coolers', 'supported_sockets', 'lga1200', 'LGA1200', 30
    UNION ALL SELECT 'cpu-coolers', 'supported_sockets', 'lga1700', 'LGA1700', 40
    UNION ALL SELECT 'cpu-coolers', 'supported_sockets', 'lga1851', 'LGA1851', 50
    UNION ALL SELECT 'cpu-coolers', 'radiator_size', 'none', 'Not applicable', 10
    UNION ALL SELECT 'cpu-coolers', 'radiator_size', 'rad_120', '120 mm', 20
    UNION ALL SELECT 'cpu-coolers', 'radiator_size', 'rad_240', '240 mm', 30
    UNION ALL SELECT 'cpu-coolers', 'radiator_size', 'rad_280', '280 mm', 40
    UNION ALL SELECT 'cpu-coolers', 'radiator_size', 'rad_360', '360 mm', 50
) o ON o.category_slug=c.slug AND o.definition_code=sd.code
ON DUPLICATE KEY UPDATE
    display_value=VALUES(display_value), sort_order=VALUES(sort_order),
    is_active=TRUE;

CREATE TABLE IF NOT EXISTS pc_data_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(160) NOT NULL,
    source_type ENUM(
        'manufacturer', 'benchmark', 'software_requirement',
        'seller', 'internal_curated'
    ) NOT NULL,
    base_url VARCHAR(1000) NULL,
    licence_notes VARCHAR(1000) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_data_sources_code UNIQUE (code),
    INDEX idx_pc_data_sources_type (source_type, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_product_provenance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    evidence_type ENUM(
        'identity', 'specification', 'benchmark', 'workload_score',
        'price', 'availability', 'manual_review'
    ) NOT NULL,
    source_url VARCHAR(1000) NULL,
    source_reference VARCHAR(255) NULL,
    confidence ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    verified_at TIMESTAMP NULL,
    notes VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_product_provenance UNIQUE (
        canonical_product_id, source_id, evidence_type
    ),
    CONSTRAINT fk_pc_provenance_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_provenance_source FOREIGN KEY (source_id)
        REFERENCES pc_data_sources (id),
    INDEX idx_pc_provenance_product_type (canonical_product_id, evidence_type),
    INDEX idx_pc_provenance_verified (verified_at, confidence)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_benchmark_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NULL,
    code VARCHAR(100) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'score',
    higher_is_better BOOLEAN NOT NULL DEFAULT TRUE,
    description VARCHAR(1000) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_benchmark_code UNIQUE (code),
    CONSTRAINT fk_pc_benchmark_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE SET NULL,
    INDEX idx_pc_benchmark_category (category_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_product_benchmarks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    benchmark_definition_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    raw_value DECIMAL(18,4) NOT NULL,
    normalized_score DECIMAL(6,3) NOT NULL,
    measured_at DATE NULL,
    sample_size INT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_product_benchmark UNIQUE (
        canonical_product_id, benchmark_definition_id, source_id
    ),
    CONSTRAINT fk_pc_product_benchmark_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_product_benchmark_definition FOREIGN KEY (benchmark_definition_id)
        REFERENCES pc_benchmark_definitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_product_benchmark_source FOREIGN KEY (source_id)
        REFERENCES pc_data_sources (id),
    CONSTRAINT chk_pc_benchmark_score CHECK (normalized_score BETWEEN 0 AND 100),
    INDEX idx_pc_product_benchmark_ranking (
        benchmark_definition_id, normalized_score, canonical_product_id
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_product_performance_profiles (
    canonical_product_id BIGINT UNSIGNED PRIMARY KEY,
    performance_tier ENUM(
        'entry', 'mainstream', 'performance', 'enthusiast', 'specialist'
    ) NOT NULL,
    overall_score DECIMAL(6,3) NOT NULL,
    value_score DECIMAL(6,3) NOT NULL,
    efficiency_score DECIMAL(6,3) NOT NULL,
    upgradeability_score DECIMAL(6,3) NOT NULL,
    reliability_score DECIMAL(6,3) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    model_version VARCHAR(40) NOT NULL,
    notes VARCHAR(1000) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pc_performance_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_performance_source FOREIGN KEY (source_id)
        REFERENCES pc_data_sources (id),
    CONSTRAINT chk_pc_performance_overall CHECK (overall_score BETWEEN 0 AND 100),
    CONSTRAINT chk_pc_performance_value CHECK (value_score BETWEEN 0 AND 100),
    CONSTRAINT chk_pc_performance_efficiency CHECK (efficiency_score BETWEEN 0 AND 100),
    CONSTRAINT chk_pc_performance_upgradeability CHECK (upgradeability_score BETWEEN 0 AND 100),
    CONSTRAINT chk_pc_performance_reliability CHECK (reliability_score BETWEEN 0 AND 100),
    INDEX idx_pc_performance_tier_score (performance_tier, overall_score)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_workload_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    default_priority DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_workload_code UNIQUE (code),
    CONSTRAINT chk_pc_workload_priority CHECK (default_priority BETWEEN 0 AND 1),
    INDEX idx_pc_workload_active (is_active, display_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_workload_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workload_profile_id BIGINT UNSIGNED NOT NULL,
    component_category_id BIGINT UNSIGNED NULL,
    metric_code VARCHAR(100) NOT NULL,
    comparison_operator ENUM('gte', 'lte', 'eq', 'contains') NOT NULL DEFAULT 'gte',
    minimum_value DECIMAL(18,4) NULL,
    recommended_value DECIMAL(18,4) NULL,
    ideal_value DECIMAL(18,4) NULL,
    option_value VARCHAR(100) NULL,
    weight DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    is_hard_requirement BOOLEAN NOT NULL DEFAULT FALSE,
    rationale VARCHAR(1000) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_workload_requirement UNIQUE (
        workload_profile_id, component_category_id, metric_code, option_value
    ),
    CONSTRAINT fk_pc_workload_requirement_profile FOREIGN KEY (workload_profile_id)
        REFERENCES pc_workload_profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_workload_requirement_category FOREIGN KEY (component_category_id)
        REFERENCES categories (id) ON DELETE CASCADE,
    CONSTRAINT chk_pc_workload_weight CHECK (weight BETWEEN 0 AND 1),
    CONSTRAINT chk_pc_workload_range CHECK (
        comparison_operator = 'lte'
        OR minimum_value IS NULL OR recommended_value IS NULL
        OR minimum_value <= recommended_value
    ),
    CONSTRAINT chk_pc_workload_ideal CHECK (
        comparison_operator = 'lte'
        OR recommended_value IS NULL OR ideal_value IS NULL
        OR recommended_value <= ideal_value
    ),
    INDEX idx_pc_workload_requirement_profile (
        workload_profile_id, sort_order, metric_code
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_product_workload_scores (
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    workload_profile_id BIGINT UNSIGNED NOT NULL,
    suitability_score DECIMAL(6,3) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    model_version VARCHAR(40) NOT NULL,
    rationale VARCHAR(1000) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (canonical_product_id, workload_profile_id),
    CONSTRAINT fk_pc_workload_score_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_workload_score_profile FOREIGN KEY (workload_profile_id)
        REFERENCES pc_workload_profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_workload_score_source FOREIGN KEY (source_id)
        REFERENCES pc_data_sources (id),
    CONSTRAINT chk_pc_workload_score CHECK (suitability_score BETWEEN 0 AND 100),
    INDEX idx_pc_workload_score_ranking (workload_profile_id, suitability_score)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_listing_price_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    price DECIMAL(13,2) NOT NULL,
    available_quantity INT UNSIGNED NOT NULL,
    captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pc_price_snapshot_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id) ON DELETE CASCADE,
    CONSTRAINT chk_pc_snapshot_price CHECK (price >= 0),
    INDEX idx_pc_price_snapshot_listing (listing_id, captured_at),
    INDEX idx_pc_price_snapshot_time (captured_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_product_data_quality (
    canonical_product_id BIGINT UNSIGNED PRIMARY KEY,
    review_status ENUM('incomplete', 'needs_review', 'verified', 'rejected')
        NOT NULL DEFAULT 'incomplete',
    completeness_score DECIMAL(6,3) NOT NULL DEFAULT 0,
    confidence_score DECIMAL(6,3) NOT NULL DEFAULT 0,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    verified_at TIMESTAMP NULL,
    review_notes VARCHAR(1000) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pc_quality_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_quality_reviewer FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_pc_quality_completeness CHECK (completeness_score BETWEEN 0 AND 100),
    CONSTRAINT chk_pc_quality_confidence CHECK (confidence_score BETWEEN 0 AND 100),
    INDEX idx_pc_quality_review (review_status, completeness_score)
) ENGINE=InnoDB;
