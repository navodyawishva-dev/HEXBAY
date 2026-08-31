<?php
declare(strict_types=1);

use Hexbay\Config\Env;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hexbay\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
        . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

Env::load(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

date_default_timezone_set('UTC');

