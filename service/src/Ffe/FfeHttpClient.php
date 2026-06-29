<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use RuntimeException;

final class FfeHttpClient
{
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly int $minimumDelayMicroseconds = 350000
    ) {
    }

    public function get(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension cURL indisponible.');
        }

        $this->respectRateLimit();

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Impossible d’initialiser cURL.');
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'PauBerliozFfeSync/0.1',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]
        );

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        $this->lastRequestAt = microtime(true);

        if ($response === false) {
            throw new RuntimeException(
                'Erreur HTTP FFE : ' . ($error !== '' ? $error : 'inconnue')
            );
        }

        if ($statusCode !== 200) {
            throw new RuntimeException(
                sprintf('Réponse FFE inattendue : HTTP %d.', $statusCode)
            );
        }

        return $response;
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