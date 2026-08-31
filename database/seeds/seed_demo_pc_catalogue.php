<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__, 2) . '/backend/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This demo seed must be run from the command line.\n");
    exit(1);
}

$assetRoot = __DIR__ . DIRECTORY_SEPARATOR . 'demo-assets';
$productStorageRoot = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'backend'
    . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'product-images';
$logoStorageRoot = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'backend'
    . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'shop-logos';
$assetFiles = [
    'cpu' => $assetRoot . DIRECTORY_SEPARATOR . 'component-cpu.png',
    'motherboard' => $assetRoot . DIRECTORY_SEPARATOR . 'component-motherboard.png',
    'memory' => $assetRoot . DIRECTORY_SEPARATOR . 'component-ram.png',
    'graphics' => $assetRoot . DIRECTORY_SEPARATOR . 'component-gpu.png',
    'power_supply' => $assetRoot . DIRECTORY_SEPARATOR . 'component-psu.png',
    'storage' => $assetRoot . DIRECTORY_SEPARATOR . 'component-storage-nvme.png',
    'case' => $assetRoot . DIRECTORY_SEPARATOR . 'component-case.png',
    'cpu_cooler' => $assetRoot . DIRECTORY_SEPARATOR . 'component-cpu-cooler.png',
    'novacore_logo' => $assetRoot . DIRECTORY_SEPARATOR . 'shop-novacore-logo.png',
    'bytecraft_logo' => $assetRoot . DIRECTORY_SEPARATOR . 'shop-bytecraft-logo.png',
];
foreach ($assetFiles as $asset) {
    if (!is_file($asset) || filesize($asset) < 1) {
        fwrite(STDERR, "Missing PC demo image: {$asset}\n");
        exit(1);
    }
}
foreach ([$productStorageRoot, $logoStorageRoot] as $storageRoot) {
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
        fwrite(STDERR, "Demo image storage is unavailable: {$storageRoot}\n");
        exit(1);
    }
}

$shops = [
    'novacore' => [
        'email' => 'seller.novacore@hexbay.test',
        'first_name' => 'Kasun',
        'last_name' => 'Fernando',
        'phone' => '+94 77 620 4101',
        'business_name' => 'NovaCore Systems',
        'shop_name' => 'NovaCore Systems',
        'slug' => 'demo-novacore-systems',
        'description' => 'Demo PC-component specialist for balanced gaming, creator and productivity builds.',
        'address' => 'Unity Plaza, Colombo 04, Sri Lanka',
        'rating' => 4.76,
        'rating_count' => 93,
        'logo' => 'novacore_logo',
    ],
    'bytecraft' => [
        'email' => 'seller.bytecraft@hexbay.test',
        'first_name' => 'Dinithi',
        'last_name' => 'Jayasinghe',
        'phone' => '+94 76 730 5202',
        'business_name' => 'ByteCraft Technologies',
        'shop_name' => 'ByteCraft Technologies',
        'slug' => 'demo-bytecraft-technologies',
        'description' => 'Demo retailer for upgrade parts and complete desktop component combinations.',
        'address' => 'Galle Road, Dehiwala, Sri Lanka',
        'rating' => 4.63,
        'rating_count' => 74,
        'logo' => 'bytecraft_logo',
    ],
];

/*
 * Model names carry a -Demo suffix so this repeatable local fixture cannot
 * overwrite a seller-created canonical product for the corresponding model.
 * Prices are simulated Sri Lankan rupee values for interface testing only.
 */
