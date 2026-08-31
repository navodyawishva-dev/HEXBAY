<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\BuyerService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class BuyerController
{
    public function __construct(private readonly BuyerService $buyer)
    {
    }

    public function addresses(int $customerUserId): never
    {
        ApiResponse::success('Delivery addresses loaded.', [
            'addresses' => $this->buyer->addresses($customerUserId),
        ]);
    }

    public function createAddress(int $customerUserId): never
    {
        ApiResponse::success('Delivery address saved.', [
            'address' => $this->buyer->saveAddress(
                $customerUserId,
                null,
                Request::json()
            ),
        ], 201);
    }

    public function updateAddress(int $customerUserId, int $addressId): never
    {
        ApiResponse::success('Delivery address updated.', [
            'address' => $this->buyer->saveAddress(
                $customerUserId,
                $addressId,
                Request::json()
            ),
        ]);
    }

    public function deleteAddress(int $customerUserId, int $addressId): never
    {
        $this->buyer->deleteAddress($customerUserId, $addressId);
        ApiResponse::success('Delivery address removed.');
    }

    public function wishlist(int $customerUserId): never
    {
        ApiResponse::success('Wishlist loaded.', [
            'wishlist' => $this->buyer->wishlist($customerUserId),
        ]);
    }

    public function addWishlistItem(int $customerUserId): never
    {
        $input = Request::json();
        ApiResponse::success('Product saved to your wishlist.', [
            'wishlist' => $this->buyer->addWishlistItem(
                $customerUserId,
                (int) ($input['listing_id'] ?? 0)
            ),
        ], 201);
    }

    public function removeWishlistItem(int $customerUserId, int $listingId): never
    {
        ApiResponse::success('Product removed from your wishlist.', [
            'wishlist' => $this->buyer->removeWishlistItem(
                $customerUserId,
                $listingId
            ),
        ]);
    }

    public function cart(int $customerUserId): never
    {
        ApiResponse::success('Cart loaded.', [
            'cart' => $this->buyer->cart($customerUserId),
        ]);
    }

    public function addCartItem(int $customerUserId): never
    {
        $input = Request::json();
        ApiResponse::success('Product added to your cart.', [
            'cart' => $this->buyer->addCartItem(
                $customerUserId,
                (int) ($input['listing_id'] ?? 0),
                $input
            ),
        ], 201);
    }

    public function addSetupToCart(int $customerUserId): never
    {
        ApiResponse::success(
            'Complete setup added to your cart after live stock and price checks.',
            $this->buyer->addSetupToCart($customerUserId, Request::json()),
            201
        );
    }

    public function updateCartItem(int $customerUserId, int $cartItemId): never
    {
        ApiResponse::success('Cart quantity updated.', [
            'cart' => $this->buyer->updateCartItem(
                $customerUserId,
                $cartItemId,
                Request::json()
            ),
        ]);
    }

    public function removeCartItem(int $customerUserId, int $cartItemId): never
    {
        ApiResponse::success('Product removed from your cart.', [
            'cart' => $this->buyer->removeCartItem(
                $customerUserId,
                $cartItemId
            ),
        ]);
    }

    public function restoreCartSetup(int $customerUserId, string $setupPublicId): never
    {
        ApiResponse::success('Missing setup products restored to the cart.', [
            'cart' => $this->buyer->restoreCartSetup(
                $customerUserId,
                $setupPublicId,
                Request::ipAddress()
            ),
        ]);
    }

    public function releaseCartSetup(int $customerUserId, string $setupPublicId): never
    {
        ApiResponse::success('Saved setup requirement removed. Current cart products were kept.', [
            'cart' => $this->buyer->releaseCartSetup(
                $customerUserId,
                $setupPublicId,
                Request::ipAddress()
            ),
        ]);
    }

    public function checkout(int $customerUserId): never
    {
        ApiResponse::success('Order placed successfully.', [
            'order' => $this->buyer->checkout(
                $customerUserId,
                Request::json(),
                Request::ipAddress()
            ),
        ], 201);
    }

    public function orders(int $customerUserId): never
    {
        ApiResponse::success('Buyer orders loaded.', [
            'orders' => $this->buyer->orders($customerUserId),
        ]);
    }

    public function order(int $customerUserId, int $orderId): never
    {
        ApiResponse::success('Buyer order loaded.', [
            'order' => $this->buyer->order($customerUserId, $orderId),
        ]);
    }

    public function confirmReceipt(int $customerUserId, int $subOrderId): never
    {
        ApiResponse::success('Receipt confirmed and seller balance posted.', [
            'order' => $this->buyer->confirmReceipt(
                $customerUserId,
                $subOrderId,
                Request::ipAddress()
            ),
        ]);
    }

    public function createReview(int $customerUserId, int $orderItemId): never
    {
        ApiResponse::success('Verified review published.', [
            'review' => $this->buyer->createReview(
                $customerUserId,
                $orderItemId,
                Request::json(),
                Request::ipAddress()
            ),
        ], 201);
    }

    public function createComplaint(int $customerUserId): never
    {
        ApiResponse::success('Complaint submitted for administrator review.', [
            'complaint' => $this->buyer->createComplaint(
                $customerUserId,
                Request::json(),
                Request::ipAddress()
            ),
        ], 201);
    }

    public function createCounterfeitReport(int $customerUserId): never
    {
        ApiResponse::success(
            'Product report submitted without making an automatic accusation.',
            [
                'report' => $this->buyer->createCounterfeitReport(
                    $customerUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ],
            201
        );
    }

    public function captureInteraction(int $customerUserId): never
    {
        $this->buyer->captureInteraction($customerUserId, Request::json());
        ApiResponse::success('Marketplace interaction recorded.');
    }
}
