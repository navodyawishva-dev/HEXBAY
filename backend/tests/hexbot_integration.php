<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Contracts\LaptopRankingClient;
use Hexbay\Contracts\PeripheralRankingClient;
use Hexbay\Repositories\HexBotRepository;
use Hexbay\Repositories\LaptopRecommendationRepository;
use Hexbay\Repositories\PcBuildRecommendationRepository;
use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Services\HexBotConversationService;
use Hexbay\Services\HexBotInterpreter;
use Hexbay\Services\LaptopRecommendationService;
use Hexbay\Services\PcBuildOptimizer;
use Hexbay\Services\PcBuildRecommendationService;
use Hexbay\Services\PcCompatibilityEngine;
use Hexbay\Services\PeripheralRecommendationService;
use Hexbay\Services\TechnicalQuestionService;
use Hexbay\Services\ProductComparisonService;
use Hexbay\Repositories\MarketplaceRepository;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::connection();

try {
    $db->beginTransaction();

    $ranker = new class implements LaptopRankingClient {
        public function rank(array $payload): array
        {
            return [
                'algorithm_version' => 'hexbot-integration-v1',
                'eligible_candidate_count' => 0,
                'recommendations' => [],
                'filtered_out' => [],
                'filter_summary' => [],
                'relaxation_suggestions' => [
                    'Increase the budget or relax one specification.',
                ],
            ];
        }
    };
    $pcComponents = new PcCompatibilityRepository($db);
    $peripheralRanker = new class implements PeripheralRankingClient {
        public function rank(array $payload): array
        {
            return [
                'algorithm_version' => 'hexbot-accessory-integration-v1',
                'recommendations' => array_map(
                    static fn (array $candidate): array => [
                        'identity_key' => $candidate['identity_key'],
                        'score' => 85,
                        'reasons' => ['Matches the requested laptop use.'],
                    ],
                    (array) ($payload['candidates'] ?? [])
                ),
            ];
        }
    };
    $peripherals = new PeripheralRecommendationService(
        $pcComponents,
        $peripheralRanker,
        true
    );
    $pcBuilds = new PcBuildRecommendationService(
        new PcBuildRecommendationRepository($db, $pcComponents),
        new PcBuildOptimizer(new PcCompatibilityEngine()),
        $peripherals
    );
    $marketplace = new MarketplaceRepository($db);
    $service = new HexBotConversationService(
        new HexBotRepository($db),
        new HexBotInterpreter(),
        new LaptopRecommendationService(
            new LaptopRecommendationRepository($db),
            $ranker
        ),
        $pcBuilds,
        $peripherals,
        new TechnicalQuestionService(),
        new ProductComparisonService($marketplace)
    );
    $sessionKey = 'hexbot_test_' . bin2hex(random_bytes(16));
    $started = $service->start($sessionKey);
    $publicId = (string) ($started['session']['public_id'] ?? '');

    if (
        $publicId === ''
        || ($started['session']['state_code'] ?? '') !== 'awaiting_intent'
        || count($started['messages'] ?? []) !== 1
        || !in_array(
            'intent:question',
            array_column((array) ($started['options'] ?? []), 'id'),
            true
        )
    ) {
        throw new RuntimeException('HexBot did not create a valid session.');
    }

    $technicalSessionKey = 'hexbot_tech_test_' . bin2hex(random_bytes(16));
    $technicalStarted = $service->start($technicalSessionKey);
    $technicalPublicId = (string) ($technicalStarted['session']['public_id'] ?? '');
    $technicalPrompt = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'Ask a tech question',
        'intent:question'
    );
    if (($technicalPrompt['session']['state_code'] ?? '') !== 'technical_question') {
        throw new RuntimeException('HexBot did not enter technical-question mode.');
    }
    $technicalAnswer = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'What is better, DDR4 or DDR5?',
        null
    );
    if (
        ($technicalAnswer['session']['state_code'] ?? '') !== 'technical_question'
        || ($technicalAnswer['technical_answer']['supported'] ?? false) !== true
        || !str_contains(
            (string) ($technicalAnswer['reply']['message_text'] ?? ''),
            'not interchangeable'
        )
    ) {
        throw new RuntimeException('HexBot did not answer a supported technical question.');
    }
    $relatedMemory = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'Show DDR5 products',
        'tech:find-related'
    );
    if (
        ($relatedMemory['navigation']['type'] ?? '') !== 'product_search'
        || ($relatedMemory['navigation']['query'] ?? '') !== 'DDR5'
    ) {
        throw new RuntimeException('HexBot did not hand a technical answer to product search.');
    }
    $mouseStatement = $db->query(
        'SELECT DISTINCT cp.name
         FROM canonical_products cp
         INNER JOIN shop_product_listings l ON l.canonical_product_id=cp.id AND l.status="active"
         INNER JOIN shops s ON s.id=l.shop_id AND s.status="approved"
         INNER JOIN product_specifications ps ON ps.canonical_product_id=cp.id
         INNER JOIN specification_definitions sd ON sd.id=ps.definition_id AND sd.code="accessory_type"
         INNER JOIN specification_options so ON so.id=ps.option_id AND so.value_code="mouse"
         ORDER BY cp.name LIMIT 2'
    );
    $mouseNames = array_column($mouseStatement->fetchAll(), 'name');
    if (count($mouseNames) !== 2) {
        throw new RuntimeException('The integration catalogue needs two live mice.');
    }
    $productComparison = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        $mouseNames[0] . ' vs ' . $mouseNames[1] . ' for gaming',
        null
    );
    if (
        ($productComparison['session']['state_code'] ?? '') !== 'technical_question'
        || ($productComparison['product_comparison']['status'] ?? '') !== 'ready'
        || count((array) ($productComparison['product_comparison']['products'] ?? [])) !== 2
        || ($productComparison['product_comparison']['category'] ?? '') !== 'Mouse'
    ) {
        throw new RuntimeException('HexBot did not complete a live product comparison.');
    }
    $firstComparedProductId = (int) (
        $productComparison['product_comparison']['products'][0]['product_id'] ?? 0
    );
    $productNavigation = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'View product',
        'compare:open:' . $firstComparedProductId
    );
    if (
        ($productNavigation['navigation']['type'] ?? '') !== 'product_detail'
        || (int) ($productNavigation['navigation']['product_id'] ?? 0)
            !== $firstComparedProductId
    ) {
        throw new RuntimeException('HexBot did not open a compared product.');
    }
    $similarProducts = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'Show similar products',
        'compare:find-related'
    );
    if (
        ($similarProducts['navigation']['type'] ?? '') !== 'product_search'
        || ($similarProducts['navigation']['query'] ?? '') !== 'mouse'
    ) {
        throw new RuntimeException('HexBot did not search for similar compared products.');
    }

    $specifiedSessionKey = 'hexbot_spec_test_' . bin2hex(random_bytes(16));
    $specifiedStarted = $service->start($specifiedSessionKey);
    $specifiedPublicId = (string) ($specifiedStarted['session']['public_id'] ?? '');
    $specifiedUse = $service->message(
        $specifiedPublicId,
        $specifiedSessionKey,
        'I want a 8GB RAM, 2GB VGA, i5 Processor with 150 000 budget',
        null
    );
    if (($specifiedUse['session']['state_code'] ?? '') !== 'pc_use') {
        throw new RuntimeException('HexBot did not recognize the complete PC specification.');
    }
    $specifiedConfirmation = $service->message(
        $specifiedPublicId,
        $specifiedSessionKey,
        'General balanced use',
        'pcuse:balanced_general'
    );
    $specifiedSummary = (array) ($specifiedConfirmation['requirements_summary'] ?? []);
    if (
        ($specifiedConfirmation['session']['state_code'] ?? '') !== 'pc_confirm'
        || !in_array('exactly 8 GB RAM', $specifiedSummary, true)
        || !in_array('Intel Core i5 processor', $specifiedSummary, true)
        || !in_array('at least 2 GB VRAM', $specifiedSummary, true)
    ) {
        throw new RuntimeException(
            'HexBot did not visibly confirm the RAM, VGA and processor requirements: '
            . json_encode($specifiedSummary, JSON_THROW_ON_ERROR)
        );
    }
    $specifiedResult = $service->message(
        $specifiedPublicId,
        $specifiedSessionKey,
        'Build my PC',
        'confirm:pc'
    );
    if (
        ($specifiedResult['pc_outcome_status'] ?? '') === 'no_solution'
        || ($specifiedResult['pc_build_recommendations'] ?? []) === []
    ) {
        throw new RuntimeException(
            'HexBot did not produce an honest compatible result for the specified PC request.'
        );
    }

    $processorRows = (array) (($marketplace->catalogue([
        'category' => 'processors',
        'available' => true,
        'per_page' => 6,
    ]))['products'] ?? []);
    if (count($processorRows) < 2) {
        throw new RuntimeException('The integration catalogue needs two processors.');
    }
    $processorComparison = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        (string) $processorRows[0]['name'] . ' vs ' . (string) $processorRows[1]['name'] . ' for gaming',
        null
    );
    if (($processorComparison['product_comparison']['status'] ?? '') !== 'ready') {
        throw new RuntimeException('HexBot did not compare two PC components.');
    }
    $processorId = (int) (
        $processorComparison['product_comparison']['products'][0]['product_id'] ?? 0
    );
    $lockedPcStart = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'Use this processor in a PC build',
        'compare:use-in-pc:' . $processorId
    );
    if (
        ($lockedPcStart['session']['state_code'] ?? '') !== 'pc_budget'
        || !str_contains(
            strtolower((string) ($lockedPcStart['reply']['message_text'] ?? '')),
            'locked'
        )
    ) {
        throw new RuntimeException('HexBot did not start a locked-component PC build.');
    }
    $lockedPcRam = $service->message(
        $technicalPublicId,
        $technicalSessionKey,
        'Rs. 400,000',
        'budget:400000'
    );
    $lockedPcConfirmation = ($lockedPcRam['session']['state_code'] ?? '') === 'pc_ram'
        ? $service->message(
            $technicalPublicId,
            $technicalSessionKey,
            '16 GB RAM',
            'pcram:16'
        )
        : $lockedPcRam;
    if (
        ($lockedPcConfirmation['session']['state_code'] ?? '') !== 'pc_confirm'
        || !array_filter(
            (array) ($lockedPcConfirmation['requirements_summary'] ?? []),
            static fn (string $item): bool => str_starts_with($item, 'locked processor:')
        )
    ) {
        throw new RuntimeException('HexBot did not preserve the locked processor through confirmation.');
    }

    $storage = $service->message(
        $publicId,
        $sessionKey,
        'I need a gaming laptop under Rs. 300,000 with 16 GB RAM and RTX graphics',
        null
    );
    if (($storage['session']['state_code'] ?? '') !== 'laptop_storage') {
        throw new RuntimeException(
            'HexBot did not skip requirements already found in natural language.'
        );
    }

    $brand = $service->message(
        $publicId,
        $sessionKey,
        '512 GB',
        'storage:512'
    );
    if (($brand['session']['state_code'] ?? '') !== 'laptop_brand') {
        throw new RuntimeException('HexBot did not continue to the brand question.');
    }

    $confirmation = $service->message(
        $publicId,
        $sessionKey,
        'No preference',
        'brand:none'
    );
    if (
        ($confirmation['session']['state_code'] ?? '') !== 'laptop_confirm'
        || !str_contains(
            (string) ($confirmation['reply']['message_text'] ?? ''),
            'Rs. 300,000'
        )
    ) {
        throw new RuntimeException('HexBot did not summarize the requirements.');
    }

    $result = $service->message(
        $publicId,
        $sessionKey,
        'Find my laptops',
        'confirm:laptop'
    );
    if (
        ($result['session']['state_code'] ?? '') !== 'laptop_results'
        || !is_array($result['recommendations'] ?? null)
    ) {
        throw new RuntimeException('HexBot did not complete the recommendation path.');
    }

    $messageCount = (int) $db->query(
        "SELECT COUNT(*) FROM chatbot_messages
         WHERE chatbot_session_id=(
             SELECT id FROM chatbot_sessions
             WHERE public_id=" . $db->quote($publicId) . "
         )"
    )->fetchColumn();
    if ($messageCount !== 9) {
        throw new RuntimeException(
            "Expected 9 persisted messages, found {$messageCount}."
        );
    }

    $accessoryResult = $service->message(
        $publicId,
        $sessionKey,
        'Add a keyboard, mouse and a headset',
        null
    );
    if (
        ($accessoryResult['session']['state_code'] ?? '') !== 'laptop_results'
        || !is_array($accessoryResult['laptop_accessories'] ?? null)
        || ($accessoryResult['laptop_accessories']['requested_categories'] ?? [])
            !== ['keyboard', 'mouse', 'headset']
    ) {
        throw new RuntimeException(
            'HexBot did not preserve laptop results while adding accessories.'
        );
    }

    $pcSessionKey = 'hexbot_pc_test_' . bin2hex(random_bytes(16));
    $pcStarted = $service->start($pcSessionKey);
    $pcPublicId = (string) ($pcStarted['session']['public_id'] ?? '');
    $pcConfirmation = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Build a 1080p gaming PC around Rs. 300,000 with 16 GB RAM and 500 GB storage',
        null
    );
    if (
        ($pcConfirmation['session']['state_code'] ?? '') !== 'pc_confirm'
        || !str_contains((string) ($pcConfirmation['reply']['message_text'] ?? ''), 'Rs. 300,000')
        || !str_contains((string) ($pcConfirmation['reply']['message_text'] ?? ''), '1080p gaming')
    ) {
        throw new RuntimeException('HexBot did not reach concise PC confirmation from natural language.');
    }

    $pcResult = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Build my PC',
        'confirm:pc'
    );
    if (
        ($pcResult['session']['state_code'] ?? '') !== 'pc_results'
        || !is_array($pcResult['pc_build_recommendations'] ?? null)
        || ($pcResult['pc_build_recommendations'] ?? []) === []
        || ($pcResult['pc_build_recommendations'][0]['compatibility']['status'] ?? '')
            !== 'compatible'
    ) {
        throw new RuntimeException('HexBot did not return a compatible PC build.');
    }

    $unclearPcChange = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Make it extra shiny please',
        null
    );
    if (
        ($unclearPcChange['session']['state_code'] ?? '') !== 'pc_results'
        || !str_contains(
            strtolower((string) ($unclearPcChange['reply']['message_text'] ?? '')),
            'current build unchanged'
        )
    ) {
        throw new RuntimeException('An unclear PC refinement incorrectly discarded the active build.');
    }

    $pcPeripheralRefinement = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Add amouse too',
        null
    );
    if (
        ($pcPeripheralRefinement['session']['state_code'] ?? '') !== 'pc_results'
        || ($pcPeripheralRefinement['pc_builder_request']['peripheral_categories'] ?? []) !== ['mouse']
        || !str_contains(
            strtolower((string) ($pcPeripheralRefinement['reply']['message_text'] ?? '')),
            'added a purpose-matched mouse'
        )
    ) {
        throw new RuntimeException('HexBot did not visibly confirm the contextual mouse addition.');
    }

    $pcRefine = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Change requirements',
        'refine'
    );
    if (($pcRefine['session']['state_code'] ?? '') !== 'pc_refine') {
        throw new RuntimeException('HexBot did not open PC refinement.');
    }
    $pcRefinedConfirmation = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Raise my budget to Rs. 400,000',
        null
    );
    if (
        ($pcRefinedConfirmation['session']['state_code'] ?? '') !== 'pc_confirm'
        || !str_contains(
            (string) ($pcRefinedConfirmation['reply']['message_text'] ?? ''),
            'Rs. 400,000'
        )
    ) {
        throw new RuntimeException('HexBot did not apply the refined PC budget.');
    }

    $pcNavigation = $service->message(
        $pcPublicId,
        $pcSessionKey,
        'Open X Board',
        'open:x-board'
    );
    if (
        ($pcNavigation['navigation']['type'] ?? '') !== 'x_board'
        || ($pcNavigation['navigation']['request']['target_budget_lkr'] ?? 0) !== 400000.0
    ) {
        throw new RuntimeException('HexBot did not preserve the request when opening X Board.');
    }

    $pcMessageCount = (int) $db->query(
        "SELECT COUNT(*) FROM chatbot_messages
         WHERE chatbot_session_id=(
             SELECT id FROM chatbot_sessions
             WHERE public_id=" . $db->quote($pcPublicId) . "
         )"
    )->fetchColumn();
    if ($pcMessageCount !== 14) {
        throw new RuntimeException("Expected 14 persisted PC messages, found {$pcMessageCount}.");
    }

    $db->rollBack();
    fwrite(
        STDOUT,
        "HexBot integration test passed "
        . "(laptop flow, concise PC flow, contextual peripherals, compatible builds, and X Board handoff).\n"
    );
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(
        STDERR,
        "HexBot integration test failed: {$exception->getMessage()}\n"
    );
    exit(1);
}
