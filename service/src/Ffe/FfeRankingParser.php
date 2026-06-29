<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class FfeRankingParser
{
    public function parse(string $html): array
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
                    'Impossible d’analyser le classement FFE.'
                );
            }

            $xpath = new DOMXPath($document);

            $tables = $xpath->query('//table');

            if ($tables === false) {
                return [
                    'published' => false,
                    'players' => [],
                ];
            }

            foreach ($tables as $table) {
                $parsedTable = $this->parseRankingTable($xpath, $table);

                if ($parsedTable !== null) {
                    return [
                        'published' => true,
                        'players' => $parsedTable,
                    ];
                }
            }

            return [
                'published' => false,
                'players' => [],
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorsState);
        }
    }

    private function parseRankingTable(
        DOMXPath $xpath,
        mixed $table
    ): ?array {
        $rows = $xpath->query('.//tr', $table);

        if ($rows === false) {
            return null;
        }

        $headerMap = null;
        $players = [];
        $fallbackOrder = 0;

        foreach ($rows as $row) {
            $cells = $xpath->query('./th|./td', $row);

            if ($cells === false || $cells->length === 0) {
                continue;
            }

            $values = [];

            foreach ($cells as $cell) {
                $values[] = $this->cleanText(
                    $cell->textContent ?? ''
                );
            }

            if ($headerMap === null) {
                $headerMap = $this->buildHeaderMap($values);
                continue;
            }

            $nameIndex = $headerMap['name'];
            $clubIndex = $headerMap['club'];

            if (
                !isset($values[$nameIndex], $values[$clubIndex])
                || $values[$nameIndex] === ''
                || $values[$clubIndex] === ''
            ) {
                continue;
            }

            $fallbackOrder++;

            $displayOrder = $fallbackOrder;

            if (
                $headerMap['order'] !== null
                && isset($values[$headerMap['order']])
                && preg_match(
                    '/\d+/',
                    $values[$headerMap['order']],
                    $matches
                ) === 1
            ) {
                $displayOrder = (int) $matches[0];
            }

            $elo = null;

            if (
                $headerMap['elo'] !== null
                && isset($values[$headerMap['elo']])
                && preg_match(
                    '/\d+/',
                    $values[$headerMap['elo']],
                    $matches
                ) === 1
            ) {
                $elo = (int) $matches[0];
            }

            $players[] = [
                'display_order' => $displayOrder,
                'player_name' => $values[$nameIndex],
                'elo' => $elo,
                'club_name' => $values[$clubIndex],
            ];
        }

        return $headerMap === null ? null : $players;
    }

    private function buildHeaderMap(array $headers): ?array
    {
        $map = [
            'order' => null,
            'name' => null,
            'elo' => null,
            'club' => null,
        ];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize($header);

            if (
                $normalized === 'pl'
                || $normalized === 'nr'
            ) {
                $map['order'] = $index;
            }

            if ($normalized === 'nom') {
                $map['name'] = $index;
            }

            if ($normalized === 'elo') {
                $map['elo'] = $index;
            }

            if ($normalized === 'club') {
                $map['club'] = $index;
            }
        }

        if (
            $map['name'] === null
            || $map['club'] === null
        ) {
            return null;
        }

        return $map;
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
                'ÿ' => 'y',
            ]
        );

        return strtolower(trim($value));
    }
}