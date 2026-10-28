<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel;

use Ezee\IntegrationRuntime\Accounting;
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
 *     $receipt = IntegrationRuntime::accounting()->createInvoice(...);
 *     IntegrationRuntime::accounting()->status($tenantId, $receipt->jobId);
 */
final class IntegrationRuntime
{
    public function __construct(
        private readonly Client $client,
        private readonly QuickBooks $quickBooks,
        private readonly Jobs $jobs,
        private readonly Connections $connections,
        private readonly TenantClient $tenants,
        private readonly Accounting $accounting,
    ) {}

    public function client(): Client
    {
        return $this->client;
    }

    /** Provider-neutral accounting: the ERP integration for every accounting provider. */
    public function accounting(): Accounting
    {
        return $this->accounting;
    }

    /**
     * @deprecated since 1.3.0, use {@see accounting()}. Kept working; removed no
     * earlier than 2.0. See docs/accounting-migration.md.
     */
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
     * be idempotent on the job ID either way. An accounting() receipt is
     * polled through its sync job (same job ID); read its neutral result with
     * accounting()->status() in the listener.
     */
    public function track(OperationReceipt $receipt, string $tenantId): void
    {
        $pollingUrl = str_starts_with($receipt->pollingUrl, '/v1/accounting/operations/')
            ? '/v1/sync-jobs/' . rawurlencode($receipt->jobId) . '?tenant_id=' . rawurlencode($tenantId)
            : $receipt->pollingUrl;
        WaitForSyncJob::dispatch($pollingUrl, $tenantId, $receipt->jobId);
    }
}
