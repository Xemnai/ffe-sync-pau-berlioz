<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use PDO;
use PauBerlioz\FfeSync\Database;

final class UpcomingEventPayloadBuilder
{
    private const DEFAULT_START_TIME = '09:00:00';

    private const DEFAULT_END_TIME = '19:00:00';

    public function buildUpcomingEvents(): array
    {
        $events = [];

        foreach ($this->loadGroups() as $group) {
            $sources = $this->loadSources((int) $group['id']);

            if ($sources === []) {
                continue;
            }

            $clubPlayers = $this->loadClubPlayers((int) $group['id']);

            $event = [
                'schema_version' => 1,
                'external_id' => 'ffe-group:' . $group['group_key'],
                'group_key' => $group['group_key'],
                'group_id' => (int) $group['id'],

                'title' => (string) $group['title'],
                'category_name' => 'Open à venir',
                'tag_name' => $this->eventTagName(
                    (string) $group['cadence_kind']
                ),

                'timezone' => 'Europe/Paris',

                'start_date' => $group['start_date'],
                'end_date' => $group['end_date'],

                /*
                 * Les horaires FFE ne sont pas toujours structurés.
                 * Ils seront approximatifs jusqu'à une future amélioration.
                 */
                'start_time' => self::DEFAULT_START_TIME,
                'end_time' => self::DEFAULT_END_TIME,
                'time_is_approximate' => true,

                'location' => $this->buildLocation($group, $sources),

                'distance_km' => $group['distance_km'] === null
                    ? null
                    : (float) $group['distance_km'],

                'rounds' => $this->uniformInteger($sources, 'rounds'),

                'cadence' => $this->cleanCadence(
                    $this->uniformString($sources, 'cadence')
                ),

                'fee_senior' => $this->representativePrice(
                    $sources,
                    'fee_senior'
                ),

                'fee_youth' => $this->representativePrice(
                    $sources,
                    'fee_youth'
                ),

                'club_players' => $clubPlayers,

                'sources' => array_map(
                    fn (array $source): array => $this->buildSourcePayload(
                        $group,
                        $source
                    ),
                    $sources
                ),
            ];

            $event['description_html'] = $this->buildDescription(
                $group,
                $sources,
                $clubPlayers,
                $event
            );

            $event['content_hash'] = hash(
                'sha256',
                json_encode(
                    $event,
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            );

            $events[] = $event;
        }

        return $events;
    }

    private function loadGroups(): array
    {
        $statement = Database::connection()->query(
            'SELECT DISTINCT
                g.id,
                g.group_key,
                g.title,
                g.department,
                g.city,
                g.start_date,
                g.end_date,
                g.cadence_kind,
                g.distance_km
             FROM pbe_event_groups g
             INNER JOIN pbe_event_group_sources egs
                ON egs.group_id = g.id
             INNER JOIN pbe_tournament_sources s
                ON s.ffe_ref = egs.ffe_ref
             WHERE s.is_upcoming = 1
               AND s.is_excluded = 0
             ORDER BY g.start_date, g.end_date, g.id'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadSources(int $groupId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT
                s.ffe_ref,
                s.title,
                s.ffe_url,
                s.results_url,
                s.start_date,
                s.end_date,
                s.rounds,
                s.cadence,
                s.cadence_kind,
                s.venue,
                s.address,
                s.fee_senior,
                s.fee_youth,
                s.registration_url,
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

    private function loadClubPlayers(int $groupId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT
                r.player_name,
                r.elo,
                r.club_name,
                r.display_order,
                egs.source_order
             FROM pbe_event_group_sources egs
             INNER JOIN pbe_club_registrations r
                ON r.ffe_ref = egs.ffe_ref
             WHERE egs.group_id = :group_id
             ORDER BY egs.source_order, r.display_order'
        );

        $statement->execute([
            ':group_id' => $groupId,
        ]);

        $players = [];
        $seenNames = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $player) {
            $key = $this->normalize(
                (string) $player['player_name']
            );

            if (isset($seenNames[$key])) {
                continue;
            }

            $seenNames[$key] = true;

            $players[] = [
                'name' => $player['player_name'],
                'elo' => $player['elo'] === null
                    ? null
                    : (int) $player['elo'],
                'club_name' => $player['club_name'],
                'display_order' => (int) $player['display_order'],
                'source_order' => (int) $player['source_order'],
            ];
        }

        return $players;
    }

    private function buildLocation(
        array $group,
        array $sources
    ): array {
        /*
         * Pour un groupe de plusieurs tournois, on prend la première
         * adresse disponible, généralement celle de l'Open principal.
         */
        $venue = $this->firstNonEmptyString($sources, 'venue');
        $address = $this->firstNonEmptyString($sources, 'address');

        $city = trim((string) $group['city']);
        $department = trim((string) $group['department']);

        $parts = array_values(
            array_unique(
                array_filter(
                    [
                        $venue,
                        $address,
                        $city !== ''
                            ? sprintf('%s (%s)', $city, $department)
                            : null,
                    ],
                    static fn (?string $value): bool =>
                        $value !== null && trim($value) !== ''
                )
            )
        );

        return [
            'venue' => $venue,
            'address' => $address,
            'city' => $city,
            'department' => $department,
            'display_name' => implode(' — ', $parts),
        ];
    }

    private function buildSourcePayload(
        array $group,
        array $source
    ): array {
        return [
            'ffe_ref' => (int) $source['ffe_ref'],

            'label' => $this->sourceLabel(
                (string) $group['title'],
                (string) $source['title']
            ),

            'title' => $source['title'],
            'ffe_url' => $source['ffe_url'],
            'results_url' => $source['results_url'],
            'registration_url' => $source['registration_url'],

            'rounds' => $source['rounds'] === null
                ? null
                : (int) $source['rounds'],

            'cadence' => $this->cleanCadence(
                $source['cadence'] === null
                    ? null
                    : (string) $source['cadence']
            ),

            'cadence_kind' => $source['cadence_kind'],
            'fee_senior' => $source['fee_senior'],
            'fee_youth' => $source['fee_youth'],
            'source_order' => (int) $source['source_order'],
        ];
    }

    private function buildDescription(
        array $group,
        array $sources,
        array $clubPlayers,
        array $event
    ): string {
        $blocks = [];

        if (count($sources) > 1) {
            $items = [];

            foreach ($sources as $source) {
                $items[] = sprintf(
                    '<li>%s</li>',
                    $this->escape(
                        $this->sourceLabel(
                            (string) $group['title'],
                            (string) $source['title']
                        )
                    )
                );
            }

            $blocks[] = sprintf(
                '<p>♟️ <strong>%d tournois proposés :</strong></p><ul>%s</ul>',
                count($sources),
                implode('', $items)
            );
        }

        if ($event['rounds'] !== null) {
            $blocks[] = sprintf(
                '<p>♟️ Nombre de rondes : %d</p>',
                $event['rounds']
            );
        }

        if ($event['cadence'] !== null) {
            $blocks[] = sprintf(
                '<p>⏳ Cadence : %s</p>',
                $this->escape((string) $event['cadence'])
            );
        }

        if ($event['location']['display_name'] !== '') {
            $blocks[] = sprintf(
                '<p>📍 Lieu : %s</p>',
                $this->escape(
                    (string) $event['location']['display_name']
                )
            );
        }

        if ($event['distance_km'] !== null) {
            $blocks[] = sprintf(
                '<p>🚗 Distance depuis Pau Berlioz : %s km</p>',
                $this->escape(
                    number_format(
                        (float) $event['distance_km'],
                        1,
                        ',',
                        ' '
                    )
                )
            );
        }

        $price = $this->formatPrice(
            $event['fee_senior'],
            $event['fee_youth']
        );

        if ($price !== null) {
            $blocks[] = sprintf(
                '<p>🏷️ Prix : %s</p>',
                $this->escape($price)
            );
        }

        if ($clubPlayers !== []) {
            $names = implode(
                ', ',
                array_map(
                    static fn (array $player): string =>
                        (string) $player['name'],
                    $clubPlayers
                )
            );

            $blocks[] = sprintf(
                '<p>👥 Inscrits du club : %s</p>',
                $this->escape($names)
            );
        }

        $blocks[] = $this->buildFfeLinksBlock(
            $group,
            $sources
        );

        $registrationBlock = $this->buildRegistrationLinksBlock(
            $group,
            $sources
        );

        if ($registrationBlock !== null) {
            $blocks[] = $registrationBlock;
        }

        return implode(
            "\n",
            array_filter(
                $blocks,
                static fn (?string $block): bool =>
                    $block !== null && $block !== ''
            )
        );
    }

    private function buildFfeLinksBlock(
        array $group,
        array $sources
    ): string {
        if (count($sources) === 1) {
            $source = $sources[0];

            return sprintf(
                '<p>🔗 <a href="%s" target="_blank" rel="noopener noreferrer">Fiche FFE</a></p>',
                $this->escapeUrl((string) $source['ffe_url'])
            );
        }

        $links = [];

        foreach ($sources as $source) {
            $links[] = sprintf(
                '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
                $this->escapeUrl((string) $source['ffe_url']),
                $this->escape(
                    $this->sourceLabel(
                        (string) $group['title'],
                        (string) $source['title']
                    )
                )
            );
        }

        return sprintf(
            '<p>🔗 <strong>Fiches FFE :</strong></p><ul>%s</ul>',
            implode('', $links)
        );
    }

    private function buildRegistrationLinksBlock(
        array $group,
        array $sources
    ): ?string {
        $registrationSources = array_values(
            array_filter(
                $sources,
                static fn (array $source): bool =>
                    is_string($source['registration_url'])
                    && trim($source['registration_url']) !== ''
            )
        );

        if ($registrationSources === []) {
            return null;
        }

        $uniqueUrls = array_values(
            array_unique(
                array_map(
                    static fn (array $source): string =>
                        (string) $source['registration_url'],
                    $registrationSources
                )
            )
        );

        if (count($uniqueUrls) === 1) {
            return sprintf(
                '<p>📝 Inscription : <a href="%s" target="_blank" rel="noopener noreferrer">lien d’inscription</a></p>',
                $this->escapeUrl($uniqueUrls[0])
            );
        }

        $links = [];

        foreach ($registrationSources as $source) {
            $links[] = sprintf(
                '<li>%s : <a href="%s" target="_blank" rel="noopener noreferrer">inscription</a></li>',
                $this->escape(
                    $this->sourceLabel(
                        (string) $group['title'],
                        (string) $source['title']
                    )
                ),
                $this->escapeUrl(
                    (string) $source['registration_url']
                )
            );
        }

        return sprintf(
            '<p>📝 <strong>Inscriptions :</strong></p><ul>%s</ul>',
            implode('', $links)
        );
    }

    private function firstNonEmptyString(
        array $sources,
        string $field
    ): ?string {
        foreach ($sources as $source) {
            $value = trim((string) ($source[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function representativePrice(
        array $sources,
        string $field
    ): ?string {
        $canonicalValue = null;
        $candidates = [];

        foreach ($sources as $source) {
            $value = trim((string) ($source[$field] ?? ''));

            if ($value === '') {
                return null;
            }

            $canonical = $this->canonicalPrice($value);

            if ($canonicalValue === null) {
                $canonicalValue = $canonical;
            } elseif ($canonical !== $canonicalValue) {
                return null;
            }

            $candidates[] = $value;
        }

        if ($candidates === []) {
            return null;
        }

        /*
         * On conserve l'explication la plus complète,
         * notamment “à partir du 01/08/2026”.
         */
        usort(
            $candidates,
            static fn (string $left, string $right): int =>
                strlen($right) <=> strlen($left)
        );

        return $candidates[0];
    }

    private function uniformString(
        array $sources,
        string $field
    ): ?string {
        $values = [];

        foreach ($sources as $source) {
            $value = trim((string) ($source[$field] ?? ''));

            if ($value === '') {
                return null;
            }

            $values[$this->normalize($value)] = $value;
        }

        if (count($values) !== 1) {
            return null;
        }

        return reset($values) ?: null;
    }

    private function uniformInteger(
        array $sources,
        string $field
    ): ?int {
        $values = [];

        foreach ($sources as $source) {
            if ($source[$field] === null) {
                return null;
            }

            $values[(int) $source[$field]] = true;
        }

        if (count($values) !== 1) {
            return null;
        }

        return (int) array_key_first($values);
    }

    private function cleanCadence(?string $cadence): ?string
    {
        if ($cadence === null) {
            return null;
        }

        $cadence = str_replace(
            ['[', ']'],
            '',
            $cadence
        );

        $cadence = preg_replace(
            "/(\d+)\s*''/",
            '$1"',
            $cadence
        ) ?? $cadence;

        return trim(
            preg_replace('/\s+/u', ' ', $cadence) ?? $cadence
        );
    }

    private function formatPrice(
        ?string $senior,
        ?string $youth
    ): ?string {
        $senior = $senior === null ? null : trim($senior);
        $youth = $youth === null ? null : trim($youth);

        if ($senior === '' || $senior === null) {
            return $youth === '' || $youth === null
                ? null
                : sprintf('Jeunes : %s', $youth);
        }

        if ($youth === '' || $youth === null) {
            return $senior;
        }

        if (
            $this->canonicalPrice($senior)
            === $this->canonicalPrice($youth)
        ) {
            return $senior;
        }

        return sprintf('%s (jeunes %s)', $senior, $youth);
    }

    private function canonicalPrice(string $value): string
    {
        $value = preg_replace(
            '/\s+à\s+partir\s+du\s+\d{1,2}\/\d{1,2}\/\d{4}/iu',
            '',
            $value
        ) ?? $value;

        return $this->normalize($value);
    }

    private function sourceLabel(
        string $groupTitle,
        string $sourceTitle
    ): string {
        if ($sourceTitle === $groupTitle) {
            return 'Open principal';
        }

        if (
            preg_match(
                '/\s+([A-Z])$/u',
                trim($sourceTitle),
                $matches
            ) === 1
        ) {
            return 'Open ' . strtoupper($matches[1]);
        }

        return $sourceTitle;
    }

    private function eventTagName(string $cadenceKind): ?string
    {
        return match ($cadenceKind) {
            'blitz' => 'Blitz',
            'rapide' => 'Rapide',
            'lent' => 'Lent',
            default => null,
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private function escapeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return '#';
        }

        return $this->escape($url);
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
                'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a',
                'Ç' => 'c',
                'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
                'Î' => 'i', 'Ï' => 'i',
                'Ô' => 'o', 'Ö' => 'o',
                'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u',
                'Ÿ' => 'y',
            ]
        );

        $value = strtolower($value);

        return trim(
            preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value
        );
    }
}