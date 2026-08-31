<?php
declare(strict_types=1);

namespace Hexbay\Services;

final class TechnicalQuestionService
{
    /** @return array<string, mixed> */
    public function answer(string $question): array
    {
        $text = trim(mb_strtolower($question));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        if (mb_strlen($text) < 3) {
            return $this->unsupported(
                'Please enter a complete hardware question.',
                'For example: “RTX vs GTX”, “DDR4 vs DDR5”, or “SSD vs HDD”.'
            );
        }

        if ($this->asksForExactModelComparison($text)) {
            return $this->unsupported(
                'Exact model comparison is not available in this first version.',
                'I can explain the technology families safely, but comparing two exact products requires their live catalogue specifications and prices. That will be added as the product-comparison phase.'
            );
        }

        if ($this->containsAny($text, ['rtx', 'gtx'])) {
            return $this->supported(
                'RTX vs GTX graphics cards',
                'RTX and GTX are NVIDIA graphics-card families. RTX cards add dedicated hardware and features for ray tracing and AI-assisted workloads, while GTX is an older family focused on traditional graphics rendering.',
                [
                    'RTX generally provides newer features such as hardware ray tracing and DLSS support.',
                    'GTX can still handle traditional games, but it lacks the full RTX feature set.',
                    'The family name is not enough to measure speed: generation, model number, VRAM, power limits and cooling still matter.',
                ],
                'Choose RTX when modern gaming, ray tracing, content creation or AI features matter. Choose GTX only when a particular older card provides acceptable performance at a clearly better price.',
                'An RTX label does not automatically make every RTX card faster than every GTX card.'
            );
        }

        if (preg_match('/\bddr\s*[345]\b/u', $text) === 1) {
            return $this->supported(
                'DDR3 vs DDR4 vs DDR5 memory',
                'DDR3, DDR4 and DDR5 are different memory generations. They are physically and electrically different, so a computer must use the generation supported by its processor and motherboard.',
                [
                    'DDR3 is a legacy generation used by much older platforms.',
                    'DDR4 remains capable and can offer good value on supported systems.',
                    'DDR5 provides higher bandwidth and is the current choice for newer platforms, but capacity, speed and latency still affect the result.',
                ],
                'For a new build, choose the memory generation required by the selected motherboard and processor. Never buy DDR5 for a DDR4 motherboard, or DDR4 for a DDR5 motherboard.',
                'DDR generations are not interchangeable, even when the capacity is the same.'
            );
        }

        if ($this->containsAny($text, ['intel', 'amd', 'ryzen', 'core i3', 'core i5', 'core i7', 'core i9'])) {
            if ($this->containsAny($text, ['core i5', 'core i7', 'ryzen 5', 'ryzen 7'])) {
                return $this->supported(
                    'Mainstream vs higher-tier processors',
                    'Core i5 and Ryzen 5 are usually mainstream performance tiers, while Core i7 and Ryzen 7 are generally higher tiers with more multicore capability within a comparable generation.',
                    [
                        'The mainstream tier is often the stronger value for gaming, study and ordinary programming.',
                        'The higher tier can help with rendering, video editing, large compilations, streaming and heavy multitasking.',
                        'Generation and exact model matter more than the tier name alone; a newer i5 or Ryzen 5 can outperform an older higher-tier processor.',
                    ],
                    'Compare the exact models, workload, platform cost, power use and upgrade path before deciding.',
                    'Intel and AMD tier names are marketing families, not guaranteed performance scores.'
                );
            }
            return $this->supported(
                'Intel vs AMD processors',
                'Neither Intel nor AMD is universally better. The correct choice depends on the exact processor, workload, total platform cost, power use and available upgrade path.',
                [
                    'Gaming performance depends on the specific CPU and graphics-card pairing.',
                    'Productivity performance depends on the application and whether it benefits from more cores or stronger single-core speed.',
                    'Motherboard price, memory generation, cooling and future CPU support affect the real value of the platform.',
                ],
                'Choose by exact model and intended use rather than choosing only by brand.',
                'A brand-level comparison cannot reliably identify the faster processor.'
            );
        }

        if ($this->containsAny($text, ['ssd', 'hdd', 'hard drive', 'nvme', 'sata'])) {
            return $this->supported(
                'SSD vs HDD storage',
                'An SSD stores data electronically and is much faster and more responsive than a mechanical HDD. An HDD normally offers more capacity for the same price.',
                [
                    'Use an SSD for the operating system, applications, games and active project files.',
                    'Use an HDD when low-cost bulk storage, archives or backups are the priority.',
                    'NVMe SSDs use PCIe and are generally faster than SATA SSDs, but real-world benefit depends on the workload.',
                ],
                'For most computers, use an SSD as the primary drive and add an HDD only when inexpensive bulk capacity is needed.',
                'Interface support and available motherboard slots must be checked before buying an NVMe drive.'
            );
        }

        if ($this->containsAny($text, ['integrated graphics', 'integrated gpu', 'dedicated graphics', 'dedicated gpu'])) {
            return $this->supported(
                'Integrated vs dedicated graphics',
                'Integrated graphics share system resources and are built into the processor or platform. A dedicated graphics card has its own graphics processor and memory.',
                [
                    'Integrated graphics suit office work, study, media playback and light creative work while using less power.',
                    'Dedicated graphics are better for demanding games, 3D rendering, GPU computing and heavier video work.',
                    'A dedicated card adds cost, heat and power requirements and must be compatible with the case and power supply.',
                ],
                'Choose integrated graphics for efficiency and ordinary use; choose dedicated graphics when the workload genuinely benefits from GPU performance.',
                'Some processors have no integrated graphics, so the computer may require a dedicated card for display output.'
            );
        }

        if (str_contains($text, 'mouse')) {
            return $this->supported(
                'Choosing the right mouse',
                'A better mouse is the one that fits the user’s hand, workload and connection needs—not simply the model with the largest sensor number.',
                [
                    'Gaming users may value low latency, reliable sensors, low weight and suitable polling rates.',
                    'Productivity users may value comfort, quiet buttons, wireless reliability, scrolling and programmable controls.',
                    'Shape, grip style and size can matter more than headline specifications during long use.',
                ],
                'Choose the purpose and comfort requirements first, then compare verified specifications and live price.',
                'This version can explain how to choose, but it does not yet declare one exact mouse model better than another.'
            );
        }

        if (str_contains($text, 'keyboard')) {
            return $this->supported(
                'Mechanical vs membrane keyboards',
                'Mechanical keyboards use individual switches, while membrane keyboards use a pressure membrane beneath the keys.',
                [
                    'Mechanical keyboards offer different switch feels, easier key replacement and often stronger durability.',
                    'Membrane keyboards are usually quieter, lighter and less expensive.',
                    'Layout, switch feel, noise, ergonomics and connectivity matter more than the category name alone.',
                ],
                'Choose mechanical when switch feel and customization matter; choose membrane when quiet operation, simplicity and price are priorities.',
                'Mechanical does not automatically mean more comfortable for every user.'
            );
        }

        if ($this->containsAny($text, ['power supply', 'psu', 'wattage', '80 plus'])) {
            return $this->supported(
                'Choosing a PC power supply',
                'A power supply must provide enough safe capacity and the correct connectors for the complete system. Wattage alone does not describe quality.',
                [
                    'GPU and CPU peak power, connector requirements and upgrade headroom affect the required capacity.',
                    'Efficiency ratings describe efficiency levels, not the complete electrical quality of a unit.',
                    'Protections, platform quality, warranty and reputable testing are important purchasing factors.',
                ],
                'Select the processor and graphics card first, then let the compatibility engine calculate capacity and connector requirements.',
                'Do not choose a power supply only because it advertises a large wattage number.'
            );
        }

        return $this->unsupported(
            'I do not have a controlled answer for that topic yet.',
            'Try asking about RTX vs GTX, DDR generations, Intel vs AMD, processor tiers, SSD vs HDD, integrated vs dedicated graphics, mice, keyboards or power supplies.'
        );
    }

