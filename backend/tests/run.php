<?php
declare(strict_types=1);

use Hexbay\Middleware\RoleMiddleware;
use Hexbay\Services\HexBotInterpreter;
use Hexbay\Services\LaptopRecommendationService;
use Hexbay\Services\PcCompatibilityEngine;
use Hexbay\Services\TechnicalQuestionService;
use Hexbay\Services\ProductComparisonService;
use Hexbay\Contracts\ProductCatalogueGateway;
use Hexbay\Support\HttpException;
use Hexbay\Support\Jwt;
use Hexbay\Validation\AuthValidator;
use Hexbay\Validation\AdminValidator;
use Hexbay\Validation\BuyerValidator;
use Hexbay\Validation\LaptopRecommendationValidator;
use Hexbay\Validation\PcCompatibilityValidator;
use Hexbay\Validation\PcBuildRecommendationValidator;
use Hexbay\Validation\SellerValidator;
use Hexbay\Validation\ShopApplicationValidator;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;

/** @param callable(): void $test */
function test(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "[FAIL] {$name}: {$exception->getMessage()}\n");
    }
}

function assertTrue(bool $condition, string $message = 'Assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $specifications
 *  @return array<string, mixed>
 */
function pcTestComponent(
    string $name,
    array $specifications,
    int $availableQuantity = 1
): array {
    return [
        'name' => $name,
        'available_quantity' => $availableQuantity,
        'specifications' => $specifications,
    ];
}

/** @return array<string, array<string, mixed>> */
function compatiblePcTestBuild(): array
{
    return [
        'processor' => pcTestComponent('AM5 test CPU', [
            'socket' => 'am5', 'architecture_family' => 'zen4',
            'supported_chipsets' => ['b650'], 'peak_power_watts' => 88,
            'tdp_watts' => 65, 'integrated_graphics' => true,
            'cooler_included' => true,
        ]),
        'motherboard' => pcTestComponent('B650 test board', [
            'cpu_socket' => 'am5', 'supported_cpu_families' => ['zen4'],
            'chipset' => 'b650', 'bios_support_note' => 'Current family supported.',
            'ram_generation' => 'ddr5', 'max_memory_capacity_gb' => 192,
            'memory_slots' => 4, 'max_memory_speed_mhz' => 6400,
            'form_factor' => 'atx', 'm2_slots' => 2,
            'm2_interfaces' => ['pcie_4', 'pcie_5'],
        ]),
        'memory' => pcTestComponent('32 GB DDR5 test memory', [
            'ddr_generation' => 'ddr5', 'capacity_gb' => 32,
            'module_count' => 2, 'speed_mhz' => 6000,
        ]),
        'graphics_card' => pcTestComponent('Test GPU', [
            'gpu_length_mm' => 267, 'gpu_thickness_slots' => 2.5,
            'recommended_psu_watts' => 650,
            'power_connectors' => ['twelve_vhpwr'],
            'total_board_power_watts' => 220,
        ]),
        'power_supply' => pcTestComponent('750 W test PSU', [
            'wattage' => 750, 'form_factor' => 'atx',
            'available_connectors' => ['eight_pin', 'twelve_vhpwr'],
            'eight_pin_connector_count' => 2,
            'twelve_vhpwr_connector_count' => 1,
            'six_pin_connector_count' => 0,
        ]),
        'storage' => pcTestComponent('PCIe 4 test SSD', [
            'storage_type' => 'nvme_ssd', 'interface' => 'pcie_4',
        ]),
        'computer_case' => pcTestComponent('ATX test case', [
            'motherboard_form_factors' => ['mini_itx', 'micro_atx', 'atx'],
            'max_gpu_length_mm' => 360, 'max_gpu_thickness_slots' => 4,
            'psu_form_factors' => ['atx'], 'max_cpu_cooler_height_mm' => 170,
            'supported_radiator_sizes' => ['rad_240', 'rad_280', 'rad_360'],
        ]),
        'cpu_cooler' => pcTestComponent('AM5 test cooler', [
            'cooler_type' => 'air', 'supported_sockets' => ['am4', 'am5'],
            'cooling_capacity_watts' => 180, 'cooler_height_mm' => 155,
            'radiator_size' => 'none',
        ]),
    ];
}

/** @param array<string, mixed> $result */
function pcCheckStatus(array $result, string $ruleCode): ?string
{
    foreach ($result['checks'] as $check) {
        if ($check['rule_code'] === $ruleCode) {
            return (string) $check['status'];
        }
    }
    return null;
}

/** @param callable(): void $callback */
function assertHttpStatus(int $status, callable $callback): void
{
    try {
        $callback();
    } catch (HttpException $exception) {
        assertTrue(
            $exception->status === $status,
            "Expected HTTP {$status}, received {$exception->status}."
        );
        return;
    }
    throw new RuntimeException("Expected HTTP {$status}, but no exception was thrown.");
}

$jwt = new Jwt(
    'unit-test-secret-with-at-least-thirty-two-characters',
    'unit-test-issuer',
    'unit-test-audience',
    3600
);

test('JWT issues and validates expected identity claims', static function () use ($jwt): void {
    $issued = $jwt->issue(['sub' => '42', 'role' => 'customer'], 1_700_000_000);
    $claims = $jwt->decode($issued['token'], 1_700_000_100);
    assertTrue($claims['sub'] === '42');
    assertTrue($claims['role'] === 'customer');
    assertTrue($claims['iss'] === 'unit-test-issuer');
    assertTrue($claims['aud'] === 'unit-test-audience');
});

test('JWT rejects a modified signature', static function () use ($jwt): void {
    $issued = $jwt->issue(['sub' => '42', 'role' => 'customer'], 1_700_000_000);
    $parts = explode('.', $issued['token']);
    $parts[2][0] = $parts[2][0] === 'A' ? 'B' : 'A';
    $token = implode('.', $parts);
    assertHttpStatus(401, static fn () => $jwt->decode($token, 1_700_000_100));
});

test('JWT rejects an expired token', static function () use ($jwt): void {
    $issued = $jwt->issue(['sub' => '42', 'role' => 'customer'], 1_700_000_000);
    assertHttpStatus(401, static fn () => $jwt->decode($issued['token'], 1_700_004_000));
});

test('JWT refuses a short secret', static function (): void {
    try {
        new Jwt('short', 'issuer', 'audience');
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Expected an invalid-secret exception.');
});

test('Customer registration validation normalises safe input', static function (): void {
    $result = AuthValidator::registration(
        [
            'email' => '  Customer@Example.com ',
            'password' => 'StrongPass123',
            'first_name' => 'Navi',
            'last_name' => 'Perera',
            'phone' => '+94 77 123 4567',
        ],
        'customer'
    );
    assertTrue($result['email'] === 'customer@example.com');
    assertTrue($result['business_name'] === null);
});

test('Vendor registration requires a business name', static function (): void {
    assertHttpStatus(422, static fn () => AuthValidator::registration(
        [
            'email' => 'vendor@example.com',
            'password' => 'StrongPass123',
            'first_name' => 'Navi',
            'last_name' => 'Perera',
        ],
        'shop_owner'
    ));
});

test('Weak registration passwords are rejected', static function (): void {
    assertHttpStatus(422, static fn () => AuthValidator::registration(
        [
            'email' => 'customer@example.com',
            'password' => 'password',
            'first_name' => 'Navi',
            'last_name' => 'Perera',
        ],
        'customer'
    ));
});

test('Login requires a valid email and password', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AuthValidator::login(['email' => 'invalid', 'password' => ''])
    );
});

