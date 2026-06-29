<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

final class PauBerliozClubMatcher
{
    public function matches(string $clubName): bool
    {
        $normalized = $this->normalize($clubName);

        $knownNames = [
            'club d echecs pau berlioz',
            'echiquier pau berlioz',
            'club echecs pau berlioz',
        ];

        if (in_array($normalized, $knownNames, true)) {
            return true;
        }

        return str_contains($normalized, 'pau berlioz');
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

        $value = strtolower($value);

        return trim(
            preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value
        );
    }
}