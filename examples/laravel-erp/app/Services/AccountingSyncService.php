<?php

namespace App\Services;

use App\Models\AccountingLink;
use App\Models\AccountingSync;
use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;
use Ezee\IntegrationRuntime\OperationReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The whole write path in one place:
 * build + persist the request -> submit -> store job -> track.
 * Provider-neutral: nothing here changes when the tenant's provider changes.
 */
class AccountingSyncService
{
    /**
     * @param string $operation e.g. 'accounting.invoice.create'
     * @param string $erpId     your document ID
     * @param string $eventId   the business event, stable across retries: 'create' for a
     *                          create, your change or revision id for an update, a new id for
     *                          each corrected resubmit. With the operation and erp_id it forms
     *                          the idempotency key.
     * @param array<string, mixed> $payload shape from accounting-operations.md: ERP ids, ERP codes, decimal strings
     */
    public function push(string $workspaceId, string $operation, string $erpId, string $eventId, array $payload): AccountingSync
    {
        $link = AccountingLink::where('workspace_id', $workspaceId)->whereNotNull('connection_id')->firstOrFail();
        if ($operation === 'accounting.invoice.create' && $this->createdThroughQuickBooks($workspaceId, $erpId)) {
            throw new \LogicException("Invoice {$erpId} may exist from quickbooks(); record it with accounting()->link(). If it was never created, delete its quickbooks_syncs row first.");
        }
        // Hashed, so any erp_id and event id fit the 128-character key limit.
        $key = substr($operation, strlen('accounting.')) . ':' . hash('sha256', $erpId . "\n" . $eventId);
        // Same business event in the same workspace => same row, same request,
        // same key. Never rebuilt. Two workspaces can share document IDs, so
        // the outbox identity includes the workspace. The workspace's link row
        // is locked so two pushes cannot both pass the create check below.
        $sync = DB::transaction(function () use ($workspaceId, $operation, $erpId, $key, $payload, $link) {
            AccountingLink::whereKey($link->id)->lockForUpdate()->first();
            // A document is created once: a new event id is a corrected resubmit,
            // allowed only after every earlier create was proven not to write.
            if ($operation === 'accounting.invoice.create') {
                $earlier = AccountingSync::where(['workspace_id' => $workspaceId, 'operation' => $operation, 'erp_id' => $erpId])
                    ->where('idempotency_key', '!=', $key)->get()
                    ->first(fn (AccountingSync $row) => ! self::provenNotWritten($row));
                if ($earlier !== null) {
                    throw new \LogicException("Invoice {$erpId} already has a create (outbox row {$earlier->id}, {$earlier->status}); retry that one instead.");
                }
            }

            return AccountingSync::createOrFirst(['workspace_id' => $workspaceId, 'idempotency_key' => $key], [
                'operation' => $operation,
                'erp_id' => $erpId,
                // The connection id is saved with the request: a retry after a lost
                // receipt must reach the original company, never a replacement one.
                'request' => Accounting::buildRequest($operation, $link->tenant_id, $erpId, $key, $payload, $link->connection_id),
            ]);
        });

        return $this->submit($sync);
    }

    /** Submit (or safely resubmit) the stored request. An exact replay returns the original job. */
    public function submit(AccountingSync $sync): AccountingSync
    {
        if ($sync->job_id !== null) {
            // Already accepted: never resubmit. If the result is still open,
            // (re)queue the poller, so a failed enqueue last time is recovered.
            if ($sync->status === 'queued' && $sync->polling_url !== null) {
                IntegrationRuntime::track(
                    new OperationReceipt($sync->job_id, $sync->polling_url, 'queued', null),
                    $sync->request['tenant_id'],
                );
            }

            return $sync;
        }
        try {
            $receipt = IntegrationRuntime::accounting()->submit($sync->request);
        } catch (RuntimeProblemException $e) {
            // fix_setup: map the code or change the setting, then call submit() again.
            // review_and_resubmit: a person corrects the document; that is a new business event.
            $sync->update(['status' => $e->decision(), 'error_code' => $e->problemCode]);
            throw $e;
        }
        $sync->update(['job_id' => $receipt->jobId, 'polling_url' => $receipt->pollingUrl, 'status' => 'queued', 'error_code' => null]);
        IntegrationRuntime::track($receipt, $sync->request['tenant_id']);

        return $sync;
    }

    /**
     * Job errors raised by a guard that runs before the provider write. Other
     * job failures (a tax mismatch found on read-back, an unknown outcome) can
     * follow a created invoice, so they never allow a second create.
     */
    private const PRE_WRITE_JOB_ERRORS = ['accounting.document_exists'];

    /** Refused at submit (no job was admitted), or a job stopped by a pre-write guard. */
    private static function provenNotWritten(AccountingSync $row): bool
    {
        return ($row->job_id === null && $row->status === RuntimeProblemException::REVIEW_AND_RESUBMIT)
            || (in_array($row->status, ['failed', 'dead_lettered'], true) && in_array($row->error_code, self::PRE_WRITE_JOB_ERRORS, true));
    }

    /** Upgrading from the quickbooks() example: its outbox lists invoices that already exist. */
    private function createdThroughQuickBooks(string $workspaceId, string $erpId): bool
    {
        return Schema::hasTable('quickbooks_syncs') && DB::table('quickbooks_syncs')
            ->where(['workspace_id' => $workspaceId, 'erp_id' => $erpId, 'operation' => 'quickbooks.invoice.create'])
            // Anything that may have reached the provider counts: a retry
            // overwrites the status, so even a refused or failed row may hide
            // a created invoice.
            ->where('status', '!=', 'cancelled')
            ->exists();
    }
}
