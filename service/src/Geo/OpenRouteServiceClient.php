<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Geo;

use JsonException;
use RuntimeException;

final class OpenRouteServiceClient
{
    private const BASE_URL = 'https://api.openrouteservice.org';

    /**
     * Retourne plusieurs candidats pour que le service appelant puisse
     * vérifier que la commune correspond réellement à la ville FFE.
     */
    public function geocodeCandidates(
        string $query,
        int $limit = 10
    ): array {
        $response = $this->requestJson(
            'GET',
            '/geocode/search?' . http_build_query(
                [
                    'text' => $query,
                    'boundary.country' => 'FR',
                    'size' => max(1, min($limit, 10)),
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );

        $features = $response['features'] ?? [];

        if (!is_array($features)) {
            return [];
        }

        $candidates = [];

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $coordinates = $feature['geometry']['coordinates'] ?? null;

            if (
                !is_array($coordinates)
                || count($coordinates) < 2
                || !is_numeric($coordinates[0])
                || !is_numeric($coordinates[1])
            ) {
                continue;
            }

            $properties = $feature['properties'] ?? [];

            if (!is_array($properties)) {
                $properties = [];
            }

            $label = $this->stringOrNull(
                $properties['label'] ?? null
            ) ?? $query;

            $candidates[] = [
                'longitude' => (float) $coordinates[0],
                'latitude' => (float) $coordinates[1],
                'label' => $label,
                'locality' => $this->stringOrNull(
                    $properties['locality'] ?? null
                ),
                'localadmin' => $this->stringOrNull(
                    $properties['localadmin'] ?? null
                ),
                'city' => $this->stringOrNull(
                    $properties['city'] ?? null
                ),
                'municipality' => $this->stringOrNull(
                    $properties['municipality'] ?? null
                ),
                'county' => $this->stringOrNull(
                    $properties['county'] ?? null
                ),
                'region' => $this->stringOrNull(
                    $properties['region'] ?? null
                ),
            ];
        }

        return $candidates;
    }

    public function drivingRoute(
        float $startLongitude,
        float $startLatitude,
        float $endLongitude,
        float $endLatitude
    ): array {
        try {
            $payload = json_encode(
                [
                    'coordinates' => [
                        [$startLongitude, $startLatitude],
                        [$endLongitude, $endLatitude],
                    ],
                ],
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Coordonnées d’itinéraire OpenRouteService invalides.',
                0,
                $exception
            );
        }

        $response = $this->requestJson(
            'POST',
            '/v2/directions/driving-car/json',
            $payload
        );

        $summary = $response['routes'][0]['summary'] ?? null;

        if (
            !is_array($summary)
            || !isset($summary['distance'])
            || !isset($summary['duration'])
            || !is_numeric($summary['distance'])
            || !is_numeric($summary['duration'])
        ) {
            throw new RuntimeException(
                'Distance routière absente de la réponse OpenRouteService.'
            );
        }

        return [
            'distance_meters' => (int) round(
                (float) $summary['distance']
            ),
            'duration_seconds' => (int) round(
                (float) $summary['duration']
            ),
        ];
    }

    private function requestJson(
        string $method,
        string $path,
        ?string $payload = null
    ): array {
        $curl = curl_init(self::BASE_URL . $path);

        if ($curl === false) {
            throw new RuntimeException(
                'Impossible d’initialiser la requête OpenRouteService.'
            );
        }

        $headers = [
            'Accept: application/json',
            'Authorization: ' . $this->apiKey(),
            'User-Agent: PauBerliozFfeSync/1.0',
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
            ]
        );

        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException(
                'OpenRouteService est indisponible : ' . $curlError
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(
                sprintf(
                    'OpenRouteService a répondu HTTP %d.',
                    $statusCode
                )
            );
        }

        try {
            $decoded = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Réponse OpenRouteService invalide.',
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Réponse OpenRouteService invalide.'
            );
        }

        return $decoded;
    }

    private function apiKey(): string
    {
        static $apiKey = null;

        if (is_string($apiKey)) {
            return $apiKey;
        }

        $configPath = dirname(__DIR__, 2)
            . '/config/runtime.php';

        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Configuration du service absente.'
            );
        }

        $config = require $configPath;

        $value = $config['openrouteservice']['api_key'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                'Clé OpenRouteService absente.'
            );
        }

        $apiKey = trim($value);

        return $apiKey;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}