    /** @param array<string, mixed> $answer */
    public function chatMessage(array $answer): string
    {
        $message = (string) ($answer['summary'] ?? '');
        $points = array_values((array) ($answer['points'] ?? []));
        if ($points !== []) {
            $message .= "\n\nKey differences:\n• " . implode("\n• ", $points);
        }
        if (($answer['recommendation'] ?? '') !== '') {
            $message .= "\n\nMy guidance: " . $answer['recommendation'];
        }
        if (($answer['caution'] ?? '') !== '') {
            $message .= "\n\nImportant: " . $answer['caution'];
        }
        return trim($message);
    }

    /** @return array<string, mixed> */
    private function supported(
        string $title,
        string $summary,
        array $points,
        string $recommendation,
        string $caution
    ): array {
        return [
            'supported' => true,
            'title' => $title,
            'summary' => $summary,
            'points' => $points,
            'recommendation' => $recommendation,
            'caution' => $caution,
            'actions' => $this->actionsFor($title),
        ];
    }

    /** @return array<string, mixed> */
    private function unsupported(string $summary, string $recommendation): array
    {
        return [
            'supported' => false,
            'title' => 'Question needs a safer answer',
            'summary' => $summary,
            'points' => [],
            'recommendation' => $recommendation,
            'caution' => 'I will not guess specifications or benchmark results that are not in HEXBAY’s controlled knowledge.',
            'actions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function actionsFor(string $title): array
    {
        $actions = match ($title) {
            'RTX vs GTX graphics cards' => [
                'related_search' => ['query' => 'RTX', 'label' => 'Show RTX products'],
                'pc_seed' => [
                    'label' => 'Build a PC with dedicated graphics',
                    'context' => ['require_dedicated_gpu' => true],
                ],
            ],
            'DDR3 vs DDR4 vs DDR5 memory' => [
                'related_search' => ['query' => 'DDR5', 'label' => 'Show DDR5 products'],
            ],
            'Mainstream vs higher-tier processors', 'Intel vs AMD processors' => [
                'related_search' => ['query' => 'processor', 'label' => 'Show processors'],
                'pc_seed' => [
                    'label' => 'Start a compatible PC build',
                    'context' => [],
                ],
            ],
            'SSD vs HDD storage' => [
                'related_search' => ['query' => 'SSD', 'label' => 'Show SSD products'],
            ],
            'Integrated vs dedicated graphics' => [
                'related_search' => ['query' => 'graphics card', 'label' => 'Show graphics cards'],
                'pc_seed' => [
                    'label' => 'Build a PC with dedicated graphics',
                    'context' => ['require_dedicated_gpu' => true],
                ],
            ],
            'Choosing the right mouse' => [
                'related_search' => ['query' => 'mouse', 'label' => 'Show mice'],
            ],
            'Mechanical vs membrane keyboards' => [
                'related_search' => ['query' => 'keyboard', 'label' => 'Show keyboards'],
            ],
            'Choosing a PC power supply' => [
                'related_search' => ['query' => 'power supply', 'label' => 'Show power supplies'],
                'pc_seed' => [
                    'label' => 'Start a compatible PC build',
                    'context' => [],
                ],
            ],
            default => [],
        };
        return $actions;
    }

    private function asksForExactModelComparison(string $text): bool
    {
        if (preg_match_all('/\b(?:rtx|gtx|rx)\s*\d{3,4}(?:\s*ti|\s*super)?\b/u', $text) >= 2) {
            return true;
        }
        if (preg_match_all('/\b(?:i[3579]-?\d{4,5}[a-z]{0,2}|ryzen\s*[3579]\s*\d{4}[a-z]{0,2})\b/u', $text) >= 2) {
            return true;
        }
        return preg_match('/\b(?:compare|versus|vs\.?|better)\b/u', $text) === 1
            && preg_match('/\b(?:mouse|keyboard|headset|monitor)\b/u', $text) === 1
            && preg_match('/\b(?:logitech|razer|corsair|steelseries|asus|hyperx|keyforge|pointarc|sonicdock)\b/u', $text) === 1;
    }

    /** @param array<int, string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }
}
