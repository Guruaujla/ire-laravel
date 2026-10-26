<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/** @internal Maps a terminal status to its event. */
final class Factory
{
    /** @param array<string, mixed> $job */
    public static function forStatus(string $tenantId, string $jobId, string $status, string $source, array $job): ?SyncJobFinished
    {
        $class = match ($status) {
            'completed' => SyncJobCompleted::class,
            'dead_lettered' => SyncJobDeadLettered::class,
            'manually_resolved' => SyncJobResolved::class,
            'failed', 'cancelled' => SyncJobFailed::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }
        $string = static fn(string $key): ?string => isset($job[$key]) && is_string($job[$key]) ? $job[$key] : null;

        return new $class($tenantId, $jobId, $status, $string('operation_key'), $string('external_object_id'), $string('last_error_code'), $source, $job);
    }
}
