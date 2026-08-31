<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\AdminService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class AdminController
{
    public function __construct(private readonly AdminService $admin)
    {
    }

    public function dashboard(): never
    {
        ApiResponse::success('Administrator dashboard loaded.', $this->admin->dashboard());
    }

    public function users(): never
    {
        ApiResponse::success(
            'Managed accounts loaded.',
            $this->admin->listUsers(
                trim((string) ($_GET['role'] ?? '')),
                trim((string) ($_GET['status'] ?? '')),
                trim((string) ($_GET['search'] ?? '')),
                (int) ($_GET['page'] ?? 1),
                (int) ($_GET['per_page'] ?? 20)
            )
        );
    }

    public function updateUserStatus(
        int $targetUserId,
        int $adminUserId
    ): never {
        ApiResponse::success(
            'Account status updated.',
            [
                'user' => $this->admin->updateUserStatus(
                    $targetUserId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function applications(): never
    {
        ApiResponse::success(
            'Shop applications loaded.',
            [
                'applications' => $this->admin->shopApplications(
                    trim((string) ($_GET['status'] ?? ''))
                ),
            ]
        );
    }

    public function decideApplication(
        int $verificationId,
        int $adminUserId
    ): never {
        ApiResponse::success(
            'Shop application decision recorded.',
            [
                'application' => $this->admin->decideShop(
                    $verificationId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function commissionRules(): never
    {
        ApiResponse::success(
            'Commission rules loaded.',
            ['rules' => $this->admin->commissionRules()]
        );
    }

    public function createCommissionRule(int $adminUserId): never
    {
        ApiResponse::success(
            'Commission rule created. Approved sellers were notified.',
            [
                'rule' => $this->admin->createCommissionRule(
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ],
            201
        );
    }

    public function finance(): never
    {
        ApiResponse::success('Platform finance and payout queue loaded.', $this->admin->financeOverview());
    }

    public function decidePayout(int $payoutId, int $adminUserId): never
    {
        ApiResponse::success('Payout status updated and the seller was notified.', [
            'payout' => $this->admin->decidePayout(
                $payoutId,
                $adminUserId,
                Request::json(),
                Request::ipAddress()
            ),
        ]);
    }

    public function auditLogs(): never
    {
        ApiResponse::success(
            'Audit records loaded.',
            $this->admin->auditLogs(
                (int) ($_GET['page'] ?? 1),
                (int) ($_GET['per_page'] ?? 30)
            )
        );
    }
}
