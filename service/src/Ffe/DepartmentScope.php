<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use InvalidArgumentException;

final class DepartmentScope
{
    /**
     * Périmètre du club : Pyrénées-Atlantiques, Landes, Gironde,
     * Gers, Hautes-Pyrénées et Haute-Garonne.
     */
    private const ALL = [
        '64',
        '40',
        '33',
        '32',
        '65',
        '31',
    ];

    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * Accepte le nouveau format :
     * {"departments":["64","40",...]}
     *
     * Et conserve le format historique :
     * {"department":"64"}
     */
    public static function fromPayload(array $payload): array
    {
        if (array_key_exists('departments', $payload)) {
            if (!is_array($payload['departments'])) {
                throw new InvalidArgumentException(
                    'Le champ departments doit être un tableau.'
                );
            }

            return self::normalize($payload['departments']);
        }

        if (array_key_exists('department', $payload)) {
            return self::normalize([
                $payload['department'],
            ]);
        }

        return self::all();
    }

    public static function normalize(array $departments): array
    {
        $requested = [];

        foreach ($departments as $department) {
            if (!is_string($department) && !is_int($department)) {
                throw new InvalidArgumentException(
                    'Code de département invalide.'
                );
            }

            $department = trim((string) $department);

            if (!in_array($department, self::ALL, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Département non pris en charge : %s.',
                        $department === '' ? '(vide)' : $department
                    )
                );
            }

            $requested[$department] = true;
        }

        if ($requested === []) {
            throw new InvalidArgumentException(
                'Aucun département demandé.'
            );
        }

        /* Ordre stable : 64, 40, 33, 32, 65, 31. */
        return array_values(
            array_filter(
                self::ALL,
                static fn (string $department): bool =>
                    isset($requested[$department])
            )
        );
    }
}
