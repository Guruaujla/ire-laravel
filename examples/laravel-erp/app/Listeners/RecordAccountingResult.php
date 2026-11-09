<?php

namespace App\Listeners;

use App\Models\AccountingSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobCompleted;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobDeadLettered;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobFailed;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobResolved;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

/**
 * Idempotent: webhook and poller may both report the same job.
 * Laravel dispatches by exact class (and interfaces), not parent class, so the
 * concrete events are listed; a `SyncJobFinished` type hint would never fire.
 * The event's operationKey/externalObjectId are the provider's native values;
 * this reads the neutral status instead, so it stays provider-neutral.
 */
class RecordAccountingResult implements ShouldQueue
{
    public int $tries = 8;

    /** @return list<int> seconds */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function handle(SyncJobCompleted|SyncJobDeadLettered|SyncJobFailed|SyncJobResolved $event): void
    {
        $sync = AccountingSync::where('job_id', $event->jobId)->first();
        if ($sync === null) {
            return;
        }
        // A runtime read (no provider call); repeated on redelivery.
        $status = IntegrationRuntime::accounting()->status($event->tenantId, $event->jobId);
        $result = is_array($status['result'] ?? null) ? $status['result'] : [];
        $values = array_filter([
            'provider_id' => $result['provider_id'] ?? null,
            'lines' => isset($result['lines']) ? json_encode($result['lines']) : null, // erp_line_id -> provider_line_id
        ], fn ($v) => $v !== null);
        // Events can race; the final-state guard is part of the UPDATE itself,
        // so a delayed delivery can never undo a recorded final outcome.
        AccountingSync::whereKey($sync->id)->whereNotIn('status', ['completed', 'manually_resolved'])->update($values + [
            'status' => $status['status'] ?? $event->status,
            'error_code' => $status['error']['code'] ?? null,
        ]);
        $sync->refresh();
        if ($sync->status !== 'completed' || $sync->documents_read_at !== null || ! is_string($sync->request['connection_id'] ?? null)) {
            return;
        }

        // Document state (revision, status, balance) comes from a fresh read,
        // never from this result: a payment or provider-side change may already
        // be newer. The written document, and the invoice a payment or credit
        // settled, are re-read once: documents_read_at is set after the reads,
        // so a redelivery repeats them only if they did not finish.
        $reads = [];
        if (($type = RecordProviderChange::documentTypeOf($sync->operation)) !== null) {
            $reads[$type] = $sync->erp_id;
        }
        if (is_string($result['invoice']['erp_id'] ?? null)) {
            $reads['invoice'] = $result['invoice']['erp_id'];
        }
        foreach ($reads as $documentType => $erpId) {
            RecordProviderChange::refresh($event->tenantId, $sync->request['connection_id'], $documentType, $erpId, [$sync->workspace_id]);
        }
        $sync->update(['documents_read_at' => now()]);
    }
}
