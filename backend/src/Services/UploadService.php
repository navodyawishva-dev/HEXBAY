<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use PDO;

final class UploadService
{
    private const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const DOCUMENT_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private readonly string $storageRoot;

    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users
    ) {
        $this->storageRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    }

    /** @return array<int, array<string, mixed>> */
    public function sellerVerificationDocuments(int $ownerUserId): array
    {
        $verification = $this->currentVerificationForOwner($ownerUserId);
        return $this->verificationDocuments((int) $verification['id']);
    }

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     *  @return array<string, mixed>
     */
    public function uploadVerificationDocument(
        int $ownerUserId,
        string $documentType,
        array $file,
        string $ipAddress
    ): array {
        $verification = $this->currentVerificationForOwner($ownerUserId);
        if ($verification['status'] !== 'pending') {
            throw new HttpException(409, 'Documents can only be added while an application is pending.');
        }
        $allowedTypes = [
            'business_registration',
            'identity',
            'address_proof',
            'other',
        ];
        if (!in_array($documentType, $allowedTypes, true)) {
            throw new HttpException(422, 'Choose a valid verification document type.');
        }
        $this->enforceUploadRate($ownerUserId);
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM verification_documents WHERE verification_id=:id'
        );
        $count->execute(['id' => $verification['id']]);
        if ((int) $count->fetchColumn() >= 5) {
            throw new HttpException(409, 'A verification application can contain up to five documents.');
        }

        $stored = $this->storeFile(
            $file,
            'protected-verification',
            self::DOCUMENT_MIMES,
            8 * 1024 * 1024,
            false
        );
        try {
            $statement = $this->db->prepare(
                'INSERT INTO verification_documents
                    (verification_id, document_type, original_filename,
                     stored_filename, mime_type, byte_size, sha256_hash)
                 VALUES
                    (:verification_id, :document_type, :original_filename,
                     :stored_filename, :mime_type, :byte_size, :sha256_hash)'
            );
            $statement->execute([
                'verification_id' => $verification['id'],
                'document_type' => $documentType,
                ...$stored,
            ]);
            $documentId = (int) $this->db->lastInsertId();
            $this->users->audit(
                $ownerUserId,
                'seller.verification_document_uploaded',
                'verification_document',
                $documentId,
                [
                    'verification_id' => $verification['id'],
                    'document_type' => $documentType,
                    'mime_type' => $stored['mime_type'],
                    'byte_size' => $stored['byte_size'],
                ],
                $ipAddress
            );
        } catch (\Throwable $exception) {
            $this->removeStored('protected-verification', $stored['stored_filename']);
            throw $exception;
        }
        return $this->documentById($documentId);
    }

    /** @return array<int, array<string, mixed>> */
    public function adminVerificationDocuments(int $verificationId): array
    {
        $exists = $this->db->prepare('SELECT id FROM vendor_verifications WHERE id=:id');
        $exists->execute(['id' => $verificationId]);
        if ($exists->fetchColumn() === false) {
            throw new HttpException(404, 'Shop application not found.');
        }
        return $this->verificationDocuments($verificationId);
    }

    public function streamVerificationDocument(
        int $documentId,
        int $adminUserId,
        string $ipAddress
    ): never {
        $document = $this->documentById($documentId);
        $this->users->audit(
            $adminUserId,
            'admin.verification_document_viewed',
            'verification_document',
            $documentId,
            ['verification_id' => $document['verification_id']],
            $ipAddress
        );
        $this->streamStored(
            'protected-verification',
            (string) $document['stored_filename'],
            (string) $document['mime_type'],
            (string) $document['original_filename'],
            false
        );
    }

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     *  @return array<string, mixed>
     */
    public function uploadShopLogo(
        int $ownerUserId,
        array $file,
        string $ipAddress
    ): array {
        $shop = $this->approvedShop($ownerUserId);
        $this->enforceUploadRate($ownerUserId);
        $stored = $this->storeFile(
            $file,
            'shop-logos',
            self::IMAGE_MIMES,
            4 * 1024 * 1024,
            true
        );
        $oldFilename = (string) ($shop['logo_path'] ?? '');
        try {
            $statement = $this->db->prepare(
                'UPDATE shops SET logo_path=:logo_path
                 WHERE id=:id AND owner_user_id=:owner_user_id'
            );
            $statement->execute([
                'logo_path' => $stored['stored_filename'],
                'id' => $shop['id'],
                'owner_user_id' => $ownerUserId,
            ]);
            $this->users->audit(
                $ownerUserId,
                'seller.shop_logo_uploaded',
                'shop',
                (int) $shop['id'],
                [
                    'mime_type' => $stored['mime_type'],
                    'byte_size' => $stored['byte_size'],
                ],
                $ipAddress
            );
        } catch (\Throwable $exception) {
            $this->removeStored('shop-logos', $stored['stored_filename']);
            throw $exception;
        }
        if ($oldFilename !== '' && $oldFilename !== $stored['stored_filename']) {
            $this->removeStored('shop-logos', $oldFilename);
        }
        return $this->approvedShop($ownerUserId);
    }

    /** @return array<string, mixed> */
    public function deleteShopLogo(int $ownerUserId, string $ipAddress): array
    {
        $shop = $this->approvedShop($ownerUserId);
        $oldFilename = (string) ($shop['logo_path'] ?? '');
        if ($oldFilename === '') {
            return $shop;
        }

        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'UPDATE shops SET logo_path=NULL
                 WHERE id=:id AND owner_user_id=:owner_user_id'
            );
            $statement->execute([
                'id' => $shop['id'],
                'owner_user_id' => $ownerUserId,
            ]);
            $this->users->audit(
                $ownerUserId,
                'seller.shop_logo_removed',
                'shop',
                (int) $shop['id'],
                [],
                $ipAddress
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->removeStored('shop-logos', $oldFilename);
        return $this->approvedShop($ownerUserId);
    }

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     *  @return array<string, mixed>
     */
    public function uploadProductImage(
        int $ownerUserId,
        int $listingId,
        string $altText,
        array $file,
        string $ipAddress
    ): array {
        $listing = $this->ownedListing($ownerUserId, $listingId);
        $this->enforceUploadRate($ownerUserId);
        if (strlen($altText) > 190) {
            throw new HttpException(422, 'Image alternative text must not exceed 190 characters.');
        }
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM product_images WHERE listing_id=:listing_id'
        );
        $count->execute(['listing_id' => $listingId]);
        if ((int) $count->fetchColumn() >= 6) {
            throw new HttpException(409, 'A product listing can contain up to six images.');
        }
        $stored = $this->storeFile(
            $file,
            'product-images',
            self::IMAGE_MIMES,
            6 * 1024 * 1024,
            true
        );
        try {
            $sort = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1
                 FROM product_images WHERE listing_id=:listing_id'
            );
            $sort->execute(['listing_id' => $listingId]);
            $statement = $this->db->prepare(
                'INSERT INTO product_images
                    (listing_id, original_filename, stored_filename, mime_type,
                     byte_size, alt_text, sort_order)
                 VALUES
                    (:listing_id, :original_filename, :stored_filename, :mime_type,
                     :byte_size, :alt_text, :sort_order)'
            );
            $statement->execute([
                'listing_id' => $listingId,
                'original_filename' => $stored['original_filename'],
                'stored_filename' => $stored['stored_filename'],
                'mime_type' => $stored['mime_type'],
                'byte_size' => $stored['byte_size'],
                'alt_text' => $altText === '' ? $listing['product_name'] : $altText,
                'sort_order' => (int) $sort->fetchColumn(),
            ]);
            $imageId = (int) $this->db->lastInsertId();
            $this->users->audit(
                $ownerUserId,
                'seller.product_image_uploaded',
                'product_image',
                $imageId,
                ['listing_id' => $listingId, 'mime_type' => $stored['mime_type']],
                $ipAddress
            );
        } catch (\Throwable $exception) {
            $this->removeStored('product-images', $stored['stored_filename']);
            throw $exception;
        }
        return $this->productImageById($imageId);
    }

    public function deleteProductImage(
        int $ownerUserId,
        int $listingId,
        int $imageId,
        string $ipAddress
    ): void {
        $this->ownedListing($ownerUserId, $listingId);
        $statement = $this->db->prepare(
            'SELECT id, stored_filename FROM product_images
             WHERE id=:id AND listing_id=:listing_id'
        );
        $statement->execute(['id' => $imageId, 'listing_id' => $listingId]);
        $image = $statement->fetch();
        if ($image === false) {
            throw new HttpException(404, 'Product image not found.');
        }
        $delete = $this->db->prepare(
            'DELETE FROM product_images WHERE id=:id AND listing_id=:listing_id'
        );
        $delete->execute(['id' => $imageId, 'listing_id' => $listingId]);
        $this->removeStored('product-images', (string) $image['stored_filename']);
        $this->users->audit(
            $ownerUserId,
            'seller.product_image_deleted',
            'product_image',
            $imageId,
            ['listing_id' => $listingId],
            $ipAddress
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function productImages(int $listingId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, listing_id, original_filename, stored_filename,
                    mime_type, byte_size, alt_text, sort_order, created_at
             FROM product_images
             WHERE listing_id=:listing_id
             ORDER BY sort_order, id'
        );
        $statement->execute(['listing_id' => $listingId]);
        return $statement->fetchAll();
    }

    public function streamPublicImage(string $kind, string $storageToken): never
    {
        if (!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $storageToken)) {
            throw new HttpException(404, 'Image not found.');
        }
        if ($kind === 'shop-logos') {
            $statement = $this->db->prepare(
                'SELECT logo_path stored_filename
                 FROM shops
                 WHERE logo_path LIKE CONCAT(:token, ".%")
                 LIMIT 1'
            );
        } elseif ($kind === 'product-images') {
            $statement = $this->db->prepare(
                'SELECT stored_filename
                 FROM product_images
                 WHERE stored_filename LIKE CONCAT(:token, ".%")
                 LIMIT 1'
            );
        } else {
            throw new HttpException(404, 'Image not found.');
        }
        $statement->execute(['token' => $storageToken]);
        $storedFilename = $statement->fetchColumn();
        if ($storedFilename === false) {
            throw new HttpException(404, 'Image not found.');
        }
        $storedFilename = (string) $storedFilename;
        $extension = strtolower((string) pathinfo($storedFilename, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => throw new HttpException(404, 'Image not found.'),
        };
        $this->streamStored($kind, $storedFilename, $mime, $storedFilename, true);
    }

    /** @return array<string, mixed> */
    private function currentVerificationForOwner(int $ownerUserId): array
    {
        $statement = $this->db->prepare(
            'SELECT vv.id, vv.status, vv.shop_id
             FROM vendor_verifications vv
             INNER JOIN shops s ON s.id=vv.shop_id
             WHERE s.owner_user_id=:owner_user_id
             ORDER BY vv.submission_number DESC LIMIT 1'
        );
        $statement->execute(['owner_user_id' => $ownerUserId]);
        $verification = $statement->fetch();
        if ($verification === false) {
            throw new HttpException(409, 'Submit the shop application before uploading documents.');
        }
        return $verification;
    }

    /** @return array<string, mixed> */
    private function approvedShop(int $ownerUserId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, owner_user_id, name, logo_path, status
             FROM shops WHERE owner_user_id=:owner_user_id LIMIT 1'
        );
        $statement->execute(['owner_user_id' => $ownerUserId]);
        $shop = $statement->fetch();
        if ($shop === false || $shop['status'] !== 'approved') {
            throw new HttpException(403, 'An approved shop is required for this action.');
        }
        return $shop;
    }

    /** @return array<string, mixed> */
    private function ownedListing(int $ownerUserId, int $listingId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.id, l.shop_id, cp.name product_name
             FROM shop_product_listings l
             INNER JOIN shops s ON s.id=l.shop_id
             INNER JOIN canonical_products cp ON cp.id=l.canonical_product_id
             WHERE l.id=:listing_id
               AND s.owner_user_id=:owner_user_id
               AND s.status="approved"'
        );
        $statement->execute([
            'listing_id' => $listingId,
            'owner_user_id' => $ownerUserId,
        ]);
        $listing = $statement->fetch();
        if ($listing === false) {
            throw new HttpException(404, 'Seller product listing not found.');
        }
        return $listing;
    }

    /** @return array<int, array<string, mixed>> */
    private function verificationDocuments(int $verificationId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, verification_id, document_type, original_filename,
                    mime_type, byte_size, uploaded_at
             FROM verification_documents
             WHERE verification_id=:verification_id
             ORDER BY uploaded_at, id'
        );
        $statement->execute(['verification_id' => $verificationId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    private function documentById(int $documentId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, verification_id, document_type, original_filename,
                    stored_filename, mime_type, byte_size, uploaded_at
             FROM verification_documents WHERE id=:id'
        );
        $statement->execute(['id' => $documentId]);
        $document = $statement->fetch();
        if ($document === false) {
            throw new HttpException(404, 'Verification document not found.');
        }
        return $document;
    }

    /** @return array<string, mixed> */
    private function productImageById(int $imageId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, listing_id, original_filename, stored_filename,
                    mime_type, byte_size, alt_text, sort_order, created_at
             FROM product_images WHERE id=:id'
        );
        $statement->execute(['id' => $imageId]);
        $image = $statement->fetch();
        if ($image === false) {
            throw new \RuntimeException('Uploaded product image could not be loaded.');
        }
        return $image;
    }

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     *  @param array<string, string> $allowedMimes
     *  @return array{original_filename: string, stored_filename: string, mime_type: string, byte_size: int, sha256_hash?: string}
     */
    private function storeFile(
        array $file,
        string $directory,
        array $allowedMimes,
        int $maximumBytes,
        bool $requireDecodableImage
    ): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = $file['error'] === UPLOAD_ERR_INI_SIZE
                || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'The selected file is too large.'
                : 'The file upload did not complete.';
            throw new HttpException(422, $message);
        }
        if (
            $file['size'] < 1
            || $file['size'] > $maximumBytes
            || !is_uploaded_file($file['tmp_name'])
        ) {
            throw new HttpException(422, 'The uploaded file is empty, too large, or invalid.');
        }
        $original = basename(str_replace('\\', '/', $file['name']));
        if ($original === '' || strlen($original) > 255 || str_contains($original, "\0")) {
            throw new HttpException(422, 'The original filename is invalid.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || !isset($allowedMimes[$mime])) {
            throw new HttpException(422, 'This file type is not allowed.');
        }
        $expectedExtension = $allowedMimes[$mime];
        $providedExtension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        $validExtensions = $expectedExtension === 'jpg' ? ['jpg', 'jpeg'] : [$expectedExtension];
        if (!in_array($providedExtension, $validExtensions, true)) {
            throw new HttpException(422, 'The filename extension does not match the file content.');
        }
        if ($requireDecodableImage || str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($file['tmp_name']);
            if (
                $dimensions === false
                || ($dimensions[0] ?? 0) < 1
                || ($dimensions[1] ?? 0) < 1
                || ($dimensions[0] ?? 0) > 6000
                || ($dimensions[1] ?? 0) > 6000
                || (($dimensions[0] ?? 0) * ($dimensions[1] ?? 0)) > 25000000
            ) {
                throw new HttpException(422, 'The uploaded image cannot be safely decoded.');
            }
        }

        $targetDirectory = $this->storageDirectory($directory);
        $storedFilename = bin2hex(random_bytes(16)) . '.' . $expectedExtension;
        $target = $targetDirectory . DIRECTORY_SEPARATOR . $storedFilename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('The uploaded file could not be stored.');
        }
        $result = [
            'original_filename' => $original,
            'stored_filename' => $storedFilename,
            'mime_type' => $mime,
            'byte_size' => (int) filesize($target),
        ];
        if (!$requireDecodableImage) {
            $result['sha256_hash'] = hash_file('sha256', $target);
        }
        return $result;
    }

    private function enforceUploadRate(int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM audit_logs
             WHERE actor_user_id=:actor
               AND action IN (
                   "seller.verification_document_uploaded",
                   "seller.shop_logo_uploaded",
                   "seller.product_image_uploaded"
               )
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE)'
        );
        $statement->execute(['actor' => $actorUserId]);
        if ((int) $statement->fetchColumn() >= 10) {
            throw new HttpException(429, 'Too many uploads. Wait a minute and try again.');
        }
    }

    private function storageDirectory(string $directory): string
    {
        $target = $this->storageRoot . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
            throw new \RuntimeException('Upload storage is unavailable.');
        }
        return $target;
    }

    private function removeStored(string $directory, string $storedFilename): void
    {
        if (!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})\.(?:pdf|jpg|png|webp)$/', $storedFilename)) {
            return;
        }
        $target = $this->storageDirectory($directory) . DIRECTORY_SEPARATOR . $storedFilename;
        if (is_file($target)) {
            @unlink($target);
        }
    }

    private function streamStored(
        string $directory,
        string $storedFilename,
        string $mimeType,
        string $downloadName,
        bool $publicCache
    ): never {
        if (!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})\.(?:pdf|jpg|png|webp)$/', $storedFilename)) {
            throw new HttpException(404, 'File not found.');
        }
        $root = realpath($this->storageDirectory($directory));
        $path = realpath($this->storageDirectory($directory) . DIRECTORY_SEPARATOR . $storedFilename);
        if (
            $root === false
            || $path === false
            || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            || !is_file($path)
        ) {
            throw new HttpException(404, 'File not found.');
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($downloadName)) ?: 'download';
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header(
            'Content-Disposition: '
            . ($publicCache ? 'inline' : 'attachment')
            . '; filename="' . $safeName . '"'
        );
        header(
            $publicCache
                ? 'Cache-Control: public, max-age=86400, immutable'
                : 'Cache-Control: private, no-store'
        );
        readfile($path);
        exit;
    }
}
