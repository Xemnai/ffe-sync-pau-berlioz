<?php

declare(strict_types=1);

namespace PauBerlioz\FfeSync\Ffe;

use RuntimeException;
use Throwable;

final class MultiDepartmentFfeSyncService
{
    public function __construct(
        private readonly FfeSyncService $syncService =
            new FfeSyncService()
    ) {
    }

    /**
     * Importe les départements l'un après l'autre.
     *
     * FfeSyncService conserve son comportement actuel : chaque import
     * met à jour les sources, reconstruit les groupes, les inscriptions,
     * les distances et le payload. Ce choix limite les risques lors de
     * l'extension du périmètre, sans modifier les données existantes.
     */
    public function sync(
        array $departments,
        string $triggerSource
    ): array {
        $departments = DepartmentScope::normalize($departments);

        $summary = [
            'status' => 'ok',
            'departments' => $departments,
            'departments_succeeded' => [],
            'departments_failed' => [],
            'department_results' => [],
            'run_id' => null,
            'run_ids' => [],
            'references_found' => 0,
            'created' => 0,
            'updated' => 0,
            'ignored' => 0,
            'errors' => 0,
            'failed_references' => [],
            'groups' => null,
            'registrations' => null,
            'distances' => null,
            'events' => [],
        ];

        foreach ($departments as $department) {
            try {
                $result = $this->syncService->syncDepartment(
                    $department,
                    $triggerSource
                );

                $runId = isset($result['run_id'])
                    ? (int) $result['run_id']
                    : null;

                if ($runId !== null && $runId > 0) {
                    $summary['run_id'] = $runId;
                    $summary['run_ids'][] = $runId;
                }

                $summary['departments_succeeded'][] = $department;

                $summary['department_results'][] = [
                    'department' => $department,
                    'status' => 'ok',
                    'run_id' => $runId,
                    'references_found' => (int) (
                        $result['references_found'] ?? 0
                    ),
                    'created' => (int) ($result['created'] ?? 0),
                    'updated' => (int) ($result['updated'] ?? 0),
                    'ignored' => (int) ($result['ignored'] ?? 0),
                    'errors' => (int) ($result['errors'] ?? 0),
                ];

                foreach (
                    [
                        'references_found',
                        'created',
                        'updated',
                        'ignored',
                        'errors',
                    ] as $key
                ) {
                    $summary[$key] += (int) ($result[$key] ?? 0);
                }

                foreach (($result['failed_references'] ?? []) as $failed) {
                    if (!is_array($failed)) {
                        continue;
                    }

                    $failed['department'] = $department;
                    $summary['failed_references'][] = $failed;
                }

                $summary['groups'] = $result['groups'] ?? $summary['groups'];

                $summary['registrations'] = $result['registrations']
                    ?? $summary['registrations'];

                $summary['distances'] = $result['distances']
                    ?? $summary['distances'];
            } catch (Throwable $exception) {
                $summary['status'] = 'partial';

                $summary['departments_failed'][] = [
                    'department' => $department,
                    'error' => $this->shortError($exception),
                ];

                $summary['department_results'][] = [
                    'department' => $department,
                    'status' => 'failed',
                    'error' => $this->shortError($exception),
                ];

                error_log(
                    sprintf(
                        '[FFE Sync] Département %s : %s',
                        $department,
                        $exception->getMessage()
                    )
                );
            }
        }

        if ($summary['departments_succeeded'] === []) {
            throw new RuntimeException(
                'Aucun département FFE n’a pu être synchronisé.'
            );
        }

        /* Repart de la base complète, pas seulement du dernier département. */
        $summary['events'] = (
            new UpcomingEventPayloadBuilder()
        )->buildUpcomingEvents();

        return $summary;
    }

    private function shortError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 255, 'UTF-8');
        }

        return substr($message, 0, 255);
    }
}
