<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/**
 * Base for the terminal sync-job events. Fired from a verified webhook
 * (`source === 'webhook'`) or from {@see \Ezee\IntegrationRuntime\Laravel\Jobs\WaitForSyncJob}
 * (`source === 'poll'`). The same job can arrive from both, and a webhook can
 * be delivered more than once: make listeners idempotent on {@see $jobId}.
 */
abstract class SyncJobFinished
{
    /**
     * @param array<string, mixed> $job the `sync_job` snapshot (webhook) or job read (poll)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
        public readonly string $status,
        public readonly ?string $operationKey,
        public readonly ?string $externalObjectId,
        public readonly ?string $lastErrorCode,
        public readonly string $source,
        public readonly array $job,
    ) {}
}