test('Role middleware permits an allowed role', static function (): void {
    RoleMiddleware::require(['role' => 'administrator'], ['administrator']);
    assertTrue(true);
});

test('Role middleware rejects a different role', static function (): void {
    assertHttpStatus(
        403,
        static fn () => RoleMiddleware::require(['role' => 'customer'], ['administrator'])
    );
});

test('PHP password hashing verifies the original password only', static function (): void {
    $hash = password_hash('StrongPass123', PASSWORD_DEFAULT);
    assertTrue(password_verify('StrongPass123', $hash));
    assertTrue(!password_verify('WrongPass123', $hash));
    assertTrue(!str_contains($hash, 'StrongPass123'));
});

test('Seller application requires explicit commission acceptance', static function (): void {
    assertHttpStatus(422, static fn () => ShopApplicationValidator::submission([
        'shop_name' => 'Example Technology',
        'address' => '10 Main Street, Colombo',
        'contact_phone' => '+94 77 123 4567',
        'contact_email' => 'shop@example.com',
        'legal_name' => 'Example Technology Private Limited',
        'business_registration_reference' => 'BR-12345',
        'commission_rule_id' => 1,
        'commission_accepted' => false,
    ]));
});

test('Valid seller application keeps the active commission rule identifier', static function (): void {
    $result = ShopApplicationValidator::submission([
        'shop_name' => 'Example Technology',
        'description' => 'Computer products and accessories.',
        'address' => '10 Main Street, Colombo',
        'contact_phone' => '+94 77 123 4567',
        'contact_email' => 'shop@example.com',
        'legal_name' => 'Example Technology Private Limited',
        'business_registration_reference' => 'BR-12345',
        'commission_rule_id' => 7,
        'commission_accepted' => true,
    ]);
    assertTrue($result['commission_rule_id'] === 7);
    assertTrue($result['commission_accepted'] === true);
});

test('Account suspension requires an administrator reason', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::accountStatus([
            'status' => 'suspended',
            'reason' => '',
        ])
    );
});

test('Shop rejection requires a decision reason', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::shopDecision([
            'decision' => 'rejected',
            'reason' => 'no',
        ])
    );
});

test('Administrator commission rate is bounded', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::commissionRule([
            'percentage' => '75',
            'reason' => 'Invalid excessive rate',
        ])
    );
});

test('Valid commission input is normalized to two decimals', static function (): void {
    $result = AdminValidator::commissionRule([
        'percentage' => '5',
        'reason' => 'Standard marketplace commission',
    ]);
    assertTrue($result['percentage'] === '5.00');
});

test('Payout rejection requires an administrator reason', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::payoutDecision([
            'decision' => 'rejected',
            'reason' => '',
        ])
    );
});

test('Payout approval is accepted without a rejection reason', static function (): void {
    $result = AdminValidator::payoutDecision([
        'decision' => 'approved',
        'reason' => '',
    ]);
    assertTrue($result['decision'] === 'approved');
    assertTrue($result['reason'] === '');
});

test('Category validation creates a URL-safe slug', static function (): void {
    $result = AdminValidator::category([
        'name' => 'Gaming Monitors',
        'description' => 'High refresh-rate displays.',
        'is_active' => true,
        'requires_listing_approval' => true,
    ]);
    assertTrue($result['slug'] === 'gaming-monitors');
    assertTrue($result['requires_listing_approval'] === true);
});

test('Controlled specification fields require options', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::specification([
            'code' => 'ram_generation',
            'display_name' => 'RAM generation',
            'data_type' => 'option',
            'options' => [],
        ])
    );
});

test('Valid specification options are normalized', static function (): void {
    $result = AdminValidator::specification([
        'code' => 'RAM Generation',
        'display_name' => 'RAM generation',
        'data_type' => 'option',
        'is_compatibility_field' => true,
        'options' => [
            ['display_value' => 'DDR4'],
            ['display_value' => 'DDR5'],
        ],
    ]);
    assertTrue($result['code'] === 'ram_generation');
    assertTrue($result['options']['ddr4'] === 'DDR4');
    assertTrue($result['is_compatibility_field'] === true);
});

test('Hidden listing decisions require a reason', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::listingDecision([
            'status' => 'hidden',
            'reason' => '',
        ])
    );
});

test('Final trust queue decisions require a review note', static function (): void {
    assertHttpStatus(
        422,
        static fn () => AdminValidator::queueDecision([
            'status' => 'actioned',
            'note' => '',
        ], 'report')
    );
});

test('Seller shop profile requires a usable address', static function (): void {
    assertHttpStatus(422, static fn () => SellerValidator::profile([
        'name' => 'Hex Tech',
        'description' => 'Computer shop.',
        'address_text' => 'No',
        'contact_phone' => '+94 77 123 4567',
        'contact_email' => 'seller@example.com',
    ]));
});

test('Seller listing normalizes the SKU and price', static function (): void {
    $result = SellerValidator::listing([
        'category_id' => 1,
        'brand_name' => 'Example',
        'product_name' => 'Example Laptop',
        'model' => 'EX-15',
        'sku' => ' ex-15_blue ',
        'condition_type' => 'new',
        'price' => '249999.9',
        'initial_stock' => 3,
        'specifications' => [],
    ]);
    assertTrue($result['sku'] === 'EX-15_BLUE');
    assertTrue($result['price'] === '249999.90');
});

