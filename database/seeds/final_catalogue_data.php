<?php
declare(strict_types=1);

/*
 * Curated final HEXBAY catalogue.
 * Retail prices and product imagery were researched on 2026-08-30.
 * source_url is retained as provenance; image_url is downloaded into protected
 * local storage by seed_final_catalogue.php so the storefront is self-contained.
 */

$shops = [
    'tech_shark' => [
        'email' => 'techshark@gmail.com',
        'first_name' => 'Tech', 'last_name' => 'Shark',
        'business_name' => 'Tech Shark', 'shop_name' => 'Tech Shark',
        'slug' => 'tech-shark',
        'description' => 'Desktop components, gaming peripherals and complete PC-build essentials.',
        'address' => 'Colombo, Sri Lanka', 'phone' => null,
        'rating' => 4.74, 'rating_count' => 41,
        'logo' => 'tech-shark-logo.png',
    ],
    'finora_tech' => [
        'email' => 'finoratech@gmail.com',
        'first_name' => 'Finora', 'last_name' => 'Tech',
        'business_name' => 'Finora Tech', 'shop_name' => 'Finora Tech',
        'slug' => 'finora-tech',
        'description' => 'Laptops, gaming keyboards, headsets, controllers and gaming chairs.',
        'address' => 'Colombo, Sri Lanka', 'phone' => null,
        'rating' => 4.68, 'rating_count' => 36,
        'logo' => 'finora-tech-logo.png',
    ],
    'tech_venom' => [
        'email' => 'techvenom@gmail.com',
        'first_name' => 'Tech', 'last_name' => 'Venom',
        'business_name' => 'Tech Venom', 'shop_name' => 'Tech Venom',
        'slug' => 'tech-venom',
        'description' => 'A compact selection of PC setup components and gaming essentials.',
        'address' => 'Colombo, Sri Lanka', 'phone' => null,
        'rating' => 4.56, 'rating_count' => 24,
        'logo' => 'tech-venom-logo.png',
    ],
];

