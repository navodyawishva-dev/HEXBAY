<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class AuthValidator
{
    /** @param array<string, mixed> $input
     *  @return array{email: string, password: string, first_name: string, last_name: string, phone: ?string, business_name: ?string}
     */
    public static function registration(array $input, string $role): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $businessName = trim((string) ($input['business_name'] ?? ''));

        $errors = [];
        if (
            $email === ''
            || strlen($email) > 190
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'][] = 'Enter a valid email address.';
        }

        if (strlen($password) < 10 || strlen($password) > 128) {
            $errors['password'][] = 'Password must contain between 10 and 128 characters.';
        }
        if (
            !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
        ) {
            $errors['password'][] = 'Password must include uppercase, lowercase, and numeric characters.';
        }

        foreach (['first_name' => $firstName, 'last_name' => $lastName] as $field => $value) {
            if (strlen($value) < 2 || strlen($value) > 80) {
                $errors[$field][] = 'Use between 2 and 80 characters.';
            }
        }

        if ($phone !== '' && preg_match('/^\+?[0-9][0-9\s-]{6,19}$/', $phone) !== 1) {
            $errors['phone'][] = 'Enter a valid telephone number.';
        }

        if ($role === 'shop_owner' && (strlen($businessName) < 2 || strlen($businessName) > 150)) {
            $errors['business_name'][] = 'Business name must contain between 2 and 150 characters.';
        }

        if ($errors !== []) {
            throw new HttpException(422, 'Registration validation failed.', $errors);
        }

        return [
            'email' => $email,
            'password' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone !== '' ? $phone : null,
            'business_name' => $businessName !== '' ? $businessName : null,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array{email: string, password: string}
     */
    public static function login(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $errors = [];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors['password'][] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new HttpException(422, 'Login validation failed.', $errors);
        }

        return ['email' => $email, 'password' => $password];
    }
}

