<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\LaptopRecommendationService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class RecommendationController
{
    public function __construct(
        private readonly LaptopRecommendationService $recommendations
    ) {
    }

    public function laptops(): never
    {
        ApiResponse::success(
            'Laptop recommendations generated.',
            $this->recommendations->recommend(Request::json())
        );
    }
}
