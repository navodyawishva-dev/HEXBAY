<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\AdminValidator;
use PDO;

final class AdminService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $counts = [];
        foreach (
            [
                'customers' => "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.name='customer'",
                'sellers' => "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.name='shop_owner'",
                'pending_shops' => "SELECT COUNT(*) FROM vendor_verifications WHERE status='pending'",
                'approved_shops' => "SELECT COUNT(*) FROM shops WHERE status='approved'",
                'suspended_accounts' => "SELECT COUNT(*) FROM users WHERE status='suspended'",
                'open_flags' => "SELECT COUNT(*) FROM listing_flags WHERE status='open'",
                'pending_listings' => "SELECT COUNT(*) FROM shop_product_listings WHERE status='pending_approval'",
                'open_complaints' => "SELECT COUNT(*) FROM complaints WHERE status IN ('open', 'under_review')",
                'open_reports' => "SELECT COUNT(*) FROM counterfeit_reports WHERE status IN ('open', 'under_review')",
            ] as $key => $sql
        ) {
            $counts[$key] = (int) $this->db->query($sql)->fetchColumn();
        }

        $applications = $this->db->query(
            'SELECT vv.id, s.name AS shop_name, vv.legal_name, vv.submitted_at,
                    u.email AS owner_email
             FROM vendor_verifications vv
             INNER JOIN shops s ON s.id = vv.shop_id
             INNER JOIN users u ON u.id = s.owner_user_id
             WHERE vv.status = "pending"
             ORDER BY vv.submitted_at ASC
             LIMIT 5'
        )->fetchAll();

        $rule = $this->db->query(
            'SELECT id, percentage, effective_from
             FROM commission_rules
             WHERE effective_from <= CURRENT_TIMESTAMP
               AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
             ORDER BY effective_from DESC
             LIMIT 1'
        )->fetch();

        return [
            'counts' => $counts,
            'pending_applications' => $applications,
            'current_commission' => $rule ?: null,
            'finance' => $this->financeOverview()['summary'],
        ];
    }

    /** @return array<string, mixed> */
    public function listUsers(
        string $role,
        string $status,
        string $search,
        int $page,
        int $perPage
    ): array {
        $where = ['r.name IN ("customer", "shop_owner")'];
        $params = [];
        if (in_array($role, ['customer', 'shop_owner'], true)) {
            $where[] = 'r.name = :role';
            $params['role'] = $role;
        }
        if (in_array($status, ['pending', 'active', 'suspended', 'deactivated'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(u.email LIKE :search OR cp.first_name LIKE :search
                         OR cp.last_name LIKE :search OR sop.first_name LIKE :search
                         OR sop.last_name LIKE :search OR sop.business_name LIKE :search)';
            $params['search'] = '%' . substr($search, 0, 100) . '%';
        }
        $whereSql = implode(' AND ', $where);
        $page = max($page, 1);
        $perPage = min(max($perPage, 5), 50);
        $offset = ($page - 1) * $perPage;

        $count = $this->db->prepare(
            "SELECT COUNT(*)
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN customer_profiles cp ON cp.user_id = u.id
             LEFT JOIN shop_owner_profiles sop ON sop.user_id = u.id
             WHERE {$whereSql}"
        );
        $count->execute($params);

        $statement = $this->db->prepare(
            "SELECT u.id, u.email, u.status, u.last_login_at, u.created_at,
                    r.name AS role,
                    COALESCE(cp.first_name, sop.first_name) AS first_name,
                    COALESCE(cp.last_name, sop.last_name) AS last_name,
                    sop.business_name,
                    s.id AS shop_id, s.name AS shop_name, s.status AS shop_status
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN customer_profiles cp ON cp.user_id = u.id
             LEFT JOIN shop_owner_profiles sop ON sop.user_id = u.id
             LEFT JOIN shops s ON s.owner_user_id = u.id
             WHERE {$whereSql}
             ORDER BY u.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $count->fetchColumn(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function updateUserStatus(
        int $targetUserId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        if ($targetUserId === $adminUserId) {
            throw new HttpException(409, 'You cannot change your own account status.');
        }
        $data = AdminValidator::accountStatus($input);
        $target = $this->users->findPublicById($targetUserId);
        if ($target === null || $target['role'] === 'administrator') {
            throw new HttpException(404, 'Managed account not found.');
        }
        $previous = (string) $target['status'];

        $statement = $this->db->prepare(
            'UPDATE users SET status = :status WHERE id = :id'
        );
        $statement->execute(['status' => $data['status'], 'id' => $targetUserId]);
        $this->users->audit(
            $adminUserId,
            'admin.user_status_changed',
            'user',
            $targetUserId,
            ['before' => $previous, 'after' => $data['status'], 'reason' => $data['reason']],
            $ipAddress
        );
        return $this->users->findPublicById($targetUserId) ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function shopApplications(string $status): array
    {
        $allowed = ['pending', 'approved', 'rejected', 'suspended'];
        $where = in_array($status, $allowed, true) ? 'WHERE vv.status = :status' : '';
        $statement = $this->db->prepare(
            "SELECT
                vv.id, vv.submission_number, vv.status, vv.legal_name,
                vv.business_registration_reference, vv.submitted_at,
                vv.reviewed_at, vv.decision_reason,
                s.id AS shop_id, s.name AS shop_name, s.contact_email,
                s.contact_phone, s.status AS shop_status,
                u.id AS owner_user_id, u.email AS owner_email,
                CONCAT(sop.first_name, ' ', sop.last_name) AS owner_name,
                ca.percentage_snapshot, ca.terms_version, ca.accepted_at,
                (
                    SELECT COUNT(*)
                    FROM verification_documents vd
                    WHERE vd.verification_id = vv.id
                ) AS document_count
             FROM vendor_verifications vv
             INNER JOIN shops s ON s.id = vv.shop_id
             INNER JOIN users u ON u.id = s.owner_user_id
             INNER JOIN shop_owner_profiles sop ON sop.user_id = u.id
             LEFT JOIN commission_acceptances ca
                ON ca.shop_id = s.id
               AND ca.id = (
                    SELECT MAX(ca2.id)
                    FROM commission_acceptances ca2
                    WHERE ca2.shop_id = s.id
               )
             {$where}
             ORDER BY
                CASE vv.status WHEN 'pending' THEN 0 ELSE 1 END,
                vv.submitted_at DESC"
        );
        $statement->execute($where === '' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function decideShop(
        int $verificationId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::shopDecision($input);
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT vv.id, vv.status, vv.shop_id, s.owner_user_id, s.name,
                        ca.percentage_snapshot
                 FROM vendor_verifications vv
                 INNER JOIN shops s ON s.id = vv.shop_id
                 LEFT JOIN commission_acceptances ca
                    ON ca.shop_id = s.id AND ca.superseded_at IS NULL
                 WHERE vv.id = :id
                 ORDER BY ca.id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['id' => $verificationId]);
            $application = $statement->fetch();
            if ($application === false) {
                throw new HttpException(404, 'Shop application not found.');
            }
            if (
                $data['decision'] !== 'suspended'
                && $application['status'] !== 'pending'
            ) {
                throw new HttpException(409, 'This application has already been decided.');
            }
            if (
                $data['decision'] === 'approved'
                && $application['percentage_snapshot'] === null
            ) {
                throw new HttpException(
                    409,
                    'The seller has not accepted the commission policy.'
                );
            }
            if ($data['decision'] === 'approved') {
                $documents = $this->db->prepare(
                    'SELECT COUNT(*) FROM verification_documents
                     WHERE verification_id=:verification_id'
                );
                $documents->execute(['verification_id' => $verificationId]);
                if ((int) $documents->fetchColumn() < 1) {
                    throw new HttpException(
                        409,
                        'A business registration or equivalent verification document is required before approval.'
                    );
                }
            }

            $verification = $this->db->prepare(
                'UPDATE vendor_verifications
                 SET status = :status,
                     reviewed_by_user_id = :reviewer,
                     reviewed_at = CURRENT_TIMESTAMP,
                     review_notes = :notes,
                     decision_reason = :reason
                 WHERE id = :id'
            );
            $verification->execute([
                'status' => $data['decision'],
                'reviewer' => $adminUserId,
                'notes' => $data['notes'] ?: null,
                'reason' => $data['reason'] ?: null,
                'id' => $verificationId,
            ]);

            $shop = $this->db->prepare(
                'UPDATE shops
                 SET status = :status,
                     status_reason = :reason,
                     approved_at = CASE
                         WHEN :status_for_approval = "approved"
                         THEN CURRENT_TIMESTAMP ELSE approved_at END
                 WHERE id = :shop_id'
            );
            $shop->execute([
                'status' => $data['decision'],
                'status_for_approval' => $data['decision'],
                'reason' => $data['reason'] ?: null,
                'shop_id' => $application['shop_id'],
            ]);

            $message = $data['decision'] === 'approved'
                ? sprintf(
                    'Your shop has been approved. The accepted Hexbay commission is %s%% '
                    . 'on completed vendor sub-orders.',
                    $application['percentage_snapshot']
                )
                : sprintf(
                    'Your shop application was %s. Reason: %s',
                    $data['decision'],
                    $data['reason']
                );
            $notification = $this->db->prepare(
                'INSERT INTO notifications
                    (user_id, type, title, message, related_resource_type,
                     related_resource_id)
                 VALUES
                    (:user_id, :type, :title, :message, "shop", :shop_id)'
            );
            $notification->execute([
                'user_id' => $application['owner_user_id'],
                'type' => 'shop_' . $data['decision'],
                'title' => 'Shop application ' . ucfirst($data['decision']),
                'message' => $message,
                'shop_id' => $application['shop_id'],
            ]);

            $this->users->audit(
                $adminUserId,
                'admin.shop_' . $data['decision'],
                'vendor_verification',
                $verificationId,
                [
                    'shop_id' => $application['shop_id'],
                    'previous_status' => $application['status'],
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

        foreach ($this->shopApplications('') as $item) {
            if ((int) $item['id'] === $verificationId) {
                return $item;
            }
        }
        throw new \RuntimeException('Updated shop application could not be loaded.');
    }

    /** @return array<int, array<string, mixed>> */
    public function commissionRules(): array
    {
        return $this->db->query(
            'SELECT cr.id, cr.percentage, cr.effective_from, cr.effective_to,
                    cr.reason, cr.created_at, u.email AS created_by
             FROM commission_rules cr
             LEFT JOIN users u ON u.id = cr.created_by_user_id
             ORDER BY cr.effective_from DESC'
        )->fetchAll();
    }

    /** @return array<string, mixed> */
    public function createCommissionRule(
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::commissionRule($input);
        try {
            $this->db->beginTransaction();
            $current = $this->db->query(
                'SELECT id, percentage
                 FROM commission_rules
                 WHERE effective_from <= CURRENT_TIMESTAMP
                   AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
                 ORDER BY effective_from DESC
                 LIMIT 1
                 FOR UPDATE'
            )->fetch();
            if ($current !== false && (string) $current['percentage'] === $data['percentage']) {
                throw new HttpException(409, 'That commission percentage is already active.');
            }
            $this->db->exec(
                'UPDATE commission_rules
                 SET effective_to = CURRENT_TIMESTAMP
                 WHERE effective_from <= CURRENT_TIMESTAMP
                   AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)'
            );
            $statement = $this->db->prepare(
                'INSERT INTO commission_rules
                    (percentage, effective_from, created_by_user_id, reason)
                 VALUES
                    (:percentage, CURRENT_TIMESTAMP, :created_by, :reason)'
            );
            $statement->execute([
                'percentage' => $data['percentage'],
                'created_by' => $adminUserId,
                'reason' => $data['reason'],
            ]);
            $ruleId = (int) $this->db->lastInsertId();
            $this->db->exec(
                'UPDATE commission_acceptances
                 SET superseded_at = CURRENT_TIMESTAMP
                 WHERE superseded_at IS NULL'
            );
            $notification = $this->db->prepare(
                'INSERT INTO notifications (user_id, type, title, message)
                 SELECT s.owner_user_id,
                        "commission_changed",
                        "Commission policy updated",
                        :message
                 FROM shops s
                 WHERE s.status = "approved"'
            );
            $notification->execute([
                'message' => sprintf(
                    'Hexbay’s platform commission is now %s%%. Review and accept the '
                    . 'updated policy in your seller dashboard.',
                    $data['percentage']
                ),
            ]);
            $this->users->audit(
                $adminUserId,
                'admin.commission_rule_created',
                'commission_rule',
                $ruleId,
                [
                    'previous_percentage' => $current['percentage'] ?? null,
                    'new_percentage' => $data['percentage'],
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

        foreach ($this->commissionRules() as $rule) {
            if ((int) $rule['id'] === $ruleId) {
                return $rule;
            }
        }
        throw new \RuntimeException('Created commission rule could not be loaded.');
    }

    /** @return array<string, mixed> */
    public function financeOverview(): array
    {
        $sales = $this->db->query(
            'SELECT
                COALESCE(SUM(gross_total),0) completed_gross_sales,
                COALESCE(SUM(commission_amount),0) commission_earned,
                COALESCE(SUM(vendor_net_amount),0) seller_net_earned,
                COUNT(*) completed_sub_orders
             FROM vendor_sub_orders WHERE status="completed"'
        )->fetch();
        $payoutSummary = $this->db->query(
            'SELECT
                COALESCE(SUM(CASE WHEN status="pending" THEN amount ELSE 0 END),0) pending_payout_amount,
                COALESCE(SUM(CASE WHEN status="approved" THEN amount ELSE 0 END),0) approved_payout_amount,
                COALESCE(SUM(CASE WHEN status="paid" THEN amount ELSE 0 END),0) paid_payout_amount,
                SUM(CASE WHEN status="pending" THEN 1 ELSE 0 END) pending_payout_count
             FROM payouts'
        )->fetch();
        $payouts = $this->db->query(
            'SELECT p.id, p.payout_reference, p.amount, p.currency_code,
                    p.status, p.decision_reason, p.requested_at,
                    p.approved_at, p.paid_at, p.rejected_at,
                    s.id shop_id, s.name shop_name,
                    owner.email owner_email, reviewer.email reviewed_by
             FROM payouts p
             INNER JOIN shops s ON s.id=p.shop_id
             INNER JOIN users owner ON owner.id=s.owner_user_id
             LEFT JOIN users reviewer ON reviewer.id=p.reviewed_by_user_id
             ORDER BY
                CASE p.status WHEN "pending" THEN 0 WHEN "approved" THEN 1 ELSE 2 END,
                p.requested_at DESC
             LIMIT 100'
        )->fetchAll();
        return [
            'summary' => [
                ...$sales,
                ...$payoutSummary,
            ],
            'payouts' => $payouts,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function decidePayout(
        int $payoutId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::payoutDecision($input);
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'SELECT p.id, p.payout_reference, p.shop_id, p.amount, p.status,
                        s.name shop_name, s.owner_user_id
                 FROM payouts p
                 INNER JOIN shops s ON s.id=p.shop_id
                 WHERE p.id=:id FOR UPDATE'
            );
            $statement->execute(['id' => $payoutId]);
            $payout = $statement->fetch();
            if ($payout === false) {
                throw new HttpException(404, 'Payout request not found.');
            }
            $allowed = match ((string) $payout['status']) {
                'pending' => ['approved', 'rejected'],
                'approved' => ['paid', 'rejected'],
                default => [],
            };
            if (!in_array($data['decision'], $allowed, true)) {
                throw new HttpException(409, 'This payout cannot move from '
                    . $payout['status'] . ' to ' . $data['decision'] . '.');
            }
            $update = $this->db->prepare(
                'UPDATE payouts
                 SET status=:status,
                     reviewed_by_user_id=:reviewer,
                     decision_reason=:reason,
                     approved_at=CASE WHEN :approved="approved" THEN COALESCE(approved_at,CURRENT_TIMESTAMP) ELSE approved_at END,
                     paid_at=CASE WHEN :paid="paid" THEN CURRENT_TIMESTAMP ELSE paid_at END,
                     rejected_at=CASE WHEN :rejected="rejected" THEN CURRENT_TIMESTAMP ELSE rejected_at END
                 WHERE id=:id'
            );
            $update->execute([
                'status' => $data['decision'],
                'reviewer' => $adminUserId,
                'reason' => $data['reason'] ?: null,
                'approved' => $data['decision'],
                'paid' => $data['decision'],
                'rejected' => $data['decision'],
                'id' => $payoutId,
            ]);
            if ($data['decision'] === 'paid') {
                $ledger = $this->db->prepare(
                    'INSERT IGNORE INTO ledger_entries
                        (event_key, shop_id, payout_id, entry_type, amount,
                         description, created_by_user_id)
                     VALUES
                        (:event_key, :shop_id, :payout_id, "payout", :amount,
                         :description, :created_by)'
                );
                $ledger->execute([
                    'event_key' => 'payout.' . $payoutId,
                    'shop_id' => $payout['shop_id'],
                    'payout_id' => $payoutId,
                    'amount' => number_format(-(float) $payout['amount'], 2, '.', ''),
                    'description' => 'Simulated payout ' . $payout['payout_reference'],
                    'created_by' => $adminUserId,
                ]);
            }
            $notification = $this->db->prepare(
                'INSERT INTO notifications
                    (user_id, type, title, message, related_resource_type, related_resource_id)
                 VALUES
                    (:user_id, :type, :title, :message, "payout", :payout_id)'
            );
            $notification->execute([
                'user_id' => $payout['owner_user_id'],
                'type' => 'payout_' . $data['decision'],
                'title' => 'Payout ' . ucfirst($data['decision']),
                'message' => sprintf(
                    'Payout %s for LKR %s was %s%s.',
                    $payout['payout_reference'],
                    number_format((float) $payout['amount'], 2),
                    $data['decision'],
                    $data['reason'] === '' ? '' : ': ' . $data['reason']
                ),
                'payout_id' => $payoutId,
            ]);
            $this->users->audit(
                $adminUserId,
                'admin.payout_' . $data['decision'],
                'payout',
                $payoutId,
                [
                    'reference' => $payout['payout_reference'],
                    'shop_id' => $payout['shop_id'],
                    'amount' => $payout['amount'],
                    'previous_status' => $payout['status'],
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
        foreach ($this->financeOverview()['payouts'] as $item) {
            if ((int) $item['id'] === $payoutId) {
                return $item;
            }
        }
        throw new \RuntimeException('Updated payout could not be loaded.');
    }

    /** @return array<string, mixed> */
    public function auditLogs(int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 10), 100);
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $statement = $this->db->prepare(
            'SELECT al.id, al.action, al.resource_type, al.resource_id,
                    al.metadata_json, al.ip_address, al.created_at,
                    u.email AS actor_email
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.actor_user_id
             ORDER BY al.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'items' => $statement->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }
}
