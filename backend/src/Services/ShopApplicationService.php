<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\MarketplaceRepository;
use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\ShopApplicationValidator;
use PDO;

final class ShopApplicationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly MarketplaceRepository $marketplace,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<string, mixed>|null */
    public function currentForOwner(int $ownerUserId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                s.id AS shop_id,
                s.name AS shop_name,
                s.description,
                s.address_text AS address,
                s.contact_phone,
                s.contact_email,
                s.status AS shop_status,
                s.status_reason,
                vv.id AS verification_id,
                vv.submission_number,
                vv.legal_name,
                vv.business_registration_reference,
                vv.status AS verification_status,
                vv.submitted_at,
                vv.reviewed_at,
                vv.review_notes,
                vv.decision_reason,
                ca.percentage_snapshot AS accepted_percentage,
                ca.terms_version,
                ca.accepted_at,
                ca.superseded_at,
                (
                    SELECT COUNT(*)
                    FROM verification_documents vd
                    WHERE vd.verification_id = vv.id
                ) AS document_count
             FROM shops s
             LEFT JOIN vendor_verifications vv
                ON vv.shop_id = s.id
               AND vv.submission_number = (
                    SELECT MAX(vv2.submission_number)
                    FROM vendor_verifications vv2
                    WHERE vv2.shop_id = s.id
               )
             LEFT JOIN commission_acceptances ca
                ON ca.shop_id = s.id
               AND ca.id = (
                    SELECT MAX(ca2.id)
                    FROM commission_acceptances ca2
                    WHERE ca2.shop_id = s.id
               )
             WHERE s.owner_user_id = :owner_user_id
             LIMIT 1'
        );
        $statement->execute(['owner_user_id' => $ownerUserId]);
        $application = $statement->fetch();
        return $application === false ? null : $application;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function submit(
        int $ownerUserId,
        array $input,
        string $ipAddress,
        string $userAgent
    ): array {
        $data = ShopApplicationValidator::submission($input);
        $activeRule = $this->marketplace->currentCommission();
        if ((int) $data['commission_rule_id'] !== (int) $activeRule['id']) {
            throw new HttpException(
                409,
                'The commission policy changed. Review and accept the current policy.'
            );
        }

        $existing = $this->currentForOwner($ownerUserId);
        if (
            $existing !== null
            && in_array($existing['shop_status'], ['pending', 'approved', 'suspended'], true)
        ) {
            throw new HttpException(
                409,
                'This seller already has a shop application that cannot be replaced.'
            );
        }

        $acceptanceText = sprintf(
            'I understand and accept Hexbay’s %s%% platform commission on each vendor '
            . 'sub-order completed after customer receipt confirmation.',
            $activeRule['percentage']
        );

        try {
            $this->db->beginTransaction();

            if ($existing === null) {
                $shopId = $this->createShop($ownerUserId, $data);
                $submissionNumber = 1;
            } else {
                $shopId = (int) $existing['shop_id'];
                $submissionNumber = ((int) ($existing['submission_number'] ?? 0)) + 1;
                $statement = $this->db->prepare(
                    'UPDATE shops
                     SET name = :name,
                         description = :description,
                         address_text = :address,
                         contact_phone = :contact_phone,
                         contact_email = :contact_email,
                         status = "pending",
                         status_reason = NULL
                     WHERE id = :id AND owner_user_id = :owner_user_id'
                );
                $statement->execute([
                    'name' => $data['shop_name'],
                    'description' => $data['description'] ?: null,
                    'address' => $data['address'],
                    'contact_phone' => $data['contact_phone'],
                    'contact_email' => $data['contact_email'],
                    'id' => $shopId,
                    'owner_user_id' => $ownerUserId,
                ]);
            }

            $verification = $this->db->prepare(
                'INSERT INTO vendor_verifications
                    (shop_id, submission_number, legal_name,
                     business_registration_reference, status, submitted_at)
                 VALUES
                    (:shop_id, :submission_number, :legal_name,
                     :business_reference, "pending", CURRENT_TIMESTAMP)'
            );
            $verification->execute([
                'shop_id' => $shopId,
                'submission_number' => $submissionNumber,
                'legal_name' => $data['legal_name'],
                'business_reference' => $data['business_registration_reference'],
            ]);
            $verificationId = (int) $this->db->lastInsertId();

            $acceptance = $this->db->prepare(
                'INSERT INTO commission_acceptances
                    (shop_owner_user_id, shop_id, commission_rule_id,
                     percentage_snapshot, terms_version, acceptance_text,
                     ip_address, user_agent)
                 VALUES
                    (:owner_user_id, :shop_id, :rule_id,
                     :percentage, :terms_version, :acceptance_text,
                     :ip_address, :user_agent)
                 ON DUPLICATE KEY UPDATE id = id'
            );
            $acceptance->execute([
                'owner_user_id' => $ownerUserId,
                'shop_id' => $shopId,
                'rule_id' => $activeRule['id'],
                'percentage' => $activeRule['percentage'],
                'terms_version' => $activeRule['terms_version'],
                'acceptance_text' => $acceptanceText,
                'ip_address' => $ipAddress,
                'user_agent' => substr($userAgent, 0, 255),
            ]);

            $this->users->audit(
                $ownerUserId,
                'shop.application_submitted',
                'vendor_verification',
                $verificationId,
                [
                    'shop_id' => $shopId,
                    'submission_number' => $submissionNumber,
                    'commission_rule_id' => $activeRule['id'],
                    'commission_percentage' => $activeRule['percentage'],
                    'terms_version' => $activeRule['terms_version'],
                ],
                $ipAddress
            );

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $application = $this->currentForOwner($ownerUserId);
        if ($application === null) {
            throw new \RuntimeException('Submitted application could not be loaded.');
        }
        return $application;
    }

    /** @return array<string, mixed> */
    public function acceptCurrentCommission(
        int $ownerUserId,
        bool $accepted,
        string $ipAddress,
        string $userAgent
    ): array {
        if (!$accepted) {
            throw new HttpException(422, 'Commission acceptance is required.');
        }
        $application = $this->currentForOwner($ownerUserId);
        if ($application === null || $application['shop_status'] !== 'approved') {
            throw new HttpException(409, 'An approved shop is required.');
        }
        $rule = $this->marketplace->currentCommission();
        $text = sprintf(
            'I understand and accept Hexbay’s %s%% platform commission on each vendor '
            . 'sub-order completed after customer receipt confirmation.',
            $rule['percentage']
        );
        $statement = $this->db->prepare(
            'INSERT INTO commission_acceptances
                (shop_owner_user_id, shop_id, commission_rule_id,
                 percentage_snapshot, terms_version, acceptance_text,
                 ip_address, user_agent)
             VALUES
                (:owner_user_id, :shop_id, :rule_id, :percentage,
                 :terms_version, :acceptance_text, :ip_address, :user_agent)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'shop_id' => $application['shop_id'],
            'rule_id' => $rule['id'],
            'percentage' => $rule['percentage'],
            'terms_version' => $rule['terms_version'],
            'acceptance_text' => $text,
            'ip_address' => $ipAddress,
            'user_agent' => substr($userAgent, 0, 255),
        ]);
        $this->users->audit(
            $ownerUserId,
            'commission.accepted',
            'commission_rule',
            (int) $rule['id'],
            ['shop_id' => $application['shop_id'], 'percentage' => $rule['percentage']],
            $ipAddress
        );
        return $this->currentForOwner($ownerUserId) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function createShop(int $ownerUserId, array $data): int
    {
        $baseSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $data['shop_name']), '-'));
        if ($baseSlug === '') {
            $baseSlug = 'shop';
        }
        $slug = substr($baseSlug, 0, 150) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $statement = $this->db->prepare(
            'INSERT INTO shops
                (owner_user_id, name, slug, description, address_text,
                 contact_phone, contact_email, status)
             VALUES
                (:owner_user_id, :name, :slug, :description, :address,
                 :contact_phone, :contact_email, "pending")'
        );
        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'name' => $data['shop_name'],
            'slug' => $slug,
            'description' => $data['description'] ?: null,
            'address' => $data['address'],
            'contact_phone' => $data['contact_phone'],
            'contact_email' => $data['contact_email'],
        ]);
        return (int) $this->db->lastInsertId();
    }
}
