<?php
declare(strict_types=1);

namespace Hexbay\Controllers;

use Hexbay\Config\Database;
use Hexbay\Support\ApiResponse;

final class HealthController
{
    public function show(): never
    {
        Database::connection()->query('SELECT 1')->fetchColumn();
        ApiResponse::success('Hexbay API is healthy.', [
            'service' => 'php-api',
            'database' => 'connected',
            'time_utc' => gmdate(DATE_ATOM),
        ]);
    }
}

