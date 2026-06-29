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

        if ($sources === []) {
            return [
                'groups_total' => 0,
                'single_tournament_groups' => 0,
                'multi_tournament_groups' => 0,
            ];
        }

        $clusters = [];

        foreach ($sources as $source) {
            $groupKey = $this->buildGroupKey($source);

            $clusters[$groupKey][] = $source;
        }

        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            $this->removeSourceLinks(
                $connection,
                array_column($sources, 'ffe_ref')
            );

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

    private function buildGroupKey(array $source): string
    {
        $parts = [
            $this->familyTitle((string) $source['title']),
            $this->normalize((string) $source['department']),
            $this->normalize((string) $source['city']),
            (string) $source['start_date'],
            (string) $source['end_date'],
            $this->normalize((string) ($source['organizer'] ?? '')),
            $this->normalize((string) ($source['venue'] ?? '')),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function buildGroupTitle(array $sources): string
    {
        $firstTitle = (string) $sources[0]['title'];

        if (count($sources) === 1) {
            return $firstTitle;
        }

        $titleWithoutVariant = $this->removeVariantSuffix($firstTitle);

        return $titleWithoutVariant !== ''
            ? $titleWithoutVariant
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

    private function removeSourceLinks(
        PDO $connection,
        array $references
    ): void {
        $references = array_values(
            array_unique(
                array_map(
                    static fn (mixed $reference): int => (int) $reference,
                    $references
                )
            )
        );

        if ($references === []) {
            return;
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($references), '?')
        );

        $statement = $connection->prepare(
            sprintf(
                'DELETE FROM pbe_event_group_sources
                 WHERE ffe_ref IN (%s)',
                $placeholders
            )
        );

        $statement->execute($references);
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

        return (int) $left['ffe_ref'] <=> (int) $right['ffe_ref'];
    }

    private function variantRank(string $title): int
    {
        if (
            preg_match(
                '/(?:\s*[-–—:]?\s*)([A-Z])$/u',
                trim($title),
                $matches
            ) === 1
        ) {
            return ord(strtoupper($matches[1])) - 64;
        }

        if (
            preg_match(
                '/(?:\s*[-–—:]?\s*)(\d{1,2})$/u',
                trim($title),
                $matches
            ) === 1
        ) {
            return 100 + (int) $matches[1];
        }

        return 0;
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
        return $this->normalize(
            $this->removeVariantSuffix($title)
        );
    }

    private function removeVariantSuffix(string $title): string
    {
        return trim(
            preg_replace(
                '/(?:\s*[-–—:]?\s*)(?:\(?[A-Z]\)?|\(?\d{1,2}\)?)$/u',
                '',
                trim($title)
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
            ]
        );

        $value = strtolower($value);

        return trim(
            preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value
        );
    }
}