<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Repositories\MarketplaceRepository;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\HttpException;

final class PublicController
{
    public function __construct(private readonly MarketplaceRepository $marketplace)
    {
    }

    public function categories(): never
    {
        ApiResponse::success(
            'Active categories loaded.',
            ['categories' => $this->marketplace->activeCategories()]
        );
    }

    public function commission(): never
    {
        ApiResponse::success(
            'Current commission policy loaded.',
            ['commission' => $this->marketplace->currentCommission()]
        );
    }

    public function notifications(int $userId): never
    {
        ApiResponse::success(
            'Notifications loaded.',
            [
                'notifications' => $this->marketplace->notifications($userId),
                'unread_count' => $this->marketplace->unreadNotificationCount($userId),
            ]
        );
    }

    public function readNotification(int $notificationId, int $userId): never
    {
        if (!$this->marketplace->markNotificationRead($notificationId, $userId)) {
            throw new HttpException(404, 'Notification not found.');
        }
        ApiResponse::success('Notification marked as read.');
    }

    public function readAllNotifications(int $userId): never
    {
        ApiResponse::success('All notifications marked as read.', [
            'updated_count' => $this->marketplace->markAllNotificationsRead($userId),
        ]);
    }

    public function catalogue(): never
    {
        ApiResponse::success(
            'Marketplace products loaded.',
            $this->marketplace->catalogue($_GET)
        );
    }

    public function product(int $productId): never
    {
        $product = $this->marketplace->product($productId);
        if ($product === null) {
            throw new HttpException(404, 'Marketplace product not found.');
        }
        ApiResponse::success('Marketplace product loaded.', ['product' => $product]);
    }

    public function shop(int $shopId): never
    {
        $shop = $this->marketplace->shop($shopId);
        if ($shop === null) {
            throw new HttpException(404, 'Approved shop not found.');
        }
        ApiResponse::success('Approved shop loaded.', ['shop' => $shop]);
    }

    public function featured(): never
    {
        ApiResponse::success('Featured products loaded.', [
            'products' => $this->marketplace->featuredProducts(6),
        ]);
    }
}
