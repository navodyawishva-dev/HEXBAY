<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\SellerModuleService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class SellerModuleController
{
    public function __construct(private readonly SellerModuleService $seller)
    {
    }

    public function dashboard(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller dashboard loaded.',
            $this->seller->dashboard($ownerUserId)
        );
    }

    public function profile(int $ownerUserId): never
    {
        ApiResponse::success(
            'Shop profile loaded.',
            ['shop' => $this->seller->profile($ownerUserId)]
        );
    }

    public function updateProfile(int $ownerUserId): never
    {
        ApiResponse::success(
            'Shop profile updated.',
            [
                'shop' => $this->seller->updateProfile(
                    $ownerUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function catalogueOptions(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller catalogue options loaded.',
            $this->seller->catalogueOptions($ownerUserId)
        );
    }

    public function listings(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller listings loaded.',
            ['listings' => $this->seller->listings($ownerUserId)]
        );
    }

    public function listing(int $ownerUserId, int $listingId): never
    {
        ApiResponse::success(
            'Seller listing loaded.',
            ['listing' => $this->seller->listing($ownerUserId, $listingId)]
        );
    }

    public function createListing(int $ownerUserId): never
    {
        ApiResponse::success(
            'Product listing submitted for marketplace review.',
            [
                'listing' => $this->seller->saveListing(
                    $ownerUserId,
                    null,
                    Request::json(),
                    Request::ipAddress()
                ),
            ],
            201
        );
    }

    public function updateListing(int $ownerUserId, int $listingId): never
    {
        ApiResponse::success(
            'Product listing updated.',
            [
                'listing' => $this->seller->saveListing(
                    $ownerUserId,
                    $listingId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function inventory(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller inventory loaded.',
            ['inventory' => $this->seller->inventory($ownerUserId)]
        );
    }

    public function adjustInventory(int $ownerUserId, int $listingId): never
    {
        ApiResponse::success(
            'Inventory adjusted.',
            [
                'inventory' => $this->seller->adjustInventory(
                    $ownerUserId,
                    $listingId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function orders(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller orders loaded.',
            [
                'orders' => $this->seller->orders(
                    $ownerUserId,
                    trim((string) ($_GET['status'] ?? ''))
                ),
            ]
        );
    }

    public function updateOrderStatus(int $ownerUserId, int $subOrderId): never
    {
        ApiResponse::success(
            'Seller order status updated.',
            [
                'order' => $this->seller->updateOrderStatus(
                    $ownerUserId,
                    $subOrderId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function completeFulfilmentCheckpoint(
        int $ownerUserId,
        int $subOrderId
    ): never {
        ApiResponse::success(
            'Fulfilment checkpoint completed.',
            [
                'order' => $this->seller->completeFulfilmentCheckpoint(
                    $ownerUserId,
                    $subOrderId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function reviews(int $ownerUserId): never
    {
        ApiResponse::success(
            'Shop reviews loaded.',
            ['reviews' => $this->seller->reviews($ownerUserId)]
        );
    }

    public function finance(int $ownerUserId): never
    {
        ApiResponse::success(
            'Seller finance summary loaded.',
            $this->seller->finance($ownerUserId)
        );
    }

    public function requestPayout(int $ownerUserId): never
    {
        ApiResponse::success(
            'Simulated payout request submitted.',
            [
                'payout' => $this->seller->requestPayout(
                    $ownerUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ],
            201
        );
    }
}
