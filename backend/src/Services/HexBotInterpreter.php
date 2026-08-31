<?php
declare(strict_types=1);

namespace Hexbay\Services;

final class HexBotInterpreter
{
    /** @return array{intent: string, confidence: float, entities: array<string, mixed>} */
    public function interpret(string $message): array
    {
        $text = trim(mb_strtolower($message));
        $text = (string) preg_replace(
            '/\b(a|an|the)(keyboard|mouse|monitor|headset)\b/u',
            '$1 $2',
            $text
        );
        $entities = $this->entities($text);
        [$intent, $confidence] = $this->intent($text, $entities);

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'entities' => $entities,
        ];
    }

    /** @return array{0: string, 1: float} */
    private function intent(string $text, array $entities): array
    {
        if ($this->matches($text, [
            '/\b(build|assemble|create)\b.*\b(pc|computer|desktop)\b/u',
            '/\bpc\s*build\b/u',
            '/\b(?:need|want|looking for)\b.*\b(?:pc|desktop)\b/u',
            '/\b(?:complete|full)\s+(?:gaming\s+|office\s+|work\s+)?setup\b/u',
        ])) {
            return ['build_pc', 0.96];
        }
        if ($this->matches($text, [
            '/\b(?:ask|answer)\b.*\b(?:tech|technical|hardware)\b.*\bquestion\b/u',
            '/\b(?:tech|technical|hardware)\s+question\b/u',
            '/\b(?:what|which|explain|difference|better|compare)\b.*\b(?:rtx|gtx|ddr\s*[345]|ssd|hdd|nvme|sata|integrated\s+graphics|dedicated\s+graphics|core\s+i[3579]|ryzen\s*[3579]|mouse|keyboard|power\s+supply|psu)\b/u',
            '/\b(?:rtx|gtx|ddr\s*[345]|ssd|hdd|nvme|sata|integrated\s+graphics|dedicated\s+graphics|core\s+i[3579]|ryzen\s*[3579]|mouse|keyboard|power\s+supply|psu)\b.*\b(?:what|which|explain|difference|better|compare|versus|vs\.?)\b/u',
        ])) {
            return ['ask_technical_question', 0.94];
        }
        if ($this->matches($text, [
            '/\b(compare|comparison|versus|vs\.?)\b/u',
        ])) {
            return ['compare_products', 0.90];
        }
        if ($this->matches($text, [
            '/\b(laptop|notebook)\b/u',
        ])) {
            return ['recommend_laptop', 0.96];
        }
        if ($this->looksLikePcSpecificationRequest($text, $entities)) {
            return ['build_pc', 0.95];
        }
        if ($this->matches($text, [
            '/\b(gaming|study|programming|office)\b.*\bcomputer\b/u',
            '/\b(?:ram|budget)\b.*\b(?:ram|budget)\b/u',
        ])) {
            return ['recommend_laptop', 0.94];
        }
        if ($this->matches($text, [
            '/\b(compatible|compatibility|work with|fits?)\b/u',
            '/\b(find|need)\b.*\b(motherboard|psu|power supply|desktop ram)\b/u',
        ])) {
            return ['compatible_hardware', 0.91];
        }
        if ($this->matches($text, [
            '/\b(help|what can you do|options|menu)\b/u',
        ])) {
            return ['help', 0.98];
        }
        if ($this->matches($text, [
            '/\b(find|search|show|looking for|buy|need)\b/u',
        ])) {
            return ['find_product', 0.75];
        }
        return ['unknown', 0.20];
    }

    /** @return array<string, mixed> */
    private function entities(string $text): array
    {
        /** @var array<string, mixed> $entities */
        $entities = $this->moneyEntities($text);

        $pcWorkloads = [
            'gaming_4k' => ['4k gaming', 'gaming at 4k', '4k games'],
            'gaming_1440p' => ['1440p gaming', 'gaming at 1440p', '1440p games'],
            'gaming_1080p' => ['1080p gaming', 'gaming pc', 'gaming computer', 'play games', 'gaming'],
            'software_compilation' => ['software compilation', 'large compilations', 'compile code'],
            'virtual_machines' => ['virtual machines', 'virtual machine', 'multiple vms', 'vm lab'],
            'graphic_design' => ['graphic design', 'photoshop', 'illustrator'],
            'video_editing' => ['video editing', 'premiere pro', 'davinci resolve'],
            'live_streaming' => ['live streaming', 'game streaming', 'stream my games'],
            'music_production' => ['music production', 'audio production', 'fl studio', 'ableton'],
            'three_d_rendering' => ['3d rendering', '3d modelling', 'blender rendering'],
            'cad_engineering' => ['cad', 'solidworks', 'engineering simulation'],
            'ai_ml' => ['ai/ml', 'machine learning', 'deep learning', 'local ai'],
            'home_server_nas' => ['home server', 'nas', 'file server'],
            'quiet_efficiency' => ['quiet pc', 'silent pc', 'energy efficient', 'low power pc'],
            'upgrade_focused' => ['upgrade focused', 'future upgrades', 'future proof', 'upgradeable'],
            'programming' => ['programming', 'coding', 'software development', 'developer'],
            'office_study' => ['office work', 'office pc', 'study', 'student work'],
            'balanced_general' => ['general use', 'everyday use', 'balanced pc', 'normal use'],
        ];
        foreach ($pcWorkloads as $value => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $entities['pc_workload'] = $value;
                    break 2;
                }
            }
        }

        $uses = [
            'any' => [
                'any use',
                'any purpose',
                'no specific use',
                'all uses',
                'everything',
                'show all products',
                'show everything',
            ],
            'gaming' => ['gaming', 'games', 'game'],
            'study' => ['study', 'student', 'school', 'university', 'classes'],
            'office' => ['office', 'business', 'documents', 'meetings'],
            'programming' => ['programming', 'coding', 'developer', 'development'],
            'content_creation' => [
                'content creation',
                'video editing',
                'photo editing',
                'graphic design',
                'creative work',
                'editing',
            ],
            'engineering' => ['engineering', 'cad', 'simulation', 'solidworks'],
            'general' => ['general', 'everyday', 'browsing', 'streaming'],
        ];
        foreach ($uses as $value => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $entities['intended_use'] = $value;
                    break 2;
                }
            }
        }

        if (
            preg_match(
                '/\b(\d{1,3})\s*(?:gb)?\s*(?:-|–|—|to)\s*(\d{1,3})\s*gb\s*(?:of\s*)?ram\b/u',
                $text,
                $match
            )
            || preg_match(
                '/\bram\s*(?:of\s*)?(\d{1,3})\s*(?:gb)?\s*(?:-|–|—|to)\s*(\d{1,3})\s*gb\b/u',
                $text,
                $match
            )
        ) {
            $first = (int) $match[1];
            $second = (int) $match[2];
            $entities['minimum_ram_gb'] = min($first, $second);
            $entities['maximum_ram_gb'] = max($first, $second);
            $entities['ram_preference_mode'] = 'range';
        } elseif (
            preg_match(
                '/\b(?:at\s+least|minimum(?:\s+of)?)\s+(\d{1,3})\s*(?:gb|gigs?)\s*(?:of\s*)?(?:ram|memory)\b/u',
                $text,
                $match
            )
            || preg_match(
                '/\b(?:ram|memory)\s*(?:of\s*)?(?:at\s+least|minimum)\s+(\d{1,3})\s*(?:gb|gigs?)\b/u',
                $text,
                $match
            )
        ) {
            $entities['minimum_ram_gb'] = (int) $match[1];
            $entities['ram_preference_mode'] = 'minimum';
        } elseif (
            preg_match(
                '/\b(\d{1,3})\s*(?:gb|gigs?)\s*(?:of\s*)?(?:ram|memory)\b/u',
                $text,
                $match
            )
            || preg_match(
                '/\b(?:ram|memory)\s*(?:of\s*)?(\d{1,3})\s*(?:gb|gigs?)\b/u',
                $text,
                $match
            )
        ) {
            $entities['minimum_ram_gb'] = (int) $match[1];
            $entities['maximum_ram_gb'] = (int) $match[1];
            $entities['ram_preference_mode'] = 'exact';
        }

        if (preg_match('/\b(?:complete|full|pc|computer|desktop)\s+(?:gaming\s+|office\s+|work\s+)?setup\b/u', $text)) {
            $entities['setup_scope'] = 'complete_setup';
        } elseif (preg_match('/\b(?:pc|computer|desktop)\s*(?:with|and|\+)\s*(?:a\s+)?monitor\b/u', $text)) {
            $entities['setup_scope'] = 'pc_monitor';
        } elseif (preg_match('/\b(?:pc|tower|desktop)\s+only\b/u', $text)) {
            $entities['setup_scope'] = 'pc_only';
        }
        $peripheralAdds = [];
        $peripheralRemovals = [];
        foreach (['monitor', 'keyboard', 'mouse', 'headset'] as $peripheral) {
            if (preg_match('/\b' . $peripheral . 's?\b/u', $text) !== 1) {
                continue;
            }
            $removeBefore = preg_match(
                '/\b(?:remove|exclude|without|skip|no)\s+(?:a\s+|an\s+|the\s+)?' . $peripheral . 's?\b/u',
                $text
            ) === 1;
            $removeAfter = preg_match(
                '/\b' . $peripheral . 's?\b[^.]{0,25}\b(?:remove|excluded?|off)\b/u',
                $text
            ) === 1;
            if ($removeBefore || $removeAfter) {
                $peripheralRemovals[] = $peripheral;
            } else {
                $peripheralAdds[] = $peripheral;
            }
        }
        if ($peripheralAdds !== []) {
            $entities['peripheral_categories_add'] = $peripheralAdds;
        }
        if ($peripheralRemovals !== []) {
            $entities['peripheral_categories_remove'] = $peripheralRemovals;
        }
        if (in_array('headset', $peripheralAdds, true)) {
            $entities['include_headset'] = true;
        } elseif (in_array('headset', $peripheralRemovals, true)) {
            $entities['include_headset'] = false;
        }
        if (
            preg_match(
                '/\b(?:(at\s+least|minimum(?:\s+of)?|exactly)\s+)?(\d+(?:\.\d+)?)\s*(tb|gb)\s*(?:of\s*)?(?:nvme(?:\s+ssd)?|sata\s+ssd|ssd|storage|drive|hard\s+drive|hdd)\b/u',
                $text,
                $match
            )
        ) {
            $value = (float) $match[2];
            $capacity = (int) round($match[3] === 'tb' ? $value * 1024 : $value);
            $entities['minimum_storage_gb'] = $capacity;
            if (($match[1] ?? '') === 'exactly' || ($match[1] ?? '') === '') {
                $entities['maximum_storage_gb'] = $capacity;
                $entities['storage_preference_mode'] = 'exact';
            } else {
                $entities['storage_preference_mode'] = 'minimum';
            }
        }
        if (preg_match('/\bnvme(?:\s+ssd)?\b/u', $text) === 1) {
            $entities['storage_type'] = 'nvme_ssd';
        } elseif (preg_match('/\bsata\s+ssd\b/u', $text) === 1) {
            $entities['storage_type'] = 'sata_ssd';
        } elseif (preg_match('/\b(?:hard\s+drive|hdd)\b/u', $text) === 1) {
            $entities['storage_type'] = 'hdd';
        } elseif (preg_match('/\bssd\b/u', $text) === 1) {
            $entities['storage_type'] = 'ssd';
        }
        if (
            preg_match(
                '/\b(\d{2}(?:\.\d)?)\s*(?:inch|inches|")\b/u',
                $text,
                $match
            )
        ) {
            $entities['preferred_screen_size_inches'] = (float) $match[1];
        }

        if (
            preg_match(
                '/\b(?:(exactly|at\s+least|minimum(?:\s+of)?)\s+)?(\d{1,2})\s*(?:gb|gigs?)\s*(?:of\s*)?(?:vram|vga|graphics?(?:\s+card)?|graphic\s+card|video\s+card)(?:\s+memory)?\b/u',
                $text,
                $match
            )
            || preg_match(
                '/\b(?:vram|vga|graphics?(?:\s+card)?|graphic\s+card|video\s+card)(?:\s+with|\s+of|\s+memory)?\s*(?:(exactly|at\s+least|minimum(?:\s+of)?)\s+)?(\d{1,2})\s*(?:gb|gigs?)\b/u',
                $text,
                $match
            )
        ) {
            $vram = (int) $match[2];
            $entities['minimum_vram_gb'] = $vram;
            $entities['vram_preference_mode'] = ($match[1] ?? '') === 'exactly'
                ? 'exact' : 'minimum';
            if ($entities['vram_preference_mode'] === 'exact') {
                $entities['maximum_vram_gb'] = $vram;
            }
            $entities['require_dedicated_gpu'] = true;
        }

        if (preg_match('/\b(rtx\s*\d{3,4}|rtx)\b/u', $text, $match)) {
            $entities['required_gpu'] = strtoupper(
                preg_replace('/\s+/', ' ', $match[1])
            );
            $entities['require_dedicated_gpu'] = true;
        } elseif (preg_match('/\b(gtx\s*\d{3,4}|gtx)\b/u', $text, $match)) {
            $entities['required_gpu'] = strtoupper(
                preg_replace('/\s+/', ' ', $match[1])
            );
            $entities['require_dedicated_gpu'] = true;
        } elseif (preg_match('/\b(rx\s*\d{3,4}|radeon)\b/u', $text, $match)) {
            $entities['required_gpu'] = strtoupper(
                preg_replace('/\s+/', ' ', $match[1])
            );
            $entities['require_dedicated_gpu'] = true;
        } elseif (str_contains($text, 'dedicated graphics')) {
            $entities['require_dedicated_gpu'] = true;
        } elseif (
            str_contains($text, 'integrated graphics')
            || str_contains($text, 'no dedicated')
            || str_contains($text, 'without dedicated')
            || str_contains($text, 'avoid dedicated')
            || str_contains($text, 'no graphics card')
        ) {
            $entities['require_dedicated_gpu'] = false;
        }

        if (
            preg_match(
                '/\b(?:intel\s+)?(?:core\s+)?i([3579])(?:\s*[- ]\s*(\d{4,5}[a-z]{0,2}))?\s*(?:processor|cpu)?\b/u',
                $text,
                $match
            ) === 1
        ) {
            $entities['required_processor_family'] = 'intel_core_i' . $match[1];
            if (($match[2] ?? '') !== '') {
                $entities['required_processor_model'] = 'Core i' . $match[1] . '-' . strtoupper($match[2]);
            }
        } elseif (
            preg_match(
                '/\b(?:amd\s+)?ryzen\s*([3579])(?:\s*[- ]?\s*(\d{4,5}[a-z]{0,2}))?\s*(?:processor|cpu)?\b/u',
                $text,
                $match
            ) === 1
        ) {
            $entities['required_processor_family'] = 'amd_ryzen_' . $match[1];
            if (($match[2] ?? '') !== '') {
                $entities['required_processor_model'] = 'Ryzen ' . $match[1] . ' ' . strtoupper($match[2]);
            }
        }

        $brands = [
            'lenovo' => 'Lenovo',
            'asus' => 'Asus',
            'dell' => 'Dell',
            'hp' => 'HP',
            'acer' => 'Acer',
            'msi' => 'MSI',
            'apple' => 'Apple',
            'microsoft' => 'Microsoft',
            'samsung' => 'Samsung',
            'gigabyte' => 'Gigabyte',
            'razer' => 'Razer',
        ];
        foreach ($brands as $needle => $display) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $text)) {
                $entities['preferred_brands'][] = $display;
            }
        }

        return $entities;
    }

    /** @return array<string, float> */
    private function moneyEntities(string $text): array
    {
        $amount = '(\d{1,3}(?:[,\s]\d{3})+|\d+(?:\.\d+)?)';
        $rangePatterns = [
            '/\b(?:budget|price(?:\s+range)?)(?:\s+(?:is|of))?\s*(?:around|between|from)?\s*(?:rs\.?|lkr)?\s*' . $amount . '\s*([km])?\s*(?:-|–|—|to|and)\s*(?:rs\.?|lkr)?\s*' . $amount . '\s*([km])?\b/u',
            '/\b(?:rs\.?|lkr)\s*' . $amount . '\s*([km])?\s*(?:-|–|—|to|and)\s*(?:rs\.?|lkr)?\s*' . $amount . '\s*([km])?\b/u',
            '/\b' . $amount . '\s*([km])?\s*(?:-|–|—|to)\s*' . $amount . '\s*([km])?\s*(?:lkr|rs\.?|budget)\b/u',
        ];
        foreach ($rangePatterns as $pattern) {
            if (!preg_match($pattern, $text, $match)) {
                continue;
            }
            $first = $this->moneyNumber($match[1], $match[2] ?? '');
            $second = $this->moneyNumber($match[3], $match[4] ?? '');
            if ($first !== null && $second !== null) {
                return [
                    'minimum_budget_lkr' => min($first, $second),
                    'max_budget_lkr' => max($first, $second),
                ];
            }
        }

        $patterns = [
            '/(?:rs\.?|lkr)\s*' . $amount . '\s*([km])?\b/u',
            '/\b(?:with\s+)?' . $amount . '\s*([km])?\s+budget\b/u',
            '/\b(?:under|below|around|about|approximately|budget(?:\s+of)?|maximum|max)\s*(?:rs\.?|lkr)?\s*' . $amount . '\s*([km])?\s*(?:budget)?\b/u',
            '/\b' . $amount . '\s*([km])\s*(?:budget|lkr|rs\.?)?\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $match)) {
                continue;
            }
            $number = $this->moneyNumber($match[1], $match[2] ?? '');
            if ($number !== null) {
                return ['max_budget_lkr' => $number];
            }
        }
        return [];
    }

    private function moneyNumber(string $raw, string $suffix): ?float
    {
        $number = (float) str_replace([',', ' '], '', $raw);
        if ($suffix === 'k') {
            $number *= 1_000;
        } elseif ($suffix === 'm') {
            $number *= 1_000_000;
        }
        return $number >= 1_000 && $number <= 100_000_000
            ? $number
            : null;
    }

    /** @param array<int, string> $patterns */
    private function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $entities */
    private function looksLikePcSpecificationRequest(string $text, array $entities): bool
    {
        if (!isset($entities['max_budget_lkr']) && !isset($entities['minimum_budget_lkr'])) {
            return false;
        }
        $signals = 0;
        foreach ([
            'minimum_ram_gb', 'minimum_vram_gb', 'required_gpu',
            'required_processor_family', 'required_processor_model',
            'minimum_storage_gb', 'storage_type',
        ] as $field) {
            if (array_key_exists($field, $entities)) {
                $signals++;
            }
        }
        return $signals >= 2 && preg_match(
            '/\b(?:pc|desktop|processor|cpu|vga|graphics?\s+card|video\s+card|ram|memory|storage|ssd|hdd|nvme)\b/u',
            $text
        ) === 1;
    }
}
