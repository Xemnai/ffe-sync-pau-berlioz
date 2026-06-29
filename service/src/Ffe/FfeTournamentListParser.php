<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final class FfeTournamentListParser
{
    private const MONTHS = [
        'janvier' => 1,
        'janv' => 1,
        'fevrier' => 2,
        'fevr' => 2,
        'mars' => 3,
        'avril' => 4,
        'avr' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7,
        'juil' => 7,
        'aout' => 8,
        'septembre' => 9,
        'sept' => 9,
        'octobre' => 10,
        'oct' => 10,
        'novembre' => 11,
        'nov' => 11,
        'decembre' => 12,
        'dec' => 12,
    ];

    public function extractUpcomingTournamentReferences(string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('Extension DOM indisponible.');
        }

        $previousErrorsState = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();

            if (!$document->loadHTML(
                '<?xml encoding="utf-8" ?>' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            )) {
                throw new RuntimeException(
                    'Impossible d’analyser la liste FFE.'
                );
            }

            $xpath = new DOMXPath($document);
            $rows = $xpath->query('//tr');

            if ($rows === false) {
                throw new RuntimeException(
                    'Lignes de tournoi introuvables dans la liste FFE.'
                );
            }

            $today = new DateTimeImmutable(
                'today',
                new DateTimeZone('Europe/Paris')
            );

            $currentYear = null;
            $references = [];

            foreach ($rows as $row) {
                $rowText = $this->cleanText($row->textContent ?? '');

                $heading = $this->extractMonthHeading($rowText);

                if ($heading !== null) {
                    $currentYear = $heading;
                    continue;
                }

                if ($currentYear === null) {
                    continue;
                }

                $links = $xpath->query(
                    ".//a[contains(@href, 'FicheTournoi.aspx?Ref=')]",
                    $row
                );

                if ($links === false || $links->length === 0) {
                    continue;
                }

                $eventDate = $this->extractRowDate(
                    $rowText,
                    $currentYear
                );

                if ($eventDate === null || $eventDate < $today) {
                    continue;
                }

                foreach ($links as $link) {
                    $href = $link->attributes?->getNamedItem('href')
                        ?->nodeValue ?? '';

                    if (
                        preg_match(
                            '/[?&]Ref=(\d+)/i',
                            $href,
                            $matches
                        ) !== 1
                    ) {
                        continue;
                    }

                    $reference = (int) $matches[1];
                    $references[$reference] = $reference;
                }
            }

            return array_values($references);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorsState);
        }
    }

    private function extractMonthHeading(string $text): ?int
    {
        if (
            preg_match(
                '/^([\p{L}]+)\s+(\d{4})$/u',
                $text,
                $matches
            ) !== 1
        ) {
            return null;
        }

        if ($this->monthNumber($matches[1]) === null) {
            return null;
        }

        return (int) $matches[2];
    }

    private function extractRowDate(
        string $rowText,
        int $year
    ): ?DateTimeImmutable {
        if (
            preg_match(
                '/\b(\d{1,2})\s+([\p{L}]+)\.?\b/u',
                $rowText,
                $matches
            ) !== 1
        ) {
            return null;
        }

        $month = $this->monthNumber($matches[2]);

        if ($month === null) {
            return null;
        }

        return DateTimeImmutable::createFromFormat(
            '!Y-n-j',
            sprintf('%d-%d-%d', $year, $month, (int) $matches[1]),
            new DateTimeZone('Europe/Paris')
        ) ?: null;
    }

    private function monthNumber(string $month): ?int
    {
        return self::MONTHS[$this->normalize($month)] ?? null;
    }

    private function cleanText(string $text): string
    {
        $text = str_replace("\xc2\xa0", ' ', $text);

        return trim(
            preg_replace('/\s+/u', ' ', $text) ?? $text
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
            ]
        );

        return strtolower(trim($value));
    }
}