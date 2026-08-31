<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\HexBotRepository;
use Hexbay\Support\HttpException;

final class HexBotConversationService
{
    private const GREETING =
        "Hi! I'm HexBot, your HEXBAY technology shopping assistant. "
        . "I can build a compatible PC, recommend a laptop, help you find a product "
        . "or answer a controlled hardware question. "
        . "What would you like to do?";

    public function __construct(
        private readonly HexBotRepository $repository,
        private readonly HexBotInterpreter $interpreter,
        private readonly LaptopRecommendationService $laptops,
        private readonly PcBuildRecommendationService $pcBuilds,
        private readonly PeripheralRecommendationService $peripherals,
        private readonly TechnicalQuestionService $technicalQuestions,
        private readonly ProductComparisonService $productComparisons
    ) {
    }

    /** @return array<string, mixed> */
    public function start(string $sessionKey): array
    {
        $sessionKey = $this->sessionKey($sessionKey);
        $session = $this->repository->activeBySessionKey($sessionKey);
        if ($session !== null) {
            return $this->sessionPayload($session, [
                'options' => $this->optionsFor((string) $session['state_code']),
                'mascot_state' => 'idle',
                'clear_workspace' => (string) $session['state_code'] === 'awaiting_intent',
            ]);
        }

        $publicId = $this->uuid();
        $sessionId = $this->repository->create(
            $publicId,
            $sessionKey,
            'awaiting_intent',
            []
        );
        $this->repository->addMessage(
            $sessionId,
            'hexbot',
            self::GREETING
        );
        $session = $this->repository->ownedSession($publicId, $sessionKey);
        if ($session === null) {
            throw new \RuntimeException('The HexBot session could not be created.');
        }
        return $this->sessionPayload($session, [
            'options' => $this->intentOptions(),
            'mascot_state' => 'wave',
            'clear_workspace' => true,
        ]);
    }

