<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use RuntimeException;

final class FfeHttpClient
{
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly int $minimumDelayMicroseconds = 500000,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $timeoutSeconds = 20,
        private readonly int $maximumAttempts = 2
    ) {
    }

    public function get(string $url): string
    {
        $lastError = 'Erreur HTTP FFE inconnue.';

        for ($attempt = 1; $attempt <= $this->maximumAttempts; $attempt++) {
            $this->respectRateLimit();

            $curl = curl_init($url);

            if ($curl === false) {
                throw new RuntimeException(
                    'Impossible d’initialiser cURL.'
                );
            }

            curl_setopt_array(
                $curl,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                    CURLOPT_TIMEOUT => $this->timeoutSeconds,
                    CURLOPT_ENCODING => '',
                    CURLOPT_USERAGENT => 'PauBerliozFfeSync/0.4',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]
            );

            $response = curl_exec($curl);

            $error = curl_error($curl);

            $statusCode = (int) curl_getinfo(
                $curl,
                CURLINFO_RESPONSE_CODE
            );

            curl_close($curl);

            $this->lastRequestAt = microtime(true);

            if ($response !== false && $statusCode === 200) {
                return $response;
            }

            $lastError = $response === false
                ? ($error !== '' ? $error : 'Erreur HTTP inconnue.')
                : sprintf(
                    'Réponse FFE inattendue : HTTP %d.',
                    $statusCode
                );

            if ($attempt < $this->maximumAttempts) {
                usleep(1_000_000);
            }
        }

        throw new RuntimeException(
            sprintf(
                'FFE indisponible après %d tentative(s) : %s',
                $this->maximumAttempts,
                $lastError
            )
        );
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestAt === 0.0) {
            return;
        }

        $elapsedMicroseconds = (int) (
            (microtime(true) - $this->lastRequestAt) * 1_000_000
        );

        $remainingMicroseconds =
            $this->minimumDelayMicroseconds - $elapsedMicroseconds;

        if ($remainingMicroseconds > 0) {
            usleep($remainingMicroseconds);
        }
    }
}