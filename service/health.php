<?php

declare(strict_types=1);

use PauBerlioz\FfeSync\AppConfig;
use PauBerlioz\FfeSync\Database;

require_once __DIR__ . '/bootstrap.php';

$statusCode = 200;

$response = [
    'status' => 'ok',
    'service' => 'ffe-sync-pau-berlioz',
    'checks' => [
        'config' => 'not_checked',
        'pdo_mysql' => extension_loaded('pdo_mysql') ? 'ok' : 'missing',
        'database' => 'not_checked',
    ],
    'time_utc' => gmdate('c'),
];

try {
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Extension PDO MySQL indisponible.');
    }

    AppConfig::all();
    $response['checks']['config'] = 'ok';

    Database::connection()
        ->query('SELECT 1')
        ->fetchColumn();

    $response['checks']['database'] = 'ok';
} catch (Throwable $exception) {
    $statusCode = 503;

    $response['status'] = 'degraded';
    $response['checks']['database'] = 'unavailable';
    $response['reason'] = databaseDiagnosticReason($exception);

    error_log(
        '[FFE Sync Pau Berlioz] MySQL health check failed: '
        . $exception->getMessage()
    );
}

http_response_code($statusCode);
header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    $response,
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);

function databaseDiagnosticReason(Throwable $exception): string
{
    $messages = [];

    for (
        $current = $exception;
        $current !== null;
        $current = $current->getPrevious()
    ) {
        $messages[] = strtolower($current->getMessage());
    }

    $message = implode(' ', $messages);

    if (
        str_contains($message, 'configuration')
        || str_contains($message, 'introuvable')
        || str_contains($message, 'incomplète')
    ) {
        return 'configuration_missing_or_invalid';
    }

    if (
        str_contains($message, 'could not find driver')
        || str_contains($message, 'pdo mysql')
    ) {
        return 'pdo_mysql_driver_missing';
    }

    if (str_contains($message, 'access denied')) {
        return 'credentials_or_permissions';
    }

    if (str_contains($message, 'unknown database')) {
        return 'database_name_incorrect';
    }

    if (
        str_contains($message, 'getaddrinfo')
        || str_contains($message, 'name or service not known')
    ) {
        return 'database_hostname_incorrect';
    }

    if (
        str_contains($message, 'connection refused')
        || str_contains($message, 'connection timed out')
        || str_contains($message, 'no route to host')
    ) {
        return 'database_port_or_network';
    }

    return 'database_connection_failed';
}