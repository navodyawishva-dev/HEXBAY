<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\AdminModerationService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class AdminModerationController
{
    public function __construct(private readonly AdminModerationService $moderation)
    {
    }

    public function listings(): never
    {
        ApiResponse::success(
            'Product listings loaded for moderation.',
            [
                'listings' => $this->moderation->listings(
                    trim((string) ($_GET['status'] ?? '')),
                    trim((string) ($_GET['search'] ?? ''))
                ),
            ]
        );
    }

    public function decideListing(int $listingId, int $adminUserId): never
    {
        ApiResponse::success(
            'Listing decision recorded and seller notified.',
            [
                'listing' => $this->moderation->decideListing(
                    $listingId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function flags(): never
    {
        ApiResponse::success(
            'Automated listing flags loaded.',
            [
                'flags' => $this->moderation->flags(
                    trim((string) ($_GET['status'] ?? 'open'))
                ),
            ]
        );
    }

    public function decideFlag(int $flagId, int $adminUserId): never
    {
        ApiResponse::success(
            'Listing flag decision recorded.',
            [
                'flag' => $this->moderation->decideFlag(
                    $flagId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function complaints(): never
    {
        ApiResponse::success(
            'Customer complaints loaded.',
            [
                'complaints' => $this->moderation->complaints(
                    trim((string) ($_GET['status'] ?? 'open'))
                ),
            ]
        );
    }

    public function decideComplaint(int $complaintId, int $adminUserId): never
    {
        ApiResponse::success(
            'Complaint decision recorded and customer notified.',
            [
                'complaint' => $this->moderation->decideComplaint(
                    $complaintId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function reports(): never
    {
        ApiResponse::success(
            'Counterfeit-product reports loaded.',
            [
                'reports' => $this->moderation->reports(
                    trim((string) ($_GET['status'] ?? 'open'))
                ),
            ]
        );
    }

    public function decideReport(int $reportId, int $adminUserId): never
    {
        ApiResponse::success(
            'Counterfeit report decision recorded.',
            [
                'report' => $this->moderation->decideReport(
                    $reportId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }
}
