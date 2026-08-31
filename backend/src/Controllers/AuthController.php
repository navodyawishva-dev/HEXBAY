<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\AuthService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function registerCustomer(): never
    {
        $data = $this->auth->register(Request::json(), 'customer', Request::ipAddress());
        ApiResponse::success('Customer account created.', $data, 201);
    }

    public function registerVendor(): never
    {
        $data = $this->auth->register(Request::json(), 'shop_owner', Request::ipAddress());
        ApiResponse::success(
            'Shop-owner account created. Shop verification is completed in Sprint 2.',
            $data,
            201
        );
    }

    public function login(): never
    {
        $data = $this->auth->login(Request::json(), Request::ipAddress());
        ApiResponse::success('Login successful.', $data);
    }
}

