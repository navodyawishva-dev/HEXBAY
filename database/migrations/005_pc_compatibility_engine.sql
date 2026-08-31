USE hexbay;

-- ---------------------------------------------------------------------------
-- Sprint 5 Step 2: versioned PC compatibility rules and privacy-minimised logs
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_compatibility_rule_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(40) NOT NULL,
    status ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'draft',
    description VARCHAR(1000) NOT NULL,
    activated_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_compatibility_rule_set_version UNIQUE (version),
    INDEX idx_pc_compatibility_rule_set_status (status, activated_at)
) ENGINE=InnoDB;

INSERT INTO pc_compatibility_rule_sets
    (version, status, description, activated_at)
VALUES
    ('pc-compat-v1.0.0', 'active',
     'Deterministic HEXBAY desktop compatibility rules for platform, memory, physical clearance, power, storage and cooling.',
     CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
    status='active', description=VALUES(description),
    activated_at=COALESCE(activated_at, CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS pc_compatibility_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_set_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    severity ENUM('hard', 'warning', 'advisory') NOT NULL,
    left_category_id BIGINT UNSIGNED NULL,
    right_category_id BIGINT UNSIGNED NULL,
    description VARCHAR(1000) NOT NULL,
    metadata_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_compatibility_rule UNIQUE (rule_set_id, code),
    CONSTRAINT fk_pc_compatibility_rule_set FOREIGN KEY (rule_set_id)
        REFERENCES pc_compatibility_rule_sets (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_compatibility_left_category FOREIGN KEY (left_category_id)
        REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_pc_compatibility_right_category FOREIGN KEY (right_category_id)
        REFERENCES categories (id) ON DELETE SET NULL,
    INDEX idx_pc_compatibility_rule_active (rule_set_id, is_active, sort_order)
) ENGINE=InnoDB;

INSERT INTO pc_compatibility_rules
    (rule_set_id, code, display_name, severity, left_category_id,
     right_category_id, description, sort_order, is_active)
SELECT rs.id, r.code, r.display_name, r.severity, lc.id, rc.id,
       r.description, r.sort_order, TRUE
FROM pc_compatibility_rule_sets rs
INNER JOIN (
    SELECT 'cpu_motherboard_socket' code, 'CPU and motherboard socket' display_name,
           'hard' severity, 'processors' left_slug, 'motherboards' right_slug,
           'The processor socket must exactly match the motherboard socket.' description,
           10 sort_order
    UNION ALL SELECT 'cpu_motherboard_family', 'CPU family support', 'hard',
        'processors', 'motherboards',
        'The motherboard must list the processor architecture family as supported.', 20
    UNION ALL SELECT 'cpu_motherboard_chipset', 'CPU chipset support', 'hard',
        'processors', 'motherboards',
        'The motherboard chipset must be supported by the processor platform.', 30
    UNION ALL SELECT 'motherboard_bios_review', 'Motherboard BIOS review', 'warning',
        'processors', 'motherboards',
        'Some processor and motherboard combinations require a firmware update.', 40
    UNION ALL SELECT 'motherboard_memory_generation', 'Memory generation', 'hard',
        'motherboards', 'memory',
        'The RAM DDR generation must match the motherboard.', 50
    UNION ALL SELECT 'motherboard_memory_capacity', 'Memory capacity', 'hard',
        'motherboards', 'memory',
        'Installed RAM must not exceed motherboard capacity.', 60
    UNION ALL SELECT 'motherboard_memory_slots', 'Memory module count', 'hard',
        'motherboards', 'memory',
        'The memory kit must not require more slots than the motherboard provides.', 70
    UNION ALL SELECT 'motherboard_memory_speed', 'Memory speed support', 'warning',
        'motherboards', 'memory',
        'Faster memory may operate at the motherboard supported speed.', 80
    UNION ALL SELECT 'motherboard_case_form_factor', 'Motherboard case fit', 'hard',
        'motherboards', 'computer-cases',
        'The case must support the selected motherboard form factor.', 90
    UNION ALL SELECT 'gpu_case_length', 'Graphics card length clearance', 'hard',
        'graphics-cards', 'computer-cases',
        'The graphics card length must not exceed case clearance.', 100
    UNION ALL SELECT 'gpu_case_thickness', 'Graphics card thickness clearance', 'hard',
        'graphics-cards', 'computer-cases',
        'The graphics card slot thickness must fit the case.', 110
    UNION ALL SELECT 'psu_case_form_factor', 'Power supply case fit', 'hard',
        'power-supplies', 'computer-cases',
        'The case must support the selected PSU form factor.', 120
    UNION ALL SELECT 'gpu_psu_wattage', 'Graphics power requirement', 'hard',
        'graphics-cards', 'power-supplies',
        'The PSU wattage must meet the graphics-card recommendation.', 130
    UNION ALL SELECT 'gpu_psu_connectors', 'Graphics power connectors', 'hard',
        'graphics-cards', 'power-supplies',
        'The PSU must supply every connector type required by the graphics card.', 140
    UNION ALL SELECT 'storage_motherboard_interface', 'Storage interface support', 'hard',
        'storage', 'motherboards',
        'The motherboard must provide a compatible storage interface and slot.', 150
    UNION ALL SELECT 'cpu_cooler_socket', 'CPU cooler socket support', 'hard',
        'processors', 'cpu-coolers',
        'The cooler mounting kit must support the processor socket.', 160
    UNION ALL SELECT 'cpu_cooler_capacity', 'CPU cooling capacity', 'hard',
        'processors', 'cpu-coolers',
        'The cooler must handle the processor thermal requirement.', 170
    UNION ALL SELECT 'cooler_case_height', 'Air cooler case clearance', 'hard',
        'cpu-coolers', 'computer-cases',
        'An air cooler must not exceed the case height limit.', 180
    UNION ALL SELECT 'cooler_case_radiator', 'Radiator case support', 'hard',
        'cpu-coolers', 'computer-cases',
        'An AIO radiator size must be supported by the case.', 190
    UNION ALL SELECT 'system_power_headroom', 'Whole-system power headroom', 'warning',
        'processors', 'power-supplies',
        'The PSU should provide safe headroom above estimated component power.', 200
    UNION ALL SELECT 'display_output_available', 'Display output availability', 'hard',
        'processors', 'graphics-cards',
        'A build needs either integrated processor graphics or a graphics card.', 210
    UNION ALL SELECT 'live_offer_available', 'Live offer availability', 'warning',
        NULL, NULL,
        'Every selected product should have an approved in-stock live offer.', 220
) r ON TRUE
LEFT JOIN categories lc ON lc.slug=r.left_slug
LEFT JOIN categories rc ON rc.slug=r.right_slug
WHERE rs.version='pc-compat-v1.0.0'
ON DUPLICATE KEY UPDATE
    display_name=VALUES(display_name), severity=VALUES(severity),
    left_category_id=VALUES(left_category_id),
    right_category_id=VALUES(right_category_id),
    description=VALUES(description), sort_order=VALUES(sort_order),
    is_active=TRUE;

CREATE TABLE IF NOT EXISTS pc_compatibility_validations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    rule_set_id BIGINT UNSIGNED NOT NULL,
    validation_mode ENUM('partial', 'complete') NOT NULL,
    overall_status ENUM('compatible', 'warning', 'incompatible', 'unknown') NOT NULL,
    selected_product_ids_json JSON NOT NULL,
    result_summary_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_compatibility_validation_public UNIQUE (public_id),
    CONSTRAINT fk_pc_compatibility_validation_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pc_compatibility_validation_rule_set FOREIGN KEY (rule_set_id)
        REFERENCES pc_compatibility_rule_sets (id),
    INDEX idx_pc_compatibility_validation_status (overall_status, created_at),
    INDEX idx_pc_compatibility_validation_user (user_id, created_at)
) ENGINE=InnoDB;

