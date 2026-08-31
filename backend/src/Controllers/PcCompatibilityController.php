<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\PcCompatibilityService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class PcCompatibilityController
{
    public function __construct(private readonly PcCompatibilityService $compatibility)
    {
    }

    public function validate(): never
    {
        ApiResponse::success(
            'PC compatibility evaluated.',
            ['validation' => $this->compatibility->validate(Request::json())]
        );
    }

    public function alternatives(): never
    {
        ApiResponse::success(
            'Compatible alternatives evaluated.',
            $this->compatibility->alternatives(Request::json())
        );
    }
}

