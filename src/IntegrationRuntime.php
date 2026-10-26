<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Connections;
use Ezee\IntegrationRuntime\Jobs;
use Ezee\IntegrationRuntime\Laravel\Jobs\WaitForSyncJob;
use Ezee\IntegrationRuntime\OperationReceipt;
use Ezee\IntegrationRuntime\QuickBooks;
use Ezee\IntegrationRuntime\TenantClient;

/**
 * Entry point behind the `IntegrationRuntime` facade.
 *
 *     $receipt = IntegrationRuntime::quickbooks()->createInvoice(...);
 *     IntegrationRuntime::track($receipt, $tenantId);   // events when it finishes
 */
final class IntegrationRuntime
{
    public function __construct(
        private readonly Client $client,
        private readonly QuickBooks $quickBooks,
        private readonly Jobs $jobs,
        private readonly Connections $connections,
        private readonly TenantClient $tenants,
    ) {}

    public function client(): Client
    {
        return $this->client;
    }

    public function quickbooks(): QuickBooks
    {
        return $this->quickBooks;
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function connections(): Connections
    {
        return $this->connections;
    }

    public function tenants(): TenantClient
    {
        return $this->tenants;
    }

    /**
     * Queue a poller that fires the same SyncJob* events as webhooks when the
     * job reaches a terminal state. Safe alongside webhooks: listeners should
     * be idempotent on the job ID either way.
     */
    public function track(OperationReceipt $receipt, string $tenantId): void
    {
        WaitForSyncJob::dispatch($receipt->pollingUrl, $tenantId, $receipt->jobId);
    }
}
