<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Geo;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PauBerlioz\FfeSync\Database;
use PDO;
use RuntimeException;
use Throwable;

final class RouteDistanceSyncService
{
    private const ORIGIN_QUERY =
        'Cité des Pyrénées, 29 Bis Rue Berlioz, 64000 Pau, France';

    private const FAILURE_RETRY_INTERVAL = 'P1D';

    private const NOT_FOUND_RETRY_INTERVAL = 'P14D';

    public function __construct(
        private readonly OpenRouteServiceClient $client =
            new OpenRouteServiceClient()
    ) {
    }

    public function refreshUpcomingGroupDistances(): array
    {
        $stats = [
            'groups_checked' => 0,
            'distances_calculated' => 0,
            'distances_cached' => 0,
            'groups_without_location' => 0,
            'groups_deferred' => 0,
            'groups_failed' => 0,
            'origin_status' => 'unknown',
        ];

        $origin = $this->resolveOrigin();
        $stats['origin_status'] = $origin['status'];

        if ($origin['status'] !== 'resolved') {
            return $stats;
        }

        foreach ($this->loadUpcomingGroups() as $group) {
            $stats['groups_checked']++;

            try {
                [$primaryQuery, $fallbackQuery] =
                    $this->buildDestinationQueries($group);

                if ($primaryQuery === null) {
                    $this->updateGroupDistance((int) $group['id'], null);
                    $stats['groups_without_location']++;
                    continue;
                }

                $result = $this->resolveDestinationRoute(
                    $origin,
                    $primaryQuery,
                    $fallbackQuery
                );

                if ($result['status'] === 'resolved') {
                    $distanceMeters = (int) $result['distance_meters'];

                    $this->updateGroupDistance(
                        (int) $group['id'],
                        round($distanceMeters / 1000, 1)
                    );

                    if ($result['from_cache']) {
                        $stats['distances_cached']++;
                    } else {
                        $stats['distances_calculated']++;
                    }

                    continue;
                }

                $this->updateGroupDistance((int) $group['id'], null);

                if ($result['status'] === 'deferred') {
                    $stats['groups_deferred']++;
                } else {
                    $stats['groups_failed']++;
                }
            } catch (Throwable $exception) {
                $this->updateGroupDistance((int) $group['id'], null);
                $stats['groups_failed']++;

                error_log(
                    sprintf(
                        '[FFE Distance] Groupe %d : %s',
                        (int) $group['id'],
                        $exception->getMessage()
                    )
                );
            }
        }

        return $stats;
    }

    private function resolveOrigin(): array
    {
        $cacheKey = hash('sha256', 'origin|' . self::ORIGIN_QUERY);
        $cached = $this->loadCache($cacheKey);

        if ($this->isResolvedLocation($cached)) {
            return $this->cacheLocationResult($cached);
        }

        if ($this->isRetryDeferred($cached)) {
            return ['status' => 'deferred'];
        }

        try {
            $location = $this->client->geocode(self::ORIGIN_QUERY);

            if ($location === null) {
                $this->saveCache([
                    'cache_key' => $cacheKey,
                    'cache_kind' => 'origin',
                    'primary_query' => self::ORIGIN_QUERY,
                    'fallback_query' => null,
                    'resolved_query' => null,
                    'resolved_label' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'distance_meters' => null,
                    'duration_seconds' => null,
                    'status' => 'not_found',
                    'error_message' => 'Adresse de départ introuvable.',
                    'next_retry_at' => $this->retryAt(
                        self::NOT_FOUND_RETRY_INTERVAL
                    ),
                ]);

                return ['status' => 'not_found'];
            }

            $result = [
                'cache_key' => $cacheKey,
                'cache_kind' => 'origin',
                'primary_query' => self::ORIGIN_QUERY,
                'fallback_query' => null,
                'resolved_query' => self::ORIGIN_QUERY,
                'resolved_label' => $location['label'],
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
                'distance_meters' => null,
                'duration_seconds' => null,
                'status' => 'resolved',
                'error_message' => null,
                'next_retry_at' => null,
            ];

            $this->saveCache($result);

            return [
                'status' => 'resolved',
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
            ];
        } catch (Throwable $exception) {
            $this->saveCache([
                'cache_key' => $cacheKey,
                'cache_kind' => 'origin',
                'primary_query' => self::ORIGIN_QUERY,
                'fallback_query' => null,
                'resolved_query' => null,
                'resolved_label' => null,
                'latitude' => null,
                'longitude' => null,
                'distance_meters' => null,
                'duration_seconds' => null,
                'status' => 'failed',
                'error_message' => $this->shortError($exception),
                'next_retry_at' => $this->retryAt(
                    self::FAILURE_RETRY_INTERVAL
                ),
            ]);

            error_log(
                '[FFE Distance] Origine Pau Berlioz : '
                . $exception->getMessage()
            );

            return ['status' => 'failed'];
        }
    }

