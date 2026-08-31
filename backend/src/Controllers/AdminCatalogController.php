<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Services\AdminCatalogService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\Request;

final class AdminCatalogController
{
    public function __construct(private readonly AdminCatalogService $catalog)
    {
    }

    public function categories(): never
    {
        ApiResponse::success(
            'Administrator categories loaded.',
            ['categories' => $this->catalog->categories()]
        );
    }

    public function createCategory(int $adminUserId): never
    {
        ApiResponse::success(
            'Category created.',
            [
                'category' => $this->catalog->saveCategory(
                    null,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ],
            201
        );
    }

    public function updateCategory(int $categoryId, int $adminUserId): never
    {
        ApiResponse::success(
            'Category updated.',
            [
                'category' => $this->catalog->saveCategory(
                    $categoryId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }

    public function specifications(int $categoryId): never
    {
        ApiResponse::success(
            'Category specifications loaded.',
            ['specifications' => $this->catalog->specifications($categoryId)]
        );
    }

    public function createSpecification(int $categoryId, int $adminUserId): never
    {
        ApiResponse::success(
            'Specification definition created.',
            [
                'specification' => $this->catalog->saveSpecification(
                    $categoryId,
                    null,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ],
            201
        );
    }

    public function updateSpecification(
        int $categoryId,
        int $specificationId,
        int $adminUserId
    ): never {
        ApiResponse::success(
            'Specification definition updated.',
            [
                'specification' => $this->catalog->saveSpecification(
                    $categoryId,
                    $specificationId,
                    $adminUserId,
                    Request::json(),
                    Request::ipAddress()
                ),
            ]
        );
    }
}
