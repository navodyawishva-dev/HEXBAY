USE hexbay;

INSERT INTO specification_definitions
    (category_id, code, display_name, data_type, unit, is_required,
     is_filterable, is_compatibility_field, minimum_value, maximum_value,
     sort_order, is_active)
SELECT
    c.id, d.code, d.display_name, d.data_type, d.unit, d.is_required,
    TRUE, d.is_compatibility_field, d.minimum_value, d.maximum_value,
    d.sort_order, TRUE
FROM categories c
INNER JOIN (
    SELECT 'laptops' category_slug, 'processor_model' code, 'Processor model' display_name, 'text' data_type, NULL unit, TRUE is_required, FALSE is_compatibility_field, NULL minimum_value, NULL maximum_value, 10 sort_order
    UNION ALL SELECT 'laptops', 'ram_capacity_gb', 'RAM capacity', 'integer', 'GB', TRUE, FALSE, 4, 256, 20
    UNION ALL SELECT 'laptops', 'gpu_model', 'Graphics model', 'text', NULL, FALSE, FALSE, NULL, NULL, 30
    UNION ALL SELECT 'laptops', 'storage_capacity_gb', 'Storage capacity', 'integer', 'GB', TRUE, FALSE, 64, 8192, 40
    UNION ALL SELECT 'laptops', 'screen_size_inches', 'Screen size', 'decimal', 'inches', FALSE, FALSE, 8, 30, 50
    UNION ALL SELECT 'processors', 'socket', 'CPU socket', 'option', NULL, TRUE, TRUE, NULL, NULL, 10
    UNION ALL SELECT 'processors', 'core_count', 'Core count', 'integer', 'cores', TRUE, FALSE, 1, 256, 20
    UNION ALL SELECT 'processors', 'thread_count', 'Thread count', 'integer', 'threads', FALSE, FALSE, 1, 512, 30
    UNION ALL SELECT 'processors', 'tdp_watts', 'Thermal design power', 'integer', 'W', FALSE, TRUE, 1, 1000, 40
    UNION ALL SELECT 'motherboards', 'cpu_socket', 'CPU socket', 'option', NULL, TRUE, TRUE, NULL, NULL, 10
    UNION ALL SELECT 'motherboards', 'ram_generation', 'Supported RAM generation', 'option', NULL, TRUE, TRUE, NULL, NULL, 20
    UNION ALL SELECT 'motherboards', 'form_factor', 'Motherboard form factor', 'option', NULL, TRUE, TRUE, NULL, NULL, 30
    UNION ALL SELECT 'motherboards', 'm2_slots', 'M.2 slot count', 'integer', 'slots', FALSE, TRUE, 0, 20, 40
    UNION ALL SELECT 'memory', 'ddr_generation', 'DDR generation', 'option', NULL, TRUE, TRUE, NULL, NULL, 10
    UNION ALL SELECT 'memory', 'capacity_gb', 'Memory capacity', 'integer', 'GB', TRUE, FALSE, 1, 1024, 20
    UNION ALL SELECT 'memory', 'speed_mhz', 'Memory speed', 'integer', 'MHz', FALSE, FALSE, 400, 12000, 30
    UNION ALL SELECT 'graphics-cards', 'vram_gb', 'Graphics memory', 'integer', 'GB', TRUE, FALSE, 1, 128, 10
    UNION ALL SELECT 'graphics-cards', 'gpu_length_mm', 'Graphics card length', 'integer', 'mm', FALSE, TRUE, 50, 1000, 20
    UNION ALL SELECT 'graphics-cards', 'recommended_psu_watts', 'Recommended PSU', 'integer', 'W', FALSE, TRUE, 100, 3000, 30
    UNION ALL SELECT 'graphics-cards', 'power_connectors', 'Required power connectors', 'multi_option', NULL, FALSE, TRUE, NULL, NULL, 40
    UNION ALL SELECT 'power-supplies', 'wattage', 'Power output', 'integer', 'W', TRUE, TRUE, 100, 3000, 10
    UNION ALL SELECT 'power-supplies', 'efficiency_rating', 'Efficiency rating', 'option', NULL, FALSE, FALSE, NULL, NULL, 20
    UNION ALL SELECT 'power-supplies', 'form_factor', 'PSU form factor', 'option', NULL, TRUE, TRUE, NULL, NULL, 30
    UNION ALL SELECT 'power-supplies', 'available_connectors', 'Available power connectors', 'multi_option', NULL, FALSE, TRUE, NULL, NULL, 40
    UNION ALL SELECT 'storage', 'storage_type', 'Storage type', 'option', NULL, TRUE, FALSE, NULL, NULL, 10
    UNION ALL SELECT 'storage', 'capacity_gb', 'Storage capacity', 'integer', 'GB', TRUE, FALSE, 16, 100000, 20
    UNION ALL SELECT 'storage', 'interface', 'Storage interface', 'option', NULL, TRUE, TRUE, NULL, NULL, 30
    UNION ALL SELECT 'storage', 'form_factor', 'Storage form factor', 'option', NULL, FALSE, FALSE, NULL, NULL, 40
    UNION ALL SELECT 'computer-cases', 'motherboard_form_factors', 'Supported motherboard sizes', 'multi_option', NULL, TRUE, TRUE, NULL, NULL, 10
    UNION ALL SELECT 'computer-cases', 'max_gpu_length_mm', 'Maximum GPU length', 'integer', 'mm', FALSE, TRUE, 100, 1000, 20
    UNION ALL SELECT 'computer-cases', 'psu_form_factors', 'Supported PSU sizes', 'multi_option', NULL, FALSE, TRUE, NULL, NULL, 30
    UNION ALL SELECT 'accessories', 'accessory_type', 'Accessory type', 'option', NULL, TRUE, FALSE, NULL, NULL, 10
) d ON d.category_slug = c.slug
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    data_type = VALUES(data_type),
    unit = VALUES(unit),
    is_required = VALUES(is_required),
    is_filterable = VALUES(is_filterable),
    is_compatibility_field = VALUES(is_compatibility_field),
    minimum_value = VALUES(minimum_value),
    maximum_value = VALUES(maximum_value),
    sort_order = VALUES(sort_order),
    is_active = TRUE;