    private function resolveDestinationRoute(
        array $origin,
        string $primaryQuery,
        ?string $fallbackQuery
    ): array {
        $cacheKey = hash(
            'sha256',
            implode(
                "\n",
                ['destination', $primaryQuery, $fallbackQuery ?? '']
            )
        );

        $cached = $this->loadCache($cacheKey);

        if (
            is_array($cached)
            && $cached['status'] === 'resolved'
            && $cached['distance_meters'] !== null
        ) {
            return [
                'status' => 'resolved',
                'distance_meters' => (int) $cached['distance_meters'],
                'from_cache' => true,
            ];
        }

        if ($this->isRetryDeferred($cached)) {
            return ['status' => 'deferred'];
        }

        $location = null;
        $resolvedQuery = null;

        if ($this->hasCoordinates($cached)) {
            $location = $this->cacheLocation($cached);
            $resolvedQuery = (string) ($cached['resolved_query'] ?? '');
        }

        try {
            if ($location === null) {
                $location = $this->client->geocode($primaryQuery);
                $resolvedQuery = $primaryQuery;

                if ($location === null && $fallbackQuery !== null) {
                    $location = $this->client->geocode($fallbackQuery);
                    $resolvedQuery = $fallbackQuery;
                }
            }

            if ($location === null) {
                $this->saveCache([
                    'cache_key' => $cacheKey,
                    'cache_kind' => 'destination',
                    'primary_query' => $primaryQuery,
                    'fallback_query' => $fallbackQuery,
                    'resolved_query' => null,
                    'resolved_label' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'distance_meters' => null,
                    'duration_seconds' => null,
                    'status' => 'not_found',
                    'error_message' => 'Lieu du tournoi introuvable.',
                    'next_retry_at' => $this->retryAt(
                        self::NOT_FOUND_RETRY_INTERVAL
                    ),
                ]);

                return ['status' => 'not_found'];
            }

            $route = $this->client->drivingRoute(
                (float) $origin['longitude'],
                (float) $origin['latitude'],
                (float) $location['longitude'],
                (float) $location['latitude']
            );

            $this->saveCache([
                'cache_key' => $cacheKey,
                'cache_kind' => 'destination',
                'primary_query' => $primaryQuery,
                'fallback_query' => $fallbackQuery,
                'resolved_query' => $resolvedQuery,
                'resolved_label' => $location['label'],
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
                'distance_meters' => $route['distance_meters'],
                'duration_seconds' => $route['duration_seconds'],
                'status' => 'resolved',
                'error_message' => null,
                'next_retry_at' => null,
            ]);

            return [
                'status' => 'resolved',
                'distance_meters' => $route['distance_meters'],
                'from_cache' => false,
            ];
        } catch (Throwable $exception) {
            $this->saveCache([
                'cache_key' => $cacheKey,
                'cache_kind' => 'destination',
                'primary_query' => $primaryQuery,
                'fallback_query' => $fallbackQuery,
                'resolved_query' => $resolvedQuery,
                'resolved_label' => $location['label'] ?? null,
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'distance_meters' => null,
                'duration_seconds' => null,
                'status' => 'failed',
                'error_message' => $this->shortError($exception),
                'next_retry_at' => $this->retryAt(
                    self::FAILURE_RETRY_INTERVAL
                ),
            ]);

            throw $exception;
        }
    }

    private function loadUpcomingGroups(): array
    {
        $statement = Database::connection()->query(
            'SELECT DISTINCT
                g.id,
                g.city,
                g.department
             FROM pbe_event_groups g
             INNER JOIN pbe_event_group_sources egs
                ON egs.group_id = g.id
             INNER JOIN pbe_tournament_sources s
                ON s.ffe_ref = egs.ffe_ref
             WHERE s.is_upcoming = 1
               AND s.is_excluded = 0
             ORDER BY g.start_date, g.id'
        );

        $groups = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as &$group) {
            $group['sources'] = $this->loadGroupSources((int) $group['id']);
        }

        unset($group);

