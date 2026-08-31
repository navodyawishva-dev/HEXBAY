<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Repositories\UserRepository;

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$email = strtolower(trim((string) readline('Administrator email: ')));
$firstName = trim((string) readline('First name: '));
$lastName = trim((string) readline('Last name: '));
$password = (string) readline('Password (10+ chars, uppercase, lowercase, number): ');

if (
    filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || strlen($firstName) < 2
    || strlen($lastName) < 2
    || strlen($password) < 10
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
) {
    fwrite(STDERR, "Invalid administrator details.\n");
    exit(1);
}

$db = Database::connection();
$users = new UserRepository($db);
if ($users->emailExists($email)) {
    fwrite(STDERR, "That email address is already registered.\n");
    exit(1);
}

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
         VALUES (:user_id, :first_name, :last_name)'
    );
    $profile->execute([
        'user_id' => $userId,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);

    $users->audit(
        $userId,
        'auth.admin_bootstrapped',
        'user',
        $userId,
        [],
        'cli'
    );
    $db->commit();
    fwrite(STDOUT, "Administrator account created successfully.\n");
} catch (\Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Administrator creation failed: {$exception->getMessage()}\n");
    exit(1);
}