test('Seller listing rejects unsafe SKU characters', static function (): void {
    assertHttpStatus(422, static fn () => SellerValidator::listing([
        'category_id' => 1,
        'brand_name' => 'Example',
        'product_name' => 'Example Laptop',
        'model' => 'EX-15',
        'sku' => 'EX 15 / BLUE',
        'condition_type' => 'new',
        'price' => '249999.90',
        'initial_stock' => 3,
    ]));
});

test('Inventory adjustments cannot silently use zero units', static function (): void {
    assertHttpStatus(
        422,
        static fn () => SellerValidator::stockAdjustment([
            'quantity_delta' => 0,
            'reason' => 'Stock count correction',
        ])
    );
});

test('Seller shipment validation requires traceable delivery evidence', static function (): void {
    $shipment = SellerValidator::orderStatus([
        'status' => 'shipped',
        'delivery_method' => 'third_party_courier',
        'delivery_partner' => 'Test Courier',
        'tracking_reference' => 'HB-TRACK-100',
        'estimated_delivery_date' => date('Y-m-d', strtotime('+3 days')),
        'shipment_note' => 'Handle the package carefully.',
    ]);
    assertTrue($shipment['delivery_method'] === 'third_party_courier');
    assertTrue($shipment['tracking_reference'] === 'HB-TRACK-100');
    assertHttpStatus(422, static fn () => SellerValidator::orderStatus([
        'status' => 'shipped',
        'delivery_method' => 'third_party_courier',
        'estimated_delivery_date' => date('Y-m-d', strtotime('+3 days')),
    ]));
});

test('Seller fulfilment checkpoints use a controlled checklist', static function (): void {
    assertTrue(
        SellerValidator::fulfilmentCheckpoint([
            'checkpoint_code' => 'items_packed',
        ]) === 'items_packed'
    );
    assertHttpStatus(422, static fn () => SellerValidator::fulfilmentCheckpoint([
        'checkpoint_code' => 'skip_checks',
    ]));
});

test('Seller payout requests require a positive amount', static function (): void {
    assertHttpStatus(
        422,
        static fn () => SellerValidator::payoutAmount(['amount' => '0'])
    );
});

test('Buyer address validation normalizes a safe Sri Lankan address', static function (): void {
    $address = BuyerValidator::address([
        'label' => ' Home ',
        'recipient_name' => 'Navi Perera',
        'phone' => '+94 77 123 4567',
        'address_line_1' => '42 Main Street',
        'city' => 'Colombo',
        'district' => 'Colombo',
        'country_code' => 'lk',
        'is_default' => true,
    ]);
    assertTrue($address['label'] === 'Home');
    assertTrue($address['country_code'] === 'LK');
    assertTrue($address['is_default'] === true);
});

test('Buyer cart quantity is bounded', static function (): void {
    assertTrue(BuyerValidator::quantity(['quantity' => 2]) === 2);
    assertHttpStatus(
        422,
        static fn () => BuyerValidator::quantity(['quantity' => 0])
    );
    assertHttpStatus(
        422,
        static fn () => BuyerValidator::quantity(['quantity' => 100])
    );
});

test('Complete setup cart validates seller offers and displayed prices', static function (): void {
    $items = BuyerValidator::setupCartItems([
        'items' => [
            ['listing_id' => 10, 'quantity' => 1, 'expected_price_lkr' => '41900.00', 'component_group' => 'pc', 'component_code' => 'processor'],
            ['listing_id' => 11, 'quantity' => 1, 'expected_price_lkr' => 42900, 'component_group' => 'pc', 'component_code' => 'motherboard'],
        ],
    ]);
    assertTrue(count($items) === 2);
    assertTrue($items[0]['listing_id'] === 10);
    assertTrue($items[0]['expected_price_lkr'] === '41900.00');
    assertHttpStatus(422, static fn () => BuyerValidator::setupCartItems([
        'items' => [
            ['listing_id' => 10, 'expected_price_lkr' => 41900, 'component_group' => 'pc', 'component_code' => 'processor'],
            ['listing_id' => 10, 'expected_price_lkr' => 41900, 'component_group' => 'pc', 'component_code' => 'motherboard'],
        ],
    ]));
    assertHttpStatus(422, static fn () => BuyerValidator::setupCartItems([
        'items' => [['listing_id' => 10]],
    ]));
});

test('HexBot setup identity receives a stable server-generated name', static function (): void {
    $identity = BuyerValidator::setupIdentity([
        'setup' => [
            'build_rank' => 2,
            'setup_scope' => 'complete_setup',
            'target_budget_lkr' => 300000,
            'max_budget_lkr' => 322500,
            'requirements' => ['workloads' => ['gaming_1080p' => 1]],
            'scores' => ['performance' => 82.5],
            'compatibility' => ['status' => 'compatible'],
        ],
    ]);
    assertTrue($identity['name'] === 'HexBot 1080p Gaming Setup · Build 2');
    assertTrue($identity['setup_scope'] === 'complete_setup');
    $listIdentity = BuyerValidator::setupIdentity([
        'setup' => [
            'build_rank' => 1,
            'setup_scope' => 'pc_only',
            'target_budget_lkr' => 250000,
            'max_budget_lkr' => 268750,
            'requirements' => ['workloads' => ['programming']],
            'scores' => [],
            'compatibility' => ['status' => 'compatible'],
        ],
    ]);
    assertTrue($listIdentity['name'] === 'HexBot Programming PC Build · Build 1');
    assertHttpStatus(422, static fn () => BuyerValidator::setupIdentity([
        'setup' => ['build_rank' => 0],
    ]));
});

