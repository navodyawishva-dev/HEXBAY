<?php
declare(strict_types=1);

use Hexbay\Config\Database;
use Hexbay\Config\Env;
use Hexbay\Controllers\AuthController;
use Hexbay\Controllers\BuyerController;
use Hexbay\Controllers\AdminController;
use Hexbay\Controllers\AdminCatalogController;
use Hexbay\Controllers\AdminModerationController;
use Hexbay\Controllers\HealthController;
use Hexbay\Controllers\HexBotController;
use Hexbay\Controllers\PublicController;
use Hexbay\Controllers\PcCompatibilityController;
use Hexbay\Controllers\PcBuildRecommendationController;
use Hexbay\Controllers\RecommendationController;
use Hexbay\Controllers\SellerController;
use Hexbay\Controllers\SellerModuleController;
use Hexbay\Controllers\UploadController;
use Hexbay\Middleware\AuthMiddleware;
use Hexbay\Middleware\CorsMiddleware;
use Hexbay\Middleware\RoleMiddleware;
use Hexbay\Repositories\UserRepository;
use Hexbay\Repositories\HexBotRepository;
use Hexbay\Repositories\LaptopRecommendationRepository;
use Hexbay\Repositories\MarketplaceRepository;
use Hexbay\Repositories\PcCompatibilityRepository;
use Hexbay\Repositories\PcBuildRecommendationRepository;
use Hexbay\Services\AdminService;
use Hexbay\Services\AdminCatalogService;
use Hexbay\Services\AdminModerationService;
use Hexbay\Services\AuthService;
use Hexbay\Services\BuyerService;
use Hexbay\Services\FlaskLaptopRankingClient;
use Hexbay\Services\FlaskPeripheralRankingClient;
use Hexbay\Services\HexBotConversationService;
use Hexbay\Services\HexBotInterpreter;
use Hexbay\Services\LaptopRecommendationService;
use Hexbay\Services\PcCompatibilityEngine;
use Hexbay\Services\PcCompatibilityService;
use Hexbay\Services\PcBuildOptimizer;
use Hexbay\Services\PcBuildRecommendationService;
use Hexbay\Services\PeripheralRecommendationService;
use Hexbay\Services\TechnicalQuestionService;
use Hexbay\Services\ProductComparisonService;
use Hexbay\Services\ShopApplicationService;
use Hexbay\Services\SellerModuleService;
use Hexbay\Services\UploadService;
use Hexbay\Support\ApiResponse;
use Hexbay\Support\HttpException;
use Hexbay\Support\Jwt;
use Hexbay\Support\Request;
use Hexbay\Support\Router;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    CorsMiddleware::handle(Env::get(
        'FRONTEND_ORIGIN',
        'http://localhost:5173,http://127.0.0.1:5173'
    ));

    $db = Database::connection();
    $users = new UserRepository($db);
    $jwt = new Jwt(
        Env::get('JWT_SECRET'),
        Env::get('JWT_ISSUER', 'hexbay-local-api'),
        Env::get('JWT_AUDIENCE', 'hexbay-react'),
        Env::int('JWT_TTL_SECONDS', 3600)
    );
    $authService = new AuthService($db, $users, $jwt);
    $buyerService = new BuyerService($db, $users);
    $marketplace = new MarketplaceRepository($db);
    $laptopRecommendationRepository = new LaptopRecommendationRepository($db);
    $laptopRankingClient = new FlaskLaptopRankingClient(
        Env::get('AI_SERVICE_URL', 'http://127.0.0.1:5000'),
        Env::get('AI_SERVICE_SECRET', ''),
        Env::int('AI_SERVICE_TIMEOUT_SECONDS', 8)
    );
    $laptopRecommendationService = new LaptopRecommendationService(
        $laptopRecommendationRepository,
        $laptopRankingClient
    );
    $pcComponentRepository = new PcCompatibilityRepository($db);
    $pcCompatibilityEngine = new PcCompatibilityEngine();
    $pcCompatibilityService = new PcCompatibilityService(
        $pcComponentRepository,
        $pcCompatibilityEngine
    );
    $peripheralRecommendationService = new PeripheralRecommendationService(
        $pcComponentRepository,
        new FlaskPeripheralRankingClient(
            Env::get('AI_SERVICE_URL', 'http://127.0.0.1:5000'),
            Env::get('AI_SERVICE_SECRET', ''),
            Env::int('AI_SERVICE_TIMEOUT_SECONDS', 8)
        ),
        Env::get('APP_ENV', 'production') === 'development'
            && strtolower(Env::get('PERIPHERAL_DEMO_MODE', 'false')) === 'true'
    );
    $pcBuildRecommendationService = new PcBuildRecommendationService(
        new PcBuildRecommendationRepository($db, $pcComponentRepository),
        new PcBuildOptimizer($pcCompatibilityEngine),
        $peripheralRecommendationService
    );
    $hexBotService = new HexBotConversationService(
        new HexBotRepository($db),
        new HexBotInterpreter(),
        $laptopRecommendationService,
        $pcBuildRecommendationService,
        $peripheralRecommendationService,
        new TechnicalQuestionService(),
        new ProductComparisonService($marketplace)
    );
    $shopApplications = new ShopApplicationService($db, $marketplace, $users);
    $sellerModuleService = new SellerModuleService($db, $users);
    $uploadService = new UploadService($db, $users);
    $adminService = new AdminService($db, $users);
    $adminCatalogService = new AdminCatalogService($db, $users);
    $adminModerationService = new AdminModerationService($db, $users);
    $authController = new AuthController($authService);
    $buyerController = new BuyerController($buyerService);
    $publicController = new PublicController($marketplace);
    $recommendationController = new RecommendationController(
        $laptopRecommendationService
    );
    $pcCompatibilityController = new PcCompatibilityController(
        $pcCompatibilityService
    );
    $pcBuildRecommendationController = new PcBuildRecommendationController(
        $pcBuildRecommendationService
    );
    $hexBotController = new HexBotController($hexBotService);
    $sellerController = new SellerController($shopApplications);
    $sellerModuleController = new SellerModuleController($sellerModuleService);
    $uploadController = new UploadController($uploadService);
    $adminController = new AdminController($adminService);
    $adminCatalogController = new AdminCatalogController($adminCatalogService);
    $adminModerationController = new AdminModerationController(
        $adminModerationService
    );
    $authMiddleware = new AuthMiddleware($jwt, $users);
    $healthController = new HealthController();

    $router = new Router();
    $router->add('GET', '/api/v1/health', [$healthController, 'show']);
    $router->add('GET', '/api/v1/categories', [$publicController, 'categories']);
    $router->add('GET', '/api/v1/products', [$publicController, 'catalogue']);
    $router->add('GET', '/api/v1/featured-products', [$publicController, 'featured']);
    $router->add(
        'POST',
        '/api/v1/recommendations/laptops',
        [$recommendationController, 'laptops']
    );
    $router->add(
        'POST',
        '/api/v1/pc-builder/compatibility',
        [$pcCompatibilityController, 'validate']
    );
    $router->add(
        'POST',
        '/api/v1/pc-builder/compatible-alternatives',
        [$pcCompatibilityController, 'alternatives']
    );
    $router->add(
        'GET',
        '/api/v1/pc-builder/workloads',
        [$pcBuildRecommendationController, 'workloads']
    );
    $router->add(
        'POST',
        '/api/v1/pc-builder/recommendations',
        [$pcBuildRecommendationController, 'recommend']
    );
    $router->add(
        'POST',
        '/api/v1/hexbot/sessions',
        [$hexBotController, 'start']
    );
    $router->add(
        'POST',
        '/api/v1/hexbot/sessions/([0-9a-f-]{36})/messages',
        static function (string $publicId) use ($hexBotController): never {
            $hexBotController->message($publicId);
        }
    );
    $router->add(
        'GET',
        '/api/v1/products/(\d+)',
        static function (string $productId) use ($publicController): never {
            $publicController->product((int) $productId);
        }
    );
    $router->add(
        'GET',
        '/api/v1/shops/(\d+)',
        static function (string $shopId) use ($publicController): never {
            $publicController->shop((int) $shopId);
        }
    );
    $router->add('GET', '/api/v1/commission/current', [$publicController, 'commission']);
    $router->add(
        'GET',
        '/api/v1/media/shop-logos/([a-f0-9]{32}|[a-f0-9]{64})',
        static function (string $storageToken) use ($uploadController): never {
            $uploadController->publicImage('shop-logos', $storageToken);
        }
    );
    $router->add(
        'GET',
        '/api/v1/media/product-images/([a-f0-9]{32}|[a-f0-9]{64})',
        static function (string $storageToken) use ($uploadController): never {
            $uploadController->publicImage('product-images', $storageToken);
        }
    );
    $router->add('POST', '/api/v1/auth/register/customer', [$authController, 'registerCustomer']);
    $router->add('POST', '/api/v1/auth/register/vendor', [$authController, 'registerVendor']);
    $router->add('POST', '/api/v1/auth/login', [$authController, 'login']);
    $router->add('GET', '/api/v1/auth/me', static function () use ($authMiddleware): never {
        $context = $authMiddleware->authenticate();
        ApiResponse::success('Authenticated user loaded.', ['user' => $context['user']]);
    });
    $router->add('POST', '/api/v1/auth/logout', static function () use (
        $authMiddleware,
        $authService
    ): never {
        $context = $authMiddleware->authenticate();
        $authService->logout($context['claims'], Request::ipAddress());
        ApiResponse::success('Logout successful.');
    });
    $router->add('GET', '/api/v1/customers/me/addresses', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->addresses((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/customers/me/addresses', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->createAddress((int) $context['user']['id']);
    });
    $router->add(
        'PATCH',
        '/api/v1/customers/me/addresses/(\d+)',
        static function (string $addressId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->updateAddress(
                (int) $context['user']['id'],
                (int) $addressId
            );
        }
    );
    $router->add(
        'DELETE',
        '/api/v1/customers/me/addresses/(\d+)',
        static function (string $addressId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->deleteAddress(
                (int) $context['user']['id'],
                (int) $addressId
            );
        }
    );
    $router->add('GET', '/api/v1/wishlist/items', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->wishlist((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/wishlist/items', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->addWishlistItem((int) $context['user']['id']);
    });
    $router->add(
        'DELETE',
        '/api/v1/wishlist/items/(\d+)',
        static function (string $listingId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->removeWishlistItem(
                (int) $context['user']['id'],
                (int) $listingId
            );
        }
    );
    $router->add('GET', '/api/v1/cart', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->cart((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/cart/items', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->addCartItem((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/cart/setup', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->addSetupToCart((int) $context['user']['id']);
    });
    $router->add(
        'POST',
        '/api/v1/cart/setups/([0-9a-f-]+)/restore',
        static function (string $setupPublicId) use ($authMiddleware, $buyerController): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->restoreCartSetup((int) $context['user']['id'], $setupPublicId);
        }
    );
    $router->add(
        'DELETE',
        '/api/v1/cart/setups/([0-9a-f-]+)',
        static function (string $setupPublicId) use ($authMiddleware, $buyerController): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->releaseCartSetup((int) $context['user']['id'], $setupPublicId);
        }
    );
    $router->add(
        'PATCH',
        '/api/v1/cart/items/(\d+)',
        static function (string $cartItemId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->updateCartItem(
                (int) $context['user']['id'],
                (int) $cartItemId
            );
        }
    );
    $router->add(
        'DELETE',
        '/api/v1/cart/items/(\d+)',
        static function (string $cartItemId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->removeCartItem(
                (int) $context['user']['id'],
                (int) $cartItemId
            );
        }
    );
    $router->add('POST', '/api/v1/orders', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->checkout((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/orders', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->orders((int) $context['user']['id']);
    });
    $router->add(
        'GET',
        '/api/v1/orders/(\d+)',
        static function (string $orderId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->order(
                (int) $context['user']['id'],
                (int) $orderId
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/sub-orders/(\d+)/confirm-receipt',
        static function (string $subOrderId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->confirmReceipt(
                (int) $context['user']['id'],
                (int) $subOrderId
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/order-items/(\d+)/reviews',
        static function (string $orderItemId) use (
            $authMiddleware,
            $buyerController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['customer']);
            $buyerController->createReview(
                (int) $context['user']['id'],
                (int) $orderItemId
            );
        }
    );
    $router->add('POST', '/api/v1/complaints', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->createComplaint((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/counterfeit-reports', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->createCounterfeitReport((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/interactions', static function () use (
        $authMiddleware,
        $buyerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['customer']);
        $buyerController->captureInteraction((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/notifications', static function () use (
        $authMiddleware,
        $publicController
    ): never {
        $context = $authMiddleware->authenticate();
        $publicController->notifications((int) $context['user']['id']);
    });
    $router->add(
        'POST',
        '/api/v1/notifications/(\d+)/read',
        static function (string $notificationId) use (
            $authMiddleware,
            $publicController
        ): never {
            $context = $authMiddleware->authenticate();
            $publicController->readNotification(
                (int) $notificationId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('POST', '/api/v1/notifications/read-all', static function () use (
        $authMiddleware,
        $publicController
    ): never {
        $context = $authMiddleware->authenticate();
        $publicController->readAllNotifications((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/shop-application', static function () use (
        $authMiddleware,
        $sellerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerController->application((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/shop-application', static function () use (
        $authMiddleware,
        $sellerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerController->submitApplication((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/verification-documents', static function () use (
        $authMiddleware,
        $uploadController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $uploadController->sellerVerificationDocuments((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/verification-documents', static function () use (
        $authMiddleware,
        $uploadController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $uploadController->uploadVerificationDocument((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/commission/accept', static function () use (
        $authMiddleware,
        $sellerController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerController->acceptCommission((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/dashboard', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->dashboard((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/profile', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->profile((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/profile', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->updateProfile((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/profile/logo', static function () use (
        $authMiddleware,
        $uploadController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $uploadController->uploadShopLogo((int) $context['user']['id']);
    });
    $router->add('DELETE', '/api/v1/seller/profile/logo', static function () use (
        $authMiddleware,
        $uploadController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $uploadController->deleteShopLogo((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/catalogue-options', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->catalogueOptions((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/listings', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->listings((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/listings', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->createListing((int) $context['user']['id']);
    });
    $router->add(
        'GET',
        '/api/v1/seller/listings/(\d+)',
        static function (string $listingId) use (
            $authMiddleware,
            $sellerModuleController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $sellerModuleController->listing(
                (int) $context['user']['id'],
                (int) $listingId
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/seller/listings/(\d+)',
        static function (string $listingId) use (
            $authMiddleware,
            $sellerModuleController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $sellerModuleController->updateListing(
                (int) $context['user']['id'],
                (int) $listingId
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/seller/listings/(\d+)/images',
        static function (string $listingId) use (
            $authMiddleware,
            $uploadController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $uploadController->uploadProductImage(
                (int) $context['user']['id'],
                (int) $listingId
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/seller/listings/(\d+)/images/(\d+)/delete',
        static function (string $listingId, string $imageId) use (
            $authMiddleware,
            $uploadController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $uploadController->deleteProductImage(
                (int) $context['user']['id'],
                (int) $listingId,
                (int) $imageId
            );
        }
    );
    $router->add('GET', '/api/v1/seller/inventory', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->inventory((int) $context['user']['id']);
    });
    $router->add(
        'POST',
        '/api/v1/seller/inventory/(\d+)/adjust',
        static function (string $listingId) use (
            $authMiddleware,
            $sellerModuleController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $sellerModuleController->adjustInventory(
                (int) $context['user']['id'],
                (int) $listingId
            );
        }
    );
    $router->add('GET', '/api/v1/seller/orders', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->orders((int) $context['user']['id']);
    });
    $router->add(
        'POST',
        '/api/v1/seller/orders/(\d+)/status',
        static function (string $subOrderId) use (
            $authMiddleware,
            $sellerModuleController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $sellerModuleController->updateOrderStatus(
                (int) $context['user']['id'],
                (int) $subOrderId
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/seller/orders/(\d+)/fulfilment-checkpoints',
        static function (string $subOrderId) use (
            $authMiddleware,
            $sellerModuleController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['shop_owner']);
            $sellerModuleController->completeFulfilmentCheckpoint(
                (int) $context['user']['id'],
                (int) $subOrderId
            );
        }
    );
    $router->add('GET', '/api/v1/seller/reviews', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->reviews((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/seller/finance', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->finance((int) $context['user']['id']);
    });
    $router->add('POST', '/api/v1/seller/payouts', static function () use (
        $authMiddleware,
        $sellerModuleController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        $sellerModuleController->requestPayout((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/admin/dashboard', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->dashboard();
    });
    $router->add('GET', '/api/v1/admin/users', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->users();
    });
    $router->add(
        'POST',
        '/api/v1/admin/users/(\d+)/status',
        static function (string $userId) use (
            $authMiddleware,
            $adminController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminController->updateUserStatus(
                (int) $userId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/shop-applications', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->applications();
    });
    $router->add(
        'GET',
        '/api/v1/admin/shop-applications/(\d+)/documents',
        static function (string $verificationId) use (
            $authMiddleware,
            $uploadController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $uploadController->adminVerificationDocuments((int) $verificationId);
        }
    );
    $router->add(
        'GET',
        '/api/v1/admin/verification-documents/(\d+)/download',
        static function (string $documentId) use (
            $authMiddleware,
            $uploadController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $uploadController->streamVerificationDocument(
                (int) $documentId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/admin/shop-applications/(\d+)/decision',
        static function (string $verificationId) use (
            $authMiddleware,
            $adminController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminController->decideApplication(
                (int) $verificationId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/commission-rules', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->commissionRules();
    });
    $router->add('POST', '/api/v1/admin/commission-rules', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->createCommissionRule((int) $context['user']['id']);
    });
    $router->add('GET', '/api/v1/admin/finance', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->finance();
    });
    $router->add(
        'POST',
        '/api/v1/admin/payouts/(\d+)/decision',
        static function (string $payoutId) use ($authMiddleware, $adminController): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminController->decidePayout((int) $payoutId, (int) $context['user']['id']);
        }
    );
    $router->add('GET', '/api/v1/admin/audit-logs', static function () use (
        $authMiddleware,
        $adminController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminController->auditLogs();
    });
    $router->add('GET', '/api/v1/admin/categories', static function () use (
        $authMiddleware,
        $adminCatalogController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminCatalogController->categories();
    });
    $router->add('POST', '/api/v1/admin/categories', static function () use (
        $authMiddleware,
        $adminCatalogController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminCatalogController->createCategory((int) $context['user']['id']);
    });
    $router->add(
        'POST',
        '/api/v1/admin/categories/(\d+)',
        static function (string $categoryId) use (
            $authMiddleware,
            $adminCatalogController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminCatalogController->updateCategory(
                (int) $categoryId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add(
        'GET',
        '/api/v1/admin/categories/(\d+)/specifications',
        static function (string $categoryId) use (
            $authMiddleware,
            $adminCatalogController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminCatalogController->specifications((int) $categoryId);
        }
    );
    $router->add(
        'POST',
        '/api/v1/admin/categories/(\d+)/specifications',
        static function (string $categoryId) use (
            $authMiddleware,
            $adminCatalogController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminCatalogController->createSpecification(
                (int) $categoryId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add(
        'POST',
        '/api/v1/admin/categories/(\d+)/specifications/(\d+)',
        static function (string $categoryId, string $specificationId) use (
            $authMiddleware,
            $adminCatalogController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminCatalogController->updateSpecification(
                (int) $categoryId,
                (int) $specificationId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/listings', static function () use (
        $authMiddleware,
        $adminModerationController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminModerationController->listings();
    });
    $router->add(
        'POST',
        '/api/v1/admin/listings/(\d+)/decision',
        static function (string $listingId) use (
            $authMiddleware,
            $adminModerationController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminModerationController->decideListing(
                (int) $listingId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/listing-flags', static function () use (
        $authMiddleware,
        $adminModerationController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminModerationController->flags();
    });
    $router->add(
        'POST',
        '/api/v1/admin/listing-flags/(\d+)/decision',
        static function (string $flagId) use (
            $authMiddleware,
            $adminModerationController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminModerationController->decideFlag(
                (int) $flagId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/complaints', static function () use (
        $authMiddleware,
        $adminModerationController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminModerationController->complaints();
    });
    $router->add(
        'POST',
        '/api/v1/admin/complaints/(\d+)/decision',
        static function (string $complaintId) use (
            $authMiddleware,
            $adminModerationController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminModerationController->decideComplaint(
                (int) $complaintId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/counterfeit-reports', static function () use (
        $authMiddleware,
        $adminModerationController
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        $adminModerationController->reports();
    });
    $router->add(
        'POST',
        '/api/v1/admin/counterfeit-reports/(\d+)/decision',
        static function (string $reportId) use (
            $authMiddleware,
            $adminModerationController
        ): never {
            $context = $authMiddleware->authenticate();
            RoleMiddleware::require($context['user'], ['administrator']);
            $adminModerationController->decideReport(
                (int) $reportId,
                (int) $context['user']['id']
            );
        }
    );
    $router->add('GET', '/api/v1/admin/ping', static function () use (
        $authMiddleware
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['administrator']);
        ApiResponse::success('Administrator access confirmed.');
    });
    $router->add('GET', '/api/v1/vendor/ping', static function () use (
        $authMiddleware
    ): never {
        $context = $authMiddleware->authenticate();
        RoleMiddleware::require($context['user'], ['shop_owner']);
        ApiResponse::success('Shop-owner access confirmed.');
    });

    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/'
    );
} catch (HttpException $exception) {
    ApiResponse::error($exception->getMessage(), $exception->status, $exception->errors);
} catch (\Throwable $exception) {
    $isDevelopment = Env::get('APP_ENV', 'production') === 'development';
    $errors = $isDevelopment
        ? ['exception' => [get_class($exception) . ': ' . $exception->getMessage()]]
        : null;
    ApiResponse::error('An unexpected server error occurred.', 500, $errors);
}
