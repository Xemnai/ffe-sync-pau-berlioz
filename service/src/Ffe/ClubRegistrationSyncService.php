<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use PDO;
use PauBerlioz\FfeSync\Database;
use Throwable;

final class ClubRegistrationSyncService
{
    public function __construct(
        private readonly FfeHttpClient $http = new FfeHttpClient(),
        private readonly FfeRankingParser $rankingParser =
            new FfeRankingParser(),
        private readonly PauBerliozClubMatcher $clubMatcher =
            new PauBerliozClubMatcher()
    ) {
    }

    public function refreshUpcomingRegistrations(): array
    {
        $stats = [
            'pages_checked' => 0,
            'pages_unavailable' => 0,
            'pages_failed' => 0,
            'club_entries_updated' => 0,
        ];

        foreach ($this->loadUpcomingSources() as $source) {
            $reference = (int) $source['ffe_ref'];
            $rankingUrl = $source['results_url'];

            if ($rankingUrl === null || $rankingUrl === '') {
                $this->replaceRegistrations($reference, []);

                $this->updateStatus(
                    $reference,
                    'unavailable',
                    null
                );

                $stats['pages_unavailable']++;

                continue;
            }

            try {
                $stats['pages_checked']++;

                $ranking = $this->rankingParser->parse(
                    $this->http->get($rankingUrl)
                );

                if (!$ranking['published']) {
                    $this->replaceRegistrations($reference, []);

                    $this->updateStatus(
                        $reference,
                        'unavailable',
                        null
                    );

                    $stats['pages_unavailable']++;

                    continue;
                }

                $clubPlayers = array_values(
                    array_filter(
                        $ranking['players'],
                        fn (array $player): bool => $this->clubMatcher
                            ->matches($player['club_name'])
                    )
                );

                $this->replaceRegistrations(
                    $reference,
                    $clubPlayers
                );

                $this->updateStatus(
                    $reference,
                    'available',
                    null
                );

                $stats['club_entries_updated'] += count($clubPlayers);
            } catch (Throwable $exception) {
                $this->updateStatus(
                    $reference,
                    'failed',
                    $exception->getMessage()
                );

                $stats['pages_failed']++;

                error_log(
                    sprintf(
                        '[FFE registrations] Ref %d : %s',
                        $reference,
                        $exception->getMessage()
                    )
                );
            }
        }

        return $stats;
    }

    private function loadUpcomingSources(): array
    {
        $statement = Database::connection()->query(
            'SELECT ffe_ref, results_url
             FROM pbe_tournament_sources
             WHERE is_upcoming = 1
               AND is_excluded = 0
             ORDER BY start_date, ffe_ref'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function replaceRegistrations(
        int $reference,
        array $players
    ): void {
        $connection = Database::connection();

        $connection->beginTransaction();

        try {
            $delete = $connection->prepare(
                'DELETE FROM pbe_club_registrations
                 WHERE ffe_ref = :ffe_ref'
            );

            $delete->execute([
                ':ffe_ref' => $reference,
            ]);

            $insert = $connection->prepare(
                'INSERT INTO pbe_club_registrations (
                    ffe_ref,
                    display_order,
                    player_name,
                    elo,
                    club_name,
                    detected_at
                ) VALUES (
                    :ffe_ref,
                    :display_order,
                    :player_name,
                    :elo,
                    :club_name,
                    UTC_TIMESTAMP()
                )'
            );

            foreach ($players as $player) {
                $insert->execute([
                    ':ffe_ref' => $reference,
                    ':display_order' => $player['display_order'],
                    ':player_name' => $player['player_name'],
                    ':elo' => $player['elo'],
                    ':club_name' => $player['club_name'],
                ]);
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function updateStatus(
        int $reference,
        string $status,
        ?string $error
    ): void {
        $statement = Database::connection()->prepare(
            'UPDATE pbe_tournament_sources
             SET
                registration_status = :status,
                registration_error = :error,
                last_registration_sync_at = UTC_TIMESTAMP()
             WHERE ffe_ref = :ffe_ref'
        );

        $statement->execute([
            ':status' => $status,
            ':error' => $error === null ? null : substr($error, 0, 255),
            ':ffe_ref' => $reference,
        ]);
    }
}