$products = [
    'ryzen-5-5600' => [
        'category' => 'processors', 'brand' => 'AMD', 'brand_slug' => 'amd',
        'name' => 'AMD Ryzen 5 5600', 'model' => 'Ryzen-5-5600-Demo',
        'mpn' => 'HB-PC-AMD-R55600', 'image' => 'cpu', 'sku' => 'R55600',
        'description' => 'Six-core AM4 desktop processor for affordable gaming and productivity builds.',
        'specs' => [
            'socket' => 'am4', 'core_count' => 6, 'thread_count' => 12,
            'tdp_watts' => 65, 'architecture_family' => 'zen3',
            'supported_chipsets' => ['a520', 'b450', 'b550', 'x570'],
            'base_clock_ghz' => 3.5, 'boost_clock_ghz' => 4.4,
            'integrated_graphics' => false, 'cooler_included' => true,
            'peak_power_watts' => 88,
        ],
        'offers' => ['novacore' => [42500, 12], 'bytecraft' => [41900, 9]],
    ],
    'ryzen-5-7600' => [
        'category' => 'processors', 'brand' => 'AMD', 'brand_slug' => 'amd',
        'name' => 'AMD Ryzen 5 7600', 'model' => 'Ryzen-5-7600-Demo',
        'mpn' => 'HB-PC-AMD-R57600', 'image' => 'cpu', 'sku' => 'R57600',
        'description' => 'Six-core AM5 desktop processor for efficient current-generation systems.',
        'specs' => [
            'socket' => 'am5', 'core_count' => 6, 'thread_count' => 12,
            'tdp_watts' => 65, 'architecture_family' => 'zen4',
            'supported_chipsets' => ['a620', 'b650', 'x670'],
            'base_clock_ghz' => 3.8, 'boost_clock_ghz' => 5.1,
            'integrated_graphics' => true, 'cooler_included' => true,
            'peak_power_watts' => 88,
        ],
        'offers' => ['novacore' => [73500, 8], 'bytecraft' => [72400, 7]],
    ],
    'core-i5-13400f' => [
        'category' => 'processors', 'brand' => 'Intel', 'brand_slug' => 'intel',
        'name' => 'Intel Core i5-13400F', 'model' => 'Core-i5-13400F-Demo',
        'mpn' => 'HB-PC-INT-I513400F', 'image' => 'cpu', 'sku' => 'I513400F',
        'description' => 'Ten-core LGA1700 processor for mainstream gaming and multi-tasking desktops.',
        'specs' => [
            'socket' => 'lga1700', 'core_count' => 10, 'thread_count' => 16,
            'tdp_watts' => 65, 'architecture_family' => 'raptor_lake',
            'supported_chipsets' => ['h610', 'b660', 'b760', 'z690', 'z790'],
            'base_clock_ghz' => 2.5, 'boost_clock_ghz' => 4.6,
            'integrated_graphics' => false, 'cooler_included' => true,
            'peak_power_watts' => 148,
        ],
        'offers' => ['novacore' => [68500, 10], 'bytecraft' => [67900, 6]],
    ],
    'core-i7-14700k' => [
        'category' => 'processors', 'brand' => 'Intel', 'brand_slug' => 'intel',
        'name' => 'Intel Core i7-14700K', 'model' => 'Core-i7-14700K-Demo',
        'mpn' => 'HB-PC-INT-I714700K', 'image' => 'cpu', 'sku' => 'I714700K',
        'description' => 'Twenty-core LGA1700 processor for high-end creator and performance systems.',
        'specs' => [
            'socket' => 'lga1700', 'core_count' => 20, 'thread_count' => 28,
            'tdp_watts' => 125, 'architecture_family' => 'raptor_lake_refresh',
            'supported_chipsets' => ['b660', 'b760', 'z690', 'z790'],
            'base_clock_ghz' => 3.4, 'boost_clock_ghz' => 5.6,
            'integrated_graphics' => true, 'cooler_included' => false,
            'peak_power_watts' => 253,
        ],
        'offers' => ['bytecraft' => [142500, 4]],
    ],
    'b550m-pro-vdh' => [
        'category' => 'motherboards', 'brand' => 'MSI', 'brand_slug' => 'msi',
        'name' => 'MSI B550M PRO-VDH WIFI', 'model' => 'B550M-PRO-VDH-Demo',
        'mpn' => 'HB-PC-MSI-B550M', 'image' => 'motherboard', 'sku' => 'B550M',
        'description' => 'Micro-ATX AM4 motherboard with DDR4 memory support and two M.2 slots.',
        'specs' => [
            'cpu_socket' => 'am4', 'ram_generation' => 'ddr4',
            'form_factor' => 'micro_atx', 'm2_slots' => 2, 'chipset' => 'b550',
            'supported_cpu_families' => ['zen3'], 'memory_slots' => 4,
            'max_memory_capacity_gb' => 128, 'max_memory_speed_mhz' => 4400,
            'pcie_x16_generation' => 'pcie_4',
            'm2_interfaces' => ['pcie_3', 'pcie_4'], 'wifi_included' => true,
            'bios_support_note' => 'Zen 3 support; exact BIOS version must be checked for each CPU revision.',
        ],
        'offers' => ['novacore' => [43500, 7], 'bytecraft' => [42900, 5]],
    ],
    'tuf-b650-plus' => [
        'category' => 'motherboards', 'brand' => 'Asus', 'brand_slug' => 'asus',
        'name' => 'Asus TUF Gaming B650-PLUS WIFI', 'model' => 'TUF-B650-PLUS-Demo',
        'mpn' => 'HB-PC-ASU-B650', 'image' => 'motherboard', 'sku' => 'B650PLUS',
        'description' => 'ATX AM5 motherboard with DDR5 support and three M.2 storage slots.',
        'specs' => [
            'cpu_socket' => 'am5', 'ram_generation' => 'ddr5',
            'form_factor' => 'atx', 'm2_slots' => 3, 'chipset' => 'b650',
            'supported_cpu_families' => ['zen4'], 'memory_slots' => 4,
            'max_memory_capacity_gb' => 192, 'max_memory_speed_mhz' => 7600,
            'pcie_x16_generation' => 'pcie_4',
            'm2_interfaces' => ['pcie_4', 'pcie_5'], 'wifi_included' => true,
            'bios_support_note' => 'AM5 CPU support depends on the installed firmware revision.',
        ],
        'offers' => ['novacore' => [82500, 6], 'bytecraft' => [81400, 4]],
    ],
    'b760m-ds3h-ddr4' => [
        'category' => 'motherboards', 'brand' => 'Gigabyte', 'brand_slug' => 'gigabyte',
        'name' => 'Gigabyte B760M DS3H AX DDR4', 'model' => 'B760M-DS3H-AX-D4-Demo',
        'mpn' => 'HB-PC-GIG-B760D4', 'image' => 'motherboard', 'sku' => 'B760MD4',
        'description' => 'Micro-ATX LGA1700 motherboard using DDR4 memory and two M.2 slots.',
        'specs' => [
            'cpu_socket' => 'lga1700', 'ram_generation' => 'ddr4',
            'form_factor' => 'micro_atx', 'm2_slots' => 2, 'chipset' => 'b760',
            'supported_cpu_families' => ['alder_lake', 'raptor_lake', 'raptor_lake_refresh'],
            'memory_slots' => 4, 'max_memory_capacity_gb' => 128,
            'max_memory_speed_mhz' => 5333, 'pcie_x16_generation' => 'pcie_4',
            'm2_interfaces' => ['pcie_4'], 'wifi_included' => true,
            'bios_support_note' => 'Later LGA1700 CPU revisions may require a firmware update.',
        ],
        'offers' => ['novacore' => [57500, 7]],
    ],
    'pro-b760-p-ddr5' => [
        'category' => 'motherboards', 'brand' => 'MSI', 'brand_slug' => 'msi',
        'name' => 'MSI PRO B760-P WIFI DDR5', 'model' => 'PRO-B760-P-D5-Demo',
        'mpn' => 'HB-PC-MSI-B760D5', 'image' => 'motherboard', 'sku' => 'B760PD5',
        'description' => 'ATX LGA1700 motherboard using DDR5 memory and two M.2 slots.',
        'specs' => [
            'cpu_socket' => 'lga1700', 'ram_generation' => 'ddr5',
            'form_factor' => 'atx', 'm2_slots' => 2, 'chipset' => 'b760',
            'supported_cpu_families' => ['alder_lake', 'raptor_lake', 'raptor_lake_refresh'],
            'memory_slots' => 4, 'max_memory_capacity_gb' => 192,
            'max_memory_speed_mhz' => 7000, 'pcie_x16_generation' => 'pcie_4',
            'm2_interfaces' => ['pcie_4'], 'wifi_included' => true,
            'bios_support_note' => 'Later LGA1700 CPU revisions may require a firmware update.',
        ],
        'offers' => ['bytecraft' => [68900, 6]],
    ],
    'z790-aorus-xtreme' => [
        'category' => 'motherboards', 'brand' => 'Gigabyte', 'brand_slug' => 'gigabyte',
        'name' => 'Gigabyte Z790 AORUS XTREME X', 'model' => 'Z790-AORUS-XTREME-X-Demo',
        'mpn' => 'HB-PC-GIG-Z790X', 'image' => 'motherboard', 'sku' => 'Z790EATX',
        'description' => 'E-ATX LGA1700 motherboard with DDR5 support and five M.2 slots.',
        'specs' => [
            'cpu_socket' => 'lga1700', 'ram_generation' => 'ddr5',
            'form_factor' => 'eatx', 'm2_slots' => 5, 'chipset' => 'z790',
            'supported_cpu_families' => ['alder_lake', 'raptor_lake', 'raptor_lake_refresh'],
            'memory_slots' => 4, 'max_memory_capacity_gb' => 192,
            'max_memory_speed_mhz' => 8266, 'pcie_x16_generation' => 'pcie_5',
            'm2_interfaces' => ['pcie_4', 'pcie_5'], 'wifi_included' => true,
            'bios_support_note' => 'CPU support depends on the board firmware revision.',
        ],
        'offers' => ['bytecraft' => [236000, 2]],
    ],
    'fury-16-ddr4' => [
        'category' => 'memory', 'brand' => 'Kingston', 'brand_slug' => 'kingston',
        'name' => 'Kingston FURY Beast 16GB DDR4-3200', 'model' => 'FURY-16-D4-3200-Demo',
        'mpn' => 'HB-PC-KIN-16D4', 'image' => 'memory', 'sku' => '16D432',
        'description' => '16GB DDR4 desktop memory kit for compatible DDR4 motherboards.',
        'specs' => [
            'ddr_generation' => 'ddr4', 'capacity_gb' => 16, 'speed_mhz' => 3200,
            'module_count' => 2, 'capacity_per_module_gb' => 8,
            'cas_latency' => 16, 'memory_profiles' => ['xmp'], 'ecc_memory' => false,
        ],
        'offers' => ['novacore' => [15500, 18], 'bytecraft' => [14900, 14]],
    ],
    'vengeance-32-ddr4' => [
        'category' => 'memory', 'brand' => 'Corsair', 'brand_slug' => 'corsair',
        'name' => 'Corsair Vengeance LPX 32GB DDR4-3600', 'model' => 'Vengeance-32-D4-3600-Demo',
        'mpn' => 'HB-PC-COR-32D4', 'image' => 'memory', 'sku' => '32D436',
        'description' => '32GB DDR4 desktop memory kit for larger productivity workloads.',
        'specs' => [
            'ddr_generation' => 'ddr4', 'capacity_gb' => 32, 'speed_mhz' => 3600,
            'module_count' => 2, 'capacity_per_module_gb' => 16,
            'cas_latency' => 18, 'memory_profiles' => ['xmp'], 'ecc_memory' => false,
        ],
        'offers' => ['bytecraft' => [26900, 10]],
    ],
    'fury-32-ddr5' => [
        'category' => 'memory', 'brand' => 'Kingston', 'brand_slug' => 'kingston',
        'name' => 'Kingston FURY Beast 32GB DDR5-6000', 'model' => 'FURY-32-D5-6000-Demo',
        'mpn' => 'HB-PC-KIN-32D5', 'image' => 'memory', 'sku' => '32D560',
        'description' => '32GB DDR5 desktop memory kit for current-generation platforms.',
        'specs' => [
            'ddr_generation' => 'ddr5', 'capacity_gb' => 32, 'speed_mhz' => 6000,
            'module_count' => 2, 'capacity_per_module_gb' => 16,
            'cas_latency' => 36, 'memory_profiles' => ['xmp', 'expo'], 'ecc_memory' => false,
        ],
        'offers' => ['novacore' => [34900, 12], 'bytecraft' => [33900, 9]],
    ],
    'vengeance-64-ddr5' => [
        'category' => 'memory', 'brand' => 'Corsair', 'brand_slug' => 'corsair',
        'name' => 'Corsair Vengeance 64GB DDR5-6000', 'model' => 'Vengeance-64-D5-6000-Demo',
        'mpn' => 'HB-PC-COR-64D5', 'image' => 'memory', 'sku' => '64D560',
        'description' => '64GB DDR5 desktop memory kit for demanding creator workstations.',
        'specs' => [
            'ddr_generation' => 'ddr5', 'capacity_gb' => 64, 'speed_mhz' => 6000,
            'module_count' => 2, 'capacity_per_module_gb' => 32,
            'cas_latency' => 36, 'memory_profiles' => ['xmp'], 'ecc_memory' => false,
        ],
        'offers' => ['bytecraft' => [69500, 5]],
    ],
    'rtx-4060-ventus' => [
        'category' => 'graphics-cards', 'brand' => 'MSI', 'brand_slug' => 'msi',
        'name' => 'MSI GeForce RTX 4060 Ventus 2X 8GB', 'model' => 'RTX4060-Ventus-2X-Demo',
        'mpn' => 'HB-PC-MSI-4060', 'image' => 'graphics', 'sku' => 'RTX4060',
        'description' => 'Compact 8GB graphics card suitable for efficient 1080p gaming builds.',
        'specs' => [
            'vram_gb' => 8, 'gpu_length_mm' => 199, 'recommended_psu_watts' => 550,
            'power_connectors' => ['eight_pin'], 'gpu_height_mm' => 120,
            'gpu_thickness_slots' => 2.0, 'total_board_power_watts' => 115,
            'pcie_generation' => 'pcie_4',
            'compute_capabilities' => ['directx_12', 'vulkan', 'opencl', 'cuda', 'av1_encode'],
        ],
        'offers' => ['novacore' => [128500, 7], 'bytecraft' => [126900, 6]],
    ],
    'rx-7600-gaming-oc' => [
        'category' => 'graphics-cards', 'brand' => 'Gigabyte', 'brand_slug' => 'gigabyte',
        'name' => 'Gigabyte Radeon RX 7600 Gaming OC 8GB', 'model' => 'RX7600-Gaming-OC-Demo',
        'mpn' => 'HB-PC-GIG-RX7600', 'image' => 'graphics', 'sku' => 'RX7600',
        'description' => '8GB mainstream graphics card with a 282 mm triple-fan cooler.',
        'specs' => [
            'vram_gb' => 8, 'gpu_length_mm' => 282, 'recommended_psu_watts' => 550,
            'power_connectors' => ['eight_pin'], 'gpu_height_mm' => 116,
            'gpu_thickness_slots' => 2.5, 'total_board_power_watts' => 165,
            'pcie_generation' => 'pcie_4',
            'compute_capabilities' => ['directx_12', 'vulkan', 'opencl', 'av1_encode'],
        ],
        'offers' => ['bytecraft' => [117500, 7]],
    ],
    'rtx-4070-super' => [
        'category' => 'graphics-cards', 'brand' => 'Asus', 'brand_slug' => 'asus',
        'name' => 'Asus Dual GeForce RTX 4070 SUPER 12GB', 'model' => 'RTX4070S-Dual-Demo',
        'mpn' => 'HB-PC-ASU-4070S', 'image' => 'graphics', 'sku' => 'RTX4070S',
        'description' => '12GB performance graphics card with a 267 mm cooler and 12VHPWR input.',
        'specs' => [
            'vram_gb' => 12, 'gpu_length_mm' => 267, 'recommended_psu_watts' => 650,
            'power_connectors' => ['twelve_vhpwr'], 'gpu_height_mm' => 134,
            'gpu_thickness_slots' => 2.6, 'total_board_power_watts' => 220,
            'pcie_generation' => 'pcie_4',
            'compute_capabilities' => ['directx_12', 'vulkan', 'opencl', 'cuda', 'av1_encode'],
        ],
        'offers' => ['novacore' => [239000, 4], 'bytecraft' => [236500, 3]],
    ],
    'rtx-4080-super' => [
        'category' => 'graphics-cards', 'brand' => 'Gigabyte', 'brand_slug' => 'gigabyte',
        'name' => 'Gigabyte GeForce RTX 4080 SUPER Gaming OC 16GB', 'model' => 'RTX4080S-Gaming-OC-Demo',
        'mpn' => 'HB-PC-GIG-4080S', 'image' => 'graphics', 'sku' => 'RTX4080S',
        'description' => 'Large 16GB high-end GPU requiring substantial case clearance and an 850 W PSU.',
        'specs' => [
            'vram_gb' => 16, 'gpu_length_mm' => 342, 'recommended_psu_watts' => 850,
            'power_connectors' => ['twelve_vhpwr'], 'gpu_height_mm' => 150,
            'gpu_thickness_slots' => 3.5, 'total_board_power_watts' => 320,
            'pcie_generation' => 'pcie_4',
            'compute_capabilities' => ['directx_12', 'vulkan', 'opencl', 'cuda', 'av1_encode'],
        ],
        'offers' => ['novacore' => [489000, 2]],
    ],
    'cx550' => [
        'category' => 'power-supplies', 'brand' => 'Corsair', 'brand_slug' => 'corsair',
        'name' => 'Corsair CX550 550W', 'model' => 'CX550-Demo',
        'mpn' => 'HB-PC-COR-CX550', 'image' => 'power_supply', 'sku' => 'CX550',
        'description' => '550 W ATX power supply with 80 Plus Bronze efficiency and standard PCIe connectors.',
        'specs' => [
            'wattage' => 550, 'efficiency_rating' => '80_plus_bronze',
            'form_factor' => 'atx', 'available_connectors' => ['six_pin', 'eight_pin'],
            'atx_standard' => 'atx_2_4', 'modularity' => 'non_modular',
            'six_pin_connector_count' => 0, 'eight_pin_connector_count' => 2,
            'twelve_vhpwr_connector_count' => 0, 'warranty_years' => 5,
        ],
        'offers' => ['novacore' => [24500, 11], 'bytecraft' => [23900, 8]],
    ],
    'mwe-650-bronze' => [
        'category' => 'power-supplies', 'brand' => 'Cooler Master', 'brand_slug' => 'cooler-master',
        'name' => 'Cooler Master MWE 650 Bronze V2', 'model' => 'MWE-650-Bronze-V2-Demo',
        'mpn' => 'HB-PC-CM-MWE650', 'image' => 'power_supply', 'sku' => 'MWE650',
        'description' => '650 W ATX Bronze power supply for mainstream graphics-card builds.',
        'specs' => [
            'wattage' => 650, 'efficiency_rating' => '80_plus_bronze',
            'form_factor' => 'atx', 'available_connectors' => ['six_pin', 'eight_pin'],
            'atx_standard' => 'atx_2_52', 'modularity' => 'non_modular',
            'six_pin_connector_count' => 0, 'eight_pin_connector_count' => 2,
            'twelve_vhpwr_connector_count' => 0, 'warranty_years' => 5,
        ],
        'offers' => ['bytecraft' => [29500, 9]],
    ],
    'rm750e' => [
        'category' => 'power-supplies', 'brand' => 'Corsair', 'brand_slug' => 'corsair',
        'name' => 'Corsair RM750e 750W', 'model' => 'RM750e-Demo',
        'mpn' => 'HB-PC-COR-RM750E', 'image' => 'power_supply', 'sku' => 'RM750E',
        'description' => '750 W modular ATX Gold power supply with modern GPU connector support.',
        'specs' => [
            'wattage' => 750, 'efficiency_rating' => '80_plus_gold',
            'form_factor' => 'atx', 'available_connectors' => ['six_pin', 'eight_pin', 'twelve_vhpwr'],
            'atx_standard' => 'atx_3_0', 'modularity' => 'fully_modular',
            'six_pin_connector_count' => 0, 'eight_pin_connector_count' => 3,
            'twelve_vhpwr_connector_count' => 1, 'warranty_years' => 7,
        ],
        'offers' => ['novacore' => [52500, 7], 'bytecraft' => [51400, 5]],
    ],
    'v850-sfx' => [
        'category' => 'power-supplies', 'brand' => 'Cooler Master', 'brand_slug' => 'cooler-master',
        'name' => 'Cooler Master V850 SFX Gold', 'model' => 'V850-SFX-Gold-Demo',
        'mpn' => 'HB-PC-CM-V850SFX', 'image' => 'power_supply', 'sku' => 'V850SFX',
        'description' => '850 W compact SFX Gold power supply with modern GPU connector support.',
        'specs' => [
            'wattage' => 850, 'efficiency_rating' => '80_plus_gold',
            'form_factor' => 'sfx', 'available_connectors' => ['six_pin', 'eight_pin', 'twelve_vhpwr'],
            'atx_standard' => 'atx_3_0', 'modularity' => 'fully_modular',
            'six_pin_connector_count' => 0, 'eight_pin_connector_count' => 4,
            'twelve_vhpwr_connector_count' => 1, 'warranty_years' => 10,
        ],
        'offers' => ['novacore' => [68500, 4]],
    ],
    'sn570-500' => [
        'category' => 'storage', 'brand' => 'Western Digital', 'brand_slug' => 'western-digital',
        'name' => 'WD Blue SN570 500GB NVMe SSD', 'model' => 'SN570-500-Demo',
        'mpn' => 'HB-PC-WD-SN570500', 'image' => 'storage', 'sku' => 'SN570500',
        'description' => '500GB M.2 2280 NVMe solid-state drive using a PCIe 3.0 interface.',
        'specs' => [
            'storage_type' => 'nvme_ssd', 'capacity_gb' => 500,
            'interface' => 'pcie_3', 'form_factor' => 'm2_2280',
            'sequential_read_mbps' => 3500, 'sequential_write_mbps' => 2300,
            'endurance_tbw' => 300,
        ],
        'offers' => ['novacore' => [17500, 14], 'bytecraft' => [16900, 11]],
    ],
    'nv2-1tb' => [
        'category' => 'storage', 'brand' => 'Kingston', 'brand_slug' => 'kingston',
        'name' => 'Kingston NV2 1TB NVMe SSD', 'model' => 'NV2-1TB-Demo',
        'mpn' => 'HB-PC-KIN-NV21TB', 'image' => 'storage', 'sku' => 'NV21TB',
        'description' => '1TB M.2 2280 NVMe solid-state drive using a PCIe 4.0 interface.',
        'specs' => [
            'storage_type' => 'nvme_ssd', 'capacity_gb' => 1000,
            'interface' => 'pcie_4', 'form_factor' => 'm2_2280',
            'sequential_read_mbps' => 3500, 'sequential_write_mbps' => 2100,
            'endurance_tbw' => 320,
        ],
        'offers' => ['novacore' => [26500, 15], 'bytecraft' => [25900, 12]],
    ],
    '990-pro-2tb' => [
        'category' => 'storage', 'brand' => 'Samsung', 'brand_slug' => 'samsung',
        'name' => 'Samsung 990 PRO 2TB NVMe SSD', 'model' => '990-PRO-2TB-Demo',
        'mpn' => 'HB-PC-SAM-990P2T', 'image' => 'storage', 'sku' => '990P2TB',
        'description' => '2TB performance M.2 2280 NVMe SSD using a PCIe 4.0 interface.',
        'specs' => [
            'storage_type' => 'nvme_ssd', 'capacity_gb' => 2000,
            'interface' => 'pcie_4', 'form_factor' => 'm2_2280',
            'sequential_read_mbps' => 7450, 'sequential_write_mbps' => 6900,
            'endurance_tbw' => 1200,
        ],
        'offers' => ['bytecraft' => [59500, 6]],
    ],
    'matrexx-40' => [
        'category' => 'computer-cases', 'brand' => 'DeepCool', 'brand_slug' => 'deepcool',
        'name' => 'DeepCool MATREXX 40', 'model' => 'MATREXX-40-Demo',
        'mpn' => 'HB-PC-DC-M40', 'image' => 'case', 'sku' => 'M40',
        'description' => 'Compact case supporting Mini-ITX and Micro-ATX boards, ATX PSUs and GPUs up to 320 mm.',
        'specs' => [
            'motherboard_form_factors' => ['mini_itx', 'micro_atx'],
            'max_gpu_length_mm' => 320, 'psu_form_factors' => ['atx'],
            'max_cpu_cooler_height_mm' => 165,
            'supported_radiator_sizes' => ['rad_120', 'rad_240', 'rad_280'],
            'max_gpu_thickness_slots' => 4.0,
        ],
        'offers' => ['novacore' => [21500, 8], 'bytecraft' => [20900, 6]],
    ],
    'corsair-4000d' => [
        'category' => 'computer-cases', 'brand' => 'Corsair', 'brand_slug' => 'corsair',
        'name' => 'Corsair 4000D Airflow', 'model' => '4000D-Airflow-Demo',
        'mpn' => 'HB-PC-COR-4000D', 'image' => 'case', 'sku' => '4000D',
        'description' => 'ATX mid-tower supporting common board sizes, ATX PSUs and GPUs up to 360 mm.',
        'specs' => [
            'motherboard_form_factors' => ['mini_itx', 'micro_atx', 'atx'],
            'max_gpu_length_mm' => 360, 'psu_form_factors' => ['atx'],
            'max_cpu_cooler_height_mm' => 170,
            'supported_radiator_sizes' => ['rad_120', 'rad_240', 'rad_280', 'rad_360'],
            'max_gpu_thickness_slots' => 4.0,
        ],
        'offers' => ['novacore' => [39500, 7], 'bytecraft' => [38400, 5]],
    ],
    'north-xl' => [
        'category' => 'computer-cases', 'brand' => 'Fractal Design', 'brand_slug' => 'fractal-design',
        'name' => 'Fractal Design North XL', 'model' => 'North-XL-Demo',
        'mpn' => 'HB-PC-FD-NORTHXL', 'image' => 'case', 'sku' => 'NORTHXL',
        'description' => 'Large tower supporting E-ATX and smaller boards, ATX or SFX PSUs and GPUs up to 413 mm.',
        'specs' => [
            'motherboard_form_factors' => ['mini_itx', 'micro_atx', 'atx', 'eatx'],
            'max_gpu_length_mm' => 413, 'psu_form_factors' => ['atx', 'sfx', 'sfx_l'],
            'max_cpu_cooler_height_mm' => 185,
            'supported_radiator_sizes' => ['rad_120', 'rad_240', 'rad_280', 'rad_360', 'rad_420'],
            'max_gpu_thickness_slots' => 5.0,
        ],
        'offers' => ['bytecraft' => [68500, 3]],
    ],
    'nr200p' => [
        'category' => 'computer-cases', 'brand' => 'Cooler Master', 'brand_slug' => 'cooler-master',
        'name' => 'Cooler Master NR200P', 'model' => 'NR200P-Demo',
        'mpn' => 'HB-PC-CM-NR200P', 'image' => 'case', 'sku' => 'NR200P',
        'description' => 'Small-form-factor case for Mini-ITX boards, SFX power supplies and GPUs up to 330 mm.',
        'specs' => [
            'motherboard_form_factors' => ['mini_itx'], 'max_gpu_length_mm' => 330,
            'psu_form_factors' => ['sfx', 'sfx_l'],
            'max_cpu_cooler_height_mm' => 155,
            'supported_radiator_sizes' => ['rad_120', 'rad_240', 'rad_280'],
            'max_gpu_thickness_slots' => 3.0,
        ],
        'offers' => ['novacore' => [46500, 4]],
    ],
    'ag400' => [
        'category' => 'cpu-coolers', 'brand' => 'DeepCool', 'brand_slug' => 'deepcool',
        'name' => 'DeepCool AG400', 'model' => 'AG400-Demo',
        'mpn' => 'HB-PC-DC-AG400', 'image' => 'cpu_cooler', 'sku' => 'AG400',
        'description' => 'Compact tower air cooler for mainstream AM4, AM5 and LGA1700 processors.',
        'specs' => [
            'cooler_type' => 'air', 'supported_sockets' => ['am4', 'am5', 'lga1700'],
            'cooling_capacity_watts' => 180, 'cooler_height_mm' => 150,
            'radiator_size' => 'none', 'noise_level_dba' => 31.6,
        ],
        'offers' => ['novacore' => [11500, 9], 'bytecraft' => [10900, 7]],
    ],
    'ak620' => [
        'category' => 'cpu-coolers', 'brand' => 'DeepCool', 'brand_slug' => 'deepcool',
        'name' => 'DeepCool AK620', 'model' => 'AK620-Demo',
        'mpn' => 'HB-PC-DC-AK620', 'image' => 'cpu_cooler', 'sku' => 'AK620',
        'description' => 'Dual-tower air cooler for higher-power AM4, AM5 and LGA1700 processors.',
        'specs' => [
            'cooler_type' => 'air', 'supported_sockets' => ['am4', 'am5', 'lga1700'],
            'cooling_capacity_watts' => 260, 'cooler_height_mm' => 160,
            'radiator_size' => 'none', 'noise_level_dba' => 28.0,
        ],
        'offers' => ['novacore' => [24500, 5], 'bytecraft' => [23900, 4]],
    ],
    'hyper-212-halo' => [
        'category' => 'cpu-coolers', 'brand' => 'Cooler Master', 'brand_slug' => 'cooler-master',
        'name' => 'Cooler Master Hyper 212 Halo', 'model' => 'Hyper-212-Halo-Demo',
        'mpn' => 'HB-PC-CM-H212H', 'image' => 'cpu_cooler', 'sku' => 'H212H',
        'description' => 'Single-tower air cooler for efficient mainstream desktop processors.',
        'specs' => [
            'cooler_type' => 'air', 'supported_sockets' => ['am4', 'am5', 'lga1700'],
            'cooling_capacity_watts' => 180, 'cooler_height_mm' => 154,
            'radiator_size' => 'none', 'noise_level_dba' => 27.0,
        ],
        'offers' => ['bytecraft' => [16900, 6]],
    ],
    'liquid-freezer-iii-240' => [
        'category' => 'cpu-coolers', 'brand' => 'Arctic', 'brand_slug' => 'arctic',
        'name' => 'Arctic Liquid Freezer III 240', 'model' => 'Liquid-Freezer-III-240-Demo',
        'mpn' => 'HB-PC-ARC-LF3240', 'image' => 'cpu_cooler', 'sku' => 'LF3240',
        'description' => '240 mm all-in-one liquid cooler for performance AM4, AM5 and LGA1700 systems.',
        'specs' => [
            'cooler_type' => 'aio', 'supported_sockets' => ['am4', 'am5', 'lga1700'],
            'cooling_capacity_watts' => 300, 'radiator_size' => 'rad_240',
            'noise_level_dba' => 30.0,
        ],
        'offers' => ['novacore' => [39500, 4]],
    ],
];

