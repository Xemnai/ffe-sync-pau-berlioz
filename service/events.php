<?php

declare(strict_types=1);

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
        verifyFallbackWordPressSignature($body);
    }

    $stage = 'payload_decoding';

    $payload = json_decode(
        $body,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $department = (string) ($payload['department'] ?? '64');

    if ($department !== '64') {
        http_response_code(422);

        echo json_encode([
            'error' => 'unsupported_department',
        ]);

        exit;
    }

    $stage = 'payload_building';

    $events = (new UpcomingEventPayloadBuilder())
        ->buildUpcomingEvents();

    echo json_encode(
        [
            'status' => 'ok',
            'generated_at_utc' => gmdate('c'),
            'department' => $department,
            'count' => count($events),
            'events' => $events,
        ],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
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

    echo json_encode(
        [
            'error' => 'events_failed',
            'stage' => $stage,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE
    );
}

function verifyFallbackWordPressSignature(string $body): void
{
    $timestamp = headerValue('HTTP_X_PBE_TIMESTAMP');
    $nonce = headerValue('HTTP_X_PBE_NONCE');
    $signature = headerValue('HTTP_X_PBE_SIGNATURE');

    if ($timestamp === null || $nonce === null || $signature === null) {
        throw new UnauthorizedRequestException(
            'Signature WordPress absente.'
        );
    }

    if (!ctype_digit($timestamp)) {
        throw new UnauthorizedRequestException(
            'Timestamp WordPress invalide.'
        );
    }

    $now = time();
    $requestTime = (int) $timestamp;

    if (abs($now - $requestTime) > 300) {
        throw new UnauthorizedRequestException(
            'Timestamp WordPress expiré.'
        );
    }

    if (!preg_match('/^[a-zA-Z0-9._-]{16,128}$/', $nonce)) {
        throw new UnauthorizedRequestException(
            'Nonce WordPress invalide.'
        );
    }

    $secret = wordpressToServiceSecret();

    $expected = hash_hmac(
        'sha256',
        $timestamp . "\n" . $nonce . "\n" . $body,
        $secret
    );

    $signature = preg_replace('/^sha256=/i', '', trim($signature))
        ?? trim($signature);

    if (!hash_equals($expected, $signature)) {
        throw new UnauthorizedRequestException(
            'Signature WordPress invalide.'
        );
    }
}

function wordpressToServiceSecret(): string
{
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
            'Secret wordpress_to_service absent.'
        );
    }

    return trim($secret);
}

function headerValue(string $serverKey): ?string
{
    $value = $_SERVER[$serverKey] ?? null;

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}