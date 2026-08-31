<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\HexBotConversationService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class HexBotController
{
    public function __construct(private readonly HexBotConversationService $hexbot)
    {
    }

    public function start(): never
    {
        $input = Request::json();
        ApiResponse::success(
            'HexBot conversation ready.',
            $this->hexbot->start((string) ($input['session_key'] ?? '')),
            201
        );
    }

    public function message(string $publicId): never
    {
        $input = Request::json();
        ApiResponse::success(
            'HexBot response generated.',
            $this->hexbot->message(
                $publicId,
                (string) ($input['session_key'] ?? ''),
                (string) ($input['message'] ?? ''),
                isset($input['action']) ? (string) $input['action'] : null
            )
        );
    }
}