test('Checkout requires card payment, the displayed total, setup IDs, and simulation notice', static function (): void {
    $setupId = '123e4567-e89b-42d3-a456-426614174000';
    $checkout = BuyerValidator::checkout([
        'address_id' => 8,
        'payment_method' => 'card_simulation',
        'simulated_payment_acknowledged' => true,
        'expected_total_lkr' => '299900',
        'setup_public_ids' => [$setupId],
    ]);
    assertTrue($checkout['address_id'] === 8);
    assertTrue($checkout['expected_total_lkr'] === '299900.00');
    assertTrue($checkout['payment_status'] === 'simulated_authorized');
    assertTrue($checkout['setup_public_ids'] === [$setupId]);

    assertHttpStatus(422, static fn () => BuyerValidator::checkout([
        'address_id' => 8,
        'payment_method' => 'cash_on_delivery',
        'simulated_payment_acknowledged' => true,
        'expected_total_lkr' => 0,
        'setup_public_ids' => [],
    ]));
    assertHttpStatus(422, static fn () => BuyerValidator::checkout([
        'address_id' => 8,
        'payment_method' => 'card_simulation',
        'expected_total_lkr' => 299900,
        'setup_public_ids' => [],
    ]));
});

test('Verified review validation requires a one-to-five rating', static function (): void {
    $review = BuyerValidator::review([
        'rating' => 5,
        'title' => 'Excellent',
        'review_text' => 'Verified purchase feedback.',
    ]);
    assertTrue($review['rating'] === 5);
    assertHttpStatus(
        422,
        static fn () => BuyerValidator::review(['rating' => 6])
    );
});

test('Customer complaint requires a target and useful description', static function (): void {
    assertHttpStatus(
        422,
        static fn () => BuyerValidator::complaint([
            'subject' => 'Delivery issue',
            'description' => 'A sufficiently detailed support description.',
        ])
    );
    $complaint = BuyerValidator::complaint([
        'order_id' => 10,
        'subject' => 'Delivery issue',
        'description' => 'A sufficiently detailed support description.',
    ]);
    assertTrue($complaint['order_id'] === 10);
});

test('Counterfeit report validation uses controlled non-accusatory reasons', static function (): void {
    $report = BuyerValidator::counterfeitReport([
        'listing_id' => 12,
        'reason_code' => 'packaging_concern',
        'description' => 'Packaging details require administrator review.',
    ]);
    assertTrue($report['reason_code'] === 'packaging_concern');
    assertHttpStatus(
        422,
        static fn () => BuyerValidator::counterfeitReport([
            'listing_id' => 12,
            'reason_code' => 'definitely_fake',
            'description' => 'Packaging details require administrator review.',
        ])
    );
});

test('Laptop recommendation validation normalises buyer preferences', static function (): void {
    $request = LaptopRecommendationValidator::request([
        'max_budget_lkr' => '350000',
        'intended_use' => 'Content Creation',
        'minimum_ram_gb' => '16',
        'minimum_storage_gb' => 512,
        'require_dedicated_gpu' => true,
        'preferred_brands' => [' Lenovo ', 'ASUS', 'lenovo'],
        'limit' => 6,
    ]);
    assertTrue($request['requirements']['max_budget_lkr'] === 350000.0);
    assertTrue($request['requirements']['intended_use'] === 'content_creation');
    assertTrue($request['requirements']['require_dedicated_gpu'] === true);
    assertTrue($request['requirements']['preferred_brands'] === ['Lenovo', 'ASUS']);
    assertTrue($request['limit'] === 6);
});

test('Laptop recommendation validation rejects unsafe ranges', static function (): void {
    assertHttpStatus(422, static fn () => LaptopRecommendationValidator::request([
        'max_budget_lkr' => 500,
        'intended_use' => 'teleportation',
        'minimum_screen_size_inches' => 17,
        'maximum_screen_size_inches' => 13,
        'require_dedicated_gpu' => 'yes',
    ]));
});

test('Laptop recommendation validation accepts price and RAM ranges', static function (): void {
    $request = LaptopRecommendationValidator::request([
        'minimum_budget_lkr' => 200000,
        'max_budget_lkr' => 350000,
        'intended_use' => 'any',
        'minimum_ram_gb' => 8,
        'maximum_ram_gb' => 16,
    ]);
    assertTrue($request['requirements']['minimum_budget_lkr'] === 200000.0);
    assertTrue($request['requirements']['max_budget_lkr'] === 350000.0);
    assertTrue($request['requirements']['minimum_ram_gb'] === 8.0);
    assertTrue($request['requirements']['maximum_ram_gb'] === 16.0);
    assertTrue($request['requirements']['intended_use'] === 'any');

    assertHttpStatus(422, static fn () => LaptopRecommendationValidator::request([
        'minimum_budget_lkr' => 400000,
        'max_budget_lkr' => 350000,
        'intended_use' => 'any',
        'minimum_ram_gb' => 32,
        'maximum_ram_gb' => 16,
    ]));
});

test('Laptop ranking results are revalidated against the MySQL snapshot', static function (): void {
    $authoritative = [
        10 => [
            'product_id' => 10,
            'listing_id' => 44,
            'name' => 'Trusted laptop',
            'price_lkr' => 275000.0,
            'stock_quantity' => 3,
        ],
        11 => [
            'product_id' => 11,
            'listing_id' => 45,
            'name' => 'Out of stock laptop',
            'price_lkr' => 190000.0,
            'stock_quantity' => 0,
        ],
    ];
    $results = LaptopRecommendationService::revalidateRankings(
        [
            [
                'product_id' => 999,
                'price_lkr' => 1,
                'score' => 1,
                'reasons' => ['Unknown product injected by the ranker.'],
            ],
            [
                'product_id' => 10,
                'price_lkr' => 1,
                'score' => 0.82,
                'reasons' => ['Matches the requested use case.'],
            ],
            ['product_id' => 11, 'score' => 0.91],
        ],
        $authoritative,
        5
    );
    assertTrue(count($results) === 1);
    assertTrue($results[0]['product_id'] === 10);
    assertTrue($results[0]['price_lkr'] === 275000.0);
    assertTrue($results[0]['listing_id'] === 44);

    $percentageScale = LaptopRecommendationService::revalidateRankings(
        [['product_id' => 10, 'score' => 82]],
        $authoritative,
        5
    );
    assertTrue($percentageScale[0]['score'] === 0.82);
});

test('HexBot understands a natural laptop request and extracts requirements', static function (): void {
    $result = (new HexBotInterpreter())->interpret(
        'I need a gaming laptop under Rs. 300,000 with at least 16 GB RAM and RTX graphics'
    );
    assertTrue($result['intent'] === 'recommend_laptop');
    assertTrue($result['confidence'] >= 0.9);
    assertTrue($result['entities']['max_budget_lkr'] === 300000.0);
    assertTrue($result['entities']['intended_use'] === 'gaming');
    assertTrue($result['entities']['minimum_ram_gb'] === 16);
    assertTrue($result['entities']['required_gpu'] === 'RTX');
    assertTrue($result['entities']['require_dedicated_gpu'] === true);
});

