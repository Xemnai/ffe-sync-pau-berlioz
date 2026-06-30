<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use DateTimeImmutable;
use DateTimeZone;
use PauBerlioz\FfeSync\Database;
use PauBerlioz\FfeSync\Geo\RouteDistanceSyncService;
use PDO;
use Throwable;

final class FfeSyncService
{
    private readonly FfeHttpClient $http;

    private readonly FfeTournamentListParser $listParser;

    private readonly FfeTournamentParser $parser;

    public function __construct()
    {
        $this->http = new FfeHttpClient();
        $this->listParser = new FfeTournamentListParser();
        $this->parser = new FfeTournamentParser();
    }

    /**
     * Importe les fiches FFE d'un département.
     *
     * Le paramètre $postProcess est volontairement false dans le workflow
     * multi-départements : groupes, distances et inscriptions sont traités
     * une seule fois, après l'import des six départements.
     */
    public function syncDepartment(
        string $department,
        string $triggerSource,
        bool $postProcess = false
    ): array {
        $runId = $this->startRun($triggerSource);

        $stats = $this->createStats($runId);

        try {
            $listingUrl = sprintf(
                'https://www.echecs.asso.fr/ListeTournois.aspx?Action=TOURNOICOMITE&ComiteRef=%s',
                rawurlencode($department)
            );

            $listingHtml = $this->http->get($listingUrl);

            $references = $this->listParser
                ->extractUpcomingTournamentReferences($listingHtml);

            sort($references);

            $stats['references_found'] = count($references);
            $stats['selected_references'] = $references;

            $knownSources = $this->loadKnownSources($references);

            $today = new DateTimeImmutable(
                'today',
                new DateTimeZone('Europe/Paris')
            );

            foreach ($references as $reference) {
                $known = $knownSources[$reference] ?? null;

                try {
                    $detailsHtml = $this->http->get(
                        sprintf(
                            'https://www.echecs.asso.fr/FicheTournoi.aspx?Ref=%d',
                            $reference
                        )
                    );

                    $source = $this->parser->parseTournament(
                        $reference,
                        $department,
                        $detailsHtml
                    );

                    $source['is_upcoming'] =
                        $source['end_date'] === null
                        || $source['end_date'] >= $today->format('Y-m-d');

                    $source['details_hash'] = hash(
                        'sha256',
                        json_encode(
                            $source,
                            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                        )
                    );

                    $changed = $known === null
                        || $known['details_hash'] !== $source['details_hash'];

                    $this->upsertSource($source);

                    if (
                        !$source['is_upcoming']
                        || $source['is_excluded']
                    ) {
                        $stats['ignored']++;
                    } elseif ($known === null) {
                        $stats['created']++;
                    } elseif ($changed) {
                        $stats['updated']++;
                    }
                } catch (Throwable $exception) {
                    $stats['errors']++;

                    $stats['failed_references'][] = [
                        'ffe_ref' => $reference,
                        'error' => $exception->getMessage(),
                    ];

                    error_log(
                        sprintf(
                            '[FFE Sync] Département %s, référence %d : %s',
                            $department,
                            $reference,
                            $exception->getMessage()
                        )
                    );
                }
            }

            if ($postProcess) {
                $this->runPostProcessing($stats);
            }

            $this->finishRun(
                $runId,
                'succeeded',
                $stats
            );

            return $stats;
        } catch (Throwable $exception) {
            $this->finishRun(
                $runId,
                'failed',
                $stats,
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    /**
     * Lance les opérations coûteuses une seule fois, après tous les imports :
     * - reconstruction des groupes ;
     * - distances routières ;
     * - lecture des inscriptions des joueurs du club.
     *
     * La construction du payload reste dans events.php. Cela évite d'envoyer
     * inutilement la liste complète des évènements dans les réponses GitHub.
     */
    public function finalizeUpcoming(string $triggerSource): array
    {
        $runId = $this->startRun($triggerSource);

        $stats = $this->createStats($runId);

        try {
            $stats['references_found'] = $this->countUpcomingSources();

            $this->runPostProcessing($stats);

            $this->finishRun(
                $runId,
                'succeeded',
                $stats
            );

            return $stats;
        } catch (Throwable $exception) {
            $this->finishRun(
                $runId,
                'failed',
                $stats,
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    private function createStats(int $runId): array
    {
        return [
            'run_id' => $runId,
            'references_found' => 0,
            'selected_references' => [],
            'created' => 0,
            'updated' => 0,
            'ignored' => 0,
            'errors' => 0,
            'failed_references' => [],
            'groups' => null,
            'registrations' => null,
            'distances' => null,
            'post_processed' => false,
        ];
    }

    private function runPostProcessing(array &$stats): void
    {
        $stats['groups'] = (
            new TournamentGroupingService()
        )->rebuildUpcomingGroups();

        try {
            $stats['distances'] = (
                new RouteDistanceSyncService()
            )->refreshUpcomingGroupDistances();
        } catch (Throwable $exception) {
            $stats['distances'] = [
                'origin_status' => 'failed',
                'error' => $exception->getMessage(),
            ];

            error_log(
                '[FFE Distance] Service indisponible : '
                . $exception->getMessage()
            );
        }

        $stats['registrations'] = (
            new ClubRegistrationSyncService()
        )->refreshUpcomingRegistrations();

        $stats['post_processed'] = true;
    }

    private function startRun(string $triggerSource): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO pbe_sync_runs (
                trigger_source,
                status,
                started_at
            ) VALUES (
                :trigger_source,
                "running",
                UTC_TIMESTAMP()
            )'
        );

        $statement->execute([
            ':trigger_source' => $triggerSource,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    private function finishRun(
        int $runId,
        string $status,
        array $stats,
        ?string $errorMessage = null
    ): void {
        $registrations = $stats['registrations'] ?? null;

        $clubEntriesUpdated = is_array($registrations)
            ? (int) ($registrations['club_entries_updated'] ?? 0)
            : 0;

        $statement = Database::connection()->prepare(
            'UPDATE pbe_sync_runs
             SET
                status = :status,
                finished_at = UTC_TIMESTAMP(),
                tournaments_found = :found,
                tournaments_created = :created,
                tournaments_updated = :updated,
                tournaments_ignored = :ignored,
                club_entries_updated = :club_entries_updated,
                error_message = :error_message
             WHERE id = :id'
        );

        $statement->execute([
            ':status' => $status,
            ':found' => (int) $stats['references_found'],
            ':created' => (int) $stats['created'],
            ':updated' => (int) $stats['updated'],
            ':ignored' => (int) $stats['ignored'],
            ':club_entries_updated' => $clubEntriesUpdated,
            ':error_message' => $errorMessage,
            ':id' => $runId,
        ]);
    }

    private function countUpcomingSources(): int
    {
        $statement = Database::connection()->query(
            'SELECT COUNT(*)
             FROM pbe_tournament_sources
             WHERE is_upcoming = 1
               AND is_excluded = 0'
        );

        return (int) $statement->fetchColumn();
    }

    private function loadKnownSources(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($references), '?')
        );

        $statement = Database::connection()->prepare(
            sprintf(
                'SELECT ffe_ref, details_hash
                 FROM pbe_tournament_sources
                 WHERE ffe_ref IN (%s)',
                $placeholders
            )
        );

        $statement->execute($references);

        $sources = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $sources[(int) $source['ffe_ref']] = $source;
        }

        return $sources;
    }

    private function upsertSource(array $source): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO pbe_tournament_sources (
                ffe_ref,
                department,
                city,
                title,
                normalized_title,
                ffe_url,
                results_url,
                start_date,
                end_date,
                rounds,
                cadence,
                cadence_kind,
                venue,
                address,
                organizer,
                arbiter,
                contact,
                fee_senior,
                fee_youth,
                announcement,
                registration_url,
                is_upcoming,
                is_excluded,
                exclusion_reason,
                details_hash,
                last_seen_at,
                last_detail_sync_at
            ) VALUES (
                :ffe_ref,
                :department,
                :city,
                :title,
                :normalized_title,
                :ffe_url,
                :results_url,
                :start_date,
                :end_date,
                :rounds,
                :cadence,
                :cadence_kind,
                :venue,
                :address,
                :organizer,
                :arbiter,
                :contact,
                :fee_senior,
                :fee_youth,
                :announcement,
                :registration_url,
                :is_upcoming,
                :is_excluded,
                :exclusion_reason,
                :details_hash,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE
                department = VALUES(department),
                city = VALUES(city),
                title = VALUES(title),
                normalized_title = VALUES(normalized_title),
                ffe_url = VALUES(ffe_url),
                results_url = VALUES(results_url),
                start_date = VALUES(start_date),
                end_date = VALUES(end_date),
                rounds = VALUES(rounds),
                cadence = VALUES(cadence),
                cadence_kind = VALUES(cadence_kind),
                venue = VALUES(venue),
                address = VALUES(address),
                organizer = VALUES(organizer),
                arbiter = VALUES(arbiter),
                contact = VALUES(contact),
                fee_senior = VALUES(fee_senior),
                fee_youth = VALUES(fee_youth),
                announcement = VALUES(announcement),
                registration_url = VALUES(registration_url),
                is_upcoming = VALUES(is_upcoming),
                is_excluded = VALUES(is_excluded),
                exclusion_reason = VALUES(exclusion_reason),
                details_hash = VALUES(details_hash),
                last_seen_at = UTC_TIMESTAMP(),
                last_detail_sync_at = UTC_TIMESTAMP()'
        );

        $statement->execute([
            ':ffe_ref' => $source['ffe_ref'],
            ':department' => $source['department'],
            ':city' => $source['city'],
            ':title' => $source['title'],
            ':normalized_title' => $source['normalized_title'],
            ':ffe_url' => $source['ffe_url'],
            ':results_url' => $source['results_url'],
            ':start_date' => $source['start_date'],
            ':end_date' => $source['end_date'],
            ':rounds' => $source['rounds'],
            ':cadence' => $source['cadence'],
            ':cadence_kind' => $source['cadence_kind'],
            ':venue' => $source['venue'],
            ':address' => $source['address'],
            ':organizer' => $source['organizer'],
            ':arbiter' => $source['arbiter'],
            ':contact' => $source['contact'],
            ':fee_senior' => $source['fee_senior'],
            ':fee_youth' => $source['fee_youth'],
            ':announcement' => $source['announcement'],
            ':registration_url' => $source['registration_url'],
            ':is_upcoming' => $source['is_upcoming'] ? 1 : 0,
            ':is_excluded' => $source['is_excluded'] ? 1 : 0,
            ':exclusion_reason' => $source['exclusion_reason'],
            ':details_hash' => $source['details_hash'],
        ]);
    }
}