$db = Database::connection();
$newFiles = [];

/** @return int */
function requiredPcDemoId(PDO $db, string $sql, array $params, string $message): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $id = (int) $statement->fetchColumn();
    if ($id < 1) {
        throw new RuntimeException($message);
    }
    return $id;
}

/** @param array<int, string> $newFiles */
function storePcDemoAsset(string $source, string $destination, array &$newFiles): void
{
    if (is_file($destination)) {
        return;
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException('Could not store demo image ' . basename($destination) . '.');
    }
    $newFiles[] = $destination;
}

try {
    $db->beginTransaction();
    $sellerRoleId = requiredPcDemoId(
        $db,
        "SELECT id FROM roles WHERE name='shop_owner' AND is_active=TRUE",
        [],
        'The shop-owner role is missing.'
    );
    $commissionRuleId = requiredPcDemoId(
        $db,
        'SELECT id FROM commission_rules
         WHERE effective_from<=CURRENT_TIMESTAMP
           AND (effective_to IS NULL OR effective_to>CURRENT_TIMESTAMP)
         ORDER BY effective_from DESC, id DESC LIMIT 1',
        [],
        'An active commission rule is required.'
    );
    $adminStatement = $db->query(
        "SELECT u.id FROM users u INNER JOIN roles r ON r.id=u.role_id
         WHERE r.name='administrator' AND u.status='active' ORDER BY u.id LIMIT 1"
    );
    $adminId = ($adminStatement->fetchColumn() ?: null);

    $categorySlugs = array_values(array_unique(array_map(
        static fn (array $product): string => $product['category'],
        $products
    )));
    $categoryIds = [];
    $categoryStatement = $db->prepare(
        'SELECT id, slug FROM categories WHERE is_active=TRUE AND slug IN ('
        . implode(',', array_fill(0, count($categorySlugs), '?')) . ')'
    );
    $categoryStatement->execute($categorySlugs);
    foreach ($categoryStatement->fetchAll() as $category) {
        $categoryIds[(string) $category['slug']] = (int) $category['id'];
    }
    if (count($categoryIds) !== count($categorySlugs)) {
        throw new RuntimeException('One or more core PC categories are missing from the schema.');
    }

    $definitionStatement = $db->prepare(
        'SELECT c.slug category_slug, sd.id, sd.code, sd.data_type, sd.is_required
         FROM specification_definitions sd
         INNER JOIN categories c ON c.id=sd.category_id
         WHERE c.slug IN (' . implode(',', array_fill(0, count($categorySlugs), '?')) . ')
           AND sd.is_active=TRUE'
    );
    $definitionStatement->execute($categorySlugs);
    $definitions = [];
    foreach ($definitionStatement->fetchAll() as $definition) {
        $definitions[(string) $definition['category_slug']][(string) $definition['code']] = [
            'id' => (int) $definition['id'],
            'data_type' => (string) $definition['data_type'],
            'is_required' => (bool) $definition['is_required'],
        ];
    }
    foreach ($categorySlugs as $categorySlug) {
        if (($definitions[$categorySlug] ?? []) === []) {
            throw new RuntimeException(
                "PC specification definitions are missing for {$categorySlug}. Apply migration 003 first."
            );
        }
    }

    $optionStatement = $db->prepare(
        'SELECT c.slug category_slug, sd.code, so.id, so.value_code
         FROM specification_options so
         INNER JOIN specification_definitions sd ON sd.id=so.definition_id
         INNER JOIN categories c ON c.id=sd.category_id
         WHERE c.slug IN (' . implode(',', array_fill(0, count($categorySlugs), '?')) . ')
           AND sd.is_active=TRUE AND so.is_active=TRUE'
    );
    $optionStatement->execute($categorySlugs);
    $options = [];
    foreach ($optionStatement->fetchAll() as $option) {
        $options[(string) $option['category_slug']][(string) $option['code']]
            [(string) $option['value_code']] = (int) $option['id'];
    }

    $sellerIds = [];
    $shopIds = [];
    $passwordHash = password_hash('DemoSeller123', PASSWORD_DEFAULT);
    foreach ($shops as $key => $shop) {
        $existingUser = $db->prepare('SELECT id FROM users WHERE email=:email');
        $existingUser->execute(['email' => $shop['email']]);
        $userId = (int) $existingUser->fetchColumn();
        if ($userId < 1) {
            $insertUser = $db->prepare(
                'INSERT INTO users
                    (role_id, email, password_hash, status, email_verified_at)
                 VALUES
                    (:role_id, :email, :password_hash, "active", CURRENT_TIMESTAMP)'
            );
            $insertUser->execute([
                'role_id' => $sellerRoleId,
                'email' => $shop['email'],
                'password_hash' => $passwordHash,
            ]);
            $userId = (int) $db->lastInsertId();
        } else {
            $updateUser = $db->prepare(
                'UPDATE users SET role_id=:role_id, password_hash=:password_hash,
                    status="active", email_verified_at=COALESCE(email_verified_at, CURRENT_TIMESTAMP)
                 WHERE id=:id'
            );
            $updateUser->execute([
                'role_id' => $sellerRoleId,
                'password_hash' => $passwordHash,
                'id' => $userId,
            ]);
        }
        $sellerIds[$key] = $userId;

        $profile = $db->prepare(
            'INSERT INTO shop_owner_profiles
                (user_id, first_name, last_name, phone, business_name)
             VALUES
                (:user_id, :first_name, :last_name, :phone, :business_name)
             ON DUPLICATE KEY UPDATE
                first_name=VALUES(first_name), last_name=VALUES(last_name),
                phone=VALUES(phone), business_name=VALUES(business_name)'
        );
        $profile->execute([
            'user_id' => $userId,
            'first_name' => $shop['first_name'],
            'last_name' => $shop['last_name'],
            'phone' => $shop['phone'],
            'business_name' => $shop['business_name'],
        ]);

        $logoFilename = md5('hexbay-pc-demo-logo-' . $key) . '.png';
        storePcDemoAsset(
            $assetFiles[$shop['logo']],
            $logoStorageRoot . DIRECTORY_SEPARATOR . $logoFilename,
            $newFiles
        );
        $existingShop = $db->prepare('SELECT id FROM shops WHERE owner_user_id=:owner');
        $existingShop->execute(['owner' => $userId]);
        $shopId = (int) $existingShop->fetchColumn();
        if ($shopId < 1) {
            $insertShop = $db->prepare(
                'INSERT INTO shops
                    (owner_user_id, name, slug, description, address_text,
                     contact_phone, contact_email, logo_path, status,
                     rating_average, rating_count, approved_at)
                 VALUES
                    (:owner, :name, :slug, :description, :address,
                     :phone, :email, :logo, "approved", :rating,
                     :rating_count, CURRENT_TIMESTAMP)'
            );
            $insertShop->execute([
                'owner' => $userId, 'name' => $shop['shop_name'],
                'slug' => $shop['slug'], 'description' => $shop['description'],
                'address' => $shop['address'], 'phone' => $shop['phone'],
                'email' => $shop['email'], 'logo' => $logoFilename,
                'rating' => $shop['rating'], 'rating_count' => $shop['rating_count'],
            ]);
            $shopId = (int) $db->lastInsertId();
        } else {
            $updateShop = $db->prepare(
                'UPDATE shops SET name=:name, slug=:slug, description=:description,
                    address_text=:address, contact_phone=:phone,
                    contact_email=:email, logo_path=:logo, status="approved",
                    status_reason=NULL, rating_average=:rating,
                    rating_count=:rating_count,
                    approved_at=COALESCE(approved_at, CURRENT_TIMESTAMP)
                 WHERE id=:id'
            );
            $updateShop->execute([
                'name' => $shop['shop_name'], 'slug' => $shop['slug'],
                'description' => $shop['description'], 'address' => $shop['address'],
                'phone' => $shop['phone'], 'email' => $shop['email'],
                'logo' => $logoFilename, 'rating' => $shop['rating'],
                'rating_count' => $shop['rating_count'], 'id' => $shopId,
            ]);
        }
        $shopIds[$key] = $shopId;

        $verification = $db->prepare(
            'INSERT INTO vendor_verifications
                (shop_id, submission_number, legal_name,
                 business_registration_reference, status, submitted_at,
                 reviewed_by_user_id, reviewed_at, review_notes)
             VALUES
                (:shop_id, 1, :legal_name, :reference, "approved",
                 CURRENT_TIMESTAMP, :reviewer, CURRENT_TIMESTAMP,
                 "Approved local PC-builder demonstration catalogue")
             ON DUPLICATE KEY UPDATE
                legal_name=VALUES(legal_name), status="approved",
                reviewed_by_user_id=VALUES(reviewed_by_user_id),
                reviewed_at=CURRENT_TIMESTAMP,
                review_notes=VALUES(review_notes), decision_reason=NULL'
        );
        $verification->execute([
            'shop_id' => $shopId, 'legal_name' => $shop['business_name'],
            'reference' => 'PC-DEMO-' . strtoupper($key), 'reviewer' => $adminId,
        ]);

        $acceptance = $db->prepare(
            'INSERT INTO commission_acceptances
                (shop_owner_user_id, shop_id, commission_rule_id,
                 percentage_snapshot, terms_version, acceptance_text,
                 ip_address, user_agent)
             SELECT :owner, :shop, cr.id, cr.percentage, "pc-demo-2026-v1",
                    CONCAT("I accept the ", cr.percentage,
                           "% HEXBAY platform commission for this local PC demo shop."),
                    "127.0.0.1", "Hexbay PC demo catalogue seed"
             FROM commission_rules cr WHERE cr.id=:rule
             ON DUPLICATE KEY UPDATE
                percentage_snapshot=VALUES(percentage_snapshot),
                acceptance_text=VALUES(acceptance_text), superseded_at=NULL'
        );
        $acceptance->execute([
            'owner' => $userId, 'shop' => $shopId, 'rule' => $commissionRuleId,
        ]);
    }

    $productIds = [];
    foreach ($products as $key => $product) {
        $categorySlug = $product['category'];
        foreach ($definitions[$categorySlug] as $code => $definition) {
            if ($definition['is_required'] && !array_key_exists($code, $product['specs'])) {
                throw new RuntimeException("Required {$categorySlug}.{$code} is missing for {$key}.");
            }
        }
        foreach ($product['specs'] as $code => $_value) {
            if (!isset($definitions[$categorySlug][$code])) {
                throw new RuntimeException("Unknown {$categorySlug}.{$code} specification for {$key}.");
            }
        }

        $brand = $db->prepare(
            'INSERT INTO brands (name, slug, is_active)
             VALUES (:name, :slug, TRUE)
             ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=TRUE'
        );
        $brand->execute(['name' => $product['brand'], 'slug' => $product['brand_slug']]);
        $brandId = requiredPcDemoId(
            $db,
            'SELECT id FROM brands WHERE slug=:slug OR name=:name
             ORDER BY (slug=:preferred_slug) DESC LIMIT 1',
            [
                'slug' => $product['brand_slug'], 'name' => $product['brand'],
                'preferred_slug' => $product['brand_slug'],
            ],
            "Brand {$product['brand']} could not be loaded."
        );
        $categoryId = $categoryIds[$categorySlug];
        $existingProduct = $db->prepare(
            'SELECT id FROM canonical_products
             WHERE category_id=:category AND brand_id=:brand AND model=:model'
        );
        $existingProduct->execute([
            'category' => $categoryId, 'brand' => $brandId, 'model' => $product['model'],
        ]);
        $productId = (int) $existingProduct->fetchColumn();
        $creatorId = $sellerIds[array_key_first($product['offers'])];
        if ($productId < 1) {
            $insertProduct = $db->prepare(
                'INSERT INTO canonical_products
                    (category_id, brand_id, name, model,
                     manufacturer_part_number, specification_completeness,
                     is_active, created_by_user_id)
                 VALUES
                    (:category, :brand, :name, :model, :mpn,
                     "complete", TRUE, :creator)'
            );
            $insertProduct->execute([
                'category' => $categoryId, 'brand' => $brandId,
                'name' => $product['name'], 'model' => $product['model'],
                'mpn' => $product['mpn'], 'creator' => $creatorId,
            ]);
            $productId = (int) $db->lastInsertId();
        } else {
            $updateProduct = $db->prepare(
                'UPDATE canonical_products SET name=:name,
                    manufacturer_part_number=:mpn,
                    specification_completeness="complete", is_active=TRUE
                 WHERE id=:id'
            );
            $updateProduct->execute([
                'name' => $product['name'], 'mpn' => $product['mpn'], 'id' => $productId,
            ]);
        }
        $productIds[$key] = $productId;

        $deleteSpecifications = $db->prepare(
            'DELETE FROM product_specifications WHERE canonical_product_id=:product'
        );
        $deleteSpecifications->execute(['product' => $productId]);
        $insertSpecification = $db->prepare(
            'INSERT INTO product_specifications
                (canonical_product_id, definition_id, option_id, value_text,
                 value_number, value_boolean, value_json, source_note,
                 updated_by_user_id)
             VALUES
                (:product, :definition, :option_id, :value_text,
                 :value_number, :value_boolean, :value_json,
                 "Local PC-builder demonstration seed", :updater)'
        );
        foreach ($product['specs'] as $code => $value) {
            $definition = $definitions[$categorySlug][$code];
            $row = [
                'product' => $productId, 'definition' => $definition['id'],
                'option_id' => null, 'value_text' => null,
                'value_number' => null, 'value_boolean' => null,
                'value_json' => null, 'updater' => $creatorId,
            ];
            if (in_array($definition['data_type'], ['integer', 'decimal'], true)) {
                $row['value_number'] = $value;
            } elseif ($definition['data_type'] === 'boolean') {
                $row['value_boolean'] = (int) (bool) $value;
            } elseif ($definition['data_type'] === 'option') {
                $optionId = $options[$categorySlug][$code][(string) $value] ?? null;
                if ($optionId === null) {
                    throw new RuntimeException("Unknown option {$categorySlug}.{$code}={$value}.");
                }
                $row['option_id'] = $optionId;
            } elseif ($definition['data_type'] === 'multi_option') {
                foreach ((array) $value as $optionCode) {
                    if (!isset($options[$categorySlug][$code][(string) $optionCode])) {
                        throw new RuntimeException(
                            "Unknown multi-option {$categorySlug}.{$code}={$optionCode}."
                        );
                    }
                }
                $row['value_json'] = json_encode(
                    array_values((array) $value),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } else {
                $row['value_text'] = substr((string) $value, 0, 500);
            }
            $insertSpecification->execute($row);
        }
    }

    $listingIds = [];
    $offerCountByShop = array_fill_keys(array_keys($shops), 0);
    foreach ($products as $productKey => $product) {
        foreach ($product['offers'] as $shopKey => [$price, $stock]) {
            $shopId = $shopIds[$shopKey];
            $productId = $productIds[$productKey];
            $sku = strtoupper(substr($shopKey, 0, 2)) . '-PC-' . $product['sku'];
            $existingListing = $db->prepare(
                'SELECT id FROM shop_product_listings
                 WHERE shop_id=:shop AND canonical_product_id=:product
                   AND condition_type="new"'
            );
            $existingListing->execute(['shop' => $shopId, 'product' => $productId]);
            $listingId = (int) $existingListing->fetchColumn();
            if ($listingId < 1) {
                $insertListing = $db->prepare(
                    'INSERT INTO shop_product_listings
                        (shop_id, canonical_product_id, sku, condition_type,
                         price, vendor_description, warranty_summary, status,
                         approved_by_user_id, approved_at, published_at)
                     VALUES
                        (:shop, :product, :sku, "new", :price, :description,
                         "One-year local seller warranty", "active", :approver,
                         CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );
                $insertListing->execute([
                    'shop' => $shopId, 'product' => $productId, 'sku' => $sku,
                    'price' => $price, 'description' => $product['description'],
                    'approver' => $adminId,
                ]);
                $listingId = (int) $db->lastInsertId();
            } else {
                $updateListing = $db->prepare(
                    'UPDATE shop_product_listings SET sku=:sku, price=:price,
                        vendor_description=:description,
                        warranty_summary="One-year local seller warranty",
                        status="active", status_reason=NULL,
                        approved_by_user_id=:approver,
                        approved_at=COALESCE(approved_at, CURRENT_TIMESTAMP),
                        published_at=COALESCE(published_at, CURRENT_TIMESTAMP)
                     WHERE id=:id'
                );
                $updateListing->execute([
                    'sku' => $sku, 'price' => $price,
                    'description' => $product['description'],
                    'approver' => $adminId, 'id' => $listingId,
                ]);
            }
            $listingIds[] = $listingId;
            $offerCountByShop[$shopKey]++;

            $inventory = $db->prepare(
                'INSERT INTO inventory
                    (listing_id, quantity_on_hand, quantity_reserved,
                     low_stock_threshold, version)
                 VALUES (:listing, :stock, 0, 3, 1)
                 ON DUPLICATE KEY UPDATE
                    quantity_on_hand=GREATEST(VALUES(quantity_on_hand), quantity_reserved),
                    low_stock_threshold=VALUES(low_stock_threshold), version=version+1'
            );
            $inventory->execute(['listing' => $listingId, 'stock' => $stock]);
            $inventoryId = requiredPcDemoId(
                $db,
                'SELECT id FROM inventory WHERE listing_id=:listing',
                ['listing' => $listingId],
                'PC demo inventory could not be loaded.'
            );
            $movementExists = $db->prepare(
                'SELECT COUNT(*) FROM inventory_movements
                 WHERE inventory_id=:inventory AND reference_type="pc_demo_seed"'
            );
            $movementExists->execute(['inventory' => $inventoryId]);
            if ((int) $movementExists->fetchColumn() === 0) {
                $movement = $db->prepare(
                    'INSERT INTO inventory_movements
                        (inventory_id, movement_type, quantity_delta,
                         quantity_after, reference_type, reason, actor_user_id)
                     VALUES
                        (:inventory, "initial", :quantity_delta, :quantity_after,
                         "pc_demo_seed", "Initial PC-builder demonstration stock", :actor)'
                );
                $movement->execute([
                    'inventory' => $inventoryId, 'quantity_delta' => $stock,
                    'quantity_after' => $stock, 'actor' => $sellerIds[$shopKey],
                ]);
            }

            $storageToken = md5('hexbay-pc-demo-' . $shopKey . '-' . $productKey);
            $storedFilename = $storageToken . '.png';
            $storedPath = $productStorageRoot . DIRECTORY_SEPARATOR . $storedFilename;
            storePcDemoAsset($assetFiles[$product['image']], $storedPath, $newFiles);
            $image = $db->prepare(
                'INSERT INTO product_images
                    (listing_id, original_filename, stored_filename, mime_type,
                     byte_size, alt_text, sort_order)
                 VALUES
                    (:listing, :original, :stored, "image/png", :bytes, :alt, 0)
                 ON DUPLICATE KEY UPDATE
                    listing_id=VALUES(listing_id), byte_size=VALUES(byte_size),
                    alt_text=VALUES(alt_text), sort_order=0'
            );
            $image->execute([
                'listing' => $listingId,
                'original' => $productKey . '-demo.png',
                'stored' => $storedFilename, 'bytes' => filesize($storedPath),
                'alt' => $product['name'] . ' demonstration product image',
            ]);
        }
    }

    $outputShops = [];
    foreach ($shops as $key => $shop) {
        $outputShops[] = [
            'name' => $shop['shop_name'], 'email' => $shop['email'],
            'shop_id' => $shopIds[$key], 'active_offers' => $offerCountByShop[$key],
        ];
    }
    $output = json_encode([
        'success' => true,
        'demo_password' => 'DemoSeller123',
        'shops' => $outputShops,
        'canonical_pc_components' => count($productIds),
        'active_offers' => count($listingIds),
        'product_images' => count($listingIds),
        'shop_logos' => count($shops),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $db->commit();
    echo $output . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($newFiles as $newFile) {
        if (is_file($newFile)) {
            @unlink($newFile);
        }
    }
    fwrite(STDERR, "PC demo catalogue seed failed: {$exception->getMessage()}\n");
    exit(1);
}