test('HexBot keeps laptop RAM requests in the laptop conversation', static function (): void {
    $result = (new HexBotInterpreter())->interpret(
        'I need a laptop with 32 GB RAM for programming'
    );
    assertTrue($result['intent'] === 'recommend_laptop');
    assertTrue($result['entities']['minimum_ram_gb'] === 32);
    assertTrue($result['entities']['intended_use'] === 'programming');
});

test('HexBot extracts natural price and RAM ranges with any-use intent', static function (): void {
    $result = (new HexBotInterpreter())->interpret(
        'I need a laptop for any use with a budget around 200,000 - 350,000 and 8 GB - 16 GB RAM'
    );
    assertTrue($result['intent'] === 'recommend_laptop');
    assertTrue($result['entities']['minimum_budget_lkr'] === 200000.0);
    assertTrue($result['entities']['max_budget_lkr'] === 350000.0);
    assertTrue($result['entities']['minimum_ram_gb'] === 8);
    assertTrue($result['entities']['maximum_ram_gb'] === 16);
    assertTrue($result['entities']['intended_use'] === 'any');
});

test('HexBot recognises product finding and PC-building intents', static function (): void {
    $interpreter = new HexBotInterpreter();
    assertTrue(
        $interpreter->interpret('Find me a wireless mouse')['intent']
            === 'find_product'
    );
    assertTrue(
        $interpreter->interpret('Build a gaming PC for me')['intent']
            === 'build_pc'
    );
});

test('HexBot recognises controlled technical questions', static function (): void {
    $interpreter = new HexBotInterpreter();
    assertTrue(
        $interpreter->interpret('What is better, DDR4 or DDR5?')['intent']
        === 'ask_technical_question'
    );
    assertTrue(
        $interpreter->interpret('RTX vs GTX')['intent']
        === 'ask_technical_question'
    );
});

test('Technical questions explain supported hardware concepts', static function (): void {
    $service = new TechnicalQuestionService();
    $memory = $service->answer('What is the difference between DDR4 and DDR5?');
    assertTrue($memory['supported'] === true);
    assertTrue(str_contains($memory['title'], 'DDR3'));
    assertTrue(count($memory['points']) === 3);
    assertTrue(($memory['actions']['related_search']['query'] ?? '') === 'DDR5');

    $mouse = $service->answer('What makes a gaming mouse better?');
    assertTrue($mouse['supported'] === true);
    assertTrue(str_contains($mouse['title'], 'mouse'));

    $graphics = $service->answer('RTX vs GTX');
    assertTrue(
        ($graphics['actions']['pc_seed']['context']['require_dedicated_gpu'] ?? false)
        === true
    );
});

test('Technical questions refuse unsupported exact-model claims', static function (): void {
    $answer = (new TechnicalQuestionService())->answer(
        'Which is better, RTX 3060 vs RTX 4060?'
    );
    assertTrue($answer['supported'] === false);
    assertTrue(str_contains($answer['summary'], 'Exact model comparison'));
    assertTrue(str_contains($answer['caution'], 'will not guess'));
});

test('Product comparison separates concept questions from exact products', static function (): void {
    $gateway = new class implements ProductCatalogueGateway {
        public function catalogue(array $filters): array { return ['products' => []]; }
        public function product(int $productId): ?array { return null; }
    };
    $service = new ProductComparisonService($gateway);
    assertTrue($service->requestFromQuestion('RTX vs GTX') === null);
    $request = $service->requestFromQuestion(
        'PointArc Everyday Mouse vs PointArc Pulse Gaming Mouse for gaming'
    );
    assertTrue($request['left_query'] === 'PointArc Everyday Mouse');
    assertTrue($request['right_query'] === 'PointArc Pulse Gaming Mouse');
    assertTrue($request['use_case'] === 'gaming');
    $processorRequest = $service->requestFromQuestion(
        'AMD Ryzen 5 5600 vs Intel Core i5-13400F for gaming'
    );
    assertTrue($processorRequest['left_query'] === 'AMD Ryzen 5 5600');
    assertTrue($processorRequest['right_query'] === 'Intel Core i5-13400F');
});

test('Product comparison uses listed specifications and live offers', static function (): void {
    $products = [
        1 => [
            'id' => 1, 'name' => 'Everyday Mouse', 'model' => 'E100',
            'brand_name' => 'PointArc', 'category_name' => 'Accessories',
            'category_slug' => 'accessories', 'rating_average' => 4.2,
            'rating_count' => 8, 'specification_completeness' => 'complete',
            'offers' => [[
                'price' => 2500, 'shop_name' => 'Shop A',
                'available_quantity' => 5, 'image_filename' => null,
            ]],
            'specifications' => [
                ['code' => 'accessory_type', 'display_name' => 'Accessory type', 'data_type' => 'option', 'unit' => null, 'specification_value' => 'Mouse'],
                ['code' => 'max_dpi', 'display_name' => 'Maximum sensor DPI', 'data_type' => 'integer', 'unit' => 'DPI', 'specification_value' => '1600'],
                ['code' => 'weight_grams', 'display_name' => 'Weight', 'data_type' => 'decimal', 'unit' => 'g', 'specification_value' => '92'],
            ],
        ],
        2 => [
            'id' => 2, 'name' => 'Pulse Gaming Mouse', 'model' => 'P16000',
            'brand_name' => 'PointArc', 'category_name' => 'Accessories',
            'category_slug' => 'accessories', 'rating_average' => 4.5,
            'rating_count' => 12, 'specification_completeness' => 'complete',
            'offers' => [[
                'price' => 4900, 'shop_name' => 'Shop B',
                'available_quantity' => 7, 'image_filename' => null,
            ]],
            'specifications' => [
                ['code' => 'accessory_type', 'display_name' => 'Accessory type', 'data_type' => 'option', 'unit' => null, 'specification_value' => 'Mouse'],
                ['code' => 'max_dpi', 'display_name' => 'Maximum sensor DPI', 'data_type' => 'integer', 'unit' => 'DPI', 'specification_value' => '16000'],
                ['code' => 'weight_grams', 'display_name' => 'Weight', 'data_type' => 'decimal', 'unit' => 'g', 'specification_value' => '68'],
            ],
        ],
    ];
    $gateway = new class($products) implements ProductCatalogueGateway {
        public function __construct(private readonly array $products) {}
        public function catalogue(array $filters): array { return ['products' => []]; }
        public function product(int $productId): ?array { return $this->products[$productId] ?? null; }
    };
    $comparison = (new ProductComparisonService($gateway))->compare([
        'left_query' => 'Everyday Mouse',
        'right_query' => 'Pulse Gaming Mouse',
        'use_case' => 'gaming',
    ], ['left' => 1, 'right' => 2]);
    assertTrue($comparison['status'] === 'ready');
    assertTrue($comparison['verdict']['listed_advantage_product_id'] === 2);
    assertTrue($comparison['verdict']['lower_price_product_id'] === 1);
    assertTrue(count($comparison['rows']) === 2);
    assertTrue($comparison['related_search_query'] === 'mouse');
    assertTrue($comparison['products'][0]['pc_component_group'] === null);
});

