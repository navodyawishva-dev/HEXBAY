<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\UserRepository;
use Hexbay\Services\AdminService;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::connection();
$payoutId = null;

try {
    $adminId = (int) $db->query(
        'SELECT u.id FROM users u INNER JOIN roles r ON r.id=u.role_id
         WHERE r.name="administrator" AND u.status="active" LIMIT 1'
    )->fetchColumn();
    $shop = $db->query(
        'SELECT s.id, s.owner_user_id FROM shops s
         WHERE s.status="approved" ORDER BY s.id LIMIT 1'
    )->fetch();
    if ($adminId < 1 || $shop === false) {
        throw new RuntimeException('An active administrator and approved shop are required.');
    }

    $reference = 'PAY-TEST-' . strtoupper(bin2hex(random_bytes(5)));
    $insert = $db->prepare(
        'INSERT INTO payouts
            (payout_reference, shop_id, requested_by_user_id, amount, status)
         VALUES (:reference, :shop_id, :owner_id, 100.00, "pending")'
    );
    $insert->execute([
        'reference' => $reference,
        'shop_id' => $shop['id'],
        'owner_id' => $shop['owner_user_id'],
    ]);
    $payoutId = (int) $db->lastInsertId();

    $service = new AdminService($db, new UserRepository($db));
    $overview = $service->financeOverview();
    if (
        !isset($overview['summary']['commission_earned'])
        || !isset($overview['summary']['pending_payout_count'])
        || count($overview['payouts']) < 1
    ) {
        throw new RuntimeException('Administrator finance overview is incomplete.');
    }

    $approved = $service->decidePayout(
        $payoutId,
        $adminId,
        ['decision' => 'approved', 'reason' => ''],
        '127.0.0.1'
    );
    if ($approved['status'] !== 'approved' || $approved['approved_at'] === null) {
        throw new RuntimeException('Pending payout was not approved.');
    }
    $paid = $service->decidePayout(
        $payoutId,
        $adminId,
        ['decision' => 'paid', 'reason' => ''],
        '127.0.0.1'
    );
    $ledger = $db->prepare(
        'SELECT amount FROM ledger_entries
         WHERE payout_id=:payout_id AND entry_type="payout"'
    );
    $ledger->execute(['payout_id' => $payoutId]);
    if ($paid['status'] !== 'paid' || (float) $ledger->fetchColumn() !== -100.0) {
        throw new RuntimeException('Approved payout was not recorded as a paid ledger entry.');
    }

    fwrite(STDOUT, "Administrator finance integration passed (earnings summary, approval, paid ledger, seller notifications).\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Administrator finance integration failed: {$exception->getMessage()}\n");
    $exitCode = 1;
} finally {
    if ($payoutId !== null) {
        foreach ([
            'DELETE FROM notifications WHERE related_resource_type="payout" AND related_resource_id=:id',
            'DELETE FROM audit_logs WHERE resource_type="payout" AND resource_id=:id',
            'DELETE FROM ledger_entries WHERE payout_id=:id',
            'DELETE FROM payouts WHERE id=:id',
        ] as $sql) {
            $cleanup = $db->prepare($sql);
            $cleanup->execute(['id' => $payoutId]);
        }
    }
}

exit($exitCode ?? 0);