$products = [
    // Tech Shark: exactly two distinct products at each requested RAM capacity.
    'kingston-2gb-ddr3-1333' => [
        'category'=>'memory','brand'=>'Kingston','brand_slug'=>'kingston','name'=>'Kingston 2GB DDR3 1333MHz Desktop RAM','model'=>'KVR13N9S6/2','mpn'=>'KVR13N9S6/2',
        'description'=>'2GB DDR3 desktop memory module for legacy-compatible systems.','specs'=>['ddr_generation'=>'ddr3','capacity_gb'=>2,'speed_mhz'=>1333,'module_count'=>1,'capacity_per_module_gb'=>2,'memory_profiles'=>['none'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/ddr3-ram-kingston-2gb-1333','image_url'=>'https://www.sense.lk/images/uploads/product/40613_5358_KINGSTON-2GB-1333.png','offers'=>['tech_shark'=>[4150,6]],
    ],
    'transcend-2gb-ddr3-1333' => [
        'category'=>'memory','brand'=>'Transcend','brand_slug'=>'transcend','name'=>'Transcend 2GB DDR3 1333MHz Desktop RAM','model'=>'JM1333KLN-2G','mpn'=>'JM1333KLN-2G',
        'description'=>'2GB DDR3-1333 desktop UDIMM for compatible older desktops.','specs'=>['ddr_generation'=>'ddr3','capacity_gb'=>2,'speed_mhz'=>1333,'module_count'=>1,'capacity_per_module_gb'=>2,'memory_profiles'=>['none'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/ddr3-memory-transcend-2gb-1333mhz','image_url'=>'https://www.sense.lk/images/uploads/product/71289_5684_TRANSCEND-2GB-1333MHZ.png','offers'=>['tech_shark'=>[1350,5]],
    ],
    'kingston-4gb-ddr4-2666' => [
        'category'=>'memory','brand'=>'Kingston','brand_slug'=>'kingston','name'=>'Kingston 4GB DDR4 2666MHz Desktop RAM','model'=>'KVR26N19S6/4','mpn'=>'KVR26N19S6/4',
        'description'=>'4GB DDR4-2666 desktop memory module for basic DDR4 systems.','specs'=>['ddr_generation'=>'ddr4','capacity_gb'=>4,'speed_mhz'=>2666,'module_count'=>1,'capacity_per_module_gb'=>4,'memory_profiles'=>['none'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/ddr4-memory-kingston-4gb-2666mhz-desktop','image_url'=>'https://www.sense.lk/images/uploads/product/40618_5695_KINGSTON-4GB-2666MHZ-DESKTOP.png','offers'=>['tech_shark'=>[6650,8]],
    ],
    'micron-4gb-ddr4' => [
        'category'=>'memory','brand'=>'Micron','brand_slug'=>'micron','name'=>'Micron 4GB DDR4 Desktop RAM','model'=>'Micron-4GB-DDR4-UDIMM','mpn'=>'MEMUXX0008',
        'description'=>'4GB non-ECC DDR4 desktop UDIMM for general upgrades.','specs'=>['ddr_generation'=>'ddr4','capacity_gb'=>4,'speed_mhz'=>2666,'module_count'=>1,'capacity_per_module_gb'=>4,'memory_profiles'=>['none'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/ddr4-memory-micron-4gb-desktop','image_url'=>'https://www.sense.lk/images/uploads/product/2025/10/20251013110532_1.png','offers'=>['tech_shark'=>[6500,5]],
    ],
    'transcend-8gb-ddr4-3200' => [
        'category'=>'memory','brand'=>'Transcend','brand_slug'=>'transcend','name'=>'Transcend JetRam 8GB DDR4 3200MHz Desktop RAM','model'=>'JM3200HLB-8G','mpn'=>'JM3200HLB-8G',
        'description'=>'8GB DDR4-3200 CL22 desktop memory module.','specs'=>['ddr_generation'=>'ddr4','capacity_gb'=>8,'speed_mhz'=>3200,'module_count'=>1,'capacity_per_module_gb'=>8,'cas_latency'=>22,'memory_profiles'=>['none'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/transcend-8gb-ddr4-3200mhz-desktop-jm3200hlb-8g-3y','image_url'=>'https://www.sense.lk/images/uploads/product/71271_3507_TRANSCEND-8GB-3200MHZ-DESKTOP.png','offers'=>['tech_shark'=>[6000,9],'tech_venom'=>[6250,4]],
    ],
    'lexar-8gb-ddr4-2666' => [
        'category'=>'memory','brand'=>'Lexar','brand_slug'=>'lexar','name'=>'Lexar 8GB DDR4 2666MHz Desktop RAM','model'=>'LD4AU008G-R2666G','mpn'=>'LD4AU008G-R2666G',
        'description'=>'8GB DDR4-2666 desktop UDIMM for mainstream desktops.','specs'=>['ddr_generation'=>'ddr4','capacity_gb'=>8,'speed_mhz'=>2666,'module_count'=>1,'capacity_per_module_gb'=>8,'cas_latency'=>19,'memory_profiles'=>['none'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/lexar-8gb-ddr4-2666mhz-desktop-3y','image_url'=>'https://www.sense.lk/images/uploads/product/2026/07/20260731130345_1.webp','offers'=>['tech_shark'=>[11700,6]],
    ],
    'vcolor-prism-pro-rgb-16gb-ddr4-3200' => [
        'category'=>'memory','brand'=>'V-Color','brand_slug'=>'v-color','name'=>'V-Color Prism Pro RGB 16GB (2x8GB) DDR4 3200MHz Kit','model'=>'Prism Pro RGB 16GB 3200','mpn'=>'MEMVCR0015',
        'description'=>'16GB dual-channel DDR4-3200 CL16 RGB desktop memory kit for gaming, streaming, and multitasking.','specs'=>['ddr_generation'=>'ddr4','capacity_gb'=>16,'speed_mhz'=>3200,'module_count'=>2,'capacity_per_module_gb'=>8,'cas_latency'=>16,'memory_profiles'=>['xmp'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/ddr4-memory-v-color-16gb-3200mhz-prism-pro-rgb-ssc-kit-8-2-0-2-3y','image_url'=>'https://v-color.net/cdn/shop/products/9a94df55bc7cb5f235917e429885854e_97fa6918-c5e6-4e96-a3f4-f6de347a00c4.jpg?v=1647318267&width=416','offers'=>['tech_shark'=>[20700,6]],
    ],
    'corsair-16gb-ddr4-3200' => [
        'category'=>'memory','brand'=>'Corsair','brand_slug'=>'corsair','name'=>'Corsair Vengeance LPX 16GB DDR4 3200MHz Desktop RAM','model'=>'Vengeance-LPX-16GB-3200','mpn'=>'CMK16GX4M1E3200C16',
        'description'=>'16GB DDR4-3200 CL16 low-profile desktop memory.','specs'=>['ddr_generation'=>'ddr4','capacity_gb'=>16,'speed_mhz'=>3200,'module_count'=>1,'capacity_per_module_gb'=>16,'cas_latency'=>16,'memory_profiles'=>['xmp'],'ecc_memory'=>false],
        'source_url'=>'https://www.sense.lk/product/corsair-vengeance-lpx-16gb-ddr4-3200mhz-desktop-10y','image_url'=>'https://www.sense.lk/images/uploads/product/2026/07/20260731125853_1.webp','offers'=>['tech_shark'=>[46000,5],'tech_venom'=>[46500,3]],
    ],

    'ryzen-5-5500' => [
        'category'=>'processors','brand'=>'AMD','brand_slug'=>'amd','name'=>'AMD Ryzen 5 5500 Desktop Processor','model'=>'Ryzen 5 5500','mpn'=>'100-100000457BOX','description'=>'Six-core AM4 processor for affordable gaming and general PC builds.',
        'specs'=>['socket'=>'am4','core_count'=>6,'thread_count'=>12,'tdp_watts'=>65,'architecture_family'=>'zen3','supported_chipsets'=>['a520','b450','b550','x570'],'base_clock_ghz'=>3.6,'boost_clock_ghz'=>4.2,'integrated_graphics'=>false,'cooler_included'=>true,'peak_power_watts'=>88],
        'source_url'=>'https://www.redlinetech.lk/product/amd-ryzen-5-5500-desktop-processor','image_url'=>'https://www.redlinetech.lk/storage/products/89/6ADws14lj6JaZzr69mgvcFbFpQrLzFS7PqiUbZpP.webp','offers'=>['tech_shark'=>[37000,7]],'score'=>[58,82],
    ],
    'ryzen-5-7600' => [
        'category'=>'processors','brand'=>'AMD','brand_slug'=>'amd','name'=>'AMD Ryzen 5 7600 AM5 Processor','model'=>'Ryzen 5 7600','mpn'=>'100-100001015BOX','description'=>'Six-core Zen 4 AM5 processor with integrated graphics.',
        'specs'=>['socket'=>'am5','core_count'=>6,'thread_count'=>12,'tdp_watts'=>65,'architecture_family'=>'zen4','supported_chipsets'=>['a620','b650','x670'],'base_clock_ghz'=>3.8,'boost_clock_ghz'=>5.1,'integrated_graphics'=>true,'cooler_included'=>true,'peak_power_watts'=>88],
        'source_url'=>'https://www.redlinetech.lk/product/amd-ryzen-5-7600-am5-processor','image_url'=>'https://www.redlinetech.lk/storage/products/94/1HH13vS2VDJAVWi6qffQF0fBuomWuwUOfXSoJAaL.webp','offers'=>['tech_shark'=>[61000,6]],'score'=>[74,79],
    ],
    'intel-i3-12100f' => [
        'category'=>'processors','brand'=>'Intel','brand_slug'=>'intel','name'=>'Intel Core i3-12100F Desktop Processor','model'=>'Core i3-12100F','mpn'=>'BX8071512100F','description'=>'Four-core LGA1700 processor for entry gaming systems with a discrete GPU.',
        'specs'=>['socket'=>'lga1700','core_count'=>4,'thread_count'=>8,'tdp_watts'=>58,'architecture_family'=>'alder_lake','supported_chipsets'=>['h610','b660','b760','z690','z790'],'base_clock_ghz'=>3.3,'boost_clock_ghz'=>4.3,'integrated_graphics'=>false,'cooler_included'=>true,'peak_power_watts'=>89],
        'source_url'=>'https://www.redlinetech.lk/product/intel-core-i3-12100f-12th-gen-desktop-processor','image_url'=>'https://www.redlinetech.lk/storage/products/64/pi8hj2NOMgg0765CVufTfmQflU8ZBvdTQOZvVkii.webp','offers'=>['tech_shark'=>[49500,6]],'score'=>[60,66],
    ],
    'intel-i5-14400t' => [
        'category'=>'processors','brand'=>'Intel','brand_slug'=>'intel','name'=>'Intel Core i5-14400T Processor','model'=>'Core i5-14400T','mpn'=>'CM8071505093105','description'=>'Efficient ten-core LGA1700 processor for balanced productivity systems.',
        'specs'=>['socket'=>'lga1700','core_count'=>10,'thread_count'=>16,'tdp_watts'=>35,'architecture_family'=>'raptor_lake_refresh','supported_chipsets'=>['h610','b660','b760','z690','z790'],'base_clock_ghz'=>1.5,'boost_clock_ghz'=>4.5,'integrated_graphics'=>true,'cooler_included'=>false,'peak_power_watts'=>82],
        'source_url'=>'https://www.redlinetech.lk/product/intel-i5-14400t-processor','image_url'=>'https://www.redlinetech.lk/storage/products/72/FFFW1LD0DongaiDm1u3kkozhokIy1se9uqotoTZk.webp','offers'=>['tech_shark'=>[79000,4]],'score'=>[76,70],
    ],
    'ryzen-5-3400g' => [
        'category'=>'processors','brand'=>'AMD','brand_slug'=>'amd','name'=>'AMD Ryzen 5 3400G Processor','model'=>'Ryzen 5 3400G','mpn'=>'YD3400C5FHBOX','description'=>'Four-core AM4 processor with Radeon Vega integrated graphics.',
        'specs'=>['socket'=>'am4','core_count'=>4,'thread_count'=>8,'tdp_watts'=>65,'architecture_family'=>'zen3','supported_chipsets'=>['a520','b450','b550'],'base_clock_ghz'=>3.7,'boost_clock_ghz'=>4.2,'integrated_graphics'=>true,'cooler_included'=>true,'peak_power_watts'=>88],
        'source_url'=>'https://www.redlinetech.lk/product/amd-ryzen-5-3400g-processor-systems-only-tray','image_url'=>'https://www.redlinetech.lk/storage/products/747/Qp0VlmUvmzlTiJyA1IRYFeezfhOmJxknUdvqrI8K.webp','offers'=>['tech_venom'=>[25500,5]],'score'=>[42,78],
    ],
    'ryzen-5-5600gt' => [
        'category'=>'processors','brand'=>'AMD','brand_slug'=>'amd','name'=>'AMD Ryzen 5 5600GT Desktop Processor','model'=>'Ryzen 5 5600GT','mpn'=>'100-100001488BOX','description'=>'Six-core AM4 processor with integrated Radeon graphics.',
        'specs'=>['socket'=>'am4','core_count'=>6,'thread_count'=>12,'tdp_watts'=>65,'architecture_family'=>'zen3','supported_chipsets'=>['a520','b450','b550','x570'],'base_clock_ghz'=>3.6,'boost_clock_ghz'=>4.6,'integrated_graphics'=>true,'cooler_included'=>true,'peak_power_watts'=>88],
        'source_url'=>'https://www.redlinetech.lk/product/amd-ryzen-5-5600gt-desktop-processor','image_url'=>'https://www.redlinetech.lk/storage/products/90/viGWyvdGopHAh02EgZqZ2qoOnpn915o5KK6KN4B1.webp','offers'=>['tech_venom'=>[57000,4]],'score'=>[64,76],
    ],

    'gigabyte-a520m-ds3h-wifi6e' => [
        'category'=>'motherboards','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte A520M DS3H WIFI6E Motherboard','model'=>'A520M DS3H WIFI6E','mpn'=>'A520M-DS3H-WIFI6E','description'=>'Micro-ATX AM4 motherboard with DDR4, M.2 storage and Wi-Fi 6E.',
        'specs'=>['cpu_socket'=>'am4','ram_generation'=>'ddr4','form_factor'=>'micro_atx','m2_slots'=>1,'chipset'=>'a520','supported_cpu_families'=>['zen3'],'memory_slots'=>4,'max_memory_capacity_gb'=>128,'max_memory_speed_mhz'=>4733,'pcie_x16_generation'=>'pcie_3','m2_interfaces'=>['pcie_3','sata'],'wifi_included'=>true,'bios_support_note'=>'Verify the installed BIOS against the exact AMD CPU revision.'],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-a520m-ds3h-wifi6e-motherboard','image_url'=>'https://www.redlinetech.lk/storage/products/915/oBXwz5yGMn7PyM7HlNy0xXQShPtd77hv6ZqUlYbd.webp','offers'=>['tech_shark'=>[36500,5],'tech_venom'=>[37250,3]],'score'=>[54,78],
    ],
    'gigabyte-b650m-gaming-wifi' => [
        'category'=>'motherboards','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte B650M Gaming WIFI Motherboard','model'=>'B650M Gaming WIFI','mpn'=>'B650M-GAMING-WIFI','description'=>'Micro-ATX AM5 DDR5 motherboard with PCIe 4.0 storage and Wi-Fi.',
        'specs'=>['cpu_socket'=>'am5','ram_generation'=>'ddr5','form_factor'=>'micro_atx','m2_slots'=>2,'chipset'=>'b650','supported_cpu_families'=>['zen4'],'memory_slots'=>2,'max_memory_capacity_gb'=>128,'max_memory_speed_mhz'=>7600,'pcie_x16_generation'=>'pcie_4','m2_interfaces'=>['pcie_4'],'wifi_included'=>true,'bios_support_note'=>'Check the CPU support list and BIOS version for newer AM5 processors.'],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-b650m-gaming-wifi-motherboard','image_url'=>'https://www.redlinetech.lk/storage/products/456/YwqzNTja0MAruvPXMmQRK9f5seHkfbWoupcXkXRc.webp','offers'=>['tech_shark'=>[55500,4]],'score'=>[68,76],
    ],
    'gigabyte-b760m-ds3h-ddr4' => [
        'category'=>'motherboards','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte B760M DS3H DDR4 Motherboard','model'=>'B760M DS3H DDR4','mpn'=>'B760M-DS3H-DDR4','description'=>'Micro-ATX LGA1700 motherboard with four DDR4 slots and two M.2 slots.',
        'specs'=>['cpu_socket'=>'lga1700','ram_generation'=>'ddr4','form_factor'=>'micro_atx','m2_slots'=>2,'chipset'=>'b760','supported_cpu_families'=>['alder_lake','raptor_lake','raptor_lake_refresh'],'memory_slots'=>4,'max_memory_capacity_gb'=>128,'max_memory_speed_mhz'=>5333,'pcie_x16_generation'=>'pcie_4','m2_interfaces'=>['pcie_4'],'wifi_included'=>false,'bios_support_note'=>'14th-generation Intel CPUs can require a recent BIOS.'],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-b760m-ds3h-ddr4-motherboard','image_url'=>'https://www.redlinetech.lk/storage/products/492/hMwBbL6jYQX0b09SKL1REmpPhUEi27rag7WouMKo.webp','offers'=>['tech_shark'=>[48000,4]],'score'=>[67,74],
    ],
    'gigabyte-gt1030-2gb' => [
        'category'=>'graphics-cards','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte GeForce GT 1030 Low Profile 2G','model'=>'GV-N1030D5-2GL','mpn'=>'GV-N1030D5-2GL','description'=>'Low-profile 2GB GDDR5 graphics card for display acceleration and light gaming.',
        'specs'=>['vram_gb'=>2,'gpu_length_mm'=>150,'recommended_psu_watts'=>300,'power_connectors'=>['none'],'gpu_height_mm'=>69,'gpu_thickness_slots'=>1,'total_board_power_watts'=>30,'pcie_generation'=>'pcie_3','compute_capabilities'=>['directx_12','opencl','cuda']],
        'source_url'=>'https://www.redlinetech.lk/store/page/28/?max_price=412500&min_price=0&orderby=menu_order&per_page=18&per_row=3&shop_view=grid','image_url'=>'https://cdn.cs.1worldsync.com/31/07/3107135e-68d5-4d63-a262-1aeca616baae.jpg','offers'=>['tech_shark'=>[42000,4],'tech_venom'=>[42750,2]],'score'=>[25,55],
    ],
    'gigabyte-gtx1630-4gb' => [
        'category'=>'graphics-cards','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte GeForce GTX 1630 OC Low Profile 4G','model'=>'GV-N1630OC-4GL','mpn'=>'GV-N1630OC-4GL','description'=>'Compact 4GB GDDR6 Turing graphics card for entry-level gaming.',
        'specs'=>['vram_gb'=>4,'gpu_length_mm'=>167,'recommended_psu_watts'=>300,'power_connectors'=>['none'],'gpu_height_mm'=>69,'gpu_thickness_slots'=>2,'total_board_power_watts'=>75,'pcie_generation'=>'pcie_3','compute_capabilities'=>['directx_12','vulkan','opencl','cuda']],
        'source_url'=>'https://www.redlinetech.lk/store/page/28/?max_price=412500&min_price=0&orderby=menu_order&per_page=18&per_row=3&shop_view=grid','image_url'=>'https://media.ldlc.com/r1600/ld/products/00/05/97/95/LD0005979586.jpg','offers'=>['tech_shark'=>[45000,4]],'score'=>[38,62],
    ],
    'axle-gtx1650-4gb' => [
        'category'=>'graphics-cards','brand'=>'AXLE','brand_slug'=>'axle','name'=>'AXLE GeForce GTX 1650 4GB Graphics Card','model'=>'AX-GTX1650-4GD6','mpn'=>'AX-GTX1650-4GD6','description'=>'4GB GDDR6 graphics card for mainstream 1080p gaming.',
        'specs'=>['vram_gb'=>4,'gpu_length_mm'=>220,'recommended_psu_watts'=>300,'power_connectors'=>['none'],'gpu_height_mm'=>112,'gpu_thickness_slots'=>2,'total_board_power_watts'=>75,'pcie_generation'=>'pcie_3','compute_capabilities'=>['directx_12','vulkan','opencl','cuda']],
        'source_url'=>'https://www.redlinetech.lk/product/axle-geforce-gtx-1650-4gb-graphic-card','image_url'=>'https://www.redlinetech.lk/storage/products/566/WqsdbbLY33GcwsV8p1gPiMxddPAnoMUhglREXygB.webp','offers'=>['tech_shark'=>[68750,4]],'score'=>[50,67],
    ],
    'gigabyte-rx7600-8gb' => [
        'category'=>'graphics-cards','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte Radeon RX 7600 GAMING OC 8GB','model'=>'GV-R76GAMING-OC-8GD','mpn'=>'GV-R76GAMING-OC-8GD','description'=>'8GB PCIe 4.0 graphics card for high-frame-rate 1080p gaming.',
        'specs'=>['vram_gb'=>8,'gpu_length_mm'=>282,'recommended_psu_watts'=>550,'power_connectors'=>['eight_pin'],'gpu_height_mm'=>116,'gpu_thickness_slots'=>2.5,'total_board_power_watts'=>165,'pcie_generation'=>'pcie_4','compute_capabilities'=>['directx_12','vulkan','opencl','av1_encode']],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-radeon-rx7600-gaming-oc-8gb-graphic-card','image_url'=>'https://www.redlinetech.lk/storage/products/139/8kS43XrPW9TRjtuCntFNWzA0E1uCvB6Wi1r7HZlV.webp','offers'=>['tech_shark'=>[135000,3]],'score'=>[78,72],
    ],

    'teamgroup-mp44l-500gb' => [
        'category'=>'storage','brand'=>'TeamGroup','brand_slug'=>'teamgroup','name'=>'Team Group MP44L 500GB M.2 PCIe Gen4 SSD','model'=>'MP44L 500GB','mpn'=>'TM8FPK500G0C101','description'=>'500GB PCIe 4.0 NVMe SSD for responsive operating-system and application storage.',
        'specs'=>['storage_type'=>'nvme_ssd','capacity_gb'=>500,'interface'=>'pcie_4','form_factor'=>'m2_2280','sequential_read_mbps'=>5000,'sequential_write_mbps'=>2500,'endurance_tbw'=>300],
        'source_url'=>'https://www.redlinetech.lk/product/team-group-mp44l-500gb-m2-pcie-gen4-ssd','image_url'=>'https://www.redlinetech.lk/storage/products/234/2bz4VjNQWlJMmTHcNUh7rujYkSRdinoUhQSFG1pr.webp','offers'=>['tech_shark'=>[35000,7]],'score'=>[71,76],
    ],
    'wd-blue-sn580-500gb' => [
        'category'=>'storage','brand'=>'Western Digital','brand_slug'=>'western-digital','name'=>'WD Blue SN580 500GB PCIe Gen4 NVMe SSD','model'=>'SN580 500GB','mpn'=>'WDS500G3B0E','description'=>'500GB PCIe 4.0 NVMe SSD with TLC NAND for responsive gaming and productivity storage.',
        'specs'=>['storage_type'=>'nvme_ssd','capacity_gb'=>500,'interface'=>'pcie_4','form_factor'=>'m2_2280','sequential_read_mbps'=>4000,'sequential_write_mbps'=>3600,'endurance_tbw'=>300],
        'source_url'=>'https://www.redlinetech.lk/product/wd-blue-sn580-500gb-pcie-gen-4-nvme-ssd','image_url'=>'https://www.redlinetech.lk/storage/products/634/C421TKlGP32hBoHC13hgR4XvYP6bnzXljLwdITm5.webp','offers'=>['tech_shark'=>[13000,6],'tech_venom'=>[13250,3]],'score'=>[74,82],
    ],
    'monova-450w' => [
        'category'=>'power-supplies','brand'=>'Monova','brand_slug'=>'monova','name'=>'Monova 450W ATX Power Supply','model'=>'Monova 450W ATX','mpn'=>'MONOVA-450W','description'=>'Entry ATX power supply for low-power desktop builds.',
        'specs'=>['wattage'=>450,'form_factor'=>'atx','available_connectors'=>['six_pin'],'atx_standard'=>'atx_2_4','modularity'=>'non_modular','six_pin_connector_count'=>1,'eight_pin_connector_count'=>0,'twelve_vhpwr_connector_count'=>0,'warranty_years'=>1],
        'source_url'=>'https://www.redlinetech.lk/product/monova-450w-atx-power-supply','image_url'=>'https://www.redlinetech.lk/storage/products/256/lioutegKvWGfEEmEMcOOwjMkF8PmvNdmnRqZCnP5.webp','offers'=>['tech_shark'=>[10000,7],'tech_venom'=>[10250,4]],'score'=>[39,67],
    ],
    'monova-fp650' => [
        'category'=>'power-supplies','brand'=>'Monova','brand_slug'=>'monova','name'=>'Monova FP-650 80 Plus Bronze 650W Power Supply','model'=>'FP-650','mpn'=>'FP-650','description'=>'650W 80 Plus Bronze ATX PSU for mainstream gaming builds.',
        'specs'=>['wattage'=>650,'efficiency_rating'=>'80_plus_bronze','form_factor'=>'atx','available_connectors'=>['six_pin','eight_pin'],'atx_standard'=>'atx_2_52','modularity'=>'non_modular','six_pin_connector_count'=>2,'eight_pin_connector_count'=>2,'twelve_vhpwr_connector_count'=>0,'warranty_years'=>3],
        'source_url'=>'https://www.redlinetech.lk/product/monova-fp-650-80-plus-bronze-650w-power-supply','image_url'=>'https://www.redlinetech.lk/storage/products/253/6NyO7l0IN7TFyl0h8Vc25225W8BIQRlOeY3r2j7z.webp','offers'=>['tech_shark'=>[19000,5]],'score'=>[62,75],
    ],
    'blackbox-idun-case' => [
        'category'=>'computer-cases','brand'=>'BlackBox','brand_slug'=>'blackbox','name'=>'BlackBox IDUN Gaming Case','model'=>'IDUN','mpn'=>'BLACKBOX-IDUN','description'=>'Budget mid-tower case for Micro-ATX and ATX desktop builds.',
        'specs'=>['motherboard_form_factors'=>['mini_itx','micro_atx','atx'],'max_gpu_length_mm'=>300,'psu_form_factors'=>['atx'],'max_cpu_cooler_height_mm'=>155,'supported_radiator_sizes'=>['rad_120'],'max_gpu_thickness_slots'=>2.5],
        'source_url'=>'https://www.redlinetech.lk/category/cases','image_url'=>'https://www.redlinetech.lk/storage/products/826/2YYrjFs8t97eh78NHIpFLo4cBE38r961EbrT74Hq.webp','offers'=>['tech_shark'=>[8500,6]],'score'=>[43,77],
    ],
    'gigabyte-c102-case' => [
        'category'=>'computer-cases','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte C102 Glass Mid Tower Gaming Case - Black','model'=>'C102 GLASS','mpn'=>'GB-C102G','description'=>'Tempered-glass mid-tower case with room for modern graphics cards.',
        'specs'=>['motherboard_form_factors'=>['mini_itx','micro_atx','atx'],'max_gpu_length_mm'=>330,'psu_form_factors'=>['atx'],'max_cpu_cooler_height_mm'=>165,'supported_radiator_sizes'=>['rad_120','rad_240'],'max_gpu_thickness_slots'=>3],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-c102-glass-mid-tower-gaming-case-black','image_url'=>'https://www.redlinetech.lk/storage/products/807/RIL4Z7wZrbzzXY99T9SO41WE8LfTaQLrjMJZqagd.webp','offers'=>['tech_shark'=>[14000,5]],'score'=>[58,74],
    ],
    'trendsonic-breeze-case' => [
        'category'=>'computer-cases','brand'=>'Trendsonic','brand_slug'=>'trendsonic','name'=>'Trendsonic Breeze Case','model'=>'Breeze','mpn'=>'TRENDSONIC-BREEZE','description'=>'Airflow-focused budget case for compact PC setups.',
        'specs'=>['motherboard_form_factors'=>['mini_itx','micro_atx','atx'],'max_gpu_length_mm'=>300,'psu_form_factors'=>['atx'],'max_cpu_cooler_height_mm'=>155,'supported_radiator_sizes'=>['rad_120'],'max_gpu_thickness_slots'=>2.5],
        'source_url'=>'https://www.redlinetech.lk/product/trendsonic-breeze-case','image_url'=>'https://www.redlinetech.lk/storage/products/380/cUwt0FRAjlsvAieO83bB2rlXMsKer21recoBgdMg.webp','offers'=>['tech_venom'=>[11500,4]],'score'=>[45,72],
    ],
    'deepcool-ag400-plus' => [
        'category'=>'cpu-coolers','brand'=>'DeepCool','brand_slug'=>'deepcool','name'=>'DeepCool AG400 Plus 120mm CPU Air Cooler','model'=>'AG400 PLUS','mpn'=>'R-AG400-BKNNMD-G','description'=>'Dual-fan tower air cooler for current AMD and Intel desktop sockets.',
        'specs'=>['cooler_type'=>'air','supported_sockets'=>['am4','am5','lga1200','lga1700'],'cooling_capacity_watts'=>220,'cooler_height_mm'=>150,'radiator_size'=>'none','noise_level_dba'=>30],
        'source_url'=>'https://www.redlinetech.lk/product/deepcool-ag400-plus-120mm-cpu-air-cooler','image_url'=>'https://www.redlinetech.lk/storage/products/284/sK5QeD5TnPmwECjUSTRbRbIUCxMZFIsa4c4VZGPl.webp','offers'=>['tech_shark'=>[16500,5],'tech_venom'=>[17000,3]],'score'=>[64,79],
    ],
    'deepcool-le500' => [
        'category'=>'cpu-coolers','brand'=>'DeepCool','brand_slug'=>'deepcool','name'=>'DeepCool LE500 240mm Liquid CPU Cooler','model'=>'LE500','mpn'=>'R-LE500-BKLNMC-G-1','description'=>'240mm all-in-one liquid cooler for mainstream gaming and productivity PCs.',
        'specs'=>['cooler_type'=>'aio','supported_sockets'=>['am4','am5','lga1200','lga1700'],'cooling_capacity_watts'=>250,'radiator_size'=>'rad_240','noise_level_dba'=>32],
        'source_url'=>'https://www.redlinetech.lk/product/deepcool-le500-all-in-one-240mm-liquid-cpu-cooler','image_url'=>'https://www.redlinetech.lk/storage/products/277/lInHM4VaUfRU1dRZdODWka5ocJFn8qMQ5QKTkpJH.webp','offers'=>['tech_shark'=>[25000,4]],'score'=>[73,72],
    ],
    'asus-tuf-m3-gen2' => [
        'category'=>'accessories','brand'=>'ASUS','brand_slug'=>'asus','name'=>'ASUS TUF Gaming M3 Gen II Gaming Mouse','model'=>'TUF Gaming M3 Gen II','mpn'=>'90MP0320-BMUA00','description'=>'Lightweight wired gaming mouse with an optical sensor and programmable buttons.',
        'specs'=>['accessory_type'=>'mouse','connectivity'=>['wired'],'colour'=>'Black','tracking_method'=>'optical','max_dpi'=>8000,'hand_orientation'=>'right','weight_grams'=>59,'programmable_buttons'=>6],
        'source_url'=>'https://www.redlinetech.lk/product/asus-p309-tuf-gaming-m3-gen-ii-gaming-mouse','image_url'=>'https://www.redlinetech.lk/storage/products/503/7eWOE2lrqaFUexKoMf0q8654Q96hoiFoK3aWlced.webp','offers'=>['tech_shark'=>[9000,7]],'tags'=>['competitive_gaming','ergonomic'],
    ],
    'asus-tuf-m4-air' => [
        'category'=>'accessories','brand'=>'ASUS','brand_slug'=>'asus','name'=>'ASUS TUF Gaming M4 Air Gaming Mouse','model'=>'TUF Gaming M4 Air','mpn'=>'90MP02K0-BMUA00','description'=>'Ultralight wired gaming mouse with a high-resolution optical sensor.',
        'specs'=>['accessory_type'=>'mouse','connectivity'=>['wired'],'colour'=>'Black','tracking_method'=>'optical','max_dpi'=>16000,'hand_orientation'=>'right','weight_grams'=>47,'programmable_buttons'=>6],
        'source_url'=>'https://www.redlinetech.lk/product/asus-tuf-p307-gaming-m4-air-gaming-mouse','image_url'=>'https://www.redlinetech.lk/storage/products/324/QS14WseMXD3vRrlbQ9ZS6mdyiTC8GZA1pw9QnlG3.webp','offers'=>['tech_shark'=>[14000,5]],'tags'=>['competitive_gaming','ergonomic'],
    ],
    'mkespn-x10' => [
        'category'=>'accessories','brand'=>'MKESPN','brand_slug'=>'mkespn','name'=>'MKESPN X10 9 Buttons RGB Gaming Mouse','model'=>'X10','mpn'=>'MKESPN-X10','description'=>'Nine-button wired RGB gaming mouse for configurable desktop control.',
        'specs'=>['accessory_type'=>'mouse','connectivity'=>['wired'],'colour'=>'Black','tracking_method'=>'optical','max_dpi'=>7200,'hand_orientation'=>'right','weight_grams'=>130,'programmable_buttons'=>9],
        'source_url'=>'https://www.redlinetech.lk/product/mkespn-x10-9-buttons-rgb-gaming-mouse','image_url'=>'https://www.redlinetech.lk/storage/products/342/NSq6lhakorGRJvfuOKkUjGWVLEUbkusx8zyCYcQN.webp','offers'=>['tech_shark'=>[4400,8],'tech_venom'=>[4600,4]],'tags'=>['competitive_gaming'],
    ],
    'logitech-g431' => [
        'category'=>'accessories','brand'=>'Logitech','brand_slug'=>'logitech','name'=>'Logitech G431 7.1 Surround Gaming Headset','model'=>'G431','mpn'=>'981-000770','description'=>'Wired over-ear gaming headset with boom microphone and 7.1 surround support.',
        'specs'=>['accessory_type'=>'headset','connectivity'=>['wired'],'colour'=>'Black','headset_style'=>'over_ear','has_microphone'=>true,'wireless_capable'=>false,'enclosure_type'=>'closed','frequency_min_hz'=>20,'frequency_max_hz'=>20000,'surround_sound'=>true],
        'source_url'=>'https://www.redlinetech.lk/category/headset-and-speakers','image_url'=>'https://www.redlinetech.lk/storage/products/577/Eyhd7TYkqWVRO6Qci9toZ0BKz54JlZzGOkJ0uaCT.webp','offers'=>['tech_shark'=>[25000,4]],'tags'=>['competitive_gaming','communication'],
    ],
    'wooyu-g18' => [
        'category'=>'accessories','brand'=>'WOOYU','brand_slug'=>'wooyu','name'=>'WOOYU G18 USB Gaming Headset','model'=>'G18','mpn'=>'WOOYU-G18','description'=>'USB over-ear gaming headset with an integrated microphone.',
        'specs'=>['accessory_type'=>'headset','connectivity'=>['wired'],'colour'=>'Black','headset_style'=>'over_ear','has_microphone'=>true,'wireless_capable'=>false,'enclosure_type'=>'closed','frequency_min_hz'=>20,'frequency_max_hz'=>20000,'surround_sound'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/wooyu-g18-usb-gaming-headset','image_url'=>'https://www.redlinetech.lk/storage/products/369/PQBUa57Ur2Y3ViHM65ackfWxzijgdWjSjBcT01Ul.webp','offers'=>['tech_shark'=>[4000,7],'tech_venom'=>[4200,4]],'tags'=>['competitive_gaming','communication'],
    ],
    'logitech-f310' => [
        'category'=>'accessories','brand'=>'Logitech','brand_slug'=>'logitech','name'=>'Logitech F310 Gaming Pad','model'=>'F310','mpn'=>'940-000112','description'=>'Classic wired dual-stick gamepad for PC gaming.',
        'specs'=>['accessory_type'=>'controller','connectivity'=>['wired'],'colour'=>'Blue and black'],
        'source_url'=>'https://www.redlinetech.lk/product/logitech-f310-gaming-pad','image_url'=>'https://www.redlinetech.lk/storage/products/435/GECHD04DVgbK8UXREjHee4Siw1rcb7ntAFScCMKL.webp','offers'=>['tech_shark'=>[8500,5]],'tags'=>['competitive_gaming'],
    ],
    'pxn-p5-white' => [
        'category'=>'accessories','brand'=>'PXN','brand_slug'=>'pxn','name'=>'PXN P5 Wireless Gaming Controller - White','model'=>'P5 White','mpn'=>'PXN-P5-WHITE','description'=>'White wireless dual-stick controller with a contrasting modern design.',
        'specs'=>['accessory_type'=>'controller','connectivity'=>['wireless','bluetooth'],'colour'=>'White'],
        'source_url'=>'https://www.redlinetech.lk/product/pxn-p5-gaming-controller-wireless-white','image_url'=>'https://www.redlinetech.lk/storage/products/431/gtamHu6Woaz4neCQUECDsputftr3eFTgMp9eJgiv.webp','offers'=>['tech_shark'=>[8500,4]],'tags'=>['competitive_gaming'],
    ],

    'acer-aspire5-mx150' => [
        'category'=>'laptops','brand'=>'Acer','brand_slug'=>'acer','name'=>'Acer Aspire 5 A515-51G MX150 Laptop','model'=>'A515-51G-515J','mpn'=>'NX.GTCAA.016','description'=>'15.6-inch everyday laptop with 8GB RAM and dedicated GeForce MX150 graphics.',
        'specs'=>['processor_model'=>'Intel Core i5-8250U','ram_capacity_gb'=>8,'gpu_model'=>'NVIDIA GeForce MX150 2GB','storage_capacity_gb'=>256,'screen_size_inches'=>15.6],
        'source_url'=>'https://www.acer.com/ac/en/US/content/support-product/7244','image_url'=>'https://www.notebookcheck.info/fileadmin/Notebooks/Acer/Aspire_5_A515-51G-51RL/4zu3_Acer_Aspire_5_A515_51G_51RL.jpg','offers'=>['finora_tech'=>[120000,4]],'tags'=>['study','office','programming','general'],
    ],
    'acer-nitro-v15-rtx5050' => [
        'category'=>'laptops','brand'=>'Acer','brand_slug'=>'acer','name'=>'Acer Nitro V15 ANV15-52-74Y5 Core i7 RTX 5050 Gaming Laptop','model'=>'ANV15-52-74Y5','mpn'=>'ANV15-52-74Y5','description'=>'16GB gaming laptop with a Core i7 processor and GeForce RTX 5050 graphics.',
        'specs'=>['processor_model'=>'Intel Core i7-14650HX','ram_capacity_gb'=>16,'gpu_model'=>'NVIDIA GeForce RTX 5050','storage_capacity_gb'=>512,'screen_size_inches'=>15.6],
        'source_url'=>'https://www.redlinetech.lk/product/acer-nitro-v15-anv15-52-74y5-core-i7-rtx-5050-gaming-laptop','image_url'=>'https://www.redlinetech.lk/storage/products/733/26xe5XYGnFcHC1H8l4eOjtarvVMFGRQLvLhuu17l.webp','offers'=>['finora_tech'=>[379000,3]],'tags'=>['gaming','programming','engineering'],
    ],
    'gigabyte-gaming-a16-rtx4050' => [
        'category'=>'laptops','brand'=>'Gigabyte','brand_slug'=>'gigabyte','name'=>'Gigabyte Gaming A16 Core i7 RTX 4050 Laptop','model'=>'Gaming A16 CVH','mpn'=>'GAMING-A16-CVH','description'=>'16GB 16-inch 165Hz gaming laptop with RTX 4050 6GB graphics.',
        'specs'=>['processor_model'=>'Intel Core i7-13620H','ram_capacity_gb'=>16,'gpu_model'=>'NVIDIA GeForce RTX 4050 6GB','storage_capacity_gb'=>512,'screen_size_inches'=>16],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-gaming-a16-core-i7-16-165hz-rtx-4050-6gb','image_url'=>'https://www.redlinetech.lk/storage/products/16/6YJPsoHliErcrPz2lJu273INjl8pvcwzpsqvB4GL.webp','offers'=>['finora_tech'=>[370000,3]],'tags'=>['gaming','programming','content_creation'],
    ],
    'asus-vivobook-x1404va' => [
        'category'=>'laptops','brand'=>'ASUS','brand_slug'=>'asus','name'=>'ASUS Vivobook X1404VA 14-inch Core 7 Laptop','model'=>'X1404VA','mpn'=>'X1404VA','description'=>'Portable 8GB everyday laptop with a 14-inch Full HD display.',
        'specs'=>['processor_model'=>'Intel Core 7 150U','ram_capacity_gb'=>8,'gpu_model'=>'Intel Graphics','storage_capacity_gb'=>512,'screen_size_inches'=>14],
        'source_url'=>'https://www.redlinetech.lk/product/asus-vivobook-x1404va-140-fhd-core-7-laptop','image_url'=>'https://www.redlinetech.lk/storage/products/950/KaEpc2mfPmsiBocmdxX7u68pIzDC6XFCmXGV0Swn.webp','offers'=>['finora_tech'=>[234000,5]],'tags'=>['study','office','programming','general'],
    ],
    'msi-crosshair-16-rtx5070' => [
        'category'=>'laptops','brand'=>'MSI','brand_slug'=>'msi','name'=>'MSI Crosshair 16 HX AI Ultra 9 RTX 5070 Laptop','model'=>'Crosshair 16 HX AI D2XWGKG','mpn'=>'D2XWGKG','description'=>'32GB high-performance 16-inch gaming and creator laptop with RTX 5070 graphics.',
        'specs'=>['processor_model'=>'Intel Core Ultra 9 275HX','ram_capacity_gb'=>32,'gpu_model'=>'NVIDIA GeForce RTX 5070','storage_capacity_gb'=>1024,'screen_size_inches'=>16],
        'source_url'=>'https://www.redlinetech.lk/product/msi-crosshair-16-hx-ai-d2xwgkg-ultra-9-rtx-5070','image_url'=>'https://www.redlinetech.lk/storage/products/33/E9nDH3KjsIgVwm76IhY0P5no2F8vFUecb5IN4U2M.webp','offers'=>['finora_tech'=>[850000,2]],'tags'=>['gaming','content_creation','video_editing','graphic_design','programming'],
    ],

    'logitech-g213' => [
        'category'=>'accessories','brand'=>'Logitech','brand_slug'=>'logitech','name'=>'Logitech G213 Prodigy RGB Gaming Keyboard','model'=>'G213 Prodigy','mpn'=>'920-008096','description'=>'Full-size wired gaming keyboard with RGB lighting and membrane switches.',
        'specs'=>['accessory_type'=>'keyboard','connectivity'=>['wired'],'colour'=>'Black','keyboard_size'=>'full_size','switch_technology'=>'membrane','backlight_type'=>'rgb','has_numpad'=>true,'hot_swappable'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/logitech-g213-prodigy-rgb-gaming-keyboard','image_url'=>'https://www.redlinetech.lk/storage/products/791/J3GgUKOhxQCMY6LSZQurLkA0duMLUQsLkgIIS1rO.webp','offers'=>['finora_tech'=>[16000,6]],'tags'=>['competitive_gaming','productivity'],
    ],
    'asus-tuf-k3-gen2' => [
        'category'=>'accessories','brand'=>'ASUS','brand_slug'=>'asus','name'=>'ASUS TUF Gaming K3 RGB Gen II Mechanical Keyboard','model'=>'TUF Gaming K3 Gen II','mpn'=>'90MP03X0-BKUA00','description'=>'Full-size wired mechanical gaming keyboard with RGB lighting.',
        'specs'=>['accessory_type'=>'keyboard','connectivity'=>['wired'],'colour'=>'Black','keyboard_size'=>'full_size','switch_technology'=>'mechanical','backlight_type'=>'rgb','has_numpad'=>true,'hot_swappable'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/asus-tuf-gaming-k3-rgb-gen-ii-mechanical-gaming-keyboard','image_url'=>'https://www.redlinetech.lk/storage/products/886/oP9MgmuVsXgdS9Fqn4yZ7I16j4QcvmjyQzaRLGUG.webp','offers'=>['finora_tech'=>[26500,4]],'tags'=>['competitive_gaming','productivity'],
    ],
    'armaggeddon-mka2c' => [
        'category'=>'accessories','brand'=>'Armaggeddon','brand_slug'=>'armaggeddon','name'=>'Armaggeddon MKA-2C Neo Psychraven Mechanical Keyboard','model'=>'MKA-2C NEO','mpn'=>'MKA-2C-NEO','description'=>'Compact wired mechanical gaming keyboard with RGB lighting.',
        'specs'=>['accessory_type'=>'keyboard','connectivity'=>['wired'],'colour'=>'Black','keyboard_size'=>'tenkeyless','switch_technology'=>'mechanical','backlight_type'=>'rgb','has_numpad'=>false,'hot_swappable'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/armaggeddon-mka-2c-neo-psychraven-mechanical-keyboard-clicky','image_url'=>'https://www.redlinetech.lk/storage/products/373/VLfFJxJpmlGK9g3iIuY78SEIH8fGQWA34jPctkMa.webp','offers'=>['finora_tech'=>[10000,5]],'tags'=>['competitive_gaming'],
    ],
    'bosston-k310' => [
        'category'=>'accessories','brand'=>'Bosston','brand_slug'=>'bosston','name'=>'Bosston K310 Mechanical Feel Wired Gaming Keyboard','model'=>'K310','mpn'=>'BOSSTON-K310','description'=>'Affordable full-size wired gaming keyboard with mechanical-feel keys.',
        'specs'=>['accessory_type'=>'keyboard','connectivity'=>['wired'],'colour'=>'Black','keyboard_size'=>'full_size','switch_technology'=>'membrane','backlight_type'=>'multicolour','has_numpad'=>true,'hot_swappable'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/bosston-k310-mechanical-feel-wired-gaming-keyboard','image_url'=>'https://www.redlinetech.lk/storage/products/415/oFt9AJgtyX4jvjWZaJylpKvOCX3hvd6WqALX0YQE.webp','offers'=>['tech_venom'=>[4500,4]],'tags'=>['competitive_gaming','productivity'],
    ],
    'aorus-agc310-chair' => [
        'category'=>'accessories','brand'=>'AORUS','brand_slug'=>'aorus','name'=>'Gigabyte AORUS AGC310 Gaming Chair','model'=>'AGC310','mpn'=>'GP-AGC310','description'=>'High-back AORUS gaming chair with an adjustable ergonomic design.',
        'specs'=>['accessory_type'=>'gaming_chair','colour'=>'Black and orange'],
        'source_url'=>'https://www.redlinetech.lk/product/gigabyte-aorus-agc310-gaming-chair','image_url'=>'https://www.redlinetech.lk/storage/products/413/bj9oeyKJdyXsnT88T62tnDMjvNim7d31Zf0SM9tO.webp','offers'=>['finora_tech'=>[88200,3]],'tags'=>['ergonomic','competitive_gaming'],
    ],
    'meetion-chr25-chair' => [
        'category'=>'accessories','brand'=>'Meetion','brand_slug'=>'meetion','name'=>'Meetion CHR25 Massage E-Sports Gaming Chair','model'=>'CHR25','mpn'=>'CHR25','description'=>'Gaming chair with 2D armrests, foot support and massage lumbar cushion.',
        'specs'=>['accessory_type'=>'gaming_chair','colour'=>'Black and red'],
        'source_url'=>'https://www.nanotek.lk/product/meetion-chr25-2d-armrest-massage-e-sports-gaming-chair','image_url'=>'https://www.nanotek.lk/storage/products/2147/KnWKVd5f5IWybD7hJM41p7pyrUlE1LkiG4B2z50P.webp','offers'=>['finora_tech'=>[58500,3]],'tags'=>['ergonomic','competitive_gaming'],
    ],
    'meetion-chr04-chair' => [
        'category'=>'accessories','brand'=>'Meetion','brand_slug'=>'meetion','name'=>'Meetion CHR04 Professional Gaming Chair','model'=>'CHR04','mpn'=>'CHR04','description'=>'Value-oriented high-back gaming chair with a contrasting professional style.',
        'specs'=>['accessory_type'=>'gaming_chair','colour'=>'Black and blue'],
        'source_url'=>'https://www.nanotek.lk/product/meetion-chr04-professional-gaming-chair','image_url'=>'https://www.nanotek.lk/storage/products/2144/kJjV4AUan6B4Q9OZxGCVghym9CdXPe28Y9ibX8nl.webp','offers'=>['finora_tech'=>[38000,4]],'tags'=>['ergonomic','competitive_gaming'],
    ],
    'logitech-g435' => [
        'category'=>'accessories','brand'=>'Logitech','brand_slug'=>'logitech','name'=>'Logitech G435 LIGHTSPEED Wireless Gaming Headset','model'=>'G435','mpn'=>'981-001050','description'=>'Lightweight wireless over-ear gaming headset with dual wireless modes.',
        'specs'=>['accessory_type'=>'headset','connectivity'=>['wireless','bluetooth'],'colour'=>'Black','headset_style'=>'over_ear','has_microphone'=>true,'wireless_capable'=>true,'enclosure_type'=>'closed','frequency_min_hz'=>20,'frequency_max_hz'=>20000,'surround_sound'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/logitech-g435-lightspeed-wireless-gaming-headset','image_url'=>'https://www.redlinetech.lk/storage/products/354/9CpBpoIZ9EA3Hf0ecx3sN8l4gfWvmBuiml4niRUk.webp','offers'=>['finora_tech'=>[24500,4]],'tags'=>['competitive_gaming','communication'],
    ],
    'logitech-g633s' => [
        'category'=>'accessories','brand'=>'Logitech','brand_slug'=>'logitech','name'=>'Logitech G633S 7.1 LIGHTSYNC Gaming Headset','model'=>'G633S','mpn'=>'981-000750','description'=>'Wired LIGHTSYNC over-ear headset with 7.1 surround audio and boom microphone.',
        'specs'=>['accessory_type'=>'headset','connectivity'=>['wired'],'colour'=>'Black','headset_style'=>'over_ear','has_microphone'=>true,'wireless_capable'=>false,'enclosure_type'=>'closed','frequency_min_hz'=>20,'frequency_max_hz'=>20000,'surround_sound'=>true],
        'source_url'=>'https://www.redlinetech.lk/product/logitech-g633s-71-lightsync-gaming-headset','image_url'=>'https://www.redlinetech.lk/storage/products/920/TjRse2Hhul5ZuDi2Z6ecfOPFs9Yvz0YYmEg0BcR2.webp','offers'=>['finora_tech'=>[44500,3]],'tags'=>['competitive_gaming','communication'],
    ],
    'logitech-f710' => [
        'category'=>'accessories','brand'=>'Logitech','brand_slug'=>'logitech','name'=>'Logitech F710 Wireless Gamepad','model'=>'F710','mpn'=>'940-000119','description'=>'Wireless dual-stick controller with a classic console-style layout.',
        'specs'=>['accessory_type'=>'controller','connectivity'=>['wireless'],'colour'=>'Silver and black'],
        'source_url'=>'https://www.redlinetech.lk/product/logitech-f710-wireless-gamepad','image_url'=>'https://www.redlinetech.lk/storage/products/429/Uqy9RnjmYXMNRkOORFgyIFxHgLKMiQLWTEk4Kyey.webp','offers'=>['finora_tech'=>[17000,4]],'tags'=>['competitive_gaming'],
    ],
    'pxn-v3-pro-wheel' => [
        'category'=>'accessories','brand'=>'PXN','brand_slug'=>'pxn','name'=>'PXN V3 Pro Racing Gaming Steering Wheel','model'=>'V3 Pro','mpn'=>'PXN-V3-PRO','description'=>'Steering-wheel and pedal controller with a visibly different racing layout.',
        'specs'=>['accessory_type'=>'controller','connectivity'=>['wired'],'colour'=>'Black'],
        'source_url'=>'https://www.redlinetech.lk/product/pxn-v3-pro-racing-gaming-steering-wheel','image_url'=>'https://www.redlinetech.lk/storage/products/425/wQx6fgNp7xLD7L9MKJXyweTk7EWX3wY8mSv4sm9S.webp','offers'=>['finora_tech'=>[32000,3]],'tags'=>['competitive_gaming'],
    ],
    'blackbox-bb24200hd-monitor' => [
        'category'=>'accessories','brand'=>'BlackBox','brand_slug'=>'blackbox','name'=>'BlackBox BB24200HD 24-inch 200Hz FHD IPS Gaming Monitor - White','model'=>'BB24200HD','mpn'=>'BB24200HD-W','description'=>'White 24-inch Full HD IPS gaming monitor with a 200Hz refresh rate.',
        'specs'=>['accessory_type'=>'monitor','connectivity'=>['wired'],'colour'=>'White','screen_size_inches'=>24,'resolution_width_pixels'=>1920,'resolution_height_pixels'=>1080,'refresh_rate_hz'=>200,'response_time_ms'=>1,'panel_type'=>'ips','aspect_ratio'=>'16_9','adaptive_sync'=>'compatible','hdr_supported'=>false],
        'source_url'=>'https://www.redlinetech.lk/product/blackbox-bb24200hd-200hz-fhd-ips-gaming-monitor-white','image_url'=>'https://www.redlinetech.lk/storage/products/897/Oc00y0nIE8neYmEVR0Kr4UpcdONsRoSDgMCaD25C.webp','offers'=>['tech_venom'=>[47500,3]],'tags'=>['competitive_gaming','visual_creative'],
    ],
];

return [
    'researched_at' => '2026-08-30',
    'shops' => $shops,
    'products' => $products,
];
