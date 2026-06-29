<?php

declare(strict_types=1);

use PauBerlioz\FfeSync\Database;

require_once __DIR__ . '/bootstrap.php';

$statusCode = 200;
$databaseStatus = 'ok';

try {
    Database::connection()
        ->query('SELECT 1')
        ->fetchColumn();
} catch (Throwable $exception) {
    $statusCode = 503;
    $databaseStatus = 'unavailable';

    error_log(
        '[FFE Sync Pau Berlioz] Échec du contrôle MySQL : '
        . $exception->getMessage()
    );
}

http_response_code($statusCode);
header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'status' => $statusCode === 200 ? 'ok' : 'degraded',
        'service' => 'ffe-sync-pau-berlioz',
        'database' => $databaseStatus,
        'time_utc' => gmdate('c'),
    ],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);