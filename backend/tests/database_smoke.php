<?php
declare(strict_types=1);

use Hexbay\Config\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = Database::connection();
    $databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $tableCount = (int) $db->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_type = 'BASE TABLE'"
    )->fetchColumn();
    $roles = $db->query(
        'SELECT name FROM roles ORDER BY name'
    )->fetchAll(PDO::FETCH_COLUMN);

    $requiredRoles = ['administrator', 'customer', 'shop_owner'];
    sort($roles);
    if ($databaseName !== 'hexbay' || $tableCount < 40 || $roles !== $requiredRoles) {
        throw new RuntimeException('Database baseline is incomplete.');
    }

    $db->beginTransaction();
    $email = 'database-smoke-' . bin2hex(random_bytes(5)) . '@example.test';
    $roleId = (int) $db->query(
        "SELECT id FROM roles WHERE name = 'customer'"
    )->fetchColumn();
    $statement = $db->prepare(
        'INSERT INTO users (role_id, email, password_hash, status)
         VALUES (:role_id, :email, :password_hash, "active")'
    );
    $statement->execute([
        'role_id' => $roleId,
        'email' => $email,
        'password_hash' => password_hash('DatabasePass123', PASSWORD_DEFAULT),
    ]);
    $db->rollBack();

    $check = $db->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
    $check->execute(['email' => $email]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Transaction rollback check failed.');
    }

    fwrite(
        STDOUT,
        "Database smoke test passed ({$tableCount} tables in {$databaseName}).\n"
    );
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Database smoke test failed: {$exception->getMessage()}\n");
    exit(1);
}

