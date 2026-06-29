<?php

declare(strict_types=1);

if (!defined('PBE_FFE_SYNC_BOOTSTRAPPED')) {
    define('PBE_FFE_SYNC_BOOTSTRAPPED', true);
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'PauBerlioz\\FfeSync\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativePath = str_replace(
            '\\',
            '/',
            substr($class, strlen($prefix))
        );

        $filePath = __DIR__ . '/src/' . $relativePath . '.php';

        if (is_file($filePath)) {
            require_once $filePath;
        }
    }
);