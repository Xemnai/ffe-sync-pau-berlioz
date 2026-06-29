<?php

declare(strict_types=1);

use PauBerlioz\FfeSync\Ffe\FfeSyncService;
use PauBerlioz\FfeSync\Security\SignedRequestAuthenticator;
use PauBerlioz\FfeSync\Security\UnauthorizedRequestException;

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'error' => 'method_not_allowed',
    ]);

    exit;
}

try {
    $body = file_get_contents('php://input') ?: '';

    SignedRequestAuthenticator::verify($body);

    $payload = json_decode(
        $body,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $department = (string) ($payload['department'] ?? '');

    if ($department !== '64') {
        http_response_code(422);

        echo json_encode([
            'error' => 'unsupported_department',
        ]);

        exit;
    }

    $result = (new FfeSyncService())->syncDepartment(
        $department,
        'manual'
    );

    echo json_encode(
        [
            'status' => 'ok',
            'result' => $result,
        ],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (UnauthorizedRequestException) {
    http_response_code(401);

    echo json_encode([
        'error' => 'unauthorized',
    ]);
} catch (Throwable $exception) {
    error_log(
        '[FFE Sync Trigger] ' . $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'error' => 'sync_failed',
    ]);
}