test('Product comparison marks core products for PC-builder handoff', static function (): void {
    $products = [
        11 => [
            'id' => 11, 'name' => 'Alpha Processor', 'model' => 'A11',
            'brand_name' => 'ChipCo', 'category_name' => 'Processors',
            'category_slug' => 'processors', 'rating_average' => 4.1,
            'rating_count' => 5, 'specification_completeness' => 'complete',
            'offers' => [[
                'price' => 42000, 'shop_name' => 'Shop A',
                'available_quantity' => 3, 'image_filename' => null,
            ]],
            'specifications' => [],
        ],
        12 => [
            'id' => 12, 'name' => 'Beta Processor', 'model' => 'B12',
            'brand_name' => 'ChipCo', 'category_name' => 'Processors',
            'category_slug' => 'processors', 'rating_average' => 4.3,
            'rating_count' => 6, 'specification_completeness' => 'complete',
            'offers' => [[
                'price' => 49000, 'shop_name' => 'Shop B',
                'available_quantity' => 4, 'image_filename' => null,
            ]],
            'specifications' => [],
        ],
    ];
    $gateway = new class($products) implements ProductCatalogueGateway {
        public function __construct(private readonly array $products) {}
        public function catalogue(array $filters): array { return ['products' => []]; }
        public function product(int $productId): ?array { return $this->products[$productId] ?? null; }
    };
    $comparison = (new ProductComparisonService($gateway))->compare([
        'left_query' => 'Alpha Processor',
        'right_query' => 'Beta Processor',
        'use_case' => 'programming',
    ], ['left' => 11, 'right' => 12]);
    assertTrue($comparison['status'] === 'ready');
    assertTrue($comparison['products'][0]['pc_component_group'] === 'processor');
    assertTrue($comparison['products'][1]['pc_component_group'] === 'processor');
});

test('HexBot extracts an advanced PC build request without technical trivia', static function (): void {
    $result = (new HexBotInterpreter())->interpret(
        'Build me a 1440p gaming PC around Rs. 300,000 with 32 GB RAM and 1 TB storage'
    );
    assertTrue($result['intent'] === 'build_pc');
    assertTrue($result['entities']['max_budget_lkr'] === 300000.0);
    assertTrue($result['entities']['pc_workload'] === 'gaming_1440p');
    assertTrue($result['entities']['minimum_ram_gb'] === 32);
    assertTrue($result['entities']['minimum_storage_gb'] === 1024);
    assertTrue(!array_key_exists('socket', $result['entities']));
    assertTrue(!array_key_exists('ddr_generation', $result['entities']));
});

test('HexBot understands RAM-first natural preferences', static function (): void {
    $interpreter = new HexBotInterpreter();
    foreach ([
        'RAM 16GB' => 16,
        'memory 32 GB' => 32,
        'at least 64 gigs of RAM' => 64,
    ] as $message => $expected) {
        $result = $interpreter->interpret($message);
        assertTrue($result['entities']['minimum_ram_gb'] === $expected);
    }
    $exact = $interpreter->interpret('RAM 16GB')['entities'];
    assertTrue($exact['maximum_ram_gb'] === 16);
    assertTrue($exact['ram_preference_mode'] === 'exact');
    $minimum = $interpreter->interpret('at least 16 GB RAM')['entities'];
    assertTrue(!array_key_exists('maximum_ram_gb', $minimum));
    assertTrue($minimum['ram_preference_mode'] === 'minimum');
});

test('HexBot extracts a complete PC specification from ordinary language', static function (): void {
    $entities = (new HexBotInterpreter())->interpret(
        'I want a 8GB RAM, 2GB VGA, i5 Processor with 150 000 budget'
    );
    assertTrue($entities['intent'] === 'build_pc');
    assertTrue($entities['entities']['max_budget_lkr'] === 150000.0);
    assertTrue($entities['entities']['minimum_ram_gb'] === 8);
    assertTrue($entities['entities']['maximum_ram_gb'] === 8);
    assertTrue($entities['entities']['minimum_vram_gb'] === 2);
    assertTrue(!array_key_exists('maximum_vram_gb', $entities['entities']));
    assertTrue($entities['entities']['require_dedicated_gpu'] === true);
    assertTrue($entities['entities']['required_processor_family'] === 'intel_core_i5');

    $exactVram = (new HexBotInterpreter())->interpret('exactly 4 GB VGA')['entities'];
    assertTrue($exactVram['minimum_vram_gb'] === 4);
    assertTrue($exactVram['maximum_vram_gb'] === 4);

    $detailed = (new HexBotInterpreter())->interpret(
        'Ryzen 5 5600GT with exactly 1 TB NVMe SSD'
    )['entities'];
    assertTrue($detailed['required_processor_family'] === 'amd_ryzen_5');
    assertTrue($detailed['required_processor_model'] === 'Ryzen 5 5600GT');
    assertTrue($detailed['minimum_storage_gb'] === 1024);
    assertTrue($detailed['maximum_storage_gb'] === 1024);
    assertTrue($detailed['storage_type'] === 'nvme_ssd');
});

test('HexBot recognises complete setup scope without asking peripheral trivia', static function (): void {
    $result = (new HexBotInterpreter())->interpret(
        'Build a complete gaming setup around Rs. 350,000 and include a headset'
    );
    assertTrue($result['intent'] === 'build_pc');
    assertTrue($result['entities']['setup_scope'] === 'complete_setup');
    assertTrue($result['entities']['include_headset'] === true);
});

