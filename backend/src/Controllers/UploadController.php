<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\UploadService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class UploadController
{
    public function __construct(private readonly UploadService $uploads)
    {
    }

    public function sellerVerificationDocuments(int $ownerUserId): never
    {
        ApiResponse::success('Verification documents loaded.', [
            'documents' => $this->uploads->sellerVerificationDocuments($ownerUserId),
        ]);
    }

    public function uploadVerificationDocument(int $ownerUserId): never
    {
        ApiResponse::success('Verification document uploaded securely.', [
            'document' => $this->uploads->uploadVerificationDocument(
                $ownerUserId,
                Request::formString('document_type'),
                Request::uploadedFile(),
                Request::ipAddress()
            ),
        ], 201);
    }

    public function uploadShopLogo(int $ownerUserId): never
    {
        ApiResponse::success('Shop logo updated.', [
            'shop' => $this->uploads->uploadShopLogo(
                $ownerUserId,
                Request::uploadedFile(),
                Request::ipAddress()
            ),
        ]);
    }

    public function deleteShopLogo(int $ownerUserId): never
    {
        ApiResponse::success('Shop logo removed.', [
            'shop' => $this->uploads->deleteShopLogo(
                $ownerUserId,
                Request::ipAddress()
            ),
        ]);
    }

    public function uploadProductImage(int $ownerUserId, int $listingId): never
    {
        ApiResponse::success('Product image uploaded.', [
            'image' => $this->uploads->uploadProductImage(
                $ownerUserId,
                $listingId,
                Request::formString('alt_text'),
                Request::uploadedFile(),
                Request::ipAddress()
            ),
        ], 201);
    }

    public function deleteProductImage(
        int $ownerUserId,
        int $listingId,
        int $imageId
    ): never {
        $this->uploads->deleteProductImage(
            $ownerUserId,
            $listingId,
            $imageId,
            Request::ipAddress()
        );
        ApiResponse::success('Product image removed.');
    }

    public function adminVerificationDocuments(int $verificationId): never
    {
        ApiResponse::success('Verification documents loaded.', [
            'documents' => $this->uploads->adminVerificationDocuments($verificationId),
        ]);
    }

    public function streamVerificationDocument(int $documentId, int $adminUserId): never
    {
        $this->uploads->streamVerificationDocument(
            $documentId,
            $adminUserId,
            Request::ipAddress()
        );
    }

    public function publicImage(string $kind, string $storedFilename): never
    {
        $this->uploads->streamPublicImage($kind, $storedFilename);
    }
}
