<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Support\Jwt;
use Hexbay\Validation\AuthValidator;
use PDO;
use PDOException;

final class AuthService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users,
        private readonly Jwt $jwt
    ) {
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function register(array $input, string $role, string $ipAddress): array
    {
        if (!in_array($role, ['customer', 'shop_owner'], true)) {
            throw new HttpException(400, 'Unsupported registration role.');
        }

        $data = AuthValidator::registration($input, $role);

        if ($this->users->emailExists($data['email'])) {
            throw new HttpException(
                409,
                'An account with this email address already exists.',
                ['email' => ['Email address is already registered.']]
            );
        }

        try {
            $this->db->beginTransaction();
            $userId = $this->users->createUser([
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'role_id' => $this->users->roleId($role),
            ]);

            $profileData = [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
            ];
            if ($role === 'customer') {
                $this->users->createCustomerProfile($userId, $profileData);
            } else {
                $this->users->createShopOwnerProfile(
                    $userId,
                    $profileData + ['business_name' => $data['business_name']]
                );
            }

            $this->users->audit(
                $userId,
                'auth.registered',
                'user',
                $userId,
                ['role' => $role],
                $ipAddress
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(409, 'An account with this email address already exists.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $user = $this->users->findPublicById($userId);
        if ($user === null) {
            throw new \RuntimeException('Registered user could not be loaded.');
        }

        return $this->authenticatedPayload($user);
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function login(array $input, string $ipAddress): array
    {
        $credentials = AuthValidator::login($input);
        $emailHash = hash('sha256', $credentials['email']);
        if ($this->users->countRecentFailedLogins($emailHash, $ipAddress) >= 5) {
            throw new HttpException(
                429,
                'Too many unsuccessful login attempts. Try again after 15 minutes.'
            );
        }

        $authUser = $this->users->findForAuthentication($credentials['email']);
        $authenticated = $authUser !== null
            && $authUser['status'] === 'active'
            && password_verify($credentials['password'], (string) $authUser['password_hash']);

        $this->users->recordLoginAttempt($emailHash, $ipAddress, $authenticated);

        if (!$authenticated || $authUser === null) {
            throw new HttpException(401, 'The email address or password is incorrect.');
        }

        if (password_needs_rehash((string) $authUser['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash(
                (int) $authUser['id'],
                password_hash($credentials['password'], PASSWORD_DEFAULT)
            );
        }

        $this->users->markLogin((int) $authUser['id']);
        $this->users->audit(
            (int) $authUser['id'],
            'auth.logged_in',
            'user',
            (int) $authUser['id'],
            [],
            $ipAddress
        );

        $user = $this->users->findPublicById((int) $authUser['id']);
        if ($user === null) {
            throw new \RuntimeException('Authenticated user could not be loaded.');
        }

        return $this->authenticatedPayload($user);
    }

    /** @param array<string, mixed> $claims */
    public function logout(array $claims, string $ipAddress): void
    {
        $this->users->revokeToken(
            (string) $claims['jti'],
            (int) $claims['sub'],
            (int) $claims['exp']
        );
        $this->users->audit(
            (int) $claims['sub'],
            'auth.logged_out',
            'user',
            (int) $claims['sub'],
            ['token_jti_hash' => hash('sha256', (string) $claims['jti'])],
            $ipAddress
        );
    }

    /** @param array<string, mixed> $user
     *  @return array<string, mixed>
     */
    private function authenticatedPayload(array $user): array
    {
        $issued = $this->jwt->issue([
            'sub' => (string) $user['id'],
            'role' => $user['role'],
        ]);
        unset($issued['jti']);

        return [
            'token_type' => 'Bearer',
            'access_token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'user' => $user,
        ];
    }
}