test('HexBot understands contextual peripheral additions and removals', static function (): void {
    $interpreter = new HexBotInterpreter();
    $add = $interpreter->interpret('I want a keyboard too')['entities'];
    assertTrue($add['peripheral_categories_add'] === ['keyboard']);
    $spacingTypo = $interpreter->interpret('add akeyboard')['entities'];
    assertTrue($spacingTypo['peripheral_categories_add'] === ['keyboard']);
    $laptopAccessories = $interpreter->interpret(
        'add a keyboard, mouse and a headset'
    )['entities'];
    assertTrue(
        $laptopAccessories['peripheral_categories_add']
        === ['keyboard', 'mouse', 'headset']
    );
    $remove = $interpreter->interpret('Remove the mouse but keep the monitor')['entities'];
    assertTrue($remove['peripheral_categories_remove'] === ['mouse']);
    assertTrue($remove['peripheral_categories_add'] === ['monitor']);
});

test('HexBot treats a PC setup request as a complete setup', static function (): void {
    $result = (new HexBotInterpreter())->interpret(
        'Build a gaming PC setup around Rs. 300,000'
    );
    assertTrue($result['intent'] === 'build_pc');
    assertTrue($result['entities']['setup_scope'] === 'complete_setup');
});

test('PC compatibility input accepts simple component groups', static function (): void {
    $validated = PcCompatibilityValidator::validationRequest([
        'mode' => 'complete',
        'components' => [
            'processor' => '10',
            'motherboard' => 20,
            'graphics_card' => null,
        ],
    ]);
    assertTrue($validated['mode'] === 'complete');
    assertTrue($validated['components'] === ['processor' => 10, 'motherboard' => 20]);
});

test('PC build recommendation input applies flexible-budget defaults', static function (): void {
    $result = PcBuildRecommendationValidator::request([
        'target_budget_lkr' => '300,000',
        'workloads' => ['gaming_1080p'],
    ]);
    assertTrue($result['target_budget_lkr'] === 300000.0);
    assertTrue($result['max_budget_lkr'] === 322500.0);
    assertTrue(abs($result['workloads']['gaming_1080p'] - 1.0) < 0.0001);
    assertTrue($result['preferences']['dedicated_graphics'] === 'auto');
    assertTrue($result['setup_scope'] === 'pc_only');
    assertTrue($result['include_headset'] === false);
});

test('PC build recommendation input accepts complete setup scope', static function (): void {
    $result = PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 350000,
        'workloads' => ['gaming_1080p'],
        'setup_scope' => 'complete_setup',
        'include_headset' => true,
        'preferences' => ['minimum_memory_gb' => 16, 'maximum_memory_gb' => 16],
    ]);
    assertTrue($result['setup_scope'] === 'complete_setup');
    assertTrue($result['include_headset'] === true);
    assertTrue($result['preferences']['maximum_memory_gb'] === 16);
});

test('PC build recommendation input accepts an explicit peripheral selection', static function (): void {
    $result = PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 300000,
        'workloads' => ['gaming_1080p'],
        'setup_scope' => 'complete_setup',
        'peripheral_categories' => ['keyboard', 'keyboard'],
    ]);
    assertTrue($result['peripheral_categories'] === ['keyboard']);
});

test('PC build recommendation input normalizes mixed use-case weights', static function (): void {
    $result = PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 500000,
        'workloads' => [
            ['code' => 'video_editing', 'weight' => 1],
            ['code' => 'programming', 'weight' => 0.5],
        ],
        'priorities' => ['performance' => 1, 'value' => 1],
        'preferences' => [
            'minimum_memory_gb' => 32,
            'minimum_storage_gb' => 1000,
        ],
    ]);
    assertTrue(abs($result['workloads']['video_editing'] - (2 / 3)) < 0.0001);
    assertTrue(abs($result['workloads']['programming'] - (1 / 3)) < 0.0001);
    assertTrue(abs(array_sum($result['priorities']) - 1.0) < 0.0001);
    assertTrue($result['preferences']['minimum_memory_gb'] === 32);
});

test('PC build recommendation input validates processor, VRAM and storage preferences', static function (): void {
    $result = PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 150000,
        'workloads' => ['balanced_general'],
        'preferences' => [
            'minimum_memory_gb' => 8,
            'maximum_memory_gb' => 8,
            'minimum_vram_gb' => 2,
            'processor_family' => 'intel_core_i5',
            'minimum_storage_gb' => 500,
            'maximum_storage_gb' => 1000,
            'storage_type' => 'nvme_ssd',
        ],
    ]);
    assertTrue($result['preferences']['minimum_vram_gb'] === 2);
    assertTrue($result['preferences']['processor_family'] === 'intel_core_i5');
    assertTrue($result['preferences']['storage_type'] === 'nvme_ssd');

    assertHttpStatus(422, static fn () => PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 150000,
        'preferences' => [
            'minimum_vram_gb' => 8,
            'maximum_vram_gb' => 2,
            'processor_family' => 'intel_core_i11',
        ],
    ]));
});

test('PC build recommendation input supports locked-component refinement', static function (): void {
    $result = PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 450000,
        'locked_components' => ['processor' => 45, 'motherboard' => 49],
    ]);
    assertTrue($result['locked_components'] === ['processor' => 45, 'motherboard' => 49]);
});

test('PC build recommendation input rejects unsafe budget and duplicate use cases', static function (): void {
    assertHttpStatus(422, static fn () => PcBuildRecommendationValidator::request([
        'target_budget_lkr' => 10000,
        'max_budget_lkr' => 200000,
        'workloads' => ['gaming_1080p', 'gaming_1080p'],
        'flexibility_percent' => 50,
    ]));
});

test('PC compatibility input rejects unknown groups and duplicate products', static function (): void {
    assertHttpStatus(422, static fn () => PcCompatibilityValidator::validationRequest([
        'components' => [
            'processor' => 10,
            'motherboard' => 10,
            'rgb_colour' => 30,
        ],
    ]));
});