        return $groups;
    }

    private function loadGroupSources(int $groupId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT
                s.venue,
                s.address,
                egs.source_order
             FROM pbe_event_group_sources egs
             INNER JOIN pbe_tournament_sources s
                ON s.ffe_ref = egs.ffe_ref
             WHERE egs.group_id = :group_id
             ORDER BY egs.source_order, s.ffe_ref'
        );

        $statement->execute([
            ':group_id' => $groupId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildDestinationQueries(array $group): array
    {
        $city = trim((string) ($group['city'] ?? ''));
        $place = $this->firstNonEmptyPlace(
            is_array($group['sources'] ?? null)
                ? $group['sources']
                : []
        );

        if ($city === '') {
            return [null, null];
        }

        $fallbackQuery = implode(', ', [$city, 'France']);

        if ($place === null) {
            return [$fallbackQuery, null];
        }

        $primaryQuery = implode(', ', [$place, $city, 'France']);

        return [
            $primaryQuery,
            $primaryQuery === $fallbackQuery ? null : $fallbackQuery,
        ];
    }

    private function firstNonEmptyPlace(array $sources): ?string
    {
        foreach ($sources as $source) {
            $address = trim((string) ($source['address'] ?? ''));

            if ($address !== '') {
                return $address;
            }

            $venue = trim((string) ($source['venue'] ?? ''));

            if ($venue !== '') {
                return $venue;
            }
        }

        return null;
    }

    private function loadCache(string $cacheKey): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT
                cache_key,
                cache_kind,
                primary_query,
                fallback_query,
                resolved_query,
                resolved_label,
                latitude,
                longitude,
                distance_meters,
                duration_seconds,
                status,
                error_message,
                next_retry_at
             FROM pbe_route_cache
             WHERE cache_key = :cache_key'
        );

        $statement->execute([
            ':cache_key' => $cacheKey,
        ]);

        $cache = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($cache) ? $cache : null;
    }

    private function saveCache(array $cache): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO pbe_route_cache (
                cache_key,
                cache_kind,
                primary_query,
                fallback_query,
                resolved_query,
                resolved_label,
                latitude,
                longitude,
                distance_meters,
                duration_seconds,
                status,
                error_message,
                next_retry_at
             ) VALUES (
                :cache_key,
                :cache_kind,
                :primary_query,
                :fallback_query,
                :resolved_query,
                :resolved_label,
                :latitude,
                :longitude,
                :distance_meters,
                :duration_seconds,
                :status,
                :error_message,
                :next_retry_at
             )
             ON DUPLICATE KEY UPDATE
                cache_kind = VALUES(cache_kind),
                primary_query = VALUES(primary_query),
                fallback_query = VALUES(fallback_query),
                resolved_query = VALUES(resolved_query),
                resolved_label = VALUES(resolved_label),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                distance_meters = VALUES(distance_meters),
                duration_seconds = VALUES(duration_seconds),
                status = VALUES(status),
                error_message = VALUES(error_message),
                next_retry_at = VALUES(next_retry_at)'
        );

        $statement->execute([
            ':cache_key' => $cache['cache_key'],
            ':cache_kind' => $cache['cache_kind'],
            ':primary_query' => $cache['primary_query'],
            ':fallback_query' => $cache['fallback_query'],
            ':resolved_query' => $cache['resolved_query'],
            ':resolved_label' => $cache['resolved_label'],
            ':latitude' => $cache['latitude'],
            ':longitude' => $cache['longitude'],
            ':distance_meters' => $cache['distance_meters'],
            ':duration_seconds' => $cache['duration_seconds'],
            ':status' => $cache['status'],
            ':error_message' => $cache['error_message'],
            ':next_retry_at' => $cache['next_retry_at'],
        ]);
    }

    private function updateGroupDistance(int $groupId, ?float $distanceKm): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE pbe_event_groups
             SET distance_km = :distance_km
             WHERE id = :id'
        );

        $statement->execute([
            ':distance_km' => $distanceKm,
            ':id' => $groupId,
        ]);
    }

    private function isResolvedLocation(?array $cache): bool
    {
        return is_array($cache)
            && $cache['status'] === 'resolved'
            && $cache['latitude'] !== null
            && $cache['longitude'] !== null;
    }

    private function cacheLocationResult(array $cache): array
    {
        return [
            'status' => 'resolved',
            ...$this->cacheLocation($cache),
        ];
    }

    private function hasCoordinates(?array $cache): bool
    {
        return is_array($cache)
            && $cache['latitude'] !== null
            && $cache['longitude'] !== null;
    }

    private function cacheLocation(array $cache): array
    {
        return [
            'latitude' => (float) $cache['latitude'],
            'longitude' => (float) $cache['longitude'],
            'label' => trim((string) ($cache['resolved_label'] ?? '')),
        ];
    }

    private function isRetryDeferred(?array $cache): bool
    {
        if (
            !is_array($cache)
            || $cache['next_retry_at'] === null
            || trim((string) $cache['next_retry_at']) === ''
        ) {
            return false;
        }

        $retryAt = new DateTimeImmutable(
            (string) $cache['next_retry_at'],
            new DateTimeZone('UTC')
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $retryAt > $now;
    }

    private function retryAt(string $interval): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now
            ->add(new DateInterval($interval))
            ->format('Y-m-d H:i:s');
    }

    private function shortError(Throwable $exception): string
    {
        return mb_substr(
            $exception->getMessage(),
            0,
            255,
            'UTF-8'
        );
    }
}
