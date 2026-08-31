<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\AdminValidator;
use PDO;

final class AdminModerationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function listings(string $status, string $search): array
    {
        $allowed = [
            'draft', 'pending_approval', 'active', 'rejected',
            'hidden', 'flagged', 'inactive',
        ];
        $where = [];
        $params = [];
        if (in_array($status, $allowed, true)) {
            $where[] = 'l.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(cp.name LIKE :search OR cp.model LIKE :search
                         OR b.name LIKE :search OR s.name LIKE :search
                         OR l.sku LIKE :search)';
            $params['search'] = '%' . substr($search, 0, 100) . '%';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $statement = $this->db->prepare(
            "SELECT l.id, l.sku, l.condition_type, l.price, l.status,
                    l.status_reason, l.created_at, l.updated_at,
                    cp.name AS product_name, cp.model, b.name AS brand_name,
                    c.name AS category_name, s.id AS shop_id, s.name AS shop_name,
                    s.owner_user_id, u.email AS owner_email,
                    COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                    COALESCE(i.quantity_reserved, 0) AS quantity_reserved,
                    COUNT(DISTINCT CASE WHEN lf.status = 'open' THEN lf.id END) AS open_flags
             FROM shop_product_listings l
             INNER JOIN canonical_products cp ON cp.id = l.canonical_product_id
             INNER JOIN brands b ON b.id = cp.brand_id
             INNER JOIN categories c ON c.id = cp.category_id
             INNER JOIN shops s ON s.id = l.shop_id
             INNER JOIN users u ON u.id = s.owner_user_id
             LEFT JOIN inventory i ON i.listing_id = l.id
             LEFT JOIN listing_flags lf ON lf.listing_id = l.id
             {$whereSql}
             GROUP BY l.id, cp.name, cp.model, b.name, c.name, s.id, s.name,
                      s.owner_user_id, u.email, i.quantity_on_hand, i.quantity_reserved
             ORDER BY
                CASE l.status WHEN 'pending_approval' THEN 0 WHEN 'flagged' THEN 1 ELSE 2 END,
                l.updated_at DESC
             LIMIT 100"
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function decideListing(
        int $listingId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::listingDecision($input);
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT l.id, l.status, l.shop_id, s.owner_user_id, cp.name
                 FROM shop_product_listings l
                 INNER JOIN shops s ON s.id = l.shop_id
                 INNER JOIN canonical_products cp ON cp.id = l.canonical_product_id
                 WHERE l.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $listingId]);
            $listing = $statement->fetch();
            if ($listing === false) {
                throw new HttpException(404, 'Product listing not found.');
            }

            $update = $this->db->prepare(
                'UPDATE shop_product_listings
                 SET status = :status,
                     status_reason = :reason,
                     approved_by_user_id = CASE
                         WHEN :approval_status = "active" THEN :admin_id
                         ELSE approved_by_user_id END,
                     approved_at = CASE
                         WHEN :approval_status_time = "active" THEN CURRENT_TIMESTAMP
                         ELSE approved_at END,
                     published_at = CASE
                         WHEN :publication_status = "active"
                         THEN COALESCE(published_at, CURRENT_TIMESTAMP)
                         ELSE published_at END
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $data['status'],
                'reason' => $data['reason'] ?: null,
                'approval_status' => $data['status'],
                'approval_status_time' => $data['status'],
                'publication_status' => $data['status'],
                'admin_id' => $adminUserId,
                'id' => $listingId,
            ]);

            $message = $data['status'] === 'active'
                ? sprintf('Your listing "%s" was approved and is now active.', $listing['name'])
                : sprintf(
                    'Your listing "%s" is now %s. Reason: %s',
                    $listing['name'],
                    $data['status'],
                    $data['reason']
                );
            $this->notify(
                (int) $listing['owner_user_id'],
                'listing_' . $data['status'],
                'Listing status updated',
                $message,
                'listing',
                $listingId
            );
            $this->users->audit(
                $adminUserId,
                'admin.listing_' . $data['status'],
                'shop_product_listing',
                $listingId,
                [
                    'before' => $listing['status'],
                    'after' => $data['status'],
                    'reason' => $data['reason'],
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
        return $this->findListing($listingId);
    }

    /** @return array<int, array<string, mixed>> */
    public function flags(string $status): array
    {
        $allowed = ['open', 'dismissed', 'actioned'];
        $where = in_array($status, $allowed, true) ? 'WHERE lf.status = :status' : '';
        $statement = $this->db->prepare(
            "SELECT lf.id, lf.rule_code, lf.rule_version, lf.severity,
                    lf.observed_value, lf.explanation, lf.status,
                    lf.reviewed_at, lf.created_at,
                    l.id AS listing_id, l.status AS listing_status, l.sku,
                    cp.name AS product_name, cp.model, s.name AS shop_name,
                    u.email AS owner_email
             FROM listing_flags lf
             INNER JOIN shop_product_listings l ON l.id = lf.listing_id
             INNER JOIN canonical_products cp ON cp.id = l.canonical_product_id
             INNER JOIN shops s ON s.id = l.shop_id
             INNER JOIN users u ON u.id = s.owner_user_id
             {$where}
             ORDER BY
                CASE lf.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                lf.created_at ASC
             LIMIT 100"
        );
        $statement->execute($where === '' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function decideFlag(
        int $flagId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::queueDecision($input, 'flag');
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT lf.id, lf.status, lf.listing_id, lf.explanation,
                        s.owner_user_id
                 FROM listing_flags lf
                 INNER JOIN shop_product_listings l ON l.id = lf.listing_id
                 INNER JOIN shops s ON s.id = l.shop_id
                 WHERE lf.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $flagId]);
            $flag = $statement->fetch();
            if ($flag === false) {
                throw new HttpException(404, 'Listing flag not found.');
            }
            $update = $this->db->prepare(
                'UPDATE listing_flags
                 SET status = :status,
                     reviewed_by_user_id = :admin_id,
                     reviewed_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $data['status'],
                'admin_id' => $adminUserId,
                'id' => $flagId,
            ]);
            if ($data['status'] === 'actioned') {
                $listing = $this->db->prepare(
                    'UPDATE shop_product_listings
                     SET status = "flagged", status_reason = :reason
                     WHERE id = :id'
                );
                $listing->execute([
                    'reason' => $data['note'],
                    'id' => $flag['listing_id'],
                ]);
                $this->notify(
                    (int) $flag['owner_user_id'],
                    'listing_flag_actioned',
                    'Listing requires attention',
                    $data['note'],
                    'listing',
                    (int) $flag['listing_id']
                );
            }
            $this->users->audit(
                $adminUserId,
                'admin.listing_flag_' . $data['status'],
                'listing_flag',
                $flagId,
                [
                    'listing_id' => $flag['listing_id'],
                    'note' => $data['note'],
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
        return $this->findQueueItem($this->flags(''), $flagId);
    }

    /** @return array<int, array<string, mixed>> */
    public function complaints(string $status): array
    {
        $allowed = ['open', 'under_review', 'resolved', 'dismissed'];
        $where = in_array($status, $allowed, true) ? 'WHERE c.status = :status' : '';
        $statement = $this->db->prepare(
            "SELECT c.id, c.subject, c.description, c.status, c.resolution_note,
                    c.created_at, c.updated_at, c.resolved_at,
                    u.email AS customer_email, s.name AS shop_name,
                    l.sku AS listing_sku, cp.name AS product_name,
                    admin.email AS assigned_admin_email
             FROM complaints c
             INNER JOIN users u ON u.id = c.customer_user_id
             LEFT JOIN shops s ON s.id = c.shop_id
             LEFT JOIN shop_product_listings l ON l.id = c.listing_id
             LEFT JOIN canonical_products cp ON cp.id = l.canonical_product_id
             LEFT JOIN users admin ON admin.id = c.assigned_admin_user_id
             {$where}
             ORDER BY
                CASE c.status WHEN 'open' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END,
                c.created_at ASC
             LIMIT 100"
        );
        $statement->execute($where === '' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function decideComplaint(
        int $complaintId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::queueDecision($input, 'complaint');
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT id, status, customer_user_id, subject
                 FROM complaints
                 WHERE id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $complaintId]);
            $complaint = $statement->fetch();
            if ($complaint === false) {
                throw new HttpException(404, 'Complaint not found.');
            }
            $update = $this->db->prepare(
                'UPDATE complaints
                 SET status = :status,
                     assigned_admin_user_id = :admin_id,
                     resolution_note = :note,
                     resolved_at = CASE
                         WHEN :final_status IN ("resolved", "dismissed")
                         THEN CURRENT_TIMESTAMP ELSE NULL END
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $data['status'],
                'admin_id' => $adminUserId,
                'note' => $data['note'] ?: null,
                'final_status' => $data['status'],
                'id' => $complaintId,
            ]);
            $this->notify(
                (int) $complaint['customer_user_id'],
                'complaint_' . $data['status'],
                'Complaint status updated',
                sprintf(
                    'Your complaint "%s" is now %s.%s',
                    $complaint['subject'],
                    str_replace('_', ' ', $data['status']),
                    $data['note'] === '' ? '' : ' ' . $data['note']
                ),
                'complaint',
                $complaintId
            );
            $this->users->audit(
                $adminUserId,
                'admin.complaint_' . $data['status'],
                'complaint',
                $complaintId,
                ['before' => $complaint['status'], 'note' => $data['note']],
                $ipAddress
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->findQueueItem($this->complaints(''), $complaintId);
    }

    /** @return array<int, array<string, mixed>> */
    public function reports(string $status): array
    {
        $allowed = ['open', 'under_review', 'dismissed', 'actioned'];
        $where = in_array($status, $allowed, true) ? 'WHERE cr.status = :status' : '';
        $statement = $this->db->prepare(
            "SELECT cr.id, cr.reason_code, cr.description, cr.status,
                    cr.review_note, cr.created_at, cr.reviewed_at,
                    reporter.email AS reporter_email,
                    l.id AS listing_id, l.sku, l.status AS listing_status,
                    cp.name AS product_name, cp.model,
                    s.name AS shop_name, owner.email AS owner_email
             FROM counterfeit_reports cr
             INNER JOIN users reporter ON reporter.id = cr.reporter_user_id
             INNER JOIN shop_product_listings l ON l.id = cr.listing_id
             INNER JOIN canonical_products cp ON cp.id = l.canonical_product_id
             INNER JOIN shops s ON s.id = l.shop_id
             INNER JOIN users owner ON owner.id = s.owner_user_id
             {$where}
             ORDER BY
                CASE cr.status WHEN 'open' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END,
                cr.created_at ASC
             LIMIT 100"
        );
        $statement->execute($where === '' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function decideReport(
        int $reportId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::queueDecision($input, 'report');
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT cr.id, cr.status, cr.reporter_user_id, cr.listing_id,
                        s.owner_user_id
                 FROM counterfeit_reports cr
                 INNER JOIN shop_product_listings l ON l.id = cr.listing_id
                 INNER JOIN shops s ON s.id = l.shop_id
                 WHERE cr.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $reportId]);
            $report = $statement->fetch();
            if ($report === false) {
                throw new HttpException(404, 'Counterfeit report not found.');
            }
            $update = $this->db->prepare(
                'UPDATE counterfeit_reports
                 SET status = :status,
                     reviewed_by_user_id = :admin_id,
                     review_note = :note,
                     reviewed_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $data['status'],
                'admin_id' => $adminUserId,
                'note' => $data['note'] ?: null,
                'id' => $reportId,
            ]);
            if ($data['status'] === 'actioned') {
                $listing = $this->db->prepare(
                    'UPDATE shop_product_listings
                     SET status = "flagged", status_reason = :reason
                     WHERE id = :id'
                );
                $listing->execute([
                    'reason' => $data['note'],
                    'id' => $report['listing_id'],
                ]);
                $this->notify(
                    (int) $report['owner_user_id'],
                    'counterfeit_report_actioned',
                    'Listing placed under review',
                    $data['note'],
                    'listing',
                    (int) $report['listing_id']
                );
            }
            $this->notify(
                (int) $report['reporter_user_id'],
                'counterfeit_report_' . $data['status'],
                'Product report updated',
                sprintf(
                    'Your product report is now %s.%s',
                    str_replace('_', ' ', $data['status']),
                    $data['note'] === '' ? '' : ' ' . $data['note']
                ),
                'counterfeit_report',
                $reportId
            );
            $this->users->audit(
                $adminUserId,
                'admin.counterfeit_report_' . $data['status'],
                'counterfeit_report',
                $reportId,
                [
                    'before' => $report['status'],
                    'listing_id' => $report['listing_id'],
                    'note' => $data['note'],
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
        return $this->findQueueItem($this->reports(''), $reportId);
    }

    /** @return array<string, mixed> */
    private function findListing(int $listingId): array
    {
        foreach ($this->listings('', '') as $listing) {
            if ((int) $listing['id'] === $listingId) {
                return $listing;
            }
        }
        throw new \RuntimeException('Updated listing could not be loaded.');
    }

    /** @param array<int, array<string, mixed>> $items
     *  @return array<string, mixed>
     */
    private function findQueueItem(array $items, int $id): array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }
        throw new \RuntimeException('Updated review item could not be loaded.');
    }

    private function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        string $resourceType,
        int $resourceId
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO notifications
                (user_id, type, title, message, related_resource_type,
                 related_resource_id)
             VALUES
                (:user_id, :type, :title, :message, :resource_type, :resource_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }
}
