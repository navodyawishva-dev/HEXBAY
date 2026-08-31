<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\PcBuildRecommendationService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class PcBuildRecommendationController
{
    public function __construct(private readonly PcBuildRecommendationService $recommendations)
    {
    }

    public function workloads(): never
    {
        ApiResponse::success(
            'PC builder use cases loaded.',
            $this->recommendations->workloads()
        );
    }

    public function recommend(): never
    {
        ApiResponse::success(
            'PC build recommendations evaluated.',
            ['recommendation' => $this->recommendations->recommend(Request::json())]
        );
    }
}