INSERT INTO specification_options
    (definition_id, value_code, display_value, sort_order, is_active)
SELECT sd.id, o.value_code, o.display_value, o.sort_order, TRUE
FROM categories c
INNER JOIN specification_definitions sd ON sd.category_id = c.id
INNER JOIN (
    SELECT 'processors' category_slug, 'socket' definition_code, 'am4' value_code, 'AM4' display_value, 10 sort_order
    UNION ALL SELECT 'processors', 'socket', 'am5', 'AM5', 20
    UNION ALL SELECT 'processors', 'socket', 'lga1200', 'LGA1200', 30
    UNION ALL SELECT 'processors', 'socket', 'lga1700', 'LGA1700', 40
    UNION ALL SELECT 'processors', 'socket', 'lga1851', 'LGA1851', 50
    UNION ALL SELECT 'motherboards', 'cpu_socket', 'am4', 'AM4', 10
    UNION ALL SELECT 'motherboards', 'cpu_socket', 'am5', 'AM5', 20
    UNION ALL SELECT 'motherboards', 'cpu_socket', 'lga1200', 'LGA1200', 30
    UNION ALL SELECT 'motherboards', 'cpu_socket', 'lga1700', 'LGA1700', 40
    UNION ALL SELECT 'motherboards', 'cpu_socket', 'lga1851', 'LGA1851', 50
    UNION ALL SELECT 'motherboards', 'ram_generation', 'ddr3', 'DDR3', 10
    UNION ALL SELECT 'motherboards', 'ram_generation', 'ddr4', 'DDR4', 20
    UNION ALL SELECT 'motherboards', 'ram_generation', 'ddr5', 'DDR5', 30
    UNION ALL SELECT 'motherboards', 'form_factor', 'mini_itx', 'Mini-ITX', 10
    UNION ALL SELECT 'motherboards', 'form_factor', 'micro_atx', 'Micro-ATX', 20
    UNION ALL SELECT 'motherboards', 'form_factor', 'atx', 'ATX', 30
    UNION ALL SELECT 'motherboards', 'form_factor', 'eatx', 'E-ATX', 40
    UNION ALL SELECT 'memory', 'ddr_generation', 'ddr3', 'DDR3', 10
    UNION ALL SELECT 'memory', 'ddr_generation', 'ddr4', 'DDR4', 20
    UNION ALL SELECT 'memory', 'ddr_generation', 'ddr5', 'DDR5', 30
    UNION ALL SELECT 'graphics-cards', 'power_connectors', 'none', 'No external connector', 10
    UNION ALL SELECT 'graphics-cards', 'power_connectors', 'six_pin', '6-pin PCIe', 20
    UNION ALL SELECT 'graphics-cards', 'power_connectors', 'eight_pin', '8-pin PCIe', 30
    UNION ALL SELECT 'graphics-cards', 'power_connectors', 'twelve_vhpwr', '12VHPWR', 40
    UNION ALL SELECT 'power-supplies', 'efficiency_rating', '80_plus_bronze', '80 Plus Bronze', 10
    UNION ALL SELECT 'power-supplies', 'efficiency_rating', '80_plus_silver', '80 Plus Silver', 20
    UNION ALL SELECT 'power-supplies', 'efficiency_rating', '80_plus_gold', '80 Plus Gold', 30
    UNION ALL SELECT 'power-supplies', 'efficiency_rating', '80_plus_platinum', '80 Plus Platinum', 40
    UNION ALL SELECT 'power-supplies', 'form_factor', 'atx', 'ATX', 10
    UNION ALL SELECT 'power-supplies', 'form_factor', 'sfx', 'SFX', 20
    UNION ALL SELECT 'power-supplies', 'form_factor', 'sfx_l', 'SFX-L', 30
    UNION ALL SELECT 'power-supplies', 'available_connectors', 'six_pin', '6-pin PCIe', 10
    UNION ALL SELECT 'power-supplies', 'available_connectors', 'eight_pin', '8-pin PCIe', 20
    UNION ALL SELECT 'power-supplies', 'available_connectors', 'twelve_vhpwr', '12VHPWR', 30
    UNION ALL SELECT 'storage', 'storage_type', 'hdd', 'Hard disk drive', 10
    UNION ALL SELECT 'storage', 'storage_type', 'sata_ssd', 'SATA SSD', 20
    UNION ALL SELECT 'storage', 'storage_type', 'nvme_ssd', 'NVMe SSD', 30
    UNION ALL SELECT 'storage', 'interface', 'sata', 'SATA', 10
    UNION ALL SELECT 'storage', 'interface', 'pcie_3', 'PCIe 3.0', 20
    UNION ALL SELECT 'storage', 'interface', 'pcie_4', 'PCIe 4.0', 30
    UNION ALL SELECT 'storage', 'interface', 'pcie_5', 'PCIe 5.0', 40
    UNION ALL SELECT 'storage', 'form_factor', 'three_point_five', '3.5 inch', 10
    UNION ALL SELECT 'storage', 'form_factor', 'two_point_five', '2.5 inch', 20
    UNION ALL SELECT 'storage', 'form_factor', 'm2_2280', 'M.2 2280', 30
    UNION ALL SELECT 'computer-cases', 'motherboard_form_factors', 'mini_itx', 'Mini-ITX', 10
    UNION ALL SELECT 'computer-cases', 'motherboard_form_factors', 'micro_atx', 'Micro-ATX', 20
    UNION ALL SELECT 'computer-cases', 'motherboard_form_factors', 'atx', 'ATX', 30
    UNION ALL SELECT 'computer-cases', 'motherboard_form_factors', 'eatx', 'E-ATX', 40
    UNION ALL SELECT 'computer-cases', 'psu_form_factors', 'atx', 'ATX', 10
    UNION ALL SELECT 'computer-cases', 'psu_form_factors', 'sfx', 'SFX', 20
    UNION ALL SELECT 'computer-cases', 'psu_form_factors', 'sfx_l', 'SFX-L', 30
    UNION ALL SELECT 'accessories', 'accessory_type', 'keyboard', 'Keyboard', 10
    UNION ALL SELECT 'accessories', 'accessory_type', 'mouse', 'Mouse', 20
    UNION ALL SELECT 'accessories', 'accessory_type', 'monitor', 'Monitor', 30
    UNION ALL SELECT 'accessories', 'accessory_type', 'headset', 'Headset', 40
    UNION ALL SELECT 'accessories', 'accessory_type', 'other', 'Other accessory', 50
) o ON o.category_slug = c.slug AND o.definition_code = sd.code
ON DUPLICATE KEY UPDATE
    display_value = VALUES(display_value),
    sort_order = VALUES(sort_order),
    is_active = TRUE;
