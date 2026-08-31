<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\ShopApplicationService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class SellerController
{
    public function __construct(private readonly ShopApplicationService $applications)
    {
    }

    public function application(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller shop application loaded.',
            ['application' => $this->applications->currentForOwner($ownerUserId)]
        );
    }

    public function submitApplication(int $ownerUserId): never
    {
        $application = $this->applications->submit(
            $ownerUserId,
            Request::json(),
            Request::ipAddress(),
            Request::userAgent()
        );
        ApiResponse::success(
            'Shop application submitted for administrator review.',
            ['application' => $application],
            201
        );
    }

    public function acceptCommission(int $ownerUserId): never
    {
        $input = Request::json();
        $application = $this->applications->acceptCurrentCommission(
            $ownerUserId,
            filter_var($input['accepted'] ?? false, FILTER_VALIDATE_BOOL),
            Request::ipAddress(),
            Request::userAgent()
        );
        ApiResponse::success(
            'Current commission policy accepted.',
            ['application' => $application]
        );
    }
}

