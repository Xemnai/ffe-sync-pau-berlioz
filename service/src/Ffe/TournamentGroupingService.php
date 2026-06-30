<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use PDO;
use PauBerlioz\FfeSync\Database;
use RuntimeException;
use Throwable;

final class TournamentGroupingService
{
    public function rebuildUpcomingGroups(): array
    {
        $sources = $this->loadUpcomingSources();
        $clusters = $this->buildClusters($sources);

        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            /*
             * On repart de toutes les associations existantes.
             *
             * C'est volontaire : si un tournoi anciennement importé devient
             * fermé ou annulé, il est désormais is_excluded = 1 et n'est
             * plus dans $sources. En supprimant d'abord tous les liens,
             * il ne peut pas rester associé à un ancien groupe.
             */
            $this->removeAllSourceLinks($connection);

            $singleTournamentGroups = 0;
            $multiTournamentGroups = 0;

            foreach ($clusters as $groupKey => $groupSources) {
                usort(
                    $groupSources,
                    fn (array $left, array $right): int =>
                        $this->compareSources($left, $right)
                );

                $groupTitle = $this->buildGroupTitle($groupSources);

                $groupId = $this->upsertGroup(
                    $connection,
                    $groupKey,
                    $groupTitle,
                    $groupSources
                );

                $this->replaceGroupSources(
                    $connection,
                    $groupId,
                    $groupSources
                );

                if (count($groupSources) > 1) {
                    $multiTournamentGroups++;
                } else {
                    $singleTournamentGroups++;
                }
            }

            $this->deleteOrphanGroups($connection);

            $connection->commit();

            return [
                'groups_total' => count($clusters),
                'single_tournament_groups' => $singleTournamentGroups,
                'multi_tournament_groups' => $multiTournamentGroups,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function loadUpcomingSources(): array
    {
        $statement = Database::connection()->query(
            'SELECT
                ffe_ref,
                department,
                city,
                title,
                normalized_title,
                start_date,
                end_date,
                cadence_kind,
                venue,
                address,
                organizer
             FROM pbe_tournament_sources
             WHERE is_upcoming = 1
               AND is_excluded = 0
               AND start_date IS NOT NULL
             ORDER BY start_date, end_date, ffe_ref'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildClusters(array $sources): array
    {
        $clusters = [];

        foreach ($sources as $source) {
            $groupKey = $this->buildGroupKey($source);

            $clusters[$groupKey][] = $source;
        }

        return $clusters;
    }

    private function buildGroupKey(array $source): string
    {
        $parts = [
            $this->normalize(
                $this->familyTitle((string) $source['title'])
            ),
            $this->normalize((string) $source['department']),
            $this->normalize((string) $source['city']),
            (string) $source['start_date'],
            (string) $source['end_date'],
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function buildGroupTitle(array $sources): string
    {
        $firstTitle = trim((string) $sources[0]['title']);

        if (count($sources) === 1) {
            return $firstTitle;
        }

        /*
         * Toutes les sources d'un cluster ont le même family title
         * normalisé. On conserve la première version lisible, avec accents.
         */
        $familyTitle = $this->familyTitle($firstTitle);

        return $familyTitle !== ''
            ? $familyTitle
            : $firstTitle;
    }

    private function upsertGroup(
        PDO $connection,
        string $groupKey,
        string $title,
        array $sources
    ): int {
        $firstSource = $sources[0];

        $statement = $connection->prepare(
            'INSERT INTO pbe_event_groups (
                group_key,
                title,
                normalized_title,
                department,
                city,
                start_date,
                end_date,
                cadence_kind,
                last_seen_at
            ) VALUES (
                :group_key,
                :title,
                :normalized_title,
                :department,
                :city,
                :start_date,
                :end_date,
                :cadence_kind,
                UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                normalized_title = VALUES(normalized_title),
                department = VALUES(department),
                city = VALUES(city),
                start_date = VALUES(start_date),
                end_date = VALUES(end_date),
                cadence_kind = VALUES(cadence_kind),
                last_seen_at = UTC_TIMESTAMP()'
        );

        $statement->execute([
            ':group_key' => $groupKey,
            ':title' => $title,
            ':normalized_title' => $this->normalize($title),
            ':department' => $firstSource['department'],
            ':city' => $firstSource['city'],
            ':start_date' => $firstSource['start_date'],
            ':end_date' => $firstSource['end_date'],
            ':cadence_kind' => $this->groupCadenceKind($sources),
        ]);

        $select = $connection->prepare(
            'SELECT id
             FROM pbe_event_groups
             WHERE group_key = :group_key'
        );

        $select->execute([
            ':group_key' => $groupKey,
        ]);

        $groupId = $select->fetchColumn();

        if ($groupId === false) {
            throw new RuntimeException(
                'Impossible de retrouver le groupe créé.'
            );
        }

        return (int) $groupId;
    }

    private function removeAllSourceLinks(PDO $connection): void
    {
        $connection->exec(
            'DELETE FROM pbe_event_group_sources'
        );
    }

    private function replaceGroupSources(
        PDO $connection,
        int $groupId,
        array $sources
    ): void {
        $delete = $connection->prepare(
            'DELETE FROM pbe_event_group_sources
             WHERE group_id = :group_id'
        );

        $delete->execute([
            ':group_id' => $groupId,
        ]);

        $insert = $connection->prepare(
            'INSERT INTO pbe_event_group_sources (
                group_id,
                ffe_ref,
                source_order
            ) VALUES (
                :group_id,
                :ffe_ref,
                :source_order
            )'
        );

        foreach ($sources as $index => $source) {
            $insert->execute([
                ':group_id' => $groupId,
                ':ffe_ref' => $source['ffe_ref'],
                ':source_order' => $index + 1,
            ]);
        }
    }

    private function compareSources(array $left, array $right): int
    {
        $leftRank = $this->variantRank((string) $left['title']);
        $rightRank = $this->variantRank((string) $right['title']);

        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        $titleComparison = strnatcasecmp(
            $this->normalize((string) $left['title']),
            $this->normalize((string) $right['title'])
        );

        if ($titleComparison !== 0) {
            return $titleComparison;
        }

        return (int) $left['ffe_ref'] <=> (int) $right['ffe_ref'];
    }

    private function variantRank(string $title): int
    {
        $normalizedTitle = $this->normalize($title);
        $titleWithoutRating = $this->removeRatingSuffix($title);
        $normalizedWithoutRating = $this->normalize($titleWithoutRating);

        if (str_contains($normalizedTitle, 'principal')) {
            return 0;
        }

        /*
         * Exemple :
         * Circuit d'Echecs Gascon 2026 Tournoi
         * avant
         * Circuit d'Echecs Gascon 2026 Tournoi Open
         */
        if (preg_match('/\btournoi$/u', $normalizedWithoutRating) === 1) {
            return 10;
        }

        if (str_contains($normalizedTitle, 'open')) {
            return 20;
        }

        if (
            preg_match(
                '/(?:^|\s)([a-z])$/u',
                $normalizedWithoutRating,
                $matches
            ) === 1
        ) {
            return 30 + (ord($matches[1]) - ord('a'));
        }

        if (str_contains($normalizedTitle, 'masters')) {
            return 80;
        }

        return 100;
    }

    private function groupCadenceKind(array $sources): string
    {
        $kinds = [];

        foreach ($sources as $source) {
            $kind = (string) ($source['cadence_kind'] ?? 'inconnu');

            if ($kind !== 'inconnu') {
                $kinds[$kind] = true;
            }
        }

        if (count($kinds) !== 1) {
            return 'inconnu';
        }

        return (string) array_key_first($kinds);
    }

    private function familyTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            return '';
        }

        $withoutRating = $this->removeRatingSuffix($title);

        /*
         * Étape importante : retirer d'abord A / B / C / 1 / 2 éventuel.
         *
         * Sans cela :
         * "Circuit d'Echecs Gascon 2026 Tournoi Open A"
         * devenait seulement "... Tournoi Open" et ne passait jamais
         * dans la règle qui retire le suffixe "Tournoi Open".
         */
        $withoutVariant = preg_replace(
            '/(?:\s*[-–—:]?\s*)(?:\(?[A-Z]\)?|\(?\d{1,2}\)?)$/u',
            '',
            $withoutRating
        );

        $withoutVariant = trim(
            $withoutVariant ?? $withoutRating
        );

        /*
         * 30ème Tournoi international de Créon – Tournoi Principal
         * Jérôme Bert (-2200)
         * => 30ème Tournoi international de Créon
         */
        if (
            preg_match(
                '/^(?<family>.+?)\s*[-–—:]\s*tournoi\b.+$/iu',
                $withoutVariant,
                $matches
            ) === 1
        ) {
            return trim($matches['family']);
        }

        /*
         * Circuit d'Echecs Gascon 2026 Tournoi
         * Circuit d'Echecs Gascon 2026 Tournoi Open
         * Circuit d'Echecs Gascon 2026 Tournoi Open A
         * => Circuit d'Echecs Gascon 2026
         */
        if (
            preg_match(
                '/^(?<family>.+?)\s+tournoi(?:\s+open)?$/iu',
                $withoutVariant,
                $matches
            ) === 1
        ) {
            return trim($matches['family']);
        }

        return $withoutVariant !== ''
            ? $withoutVariant
            : $title;
    }

    private function removeRatingSuffix(string $title): string
    {
        return trim(
            preg_replace(
                '/\s*\(\s*[+-]\s*\d{3,4}\s*\)\s*$/u',
                '',
                $title
            ) ?? $title
        );
    }

    private function normalize(string $value): string
    {
        $value = strtr(
            $value,
            [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a',
                'ç' => 'c',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'î' => 'i', 'ï' => 'i',
                'ô' => 'o', 'ö' => 'o',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ÿ' => 'y',
                'œ' => 'oe',
                'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a',
                'Ç' => 'c',
                'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
                'Î' => 'i', 'Ï' => 'i',
                'Ô' => 'o', 'Ö' => 'o',
                'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u',
                'Ÿ' => 'y',
                'Œ' => 'oe',
            ]
        );

        $value = strtolower($value);

        return trim(
            preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value
        );
    }

    private function deleteOrphanGroups(PDO $connection): void
    {
        /*
         * On conserve les anciens groupes éventuellement liés à un évènement
         * WordPress : le plugin WordPress se charge de mettre l'évènement
         * absent du nouveau payload à la corbeille après une synchro complète.
         */
        $connection->exec(
            'DELETE g
             FROM pbe_event_groups g
             LEFT JOIN pbe_event_group_sources egs
                 ON egs.group_id = g.id
             WHERE egs.group_id IS NULL
               AND g.wp_event_id IS NULL'
        );
    }
}
