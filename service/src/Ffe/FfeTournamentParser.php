<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use DOMDocument;
use DOMXPath;

final class FfeTournamentParser
{
    public function extractTournamentReferences(string $html): array
    {
        preg_match_all(
            '~FicheTournoi\.aspx\?Ref=(\d+)~i',
            $html,
            $matches
        );

        $references = array_map(
            static fn (string $reference): int => (int) $reference,
            $matches[1] ?? []
        );

        $references = array_values(array_unique($references));
        sort($references);

        return $references;
    }

    public function parseTournament(
        int $reference,
        string $department,
        string $html
    ): array {
        $lines = $this->extractLines($html);

        [$title, $city] = $this->extractTitleAndCity($lines);

        $dates = $this->extractField($lines, 'Dates');
        $dateValues = $this->extractFrenchDates($dates ?? '');

        $startDate = $dateValues[0] ?? null;
        $endDate = $dateValues[count($dateValues) - 1] ?? $startDate;

        $cadence = $this->extractField($lines, 'Cadence');
        $announcement = $this->extractAnnouncement($lines);
$address = $this->extractField($lines, 'Adresse')
    ?? $this->extractStructuredField($html, 'Adresse');
        $normalizedTitle = $this->normalize($title);
        $isExcluded = $this->isExcludedTitle($normalizedTitle);

        return [
            'ffe_ref' => $reference,
            'department' => $department,
            'city' => $city,
            'title' => $title,
            'normalized_title' => $normalizedTitle,
            'ffe_url' => sprintf(
                'https://www.echecs.asso.fr/FicheTournoi.aspx?Ref=%d',
                $reference
            ),
'results_url' => $this->extractRankingUrl($html)
    ?? $this->buildRankingUrl($reference),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rounds' => $this->extractIntegerField(
                $this->extractField($lines, 'Nombre de rondes')
            ),
            'cadence' => $cadence,
            'cadence_kind' => $this->classifyCadence(
                $title,
                $cadence
            ),
         'venue' => $address,
'address' => $address,
            'organizer' => $this->extractField($lines, 'Organisateur'),
            'arbiter' => $this->extractField($lines, 'Arbitre'),
            'contact' => $this->extractField($lines, 'Contact'),
            'fee_senior' => $this->extractField(
                $lines,
                'Inscription Senior'
            ),
            'fee_youth' => $this->extractField(
                $lines,
                'Inscription Jeunes'
            ),
            'announcement' => $announcement,
            'registration_url' => $this->extractRegistrationUrl(
                $announcement
            ),
            'is_excluded' => $isExcluded,
            'exclusion_reason' => $isExcluded
                ? 'Titre scolaire, collège, interne ou individuel.'
                : null,
        ];
    }

    private function extractLines(string $html): array
    {
        $html = preg_replace(
            '~<(?:br\s*/?|/tr|/td|/p|/div|/h[1-6])[^>]*>~i',
            "\n",
            $html
        ) ?? $html;

        $text = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = str_replace(["\xc2\xa0", "\r"], [' ', ''], $text);

        $lines = preg_split('/\n+/u', $text) ?: [];

        return array_values(
            array_filter(
                array_map(
                    static fn (string $line): string => trim(
                        preg_replace('/\s+/u', ' ', $line) ?? $line
                    ),
                    $lines
                ),
                static fn (string $line): bool => $line !== ''
            )
        );
    }

    private function extractTitleAndCity(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (
                preg_match(
                    '/^\d{2,3}\s*-\s*(.+)$/u',
                    $line,
                    $matches
                ) === 1
            ) {
                $title = $this->previousNonEmptyLine($lines, $index);

                if ($title === null) {
                    break;
                }

                return [$title, trim($matches[1])];
            }
        }

        throw new RuntimeException(
            'Titre ou ville introuvable sur la fiche FFE.'
        );
    }

    private function previousNonEmptyLine(
        array $lines,
        int $index
    ): ?string {
        for ($position = $index - 1; $position >= 0; $position--) {
            if ($lines[$position] !== '') {
                return $lines[$position];
            }
        }

        return null;
    }

    private function extractField(array $lines, string $label): ?string
    {
        $pattern = '/^' . preg_quote($label, '/') . '\s*:\s*(.*)$/iu';

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            $value = trim($matches[1]);

            if ($value !== '') {
                return $value;
            }

            for ($next = $index + 1; $next < count($lines); $next++) {
                if ($lines[$next] !== '') {
                    return $lines[$next];
                }
            }
        }

        return null;
    }

    private function extractAnnouncement(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            if (
                preg_match('/^Annonce\s*:\s*(.*)$/iu', $line, $matches)
                !== 1
            ) {
                continue;
            }

            $parts = [];

            if (trim($matches[1]) !== '') {
                $parts[] = trim($matches[1]);
            }

            for ($next = $index + 1; $next < count($lines); $next++) {
                $candidate = $lines[$next];

                if (
                    preg_match(
                        '/^(Joueurs|Grille|Classement|Fide|Rd\d+|Stats|Copyright)/iu',
                        $candidate
                    ) === 1
                ) {
                    break;
                }

                $parts[] = $candidate;
            }

            $announcement = trim(implode("\n", $parts));

            return $announcement !== '' ? $announcement : null;
        }

        return null;
    }

    private function extractFrenchDates(string $value): array
    {
        preg_match_all(
            '/\b(\d{1,2})\s+([\p{L}]+)\s+(\d{4})\b/u',
            $value,
            $matches,
            PREG_SET_ORDER
        );

        $dates = [];

        foreach ($matches as $match) {
            $date = $this->parseFrenchDate(
                (int) $match[1],
                $match[2],
                (int) $match[3]
            );

            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return array_values(array_unique($dates));
    }

    private function parseFrenchDate(
        int $day,
        string $month,
        int $year
    ): ?string {
        $months = [
            'janvier' => 1,
            'fevrier' => 2,
            'mars' => 3,
            'avril' => 4,
            'mai' => 5,
            'juin' => 6,
            'juillet' => 7,
            'aout' => 8,
            'septembre' => 9,
            'octobre' => 10,
            'novembre' => 11,
            'decembre' => 12,
        ];

        $monthNumber = $months[$this->normalize($month)] ?? null;

        if ($monthNumber === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-n-j',
            sprintf('%d-%d-%d', $year, $monthNumber, $day),
            new DateTimeZone('Europe/Paris')
        );

        return $date?->format('Y-m-d');
    }

    private function extractIntegerField(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/\d+/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    private function classifyCadence(
        string $title,
        ?string $cadence
    ): string {
        $text = $this->normalize($title . ' ' . ($cadence ?? ''));

        if (str_contains($text, 'blitz')) {
            return 'blitz';
        }

        if (str_contains($text, 'rapide')) {
            return 'rapide';
        }

        if ($cadence === null) {
            return 'inconnu';
        }

        if (
            preg_match(
                '/(\d+)\s*h(?:\s*(\d{1,2}))?/iu',
                $cadence,
                $matches
            ) === 1
        ) {
            $minutes = ((int) $matches[1] * 60)
                + (int) ($matches[2] ?? 0);

            return $minutes > 60 ? 'lent' : 'rapide';
        }

        if (
            preg_match(
                '/(\d{1,3})\s*(?:mn|min|minutes|\')/iu',
                $cadence,
                $matches
            ) === 1
        ) {
            $minutes = (int) $matches[1];

            if ($minutes <= 10) {
                return 'blitz';
            }

            if ($minutes <= 60) {
                return 'rapide';
            }

            return 'lent';
        }

        return 'inconnu';
    }

    private function extractRegistrationUrl(?string $announcement): ?string
    {
        if ($announcement === null) {
            return null;
        }

        preg_match_all(
            '~https?://[^\s<>"\]]+~iu',
            $announcement,
            $matches
        );

        foreach ($matches[0] ?? [] as $url) {
            $url = rtrim($url, ".,;:)]}");

            if (
                str_contains(strtolower($url), 'helloasso')
                || str_contains(strtolower($url), 'billetweb')
                || str_contains(strtolower($url), 'weezevent')
            ) {
                return $url;
            }
        }

        return null;
    }

    private function isExcludedTitle(string $normalizedTitle): bool
    {
        return preg_match(
            '/\b(scolaire|ecole|college|interne|individuel)\b/u',
            $normalizedTitle
        ) === 1;
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

    private function extractRankingUrl(string $html): ?string
{
    if (
        preg_match(
            '~href\s*=\s*["\']([^"\']*Resultats\.aspx\?[^"\']*Action=Cl[^"\']*)["\']~iu',
            $html,
            $matches
        ) !== 1
    ) {
        return null;
    }

    $url = html_entity_decode(
        $matches[1],
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    if (
        str_starts_with($url, 'https://')
        || str_starts_with($url, 'http://')
    ) {
        return $url;
    }

    return 'https://www.echecs.asso.fr/' . ltrim($url, '/');
}
}