<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\UserRepository;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$email = strtolower(trim((string) getenv('HEX_TEST_ADMIN_EMAIL')));
$password = (string) getenv('HEX_TEST_ADMIN_PASSWORD');
if (
    PHP_SAPI !== 'cli'
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || strlen($password) < 16
) {
    fwrite(STDERR, "Invalid Sprint 2 administrator fixture environment.\n");
    exit(1);
}

$db = Database::connection();
$users = new UserRepository($db);
try {
    $db->beginTransaction();
    $statement = $db->prepare(
        'INSERT INTO users (role_id, email, password_hash, status, email_verified_at)
         VALUES (:role_id, :email, :password_hash, "active", CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        'role_id' => $users->roleId('administrator'),
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $db->lastInsertId();
    $profile = $db->prepare(
        'INSERT INTO administrator_profiles (user_id, first_name, last_name)
         VALUES (:user_id, "Sprint", "Two Test")'
    );
    $profile->execute(['user_id' => $userId]);
    $db->commit();
    echo json_encode(['id' => $userId, 'email' => $email], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

