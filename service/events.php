<?php

declare(strict_types=1);

use PauBerlioz\FfeSync\Ffe\DepartmentScope;
use PauBerlioz\FfeSync\Ffe\UpcomingEventPayloadBuilder;
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

$stage = 'request_received';

try {
    $body = file_get_contents('php://input') ?: '';

    $stage = 'authentication';

    try {
        SignedRequestAuthenticator::verify($body);
    } catch (UnauthorizedRequestException) {
        verifyWordPressSignature($body);
    }

    $stage = 'payload_decoding';

    $payload = json_decode(
        $body,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException(
            'Payload JSON invalide.'
        );
    }

    $stage = 'department_validation';
    $departments = DepartmentScope::fromPayload($payload);

    $stage = 'payload_building';

    $events = (new UpcomingEventPayloadBuilder())
        ->buildUpcomingEvents();

    echo json_encode(
        [
            'status' => 'ok',
            'generated_at_utc' => gmdate('c'),
            'departments' => $departments,
            'count' => count($events),
            'events' => $events,
        ],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (UnauthorizedRequestException $exception) {
    http_response_code(401);

    echo json_encode([
        'error' => 'unauthorized',
    ]);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);

    echo json_encode(
        [
            'error' => 'invalid_departments',
            'message' => $exception->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $exception) {
    error_log(
        sprintf(
            '[FFE Events Endpoint] Stage=%s | %s: %s',
            $stage,
            $exception::class,
            $exception->getMessage()
        )
    );

    http_response_code(500);

    echo json_encode([
        'error' => 'events_failed',
    ]);
}

function verifyWordPressSignature(string $body): void
{
    $timestamp = requestHeader('HTTP_X_PBE_TIMESTAMP');
    $nonce = requestHeader('HTTP_X_PBE_NONCE');
    $signature = requestHeader('HTTP_X_PBE_SIGNATURE');

    if (
        $timestamp === null
        || $nonce === null
        || $signature === null
    ) {
        throw new UnauthorizedRequestException(
            'En-têtes WordPress absents.'
        );
    }

    if (!ctype_digit($timestamp)) {
        throw new UnauthorizedRequestException(
            'Timestamp WordPress invalide.'
        );
    }

    if (abs(time() - (int) $timestamp) > 300) {
        throw new UnauthorizedRequestException(
            'Timestamp WordPress expiré.'
        );
    }

    if (!preg_match('/^[a-zA-Z0-9._-]{16,128}$/', $nonce)) {
        throw new UnauthorizedRequestException(
            'Nonce WordPress invalide.'
        );
    }

    $configPath = __DIR__ . '/config/runtime.php';

    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Configuration privée absente.'
        );
    }

    $config = require $configPath;

    $secret = $config['security']['wordpress_to_service_secret'] ?? null;

    if (!is_string($secret) || trim($secret) === '') {
        throw new RuntimeException(
            'Secret WordPress vers service absent.'
        );
    }

    $expectedSignature = hash_hmac(
        'sha256',
        $timestamp . "\n" . $nonce . "\n" . $body,
        trim($secret)
    );

    $signature = preg_replace(
        '/^sha256=/i',
        '',
        trim($signature)
    ) ?? trim($signature);

    if (!hash_equals($expectedSignature, $signature)) {
        throw new UnauthorizedRequestException(
            'Signature WordPress invalide.'
        );
    }
}

function requestHeader(string $serverKey): ?string
{
    $value = $_SERVER[$serverKey] ?? null;

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}
