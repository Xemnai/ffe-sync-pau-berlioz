<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Security;

use PauBerlioz\FfeSync\AppConfig;
use PauBerlioz\FfeSync\Database;
use PDOException;

final class SignedRequestAuthenticator
{
    public static function verify(string $body): void
    {
        $secret = AppConfig::all()['security']
            ['wordpress_to_service_secret'] ?? '';

        if (!is_string($secret) || $secret === '') {
            throw new UnauthorizedRequestException(
                'Secret de signature indisponible.'
            );
        }

        $timestamp = $_SERVER['HTTP_X_PBE_TIMESTAMP'] ?? '';
        $nonce = $_SERVER['HTTP_X_PBE_NONCE'] ?? '';
        $signature = $_SERVER['HTTP_X_PBE_SIGNATURE'] ?? '';

        if (
            !ctype_digit($timestamp)
            || preg_match('/^[a-f0-9]{64}$/i', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/i', $signature) !== 1
        ) {
            throw new UnauthorizedRequestException(
                'En-têtes de sécurité invalides.'
            );
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new UnauthorizedRequestException(
                'Requête expirée.'
            );
        }

        $signedPayload = sprintf(
            "%s\n%s\n%s",
            $timestamp,
            $nonce,
            $body
        );

        $expectedSignature = hash_hmac(
            'sha256',
            $signedPayload,
            $secret
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new UnauthorizedRequestException(
                'Signature invalide.'
            );
        }

        try {
            Database::connection()->prepare(
                'DELETE FROM pbe_api_nonces
                 WHERE expires_at < UTC_TIMESTAMP()'
            )->execute();

            Database::connection()->prepare(
                'INSERT INTO pbe_api_nonces (
                    nonce,
                    direction,
                    expires_at
                ) VALUES (
                    :nonce,
                    "wp_to_service",
                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
                )'
            )->execute([
                ':nonce' => $nonce,
            ]);
        } catch (PDOException) {
            throw new UnauthorizedRequestException(
                'Nonce déjà utilisé.'
            );
        }
    }
}