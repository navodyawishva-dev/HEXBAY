<?php
declare(strict_types=1);

namespace Hexbay\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function roleId(string $name): int
    {
        $statement = $this->db->prepare(
            'SELECT id FROM roles WHERE name = :name AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException("Required role is missing: {$name}");
        }
        return (int) $value;
    }

    public function emailExists(string $email): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array{email: string, password_hash: string, role_id: int} $data */
    public function createUser(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (role_id, email, password_hash, status)
             VALUES (:role_id, :email, :password_hash, "active")'
        );
        $statement->execute($data);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{first_name: string, last_name: string, phone: ?string} $data */
    public function createCustomerProfile(int $userId, array $data): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO customer_profiles (user_id, first_name, last_name, phone)
             VALUES (:user_id, :first_name, :last_name, :phone)'
        );
        $statement->execute(['user_id' => $userId] + $data);
    }

    /** @param array{first_name: string, last_name: string, phone: ?string, business_name: ?string} $data */
    public function createShopOwnerProfile(int $userId, array $data): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO shop_owner_profiles
                (user_id, first_name, last_name, phone, business_name)
             VALUES
                (:user_id, :first_name, :last_name, :phone, :business_name)'
        );
        $statement->execute(['user_id' => $userId] + $data);
    }

    /** @return array<string, mixed>|null */
    public function findForAuthentication(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.email, u.password_hash, u.status, u.role_id, r.name AS role
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findPublicById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                u.id,
                u.email,
                u.status,
                u.email_verified_at,
                u.last_login_at,
                u.created_at,
                r.name AS role,
                COALESCE(cp.first_name, sop.first_name, ap.first_name) AS first_name,
                COALESCE(cp.last_name, sop.last_name, ap.last_name) AS last_name,
                COALESCE(cp.phone, sop.phone) AS phone,
                sop.business_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN customer_profiles cp ON cp.user_id = u.id
             LEFT JOIN shop_owner_profiles sop ON sop.user_id = u.id
             LEFT JOIN administrator_profiles ap ON ap.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
    }

    public function markLogin(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function countRecentFailedLogins(string $emailHash, string $ipAddress): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE email_hash = :email_hash
               AND ip_address = :ip_address
               AND succeeded = 0
               AND attempted_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)'
        );
        $statement->execute([
            'email_hash' => $emailHash,
            'ip_address' => $ipAddress,
        ]);
        return (int) $statement->fetchColumn();
    }

    public function recordLoginAttempt(
        string $emailHash,
        string $ipAddress,
        bool $succeeded
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO login_attempts (email_hash, ip_address, succeeded)
             VALUES (:email_hash, :ip_address, :succeeded)'
        );
        $statement->execute([
            'email_hash' => $emailHash,
            'ip_address' => $ipAddress,
            'succeeded' => $succeeded ? 1 : 0,
        ]);
    }

    public function revokeToken(string $jti, int $userId, int $expiresAt): void
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO auth_revoked_tokens
                (jti_hash, user_id, expires_at)
             VALUES
                (:jti_hash, :user_id, :expires_at)'
        );
        $statement->execute([
            'jti_hash' => hash('sha256', $jti),
            'user_id' => $userId,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ]);
    }

    public function tokenIsRevoked(string $jti): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM auth_revoked_tokens
             WHERE jti_hash = :jti_hash
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $statement->execute(['jti_hash' => hash('sha256', $jti)]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $metadata */
    public function audit(
        ?int $actorUserId,
        string $action,
        string $resourceType,
        ?int $resourceId,
        array $metadata,
        string $ipAddress
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, action, resource_type, resource_id, metadata_json, ip_address)
             VALUES
                (:actor_user_id, :action, :resource_type, :resource_id, :metadata_json, :ip_address)'
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata_json' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'ip_address' => $ipAddress,
        ]);
    }
}