test('PC compatibility engine approves a complete known-good build', static function (): void {
    $result = (new PcCompatibilityEngine())->validate(compatiblePcTestBuild(), 'complete');
    assertTrue($result['overall_status'] === 'compatible');
    assertTrue($result['is_compatible'] === true);
    assertTrue($result['complete'] === true);
    assertTrue($result['summary']['failed'] === 0);
    assertTrue(pcCheckStatus($result, 'cpu_motherboard_socket') === 'pass');
    assertTrue(pcCheckStatus($result, 'gpu_psu_connectors') === 'pass');
    assertTrue(pcCheckStatus($result, 'build_completeness') === 'pass');
});

test('PC compatibility engine rejects CPU socket and family mismatches', static function (): void {
    $build = compatiblePcTestBuild();
    $build['motherboard']['specifications']['cpu_socket'] = 'am4';
    $build['motherboard']['specifications']['supported_cpu_families'] = ['zen3'];
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue($result['overall_status'] === 'incompatible');
    assertTrue(pcCheckStatus($result, 'cpu_motherboard_socket') === 'fail');
    assertTrue(pcCheckStatus($result, 'cpu_motherboard_family') === 'fail');
});

test('PC compatibility engine rejects wrong RAM generation and excessive modules', static function (): void {
    $build = compatiblePcTestBuild();
    $build['memory']['specifications']['ddr_generation'] = 'ddr4';
    $build['memory']['specifications']['module_count'] = 8;
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue(pcCheckStatus($result, 'motherboard_memory_generation') === 'fail');
    assertTrue(pcCheckStatus($result, 'motherboard_memory_slots') === 'fail');
});

test('PC compatibility engine warns when memory will run below its rated speed', static function (): void {
    $build = compatiblePcTestBuild();
    $build['memory']['specifications']['speed_mhz'] = 7200;
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue($result['overall_status'] === 'warning');
    assertTrue(pcCheckStatus($result, 'motherboard_memory_speed') === 'warning');
});

test('PC compatibility engine enforces motherboard and GPU case clearances', static function (): void {
    $build = compatiblePcTestBuild();
    $build['motherboard']['specifications']['form_factor'] = 'eatx';
    $build['graphics_card']['specifications']['gpu_length_mm'] = 400;
    $build['graphics_card']['specifications']['gpu_thickness_slots'] = 5;
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue(pcCheckStatus($result, 'motherboard_case_form_factor') === 'fail');
    assertTrue(pcCheckStatus($result, 'gpu_case_length') === 'fail');
    assertTrue(pcCheckStatus($result, 'gpu_case_thickness') === 'fail');
});

test('PC compatibility engine enforces PSU wattage, connectors and case size', static function (): void {
    $build = compatiblePcTestBuild();
    $build['power_supply']['specifications']['wattage'] = 500;
    $build['power_supply']['specifications']['form_factor'] = 'sfx';
    $build['power_supply']['specifications']['available_connectors'] = ['eight_pin'];
    $build['power_supply']['specifications']['twelve_vhpwr_connector_count'] = 0;
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue(pcCheckStatus($result, 'psu_case_form_factor') === 'fail');
    assertTrue(pcCheckStatus($result, 'gpu_psu_wattage') === 'fail');
    assertTrue(pcCheckStatus($result, 'gpu_psu_connectors') === 'fail');
});

test('PC compatibility engine accepts slot-powered graphics cards', static function (): void {
    $build = compatiblePcTestBuild();
    $build['graphics_card']['specifications']['power_connectors'] = ['none'];
    $build['power_supply']['specifications']['available_connectors'] = [];
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue(pcCheckStatus($result, 'gpu_psu_connectors') === 'pass');
});

test('PC compatibility engine understands backward-compatible NVMe generations', static function (): void {
    $engine = new PcCompatibilityEngine();
    $build = compatiblePcTestBuild();
    $build['storage']['specifications']['interface'] = 'pcie_3';
    assertTrue(pcCheckStatus($engine->validate($build), 'storage_motherboard_interface') === 'pass');
    $build['storage']['specifications']['interface'] = 'pcie_5';
    $build['motherboard']['specifications']['m2_interfaces'] = ['pcie_4'];
    assertTrue(pcCheckStatus($engine->validate($build), 'storage_motherboard_interface') === 'pass');
});

test('PC compatibility engine validates cooler socket, capacity and case height', static function (): void {
    $build = compatiblePcTestBuild();
    $build['cpu_cooler']['specifications']['supported_sockets'] = ['am4'];
    $build['cpu_cooler']['specifications']['cooling_capacity_watts'] = 60;
    $build['cpu_cooler']['specifications']['cooler_height_mm'] = 190;
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue(pcCheckStatus($result, 'cpu_cooler_socket') === 'fail');
    assertTrue(pcCheckStatus($result, 'cpu_cooler_capacity') === 'fail');
    assertTrue(pcCheckStatus($result, 'cooler_case_height') === 'fail');
});

test('PC compatibility engine validates AIO radiator support', static function (): void {
    $build = compatiblePcTestBuild();
    $build['cpu_cooler']['specifications']['cooler_type'] = 'aio';
    $build['cpu_cooler']['specifications']['radiator_size'] = 'rad_420';
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue(pcCheckStatus($result, 'cooler_case_radiator') === 'fail');
});

test('Complete PC validation requires a display path and necessary cooler', static function (): void {
    $build = compatiblePcTestBuild();
    unset($build['graphics_card'], $build['cpu_cooler']);
    $build['processor']['specifications']['integrated_graphics'] = false;
    $build['processor']['specifications']['cooler_included'] = false;
    $result = (new PcCompatibilityEngine())->validate($build, 'complete');
    assertTrue($result['overall_status'] === 'incompatible');
    assertTrue(pcCheckStatus($result, 'display_output_available') === 'fail');
    assertTrue(in_array('graphics_card', $result['missing_components'], true));
    assertTrue(in_array('cpu_cooler', $result['missing_components'], true));
});

test('PC compatibility engine reports unknown instead of guessing missing facts', static function (): void {
    $build = compatiblePcTestBuild();
    unset($build['motherboard']['specifications']['cpu_socket']);
    $result = (new PcCompatibilityEngine())->validate($build);
    assertTrue($result['overall_status'] === 'unknown');
    assertTrue(pcCheckStatus($result, 'cpu_motherboard_socket') === 'unknown');
});

fwrite(
    $failed === 0 ? STDOUT : STDERR,
    "\nResult: {$passed} passed, {$failed} failed.\n"
);
exit($failed === 0 ? 0 : 1);
