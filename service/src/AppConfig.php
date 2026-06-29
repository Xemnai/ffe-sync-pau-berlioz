<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync;

use RuntimeException;

final class AppConfig
{
    private static ?array $config = null;

    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

$configPath = dirname(__DIR__) . '/config/runtime.php';

if (!is_file($configPath)) {
    throw new RuntimeException('Configuration du service introuvable.');
}

        if (!is_file($configPath)) {
            throw new RuntimeException('Configuration du service introuvable.');
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new RuntimeException('Configuration du service invalide.');
        }

        self::validateDatabaseConfiguration($config);

        self::$config = $config;

        return self::$config;
    }

    public static function database(): array
    {
        return self::all()['database'];
    }

    private static function validateDatabaseConfiguration(array $config): void
    {
        if (!isset($config['database']) || !is_array($config['database'])) {
            throw new RuntimeException('Configuration MySQL absente.');
        }

        foreach (['host', 'port', 'name', 'username', 'password'] as $key) {
            if (!isset($config['database'][$key]) || $config['database'][$key] === '') {
                throw new RuntimeException(
                    sprintf('Configuration MySQL incomplète : %s.', $key)
                );
            }
        }
    }
}