    /** @return array<string, mixed> */
    public function message(
        string $publicId,
        string $sessionKey,
        string $message,
        ?string $action
    ): array {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $publicId
            ) !== 1
        ) {
            throw new HttpException(404, 'HexBot session not found.');
        }
        $session = $this->repository->ownedSession(
            $publicId,
            $this->sessionKey($sessionKey)
        );
        if ($session === null) {
            throw new HttpException(
                404,
                'This HexBot session expired. Please start a new conversation.'
            );
        }
        if ($this->repository->messageCount((int) $session['id']) >= 80) {
            throw new HttpException(
                429,
                'This conversation is full. Start a fresh HexBot conversation.'
            );
        }

        $message = trim($message);
        $action = $action === null ? null : trim($action);
        if ($message === '' && ($action === null || $action === '')) {
            throw new HttpException(422, 'Type a message or choose an option.', [
                'message' => ['A message or option is required.'],
            ]);
        }
        if (mb_strlen($message) > 500) {
            throw new HttpException(422, 'Your message is too long.', [
                'message' => ['Use no more than 500 characters.'],
            ]);
        }
        if ($action !== null && preg_match('/^[a-z0-9:_-]{1,80}$/', $action) !== 1) {
            throw new HttpException(422, 'The selected HexBot option is invalid.');
        }

        $interpretation = $this->interpreter->interpret($message);
        $this->repository->addMessage(
            (int) $session['id'],
            'customer',
            $message !== '' ? $message : (string) $action,
            $interpretation['intent'],
            $interpretation['confidence'],
            $interpretation['entities']
        );

        if (
            $action === 'restart'
            || preg_match('/\b(start over|restart|main menu)\b/i', $message)
        ) {
            return $this->restart($session);
        }

        return $this->advance(
            $session,
            $message,
            $action,
            $interpretation
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array{intent: string, confidence: float, entities: array<string, mixed>} $interpretation
     * @return array<string, mixed>
     */
    private function advance(
        array $session,
        string $message,
        ?string $action,
        array $interpretation
    ): array {
        $state = (string) $session['state_code'];
        $context = $session['context'];

        if ($state === 'awaiting_intent') {
            $intent = $this->intentFrom($action, $interpretation['intent']);
            if (
                $intent === 'unknown'
                && (
                    isset($interpretation['entities']['peripheral_categories_add'])
                    || isset($interpretation['entities']['peripheral_categories_remove'])
                )
            ) {
                $intent = 'build_pc';
            }
            if ($intent === 'recommend_laptop') {
                $context = $this->mergeEntities($context, $interpretation['entities']);
                return $this->nextLaptopQuestion($session, $context);
            }
            if (in_array($intent, ['build_pc', 'compatible_hardware'], true)) {
                $context = $this->normalizePcContext(
                    $this->mergeEntities($context, $interpretation['entities'])
                );
                return $this->nextPcQuestion($session, $context);
            }
            if ($intent === 'ask_technical_question') {
                if (
                    $action !== 'intent:question'
                    && preg_match('/\b(?:ask|technical|hardware)\s+(?:a\s+)?question\b/i', $message) !== 1
                ) {
                    return $this->answerTechnicalQuestion($session, $message, $action);
                }
                return $this->reply(
                    $session,
                    'ask_technical_question',
                    'technical_question',
                    [],
                    'What hardware topic would you like me to explain? You can ask something like “RTX vs GTX”, “DDR4 vs DDR5”, or “SSD vs HDD”.',
                    $this->technicalQuestionOptions(),
                    'talking'
                );
            }
            if ($intent === 'compare_products') {
                $request = $this->productComparisons->requestFromQuestion($message);
                if ($request !== null) {
                    return $this->respondProductComparison($session, $request);
                }
                return $this->reply(
                    $session,
                    'ask_technical_question',
                    'technical_question',
                    [],
                    'Tell me the two exact product names, for example “PointArc Everyday Mouse vs PointArc Pulse Gaming Mouse”.',
                    $this->technicalQuestionOptions(),
                    'talking'
                );
            }
            if ($intent === 'find_product') {
                return $this->reply(
                    $session,
                    $intent,
                    'find_product_query',
                    [],
                    'What product would you like me to find? You can enter a name, brand or model.',
                    [],
                    'talking'
                );
            }
            if ($intent === 'help') {
                return $this->reply(
                    $session,
                    null,
                    'awaiting_intent',
                    [],
                    "I can generate complete compatible PC builds around a flexible budget, "
                    . "recommend laptops, answer controlled hardware questions and guide marketplace product searches. For a PC, "
                    . "I only ask about budget, use case and optional major preferences—"
                    . "the technical compatibility checks happen automatically.",
                    $this->intentOptions(),
                    'talking'
                );
            }
            return $this->reply(
                $session,
                null,
                'awaiting_intent',
                [],
                "I can build a PC, recommend a laptop, answer a hardware question or help find a technology product. "
                . "Please choose one of these options.",
                $this->intentOptions(),
                'talking'
            );
        }

        if ($state === 'find_product_query') {
            $query = trim($message);
            if (mb_strlen($query) < 2) {
                return $this->reply(
                    $session,
                    'find_product',
                    'find_product_query',
                    [],
                    'Please enter at least two characters, such as “RTX 4060” or “wireless mouse”.',
                    [],
                    'talking'
                );
            }
            return $this->reply(
                $session,
                'find_product',
                'find_product_ready',
                ['product_query' => mb_substr($query, 0, 100)],
                "I prepared the marketplace search for “"
                . mb_substr($query, 0, 100)
                . "”. Open the results when you're ready.",
                [
                    ['id' => 'open:products', 'label' => 'Open product results'],
                    ['id' => 'restart', 'label' => 'Ask something else'],
                ],
                'happy',
                [
                    'type' => 'product_search',
                    'query' => mb_substr($query, 0, 100),
                ]
            );
        }

        if ($state === 'find_product_ready') {
            if ($action === 'open:products') {
                return $this->sessionPayload($session, [
                    'options' => [
                        ['id' => 'restart', 'label' => 'Ask something else'],
                    ],
                    'navigation' => [
                        'type' => 'product_search',
                        'query' => (string) ($context['product_query'] ?? ''),
                    ],
                    'mascot_state' => 'happy',
                ]);
            }
            return $this->restart($session);
        }

        if ($state === 'technical_question') {
            return $this->answerTechnicalQuestion($session, $message, $action);
        }

        if ($state === 'product_comparison_clarify') {
            return $this->advanceProductComparisonClarification(
                $session,
                $message,
                $action
            );
        }

        if (str_starts_with($state, 'laptop_')) {
            return $this->advanceLaptop(
                $session,
                $message,
                $action,
                $interpretation['entities']
            );
        }

        if (str_starts_with($state, 'pc_')) {
            return $this->advancePc(
                $session,
                $message,
                $action,
                $interpretation['entities']
            );
        }

        return $this->restart($session);
    }

    /** @param array<string, mixed> $session
     *  @return array<string, mixed>
     */
    private function answerTechnicalQuestion(
        array $session,
        string $message,
        ?string $action = null
    ): array
    {
        if ($action === 'tech:find-related') {
            $search = (array) ($session['context']['last_technical_actions']['related_search'] ?? []);
            return $this->navigationPayload(
                $session,
                ['type' => 'product_search', 'query' => (string) ($search['query'] ?? '')]
            );
        }
        if ($action === 'tech:start-pc') {
            $seed = (array) ($session['context']['last_technical_actions']['pc_seed']['context'] ?? []);
            return $this->startPcFromTechnicalAction($session, $seed);
        }
        if ($action === 'compare:find-related') {
            $comparison = (array) ($session['context']['last_product_comparison'] ?? []);
            return $this->navigationPayload($session, [
                'type' => 'product_search',
                'query' => (string) ($comparison['related_search_query'] ?? ''),
            ]);
        }
        if ($action === 'open:x-board') {
            return $this->navigationPayload($session, ['type' => 'x_board']);
        }
        if ($action !== null && preg_match('/^compare:open:(\d+)$/', $action, $match) === 1) {
            return $this->navigationPayload($session, [
                'type' => 'product_detail',
                'product_id' => (int) $match[1],
            ]);
        }
        if ($action !== null && preg_match('/^compare:use-in-pc:(\d+)$/', $action, $match) === 1) {
            return $this->startPcFromComparedProduct($session, (int) $match[1]);
        }
        $comparisonRequest = $this->productComparisons->requestFromQuestion($message);
        if ($comparisonRequest !== null) {
            return $this->respondProductComparison($session, $comparisonRequest);
        }
        $answer = $this->technicalQuestions->answer($message);
        $context = $session['context'];
        $context['last_technical_question'] = mb_substr(trim($message), 0, 500);
        $context['last_technical_answer_title'] = (string) ($answer['title'] ?? '');
        $context['last_technical_actions'] = (array) ($answer['actions'] ?? []);

        return $this->reply(
            $session,
            'ask_technical_question',
            'technical_question',
            $context,
            $this->technicalQuestions->chatMessage($answer),
            $this->technicalAnswerOptions($answer),
            ($answer['supported'] ?? false) === true ? 'happy' : 'talking',
            null,
            ['technical_answer' => $answer]
        );
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $request
     *  @param array<string, int> $selectedIds
     *  @return array<string, mixed>
     */
    private function respondProductComparison(
        array $session,
        array $request,
        array $selectedIds = []
    ): array {
        $comparison = $this->productComparisons->compare($request, $selectedIds);
        $context = $session['context'];
        $context['product_comparison_request'] = $comparison['request'] ?? $request;
        $context['product_comparison_selected_ids'] = $comparison['selected_ids'] ?? $selectedIds;
        $status = (string) ($comparison['status'] ?? 'not_found');
        if ($status === 'ready') {
            $context['last_product_comparison'] = $comparison;
        }
        if ($status === 'needs_clarification') {
            return $this->reply(
                $session,
                'ask_technical_question',
                'product_comparison_clarify',
                $context,
                (string) $comparison['message'],
                $this->productClarificationOptions($comparison),
                'talking',
                null,
                ['product_comparison' => $comparison]
            );
        }
        $message = (string) ($comparison['message'] ?? 'I could not complete that comparison.');
        if ($status === 'ready') {
            $message .= "\n\n" . (string) ($comparison['verdict']['guidance'] ?? '');
        }
        return $this->reply(
            $session,
            'ask_technical_question',
            'technical_question',
            $context,
            trim($message),
            $status === 'ready'
                ? $this->productComparisonResultOptions($comparison)
                : $this->technicalQuestionOptions(),
            $status === 'ready' ? 'happy' : 'talking',
            null,
            ['product_comparison' => $comparison]
        );
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $seed
     *  @return array<string, mixed>
     */
    private function startPcFromTechnicalAction(array $session, array $seed): array
    {
        $context = [
            ...$seed,
            'setup_scope' => 'pc_only',
        ];
        if ($seed !== []) {
            $context['pc_preferences_answered'] = true;
        }
        return $this->reply(
            $session,
            'build_pc',
            'pc_budget',
            $context,
            'I carried that technical requirement into a new compatible PC build. What target budget should I build around in LKR?',
            $this->pcBudgetOptions(),
            'talking',
            null,
            ['clear_workspace' => true]
        );
    }

    /** @param array<string, mixed> $session
     *  @return array<string, mixed>
     */
    private function startPcFromComparedProduct(array $session, int $productId): array
    {
        $comparison = (array) ($session['context']['last_product_comparison'] ?? []);
        $selected = null;
        foreach ((array) ($comparison['products'] ?? []) as $product) {
            if ((int) ($product['product_id'] ?? 0) === $productId) {
                $selected = $product;
                break;
            }
        }
        $group = (string) ($selected['pc_component_group'] ?? '');
        if ($selected === null || $group === '') {
            return $this->reply(
                $session,
                'ask_technical_question',
                'technical_question',
                $session['context'],
                'That product cannot be locked into the PC builder. Choose a processor, motherboard, memory kit, graphics card, power supply, storage drive, case or CPU cooler.',
                $this->technicalQuestionOptions(),
                'talking'
            );
        }
        $useCase = (string) ($comparison['use_case'] ?? 'general');
        $workload = match ($useCase) {
            'gaming' => 'gaming_1080p',
            'programming' => 'programming',
            'productivity' => 'office_study',
            'creative_work' => 'video_editing',
            default => null,
        };
        $context = [
            'setup_scope' => 'pc_only',
            'pc_preferences_answered' => true,
            'locked_components' => [$group => $productId],
            'locked_component_names' => [$group => (string) $selected['name']],
        ];
        if ($workload !== null) {
            $context['pc_workload'] = $workload;
        }
        if ($group === 'graphics_card') {
            $context['require_dedicated_gpu'] = true;
        }
        return $this->reply(
            $session,
            'build_pc',
            'pc_budget',
            $context,
            sprintf(
                'I locked %s as the starting %s. The compatibility engine will build every other part around it. What target budget should I use?',
                (string) $selected['name'],
                str_replace('_', ' ', $group)
            ),
            $this->pcBudgetOptions(),
            'happy',
            null,
            ['clear_workspace' => true]
        );
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $navigation
     *  @return array<string, mixed>
     */
    private function navigationPayload(array $session, array $navigation): array
    {
        return $this->sessionPayload($session, [
            'options' => $this->optionsFor((string) $session['state_code']),
            'navigation' => $navigation,
            'mascot_state' => 'happy',
        ]);
    }

    /** @param array<string, mixed> $session
     *  @return array<string, mixed>
     */
    private function advanceProductComparisonClarification(
        array $session,
        string $message,
        ?string $action
    ): array {
        $request = (array) ($session['context']['product_comparison_request'] ?? []);
        $selectedIds = array_map(
            'intval',
            (array) ($session['context']['product_comparison_selected_ids'] ?? [])
        );
        if (
            $action !== null
            && preg_match('/^compare-select:(left|right):(\d+)$/', $action, $match) === 1
        ) {
            $selectedIds[$match[1]] = (int) $match[2];
            return $this->respondProductComparison($session, $request, $selectedIds);
        }
        $newRequest = $this->productComparisons->requestFromQuestion($message);
        if ($newRequest !== null) {
            return $this->respondProductComparison($session, $newRequest);
        }
        return $this->reply(
            $session,
            'ask_technical_question',
            'product_comparison_clarify',
            $session['context'],
            'Choose one of the matching catalogue products below, or type a new comparison using two exact product names.',
            $this->technicalQuestionOptions(),
            'talking'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $entities
     * @return array<string, mixed>
     */
    private function advanceLaptop(
        array $session,
        string $message,
        ?string $action,
        array $entities
    ): array {
        $state = (string) $session['state_code'];
        $context = $this->mergeEntities($session['context'], $entities);

        if ($state === 'laptop_budget') {
            $value = $this->actionValue($action, 'budget');
            if ($value !== null) {
                unset($context['minimum_budget_lkr']);
                $context['max_budget_lkr'] = (float) $value;
            } elseif (!isset($context['max_budget_lkr']) && is_numeric($message)) {
                $context['max_budget_lkr'] = (float) $message;
            }
            if (
                !isset($context['max_budget_lkr'])
                || $context['max_budget_lkr'] < 1_000
                || $context['max_budget_lkr'] > 100_000_000
            ) {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_budget',
                    $context,
                    'Please enter a budget such as “Rs. 300,000” or choose an example.',
                    $this->budgetOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'laptop_use') {
            $value = $this->actionValue($action, 'use');
            if ($value !== null) {
                $context['intended_use'] = $value;
            }
            if (!isset($context['intended_use'])) {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_use',
                    $context,
                    'What will you mainly use the laptop for?',
                    $this->useOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'laptop_ram') {
            $value = $this->actionValue($action, 'ram');
            if ($value !== null) {
                if ($value === 'none') {
                    unset($context['minimum_ram_gb'], $context['maximum_ram_gb']);
                } else {
                    unset($context['maximum_ram_gb']);
                    $context['minimum_ram_gb'] = (float) $value;
                }
                $context['ram_answered'] = true;
            } elseif (isset($entities['minimum_ram_gb'])) {
                $context['ram_answered'] = true;
            } else {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_ram',
                    $context,
                    'Choose a minimum RAM amount, or tell me one such as “at least 16 GB RAM”.',
                    $this->ramOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'laptop_storage') {
            $value = $this->actionValue($action, 'storage');
            if ($value !== null) {
                if ($value === 'none') {
                    unset($context['minimum_storage_gb']);
                } else {
                    $context['minimum_storage_gb'] = (float) $value;
                }
                $context['storage_answered'] = true;
            } elseif (isset($entities['minimum_storage_gb'])) {
                $context['storage_answered'] = true;
            } else {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_storage',
                    $context,
                    'Choose your minimum storage, or tell me a value such as “512 GB SSD”.',
                    $this->storageOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'laptop_gpu') {
            $value = $this->actionValue($action, 'gpu');
            if ($value !== null) {
                unset($context['required_gpu']);
                $context['require_dedicated_gpu'] = false;
                if ($value === 'dedicated') {
                    $context['require_dedicated_gpu'] = true;
                } elseif ($value === 'rtx') {
                    $context['required_gpu'] = 'RTX';
                    $context['require_dedicated_gpu'] = true;
                }
                $context['gpu_answered'] = true;
            } elseif (
                isset($entities['required_gpu'])
                || array_key_exists('require_dedicated_gpu', $entities)
            ) {
                $context['gpu_answered'] = true;
            } else {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_gpu',
                    $context,
                    'Do you have a graphics requirement?',
                    $this->gpuOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'laptop_brand') {
            $value = $this->actionValue($action, 'brand');
            if ($value !== null) {
                if ($value === 'none') {
                    unset($context['preferred_brands']);
                } else {
                    $context['preferred_brands'] = [ucfirst($value)];
                }
                $context['brand_answered'] = true;
            } elseif (isset($entities['preferred_brands'])) {
                $context['brand_answered'] = true;
            } elseif (preg_match('/\b(no preference|any brand|skip)\b/i', $message)) {
                unset($context['preferred_brands']);
                $context['brand_answered'] = true;
            } elseif (preg_match('/^[\p{L}\p{N} .-]{2,80}$/u', trim($message))) {
                $context['preferred_brands'] = [trim($message)];
                $context['brand_answered'] = true;
            } else {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_brand',
                    $context,
                    'Choose a preferred brand, type another brand, or select no preference.',
                    $this->brandOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'laptop_refine') {
            if ($entities === []) {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_refine',
                    $context,
                    'Tell me what to change, for example “increase the budget to Rs. 400,000” or “I need 32 GB RAM”.',
                    [['id' => 'restart', 'label' => 'Start over']],
                    'talking'
                );
            }
            $context = $this->mergeEntities($context, $entities);
            return $this->confirmation($session, $context);
        } elseif ($state === 'laptop_confirm') {
            if ($action === 'confirm:laptop' || preg_match('/\b(yes|correct|confirm|go ahead)\b/i', $message)) {
                return $this->recommend($session, $context);
            }
            if ($action === 'refine' || preg_match('/\b(change|edit|refine|no)\b/i', $message)) {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_refine',
                    $context,
                    'What would you like to change?',
                    [['id' => 'restart', 'label' => 'Start over']],
                    'talking'
                );
            }
            return $this->confirmation($session, $context);
        } elseif ($state === 'laptop_results') {
            if (
                isset($entities['peripheral_categories_add'])
                || isset($entities['peripheral_categories_remove'])
            ) {
                $context = $this->applyLaptopAccessoryAdjustments($context);
                return $this->recommendLaptopAccessories($session, $context);
            }
            if ($action === 'refine') {
                return $this->reply(
                    $session,
                    'recommend_laptop',
                    'laptop_refine',
                    $context,
                    'Tell me what to change in your laptop requirements.',
                    [['id' => 'restart', 'label' => 'Start over']],
                    'talking'
                );
            }
            if ($action === 'run:laptop') {
                return $this->recommend($session, $context);
            }
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_results',
                $context,
                'Your laptop recommendations are still here. Tell me whether to add accessories or refine the laptop requirements.',
                $this->optionsFor('laptop_results'),
                'talking'
            );
        }

        return $this->nextLaptopQuestion($session, $context);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $entities
     * @return array<string, mixed>
     */
    private function advancePc(
        array $session,
        string $message,
        ?string $action,
        array $entities
    ): array {
        $state = (string) $session['state_code'];
        $context = $this->normalizePcContext(
            $this->mergeEntities($session['context'], $entities)
        );

        if ($state === 'pc_budget') {
            $value = $this->actionValue($action, 'pcbudget');
            if ($value !== null && is_numeric($value)) {
                unset(
                    $context['minimum_budget_lkr'],
                    $context['max_budget_lkr'],
                    $context['pc_max_budget_lkr']
                );
                $context['pc_target_budget_lkr'] = (float) $value;
            } elseif (!isset($context['pc_target_budget_lkr']) && is_numeric($message)) {
                $context['pc_target_budget_lkr'] = (float) $message;
            }
            if (
                !isset($context['pc_target_budget_lkr'])
                || $context['pc_target_budget_lkr'] < 50000
                || $context['pc_target_budget_lkr'] > 10000000
            ) {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_budget',
                    $context,
                    'Tell me your target budget, such as “around Rs. 300,000”. You may also give a range such as “Rs. 300,000 to 325,000”.',
                    $this->pcBudgetOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'pc_use') {
            $value = $this->actionValue($action, 'pcuse');
            if ($value !== null) {
                $context['pc_workload'] = $value;
            }
            if (!isset($context['pc_workload'])) {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_use',
                    $context,
                    'What will you mainly use this PC for? Choose a common use or describe another one.',
                    $this->pcUseOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'pc_ram') {
            $value = $this->actionValue($action, 'pcram');
            if ($value !== null && is_numeric($value)) {
                $context['minimum_ram_gb'] = (float) $value;
                $context['maximum_ram_gb'] = (float) $value;
                $context['ram_preference_mode'] = 'exact';
                $context['pc_ram_answered'] = true;
            } elseif (isset($entities['minimum_ram_gb'])) {
                $context['pc_ram_answered'] = true;
            } else {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_ram',
                    $context,
                    'How much RAM should the PC have? You can choose an amount or type something like “at least 16 GB RAM”.',
                    $this->pcRamOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'pc_preferences') {
            $value = $this->actionValue($action, 'pcpref');
            if ($value === 'smart') {
                $context['pc_preferences_answered'] = true;
            } elseif ($value === 'balanced') {
                $context['minimum_ram_gb'] = 32;
                $context['minimum_storage_gb'] = 1000;
                $context['pc_preferences_answered'] = true;
            } elseif ($value === 'gpu') {
                $context['require_dedicated_gpu'] = true;
                $context['pc_preferences_answered'] = true;
            } elseif ($value === 'nogpu') {
                $context['require_dedicated_gpu'] = false;
                $context['pc_preferences_answered'] = true;
            } elseif ($value === 'monitor') {
                $context['setup_scope'] = 'pc_monitor';
                $context['pc_preferences_answered'] = true;
            } elseif ($value === 'setup') {
                $context['setup_scope'] = 'complete_setup';
                $context['pc_preferences_answered'] = true;
            } elseif ($value === 'setup_headset') {
                $context['setup_scope'] = 'complete_setup';
                $context['include_headset'] = true;
                $context['pc_preferences_answered'] = true;
            } elseif (
                preg_match('/\b(smart defaults|no preference|you decide|skip)\b/i', $message)
            ) {
                $context['pc_preferences_answered'] = true;
            } elseif ($this->hasPcComponentPreferenceEntities($entities)) {
                $context['pc_preferences_answered'] = true;
            } else {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_preferences',
                    $context,
                    'Any other major preference? You can mention storage, an Intel or AMD processor, GPU/VGA memory, or a graphics-card model in one message.',
                    $this->pcPreferenceOptions(),
                    'talking'
                );
            }
        } elseif ($state === 'pc_refine') {
            if (in_array($action, ['open:x-board', 'open:pc-builder'], true)) {
                return $this->xBoardNavigation($session, $context);
            }
            if (isset($entities['minimum_budget_lkr'], $entities['max_budget_lkr'])) {
                $context['pc_target_budget_lkr'] = (float) $entities['minimum_budget_lkr'];
                $context['pc_max_budget_lkr'] = (float) $entities['max_budget_lkr'];
            } elseif (isset($entities['max_budget_lkr'])) {
                $context['pc_target_budget_lkr'] = (float) $entities['max_budget_lkr'];
                unset($context['pc_max_budget_lkr'], $context['minimum_budget_lkr']);
            }
            if ($entities === [] && !$this->hasPcPreferenceEntities($entities)) {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_refine',
                    $context,
                    'Tell me one change, such as “raise the budget to Rs. 450,000”, “make it 32 GB RAM”, or “avoid a graphics card”.',
                    [
                        ['id' => 'open:x-board', 'label' => 'Open X Board'],
                        ['id' => 'restart', 'label' => 'Start over'],
                    ],
                    'talking'
                );
            }
            $context = $this->normalizePcContext($this->mergeEntities($context, $entities));
            return $this->pcConfirmation($session, $context);
        } elseif ($state === 'pc_confirm') {
            if (in_array($action, ['open:x-board', 'open:pc-builder'], true)) {
                return $this->xBoardNavigation($session, $context);
            }
            if ($action === 'confirm:pc' || preg_match('/\b(yes|correct|confirm|go ahead|build it)\b/i', $message)) {
                return $this->recommendPc($session, $context);
            }
            if ($action === 'refine' || preg_match('/\b(change|edit|refine|no)\b/i', $message)) {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_refine',
                    $context,
                    'What would you like to change?',
                    [
                        ['id' => 'open:x-board', 'label' => 'Open X Board'],
                        ['id' => 'restart', 'label' => 'Start over'],
                    ],
                    'talking'
                );
            }
            return $this->pcConfirmation($session, $context);
        } elseif ($state === 'pc_results') {
            if (in_array($action, ['open:x-board', 'open:pc-builder'], true)) {
                return $this->xBoardNavigation($session, $context);
            }
            if ($action === 'refine') {
                return $this->reply(
                    $session,
                    'build_pc',
                    'pc_refine',
                    $context,
                    'Tell me what to change, or open X Board to inspect the complete recommendation.',
                    [
                        ['id' => 'open:x-board', 'label' => 'Open X Board'],
                        ['id' => 'restart', 'label' => 'Start over'],
                    ],
                    'talking'
                );
            }
            if ($action === 'run:pc') {
                return $this->recommendPc($session, $context);
            }
            if ($this->hasPcRefinementEntities($entities)) {
                $acknowledgement = $this->pcRefinementAcknowledgement($entities);
                if ($acknowledgement !== null) {
                    $context['pending_refinement_acknowledgement'] = $acknowledgement;
                }
                return $this->recommendPc($session, $context);
            }
            return $this->reply(
                $session,
                'build_pc',
                'pc_results',
                $context,
                "I couldn't identify a PC change in that message, so I kept your current build unchanged. Try something like “add a keyboard”, “remove the mouse”, or “change RAM to 32 GB”.",
                [
                    ['id' => 'refine', 'label' => 'Change requirements'],
                    ['id' => 'run:pc', 'label' => 'Check live prices again'],
                    ['id' => 'restart', 'label' => 'Main menu'],
                ],
                'talking'
            );
        }

        return $this->nextPcQuestion($session, $context);
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function nextPcQuestion(array $session, array $context): array
    {
        $context = $this->normalizePcContext($context);
        if (!isset($context['pc_target_budget_lkr'])) {
            return $this->reply(
                $session,
                'build_pc',
                'pc_budget',
                $context,
                'What target budget should I build around in LKR?',
                $this->pcBudgetOptions(),
                'talking'
            );
        }
        if (!isset($context['pc_workload'])) {
            return $this->reply(
                $session,
                'build_pc',
                'pc_use',
                $context,
                'What will you mainly use this PC for?',
                $this->pcUseOptions(),
                'talking'
            );
        }
        if (!isset($context['pc_ram_answered']) && !isset($context['minimum_ram_gb'])) {
            return $this->reply(
                $session,
                'build_pc',
                'pc_ram',
                $context,
                'How much RAM should the PC have?',
                $this->pcRamOptions(),
                'talking'
            );
        }
        $context['pc_ram_answered'] = true;
        if (!isset($context['pc_preferences_answered'])) {
            return $this->reply(
                $session,
                'build_pc',
                'pc_preferences',
                $context,
                'Any other major preference? I can choose automatically, or you can mention storage, processor or GPU/VGA specifications.',
                $this->pcPreferenceOptions(),
                'talking'
            );
        }
        return $this->pcConfirmation($session, $context);
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function pcConfirmation(array $session, array $context): array
    {
        return $this->reply(
            $session,
            'build_pc',
            'pc_confirm',
            $context,
            'Please confirm: ' . $this->pcSummary($context),
            [
                ['id' => 'confirm:pc', 'label' => 'Build my PC'],
                ['id' => 'refine', 'label' => 'Change something'],
                ['id' => 'open:x-board', 'label' => 'Open X Board'],
            ],
            'talking',
            null,
            ['requirements_summary' => $this->pcSummaryItems($context)]
        );
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function recommendPc(array $session, array $context): array
    {
        $request = $this->pcRequest($context);
        try {
            $result = $this->pcBuilds->recommend($request);
        } catch (HttpException $exception) {
            if ($exception->status < 500) {
                throw $exception;
            }
            return $this->reply(
                $session,
                'build_pc',
                'pc_confirm',
                $context,
                'The PC recommendation engine is temporarily unavailable. Your main requirements are saved, so you can try again.',
                [
                    ['id' => 'confirm:pc', 'label' => 'Try again'],
                    ['id' => 'open:x-board', 'label' => 'Open X Board'],
                ],
                'thinking'
            );
        }

        $builds = is_array($result['recommendations'] ?? null)
            ? $result['recommendations'] : [];
        $outcome = (string) ($result['outcome_status'] ?? 'no_solution');
        $budget = $result['budget_analysis'] ?? [];
        $message = match ($outcome) {
            'recommended' => sprintf(
                'I found %d complete compatible build%s around your budget. I compared live prices and kept only worthwhile stretch options.',
                count($builds),
                count($builds) === 1 ? '' : 's'
            ),
            'stretch_only' => sprintf(
                'Nothing complete fits the exact target, but I found %d compatible option%s within your allowed stretch budget.',
                count($builds),
                count($builds) === 1 ? '' : 's'
            ),
            'nearest_only' => sprintf(
                'The current catalogue cannot meet the ceiling. The nearest complete build starts at Rs. %s, so I am showing the shortfall honestly.',
                number_format((float) ($budget['minimum_viable_budget_lkr'] ?? 0), 0)
            ),
            default => (string) ($result['notice'] ?? 'No safe complete build is available for these requirements.'),
        };
        $acknowledgement = trim((string) ($context['pending_refinement_acknowledgement'] ?? ''));
        unset($context['pending_refinement_acknowledgement']);
        if ($acknowledgement !== '') {
            $message = $acknowledgement . ' ' . $message;
        }
        $context['last_pc_recommendation_id'] = $result['recommendation_id'] ?? null;
        $context['last_recommended_at'] = gmdate(DATE_ATOM);
        return $this->reply(
            $session,
            'build_pc',
            'pc_results',
            $context,
            $message,
            [
                ['id' => 'open:x-board', 'label' => 'Open X Board'],
                ['id' => 'refine', 'label' => 'Change requirements'],
                ['id' => 'run:pc', 'label' => 'Check live prices again'],
                ['id' => 'restart', 'label' => 'Main menu'],
            ],
            $builds !== [] ? 'happy' : 'talking',
            null,
            [
                'pc_recommendation_id' => $result['recommendation_id'] ?? null,
                'pc_build_recommendations' => $builds,
                'pc_budget_analysis' => $budget,
                'pc_outcome_status' => $outcome,
                'pc_constraint_conflicts' => $result['constraint_conflicts'] ?? [],
                'pc_builder_request' => $request,
                'requirements_summary' => $this->pcSummaryItems($context),
            ]
        );
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function xBoardNavigation(array $session, array $context): array
    {
        return $this->sessionPayload($session, [
            'options' => [
                ['id' => 'run:pc', 'label' => 'Check recommendations again'],
                ['id' => 'restart', 'label' => 'Main menu'],
            ],
            'navigation' => [
                'type' => 'x_board',
                'request' => $this->pcRequest($context),
            ],
            'mascot_state' => 'happy',
        ]);
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function pcRequest(array $context): array
    {
        $preferences = [
            'dedicated_graphics' => array_key_exists('require_dedicated_gpu', $context)
                ? (($context['require_dedicated_gpu'] ?? false) ? 'required' : 'avoid')
                : 'auto',
            'minimum_memory_gb' => (int) ($context['minimum_ram_gb'] ?? 0),
            'maximum_memory_gb' => (int) ($context['maximum_ram_gb'] ?? 0),
            'minimum_storage_gb' => (int) ($context['minimum_storage_gb'] ?? 0),
            'maximum_storage_gb' => (int) ($context['maximum_storage_gb'] ?? 0),
            'storage_type' => (string) ($context['storage_type'] ?? 'any'),
            'minimum_vram_gb' => (int) ($context['minimum_vram_gb'] ?? 0),
            'maximum_vram_gb' => (int) ($context['maximum_vram_gb'] ?? 0),
            'gpu_model' => (string) ($context['required_gpu'] ?? ''),
            'processor_family' => (string) ($context['required_processor_family'] ?? ''),
            'processor_model' => (string) ($context['required_processor_model'] ?? ''),
        ];
        $request = [
            'target_budget_lkr' => (float) $context['pc_target_budget_lkr'],
            'workloads' => [(string) $context['pc_workload']],
            'preferences' => $preferences,
            'setup_scope' => (string) ($context['setup_scope'] ?? 'pc_only'),
            'include_headset' => (bool) ($context['include_headset'] ?? false),
            'limit' => 3,
        ];
        if (isset($context['peripheral_categories'])) {
            $request['peripheral_categories'] = array_values(
                (array) $context['peripheral_categories']
            );
        }
        if (isset($context['locked_components'])) {
            $request['locked_components'] = array_map(
                'intval',
                (array) $context['locked_components']
            );
        }
        if (isset($context['pc_max_budget_lkr'])) {
            $request['max_budget_lkr'] = (float) $context['pc_max_budget_lkr'];
        } else {
            $request['flexibility_percent'] = 7.5;
        }
        return $request;
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function normalizePcContext(array $context): array
    {
        if (isset($context['minimum_budget_lkr'], $context['max_budget_lkr'])) {
            $context['pc_target_budget_lkr'] = (float) $context['minimum_budget_lkr'];
            $context['pc_max_budget_lkr'] = (float) $context['max_budget_lkr'];
        } elseif (!isset($context['pc_target_budget_lkr']) && isset($context['max_budget_lkr'])) {
            $context['pc_target_budget_lkr'] = (float) $context['max_budget_lkr'];
        }
        if ((int) ($context['minimum_storage_gb'] ?? 0) === 1024) {
            $context['minimum_storage_gb'] = 1000;
        }
        if ((int) ($context['maximum_storage_gb'] ?? 0) === 1024) {
            $context['maximum_storage_gb'] = 1000;
        }
        $context = $this->applyPeripheralAdjustments($context);
        if (isset($context['minimum_ram_gb'])) {
            $context['pc_ram_answered'] = true;
        }
        if ($this->hasPcComponentPreferenceEntities($context)) {
            $context['pc_preferences_answered'] = true;
        }
        return $context;
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function applyPeripheralAdjustments(array $context): array
    {
        $adds = array_values((array) ($context['peripheral_categories_add'] ?? []));
        $removals = array_values((array) ($context['peripheral_categories_remove'] ?? []));
        if ($adds === [] && $removals === []) {
            return $context;
        }

        $selected = isset($context['peripheral_categories'])
            ? array_values((array) $context['peripheral_categories'])
            : match ((string) ($context['setup_scope'] ?? 'pc_only')) {
                'pc_monitor' => ['monitor'],
                'complete_setup' => ['monitor', 'keyboard', 'mouse'],
                default => [],
            };
        $selected = array_values(array_unique(array_merge($selected, $adds)));
        $selected = array_values(array_diff($selected, $removals));
        $ordered = [];
        foreach (['monitor', 'keyboard', 'mouse', 'headset'] as $category) {
            if (in_array($category, $selected, true)) {
                $ordered[] = $category;
            }
        }

        $context['peripheral_categories'] = $ordered;
        $context['include_headset'] = in_array('headset', $ordered, true);
        $context['setup_scope'] = $ordered === []
            ? 'pc_only'
            : ($ordered === ['monitor'] ? 'pc_monitor' : 'complete_setup');
        unset(
            $context['peripheral_categories_add'],
            $context['peripheral_categories_remove']
        );
        return $context;
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function applyLaptopAccessoryAdjustments(array $context): array
    {
        $adds = array_values((array) ($context['peripheral_categories_add'] ?? []));
        $removals = array_values((array) ($context['peripheral_categories_remove'] ?? []));
        $selected = array_values((array) ($context['laptop_accessory_categories'] ?? []));
        $selected = array_values(array_unique(array_merge($selected, $adds)));
        $selected = array_values(array_diff($selected, $removals));

        $ordered = [];
        foreach (['monitor', 'keyboard', 'mouse', 'headset'] as $category) {
            if (in_array($category, $selected, true)) {
                $ordered[] = $category;
            }
        }
        $context['laptop_accessory_categories'] = $ordered;
        unset(
            $context['peripheral_categories_add'],
            $context['peripheral_categories_remove']
        );
        return $context;
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function recommendLaptopAccessories(array $session, array $context): array
    {
        $categories = array_values((array) ($context['laptop_accessory_categories'] ?? []));
        if ($categories === []) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_results',
                $context,
                'I removed the accessories. Your laptop recommendations are unchanged.',
                $this->optionsFor('laptop_results'),
                'talking',
                null,
                ['laptop_accessories' => [
                    'peripherals' => [],
                    'requested_categories' => [],
                    'selected_category_count' => 0,
                    'peripheral_total_price_lkr' => 0,
                    'complete' => true,
                    'notices' => [],
                ]]
            );
        }

        $workload = match ((string) ($context['intended_use'] ?? 'any')) {
            'gaming' => 'gaming_1080p',
            'content_creation' => 'video_editing',
            'programming' => 'programming',
            'office', 'study' => 'office_study',
            default => 'balanced_general',
        };
        try {
            $accessories = $this->peripherals->recommendSetup(
                'complete_setup',
                $workload,
                (float) ($context['max_budget_lkr'] ?? 0),
                in_array('headset', $categories, true),
                $categories
            );
        } catch (HttpException $exception) {
            if (!in_array($exception->status, [502, 503], true)) {
                throw $exception;
            }
            $accessories = [
                'peripherals' => [],
                'requested_categories' => $categories,
                'selected_category_count' => 0,
                'peripheral_total_price_lkr' => 0,
                'complete' => false,
                'notices' => [
                    'The accessory ranking service is temporarily unavailable. Your laptop recommendations are unchanged.',
                ],
            ];
        }

        $selectedCount = (int) ($accessories['selected_category_count'] ?? 0);
        $requestedCount = count($categories);
        $message = $selectedCount > 0
            ? sprintf(
                'I added %d purpose-matched accessor%s for your %s requirements. Your laptop recommendations are unchanged.',
                $selectedCount,
                $selectedCount === 1 ? 'y' : 'ies',
                str_replace('_', ' ', $workload)
            )
            : 'I kept your laptop recommendations, but no matching in-stock accessories are currently recommendation-eligible.';
        if ($selectedCount > 0 && $selectedCount < $requestedCount) {
            $message .= ' I also listed anything that could not be matched.';
        }

        return $this->reply(
            $session,
            'recommend_laptop',
            'laptop_results',
            $context,
            $message,
            $this->optionsFor('laptop_results'),
            $selectedCount > 0 ? 'happy' : 'talking',
            null,
            ['laptop_accessories' => $accessories]
        );
    }

    /** @param array<string, mixed> $entities */
    private function hasPcPreferenceEntities(array $entities): bool
    {
        foreach ([
            'minimum_ram_gb', 'maximum_ram_gb',
            'minimum_storage_gb', 'maximum_storage_gb', 'storage_type',
            'minimum_vram_gb', 'maximum_vram_gb',
            'required_gpu', 'require_dedicated_gpu',
            'required_processor_family', 'required_processor_model',
            'setup_scope', 'include_headset', 'peripheral_categories',
            'peripheral_categories_add', 'peripheral_categories_remove',
        ] as $field) {
            if (array_key_exists($field, $entities)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $entities */
    private function hasPcComponentPreferenceEntities(array $entities): bool
    {
        foreach ([
            'minimum_storage_gb', 'maximum_storage_gb', 'storage_type',
            'minimum_vram_gb', 'maximum_vram_gb',
            'required_gpu', 'require_dedicated_gpu',
            'required_processor_family', 'required_processor_model',
            'setup_scope', 'include_headset', 'peripheral_categories',
            'peripheral_categories_add', 'peripheral_categories_remove',
            'locked_components',
        ] as $field) {
            if (array_key_exists($field, $entities)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $entities */
    private function hasPcRefinementEntities(array $entities): bool
    {
        if ($this->hasPcPreferenceEntities($entities)) {
            return true;
        }
        foreach (['minimum_budget_lkr', 'max_budget_lkr', 'pc_workload'] as $field) {
            if (array_key_exists($field, $entities)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $entities */
    private function pcRefinementAcknowledgement(array $entities): ?string
    {
        $adds = array_values((array) ($entities['peripheral_categories_add'] ?? []));
        $removals = array_values((array) ($entities['peripheral_categories_remove'] ?? []));
        if ($adds === [] && $removals === []) {
            return null;
        }

        $changes = [];
        if ($adds !== []) {
            $changes[] = 'added a purpose-matched ' . $this->naturalList($adds);
        }
        if ($removals !== []) {
            $changes[] = 'removed the ' . $this->naturalList($removals);
        }
        return 'I ' . $this->naturalList($changes)
            . ' and rebalanced the complete setup around your budget.';
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function nextLaptopQuestion(array $session, array $context): array
    {
        if (!isset($context['max_budget_lkr'])) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_budget',
                $context,
                'What is your maximum laptop budget in LKR?',
                $this->budgetOptions(),
                'talking'
            );
        }
        if (!isset($context['intended_use'])) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_use',
                $context,
                'What will you mainly use the laptop for?',
                $this->useOptions(),
                'talking'
            );
        }
        if (!isset($context['ram_answered']) && !isset($context['minimum_ram_gb'])) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_ram',
                $context,
                'What is the minimum RAM you need?',
                $this->ramOptions(),
                'talking'
            );
        }
        $context['ram_answered'] = true;
        if (
            !isset($context['storage_answered'])
            && !isset($context['minimum_storage_gb'])
        ) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_storage',
                $context,
                'How much minimum storage would you like?',
                $this->storageOptions(),
                'talking'
            );
        }
        $context['storage_answered'] = true;
        if (
            !isset($context['gpu_answered'])
            && !isset($context['required_gpu'])
            && !array_key_exists('require_dedicated_gpu', $context)
        ) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_gpu',
                $context,
                'Do you have a graphics requirement?',
                $this->gpuOptions(),
                'talking'
            );
        }
        $context['gpu_answered'] = true;
        if (
            !isset($context['brand_answered'])
            && !isset($context['preferred_brands'])
        ) {
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_brand',
                $context,
                'Do you prefer a particular laptop brand?',
                $this->brandOptions(),
                'talking'
            );
        }
        $context['brand_answered'] = true;
        return $this->confirmation($session, $context);
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function confirmation(array $session, array $context): array
    {
        return $this->reply(
            $session,
            'recommend_laptop',
            'laptop_confirm',
            $context,
            'Please confirm: ' . $this->summary($context),
            [
                ['id' => 'confirm:laptop', 'label' => 'Find my laptops'],
                ['id' => 'refine', 'label' => 'Change something'],
                ['id' => 'restart', 'label' => 'Start over'],
            ],
            'talking',
            null,
            ['requirements_summary' => $this->summaryItems($context)]
        );
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function recommend(array $session, array $context): array
    {
        $request = [
            'max_budget_lkr' => $context['max_budget_lkr'],
            'intended_use' => $context['intended_use'],
            'require_dedicated_gpu' =>
                (bool) ($context['require_dedicated_gpu'] ?? false),
            'preferred_brands' => $context['preferred_brands'] ?? [],
            'limit' => ($context['intended_use'] ?? '') === 'any' ? 12 : 3,
        ];
        foreach ([
            'minimum_budget_lkr',
            'minimum_ram_gb',
            'maximum_ram_gb',
            'minimum_storage_gb',
            'required_gpu',
            'preferred_screen_size_inches',
        ] as $field) {
            if (isset($context[$field])) {
                $request[$field] = $context[$field];
            }
        }

        try {
            $results = $this->laptops->recommend($request);
        } catch (HttpException $exception) {
            if (!in_array($exception->status, [502, 503], true)) {
                throw $exception;
            }
            return $this->reply(
                $session,
                'recommend_laptop',
                'laptop_confirm',
                $context,
                "My recommendation engine is temporarily unavailable. "
                . "Your requirements are saved, so you can try again.",
                [
                    ['id' => 'confirm:laptop', 'label' => 'Try again'],
                    ['id' => 'restart', 'label' => 'Main menu'],
                ],
                'thinking'
            );
        }

        $recommendations = $results['recommendations'] ?? [];
        $hasResults = is_array($recommendations) && $recommendations !== [];
        $gamingAlternatives = is_array(
            $results['gaming_capable_alternatives'] ?? null
        ) ? $results['gaming_capable_alternatives'] : [];
        $gamingAlternativeCount = max(
            0,
            (int) (
                $results['gaming_capable_alternative_count']
                ?? count($gamingAlternatives)
            )
        );
        $eligibleCount = max(
            0,
            (int) ($results['eligible_candidate_count'] ?? count($recommendations))
        );
        $shownCount = is_array($recommendations) ? count($recommendations) : 0;
        if (($context['intended_use'] ?? '') === 'gaming') {
            if ($hasResults) {
                $message = sprintf(
                    'I found %d dedicated gaming laptop%s in live HEXBAY stock%s.%s',
                    $eligibleCount,
                    $eligibleCount === 1 ? '' : 's',
                    $shownCount < $eligibleCount
                        ? sprintf(' and I am showing the best %d', $shownCount)
                        : '',
                    $gamingAlternativeCount > 0
                        ? sprintf(
                            ' I also found %d separately labelled gaming-capable alternative%s.',
                            $gamingAlternativeCount,
                            $gamingAlternativeCount === 1 ? '' : 's'
                        )
                        : ''
                );
            } elseif ($gamingAlternativeCount > 0) {
                $message = sprintf(
                    'No dedicated gaming laptop satisfies every requirement. '
                    . 'I found %d gaming-capable alternative%s, kept separate so it is not mistaken for a gaming model.',
                    $gamingAlternativeCount,
                    $gamingAlternativeCount === 1 ? '' : 's'
                );
            } else {
                $message = (string) (
                    $results['notice']
                    ?? 'No dedicated gaming laptop currently satisfies every requirement.'
                );
            }
        } else {
            $message = $hasResults
                ? sprintf(
                    'I found %d matching laptop%s in live HEXBAY stock%s.',
                    $eligibleCount,
                    $eligibleCount === 1 ? '' : 's',
                    $shownCount < $eligibleCount
                        ? sprintf(' and I am showing the best %d', $shownCount)
                        : ''
                )
                : (string) (
                    $results['notice']
                    ?? 'No available laptop currently satisfies every requirement.'
                );
        }
        $context['last_recommended_at'] = gmdate(DATE_ATOM);
        return $this->reply(
            $session,
            'recommend_laptop',
            'laptop_results',
            $context,
            $message,
            [
                ['id' => 'refine', 'label' => 'Refine requirements'],
                ['id' => 'run:laptop', 'label' => 'Check again'],
                ['id' => 'restart', 'label' => 'Main menu'],
            ],
            $hasResults ? 'happy' : 'talking',
            null,
            [
                'recommendations' => $recommendations,
                'eligible_candidate_count' => $eligibleCount,
                'shown_recommendation_count' => $shownCount,
                'gaming_capable_alternative_count' => $gamingAlternativeCount,
                'gaming_capable_alternatives' => $gamingAlternatives,
                'relaxation_suggestions' =>
                    $results['relaxation_suggestions'] ?? [],
                'requirements_summary' => $this->summaryItems($context),
            ]
        );
    }

    /** @param array<string, mixed> $session
     *  @return array<string, mixed>
     */
    private function restart(array $session): array
    {
        return $this->reply(
            $session,
            null,
            'awaiting_intent',
            [],
            'No problem—what would you like help with now?',
            $this->intentOptions(),
            'wave',
            null,
            ['clear_workspace' => true]
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $context
     * @param array<int, array{id: string, label: string}> $options
     * @param array<string, mixed>|null $navigation
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function reply(
        array $session,
        ?string $intent,
        string $state,
        array $context,
        string $message,
        array $options,
        string $mascotState,
        ?array $navigation = null,
        array $extra = []
    ): array {
        $this->repository->update(
            (int) $session['id'],
            $intent,
            $state,
            $context
        );
        $messageId = $this->repository->addMessage(
            (int) $session['id'],
            'hexbot',
            $message,
            $intent,
            1.0
        );
        $session['active_intent'] = $intent;
        $session['state_code'] = $state;
        $session['context'] = $context;
        return $this->sessionPayload($session, [
            'reply' => [
                'id' => $messageId,
                'sender' => 'hexbot',
                'message_text' => $message,
            ],
            'options' => $options,
            'navigation' => $navigation,
            'mascot_state' => $mascotState,
            ...$extra,
        ]);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function sessionPayload(array $session, array $extra): array
    {
        return [
            'session' => [
                'public_id' => $session['public_id'],
                'state_code' => $session['state_code'],
                'active_intent' => $session['active_intent'],
                'status' => $session['status'],
                'expires_at' => $session['expires_at'],
            ],
            'messages' => $this->repository->messages((int) $session['id']),
            ...$extra,
        ];
    }

    /** @param array<string, mixed> $context
     *  @param array<string, mixed> $entities
     *  @return array<string, mixed>
     */
    private function mergeEntities(array $context, array $entities): array
    {
        foreach ($entities as $key => $value) {
            $context[$key] = $value;
            if (in_array($key, ['minimum_ram_gb', 'maximum_ram_gb'], true)) {
                $context['ram_answered'] = true;
                $context['pc_ram_answered'] = true;
            } elseif (in_array($key, ['minimum_storage_gb', 'maximum_storage_gb', 'storage_type'], true)) {
                $context['storage_answered'] = true;
            } elseif (in_array($key, [
                'required_gpu', 'require_dedicated_gpu',
                'minimum_vram_gb', 'maximum_vram_gb',
            ], true)) {
                $context['gpu_answered'] = true;
            } elseif ($key === 'preferred_brands') {
                $context['brand_answered'] = true;
            }
        }
        return $context;
    }

    /** @return array<int, array{id: string, label: string}> */
    private function optionsFor(string $state): array
    {
        return match ($state) {
            'awaiting_intent' => $this->intentOptions(),
            'laptop_budget' => $this->budgetOptions(),
            'laptop_use' => $this->useOptions(),
            'laptop_ram' => $this->ramOptions(),
            'laptop_storage' => $this->storageOptions(),
            'laptop_gpu' => $this->gpuOptions(),
            'laptop_brand' => $this->brandOptions(),
            'laptop_confirm' => [
                ['id' => 'confirm:laptop', 'label' => 'Find my laptops'],
                ['id' => 'refine', 'label' => 'Change something'],
                ['id' => 'restart', 'label' => 'Start over'],
            ],
            'laptop_results' => [
                ['id' => 'run:laptop', 'label' => 'Check recommendations again'],
                ['id' => 'refine', 'label' => 'Refine requirements'],
                ['id' => 'restart', 'label' => 'Main menu'],
            ],
            'pc_budget' => $this->pcBudgetOptions(),
            'pc_use' => $this->pcUseOptions(),
            'pc_ram' => $this->pcRamOptions(),
            'pc_preferences' => $this->pcPreferenceOptions(),
            'pc_confirm' => [
                ['id' => 'confirm:pc', 'label' => 'Build my PC'],
                ['id' => 'refine', 'label' => 'Change something'],
                ['id' => 'open:x-board', 'label' => 'Open X Board'],
            ],
            'pc_results' => [
                ['id' => 'open:x-board', 'label' => 'Open X Board'],
                ['id' => 'refine', 'label' => 'Change requirements'],
                ['id' => 'run:pc', 'label' => 'Check live prices again'],
                ['id' => 'restart', 'label' => 'Main menu'],
            ],
            'find_product_ready' => [
                ['id' => 'open:products', 'label' => 'Open product results'],
                ['id' => 'restart', 'label' => 'Ask something else'],
            ],
            'technical_question' => $this->technicalQuestionOptions(),
            'product_comparison_clarify' => $this->technicalQuestionOptions(),
            default => [],
        };
    }

    /** @return array<int, array{id: string, label: string}> */
    private function intentOptions(): array
    {
        return [
            ['id' => 'intent:pc', 'label' => 'Build a compatible PC'],
            ['id' => 'intent:laptop', 'label' => 'Recommend a laptop'],
            ['id' => 'intent:find', 'label' => 'Find a product'],
            ['id' => 'intent:question', 'label' => 'Ask a tech question'],
            ['id' => 'intent:help', 'label' => 'What can you do?'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function technicalQuestionOptions(): array
    {
        return [
            ['id' => 'question:compare', 'label' => 'Compare two products'],
            ['id' => 'question:rtx-gtx', 'label' => 'RTX vs GTX'],
            ['id' => 'question:ddr4-ddr5', 'label' => 'DDR4 vs DDR5'],
            ['id' => 'question:ssd-hdd', 'label' => 'SSD vs HDD'],
            ['id' => 'restart', 'label' => 'Main menu'],
        ];
    }

    /** @param array<string, mixed> $answer
     *  @return array<int, array{id: string, label: string}>
     */
    private function technicalAnswerOptions(array $answer): array
    {
        $actions = (array) ($answer['actions'] ?? []);
        $options = [];
        if (isset($actions['related_search'])) {
            $options[] = [
                'id' => 'tech:find-related',
                'label' => (string) ($actions['related_search']['label'] ?? 'Show matching products'),
            ];
        }
        if (isset($actions['pc_seed'])) {
            $options[] = [
                'id' => 'tech:start-pc',
                'label' => (string) ($actions['pc_seed']['label'] ?? 'Start a PC build'),
            ];
        }
        $options[] = ['id' => 'question:compare', 'label' => 'Compare two products'];
        $options[] = ['id' => 'restart', 'label' => 'Main menu'];
        return $options;
    }

    /** @param array<string, mixed> $comparison
     *  @return array<int, array{id: string, label: string}>
     */
    private function productComparisonResultOptions(array $comparison): array
    {
        $options = [['id' => 'open:x-board', 'label' => 'Open comparison in X Board']];
        $leaderId = (int) ($comparison['verdict']['listed_advantage_product_id'] ?? 0);
        foreach ((array) ($comparison['products'] ?? []) as $product) {
            if (
                $leaderId > 0
                && (int) ($product['product_id'] ?? 0) === $leaderId
                && ($product['pc_component_group'] ?? null) !== null
            ) {
                $options[] = [
                    'id' => 'compare:use-in-pc:' . $leaderId,
                    'label' => 'Use ' . (string) $product['name'] . ' in a PC build',
                ];
                break;
            }
        }
        $options[] = ['id' => 'compare:find-related', 'label' => 'Show similar products'];
        $options[] = ['id' => 'question:compare', 'label' => 'Compare another pair'];
        $options[] = ['id' => 'restart', 'label' => 'Main menu'];
        return $options;
    }

    /** @param array<string, mixed> $comparison
     *  @return array<int, array{id: string, label: string}>
     */
    private function productClarificationOptions(array $comparison): array
    {
        $side = (string) ($comparison['selection_side'] ?? 'left');
        $options = [];
        foreach (array_slice((array) ($comparison['candidates'] ?? []), 0, 4) as $candidate) {
            $options[] = [
                'id' => sprintf(
                    'compare-select:%s:%d',
                    $side,
                    (int) $candidate['product_id']
                ),
                'label' => (string) $candidate['name'],
            ];
        }
        $options[] = ['id' => 'restart', 'label' => 'Main menu'];
        return $options;
    }

    /** @return array<int, array{id: string, label: string}> */
    private function pcBudgetOptions(): array
    {
        return [
            ['id' => 'pcbudget:200000', 'label' => 'Around Rs. 200,000'],
            ['id' => 'pcbudget:300000', 'label' => 'Around Rs. 300,000'],
            ['id' => 'pcbudget:500000', 'label' => 'Around Rs. 500,000'],
            ['id' => 'pcbudget:750000', 'label' => 'Around Rs. 750,000'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function pcUseOptions(): array
    {
        return [
            ['id' => 'pcuse:balanced_general', 'label' => 'General balanced use'],
            ['id' => 'pcuse:gaming_1080p', 'label' => '1080p gaming'],
            ['id' => 'pcuse:gaming_1440p', 'label' => '1440p gaming'],
            ['id' => 'pcuse:programming', 'label' => 'Programming'],
            ['id' => 'pcuse:video_editing', 'label' => 'Video editing'],
            ['id' => 'pcuse:ai_ml', 'label' => 'AI / machine learning'],
            ['id' => 'pcuse:office_study', 'label' => 'Office or study'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function pcRamOptions(): array
    {
        return [
            ['id' => 'pcram:8', 'label' => '8 GB RAM'],
            ['id' => 'pcram:16', 'label' => '16 GB RAM'],
            ['id' => 'pcram:32', 'label' => '32 GB RAM'],
            ['id' => 'pcram:64', 'label' => '64 GB RAM'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function pcPreferenceOptions(): array
    {
        return [
            ['id' => 'pcpref:smart', 'label' => 'Use smart defaults'],
            ['id' => 'pcpref:balanced', 'label' => '32 GB RAM + 1 TB storage'],
            ['id' => 'pcpref:gpu', 'label' => 'Require dedicated graphics'],
            ['id' => 'pcpref:nogpu', 'label' => 'Avoid dedicated graphics'],
            ['id' => 'pcpref:monitor', 'label' => 'Include a monitor'],
            ['id' => 'pcpref:setup', 'label' => 'Complete setup'],
            ['id' => 'pcpref:setup_headset', 'label' => 'Complete setup + headset'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function budgetOptions(): array
    {
        return [
            ['id' => 'budget:150000', 'label' => 'Rs. 150,000'],
            ['id' => 'budget:250000', 'label' => 'Rs. 250,000'],
            ['id' => 'budget:350000', 'label' => 'Rs. 350,000'],
            ['id' => 'budget:500000', 'label' => 'Rs. 500,000'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function useOptions(): array
    {
        return [
            ['id' => 'use:any', 'label' => 'Any use · show everything'],
            ['id' => 'use:study', 'label' => 'Study'],
            ['id' => 'use:office', 'label' => 'Office work'],
            ['id' => 'use:programming', 'label' => 'Programming'],
            ['id' => 'use:gaming', 'label' => 'Gaming'],
            ['id' => 'use:content_creation', 'label' => 'Creative work'],
            ['id' => 'use:engineering', 'label' => 'Engineering'],
            ['id' => 'use:general', 'label' => 'Everyday use'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function ramOptions(): array
    {
        return [
            ['id' => 'ram:8', 'label' => '8 GB'],
            ['id' => 'ram:16', 'label' => '16 GB'],
            ['id' => 'ram:32', 'label' => '32 GB'],
            ['id' => 'ram:none', 'label' => 'No minimum'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function storageOptions(): array
    {
        return [
            ['id' => 'storage:256', 'label' => '256 GB'],
            ['id' => 'storage:512', 'label' => '512 GB'],
            ['id' => 'storage:1024', 'label' => '1 TB'],
            ['id' => 'storage:none', 'label' => 'No minimum'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function gpuOptions(): array
    {
        return [
            ['id' => 'gpu:none', 'label' => 'No preference'],
            ['id' => 'gpu:dedicated', 'label' => 'Dedicated graphics'],
            ['id' => 'gpu:rtx', 'label' => 'NVIDIA RTX'],
        ];
    }

    /** @return array<int, array{id: string, label: string}> */
    private function brandOptions(): array
    {
        return [
            ['id' => 'brand:none', 'label' => 'No preference'],
            ['id' => 'brand:lenovo', 'label' => 'Lenovo'],
            ['id' => 'brand:asus', 'label' => 'Asus'],
            ['id' => 'brand:dell', 'label' => 'Dell'],
            ['id' => 'brand:hp', 'label' => 'HP'],
            ['id' => 'brand:acer', 'label' => 'Acer'],
        ];
    }

    /** @param array<string, mixed> $context */
    private function summary(array $context): string
    {
        return implode('; ', $this->summaryItems($context)) . '.';
    }

    /** @param array<string, mixed> $context */
    private function pcSummary(array $context): string
    {
        return implode('; ', $this->pcSummaryItems($context)) . '.';
    }

    /** @param array<string, mixed> $context
     *  @return array<int, string>
     */
    private function pcSummaryItems(array $context): array
    {
        $target = (float) ($context['pc_target_budget_lkr'] ?? 0);
        $items = ['target around Rs. ' . number_format($target, 0)];
        if (isset($context['pc_max_budget_lkr'])) {
            $items[] = 'may stretch to Rs. '
                . number_format((float) $context['pc_max_budget_lkr'], 0);
        } else {
            $items[] = 'smart stretch up to 7.5% when worthwhile';
        }
        $items[] = $this->pcWorkloadName(
            (string) ($context['pc_workload'] ?? 'balanced_general')
        );
        if (isset($context['minimum_ram_gb'])) {
            $items[] = isset($context['maximum_ram_gb'])
                && (float) $context['maximum_ram_gb'] === (float) $context['minimum_ram_gb']
                ? 'exactly ' . number_format((float) $context['minimum_ram_gb'], 0) . ' GB RAM'
                : 'at least ' . number_format((float) $context['minimum_ram_gb'], 0) . ' GB RAM';
        }
        if (isset($context['required_processor_model'])) {
            $items[] = (string) $context['required_processor_model'] . ' processor';
        } elseif (isset($context['required_processor_family'])) {
            $items[] = $this->processorFamilyName(
                (string) $context['required_processor_family']
            ) . ' processor';
        }
        if (isset($context['minimum_storage_gb'])) {
            $storageAmount = isset($context['maximum_storage_gb'])
                && (float) $context['maximum_storage_gb'] === (float) $context['minimum_storage_gb']
                ? 'exactly ' . number_format((float) $context['minimum_storage_gb'], 0)
                : 'at least ' . number_format((float) $context['minimum_storage_gb'], 0);
            $storageType = $this->storageTypeName((string) ($context['storage_type'] ?? 'any'));
            $items[] = $storageAmount . ' GB'
                . ($storageType === '' ? ' storage' : ' ' . $storageType . ' storage');
        } elseif (isset($context['storage_type'])) {
            $items[] = $this->storageTypeName((string) $context['storage_type']) . ' storage';
        }
        foreach ((array) ($context['locked_component_names'] ?? []) as $group => $name) {
            $items[] = 'locked ' . str_replace('_', ' ', (string) $group)
                . ': ' . (string) $name;
        }
        if (isset($context['required_gpu'])) {
            $items[] = (string) $context['required_gpu'] . ' graphics card';
        }
        if (isset($context['minimum_vram_gb'])) {
            $items[] = isset($context['maximum_vram_gb'])
                && (float) $context['maximum_vram_gb'] === (float) $context['minimum_vram_gb']
                ? 'exactly ' . number_format((float) $context['minimum_vram_gb'], 0) . ' GB VRAM'
                : 'at least ' . number_format((float) $context['minimum_vram_gb'], 0) . ' GB VRAM';
        }
        if (
            !isset($context['required_gpu'])
            && !isset($context['minimum_vram_gb'])
            && array_key_exists('require_dedicated_gpu', $context)
        ) {
            $items[] = $context['require_dedicated_gpu']
                ? 'dedicated graphics required'
                : 'dedicated graphics avoided';
        } elseif (
            !isset($context['required_gpu'])
            && !isset($context['minimum_vram_gb'])
            && !array_key_exists('require_dedicated_gpu', $context)
        ) {
            $items[] = 'graphics chosen automatically for the workload';
        }
        if (isset($context['peripheral_categories'])) {
            $peripherals = array_values((array) $context['peripheral_categories']);
            $items[] = $peripherals === []
                ? 'PC only'
                : 'PC with ' . $this->naturalList($peripherals);
        } else {
            $items[] = match ((string) ($context['setup_scope'] ?? 'pc_only')) {
                'pc_monitor' => 'PC with monitor',
                'complete_setup' => ($context['include_headset'] ?? false)
                    ? 'complete setup plus headset'
                    : 'complete setup (monitor, keyboard and mouse)',
                default => 'PC only',
            };
        }
        return $items;
    }

    /** @param array<int, string> $items */
    private function naturalList(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private function pcWorkloadName(string $code): string
    {
        return match ($code) {
            'gaming_1080p' => '1080p gaming',
            'gaming_1440p' => '1440p gaming',
            'gaming_4k' => '4K gaming',
            'ai_ml' => 'AI and machine learning',
            'three_d_rendering' => '3D rendering',
            'cad_engineering' => 'CAD and engineering',
            'home_server_nas' => 'home server or NAS',
            'quiet_efficiency' => 'quiet and efficient use',
            'upgrade_focused' => 'upgrade-focused use',
            default => str_replace('_', ' ', $code),
        };
    }

    private function processorFamilyName(string $code): string
    {
        if (preg_match('/^intel_core_i([3579])$/', $code, $match) === 1) {
            return 'Intel Core i' . $match[1];
        }
        if (preg_match('/^amd_ryzen_([3579])$/', $code, $match) === 1) {
            return 'AMD Ryzen ' . $match[1];
        }
        return str_replace('_', ' ', $code);
    }

    private function storageTypeName(string $code): string
    {
        return match ($code) {
            'ssd' => 'SSD',
            'nvme_ssd' => 'NVMe SSD',
            'sata_ssd' => 'SATA SSD',
            'hdd' => 'HDD',
            default => '',
        };
    }

    /** @param array<string, mixed> $context
     *  @return array<int, string>
     */
    private function summaryItems(array $context): array
    {
        $items = [];
        if (isset($context['minimum_budget_lkr'])) {
            $items[] = 'budget Rs. '
                . number_format((float) $context['minimum_budget_lkr'], 0)
                . '–'
                . number_format((float) ($context['max_budget_lkr'] ?? 0), 0);
        } else {
            $items[] = 'budget up to Rs. '
                . number_format((float) ($context['max_budget_lkr'] ?? 0), 0);
        }
        $intendedUse = (string) ($context['intended_use'] ?? 'general');
        $items[] = $intendedUse === 'any'
            ? 'any use (no use-case filter)'
            : str_replace('_', ' ', $intendedUse) . ' use';
        if (isset($context['minimum_ram_gb'])) {
            $items[] = isset($context['maximum_ram_gb'])
                ? number_format((float) $context['minimum_ram_gb'], 0)
                    . '–'
                    . number_format((float) $context['maximum_ram_gb'], 0)
                    . ' GB RAM'
                : 'at least '
                    . number_format((float) $context['minimum_ram_gb'], 0)
                    . ' GB RAM';
        }
        if (isset($context['minimum_storage_gb'])) {
            $items[] = 'at least '
                . number_format((float) $context['minimum_storage_gb'], 0)
                . ' GB storage';
        }
        if (isset($context['required_gpu'])) {
            $items[] = (string) $context['required_gpu'] . ' graphics';
        } elseif (($context['require_dedicated_gpu'] ?? false) === true) {
            $items[] = 'dedicated graphics';
        }
        if (isset($context['preferred_brands'])) {
            $items[] = 'preferred '
                . implode(' or ', (array) $context['preferred_brands']);
        }
        return $items;
    }

    private function intentFrom(?string $action, string $detected): string
    {
        return match ($action) {
            'intent:pc' => 'build_pc',
            'intent:laptop' => 'recommend_laptop',
            'intent:find' => 'find_product',
            'intent:question' => 'ask_technical_question',
            'intent:help' => 'help',
            default => $detected,
        };
    }

    private function actionValue(?string $action, string $prefix): ?string
    {
        if ($action === null || !str_starts_with($action, $prefix . ':')) {
            return null;
        }
        return substr($action, strlen($prefix) + 1);
    }

    private function sessionKey(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[a-zA-Z0-9_-]{20,100}$/', $value) !== 1) {
            throw new HttpException(422, 'The HexBot browser session is invalid.');
        }
        return $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
