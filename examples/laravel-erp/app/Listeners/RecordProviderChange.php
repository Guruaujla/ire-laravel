<?php

namespace App\Listeners;

use App\Models\AccountingLink;
use App\Models\AccountingSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Carbon\CarbonImmutable;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Laravel\Events\AccountingDocumentStatusChanged;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;
use Illuminate\Support\Facades\DB;

/**
 * A document the runtime wrote was paid, voided or changed in the provider.
 * Needs the webhook endpoint subscribed to accounting.document.status_changed.
 *
 * The event is a signal, not the state to store: the document is re-read
 * with accounting()->refresh() and only that read is stored (a verified
 * deletion, which cannot be read back, is the one exception). RecordAccountingResult
 * does the same after each completed write, so an event that arrives before
 * the write is recorded loses nothing.
 */
class RecordProviderChange implements ShouldQueue
{
    /** Retried by the queue: the webhook was already answered, so a lost read is not redelivered. */
    public int $tries = 8;

    /** @return list<int> seconds */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    /** Document types accounting()->refresh() reads, by the operations that write them. */
    public const OPERATIONS = [
        'invoice' => ['accounting.invoice.create', 'accounting.invoice.update', 'accounting.invoice.void'],
        'payment' => ['accounting.payment.create'],
    ];

    public function handle(AccountingDocumentStatusChanged $event): void
    {
        $document = $event->document;
        $type = $document['document_type'] ?? null;
        // A credit_application event also changes its invoice, which gets its own event.
        if (! isset(self::OPERATIONS[$type]) || ! is_string($document['erp_id'] ?? null) || $event->connectionId === null) {
            return;
        }
        // Only workspaces that wrote or track this document.
        $linked = AccountingLink::where(['tenant_id' => $event->tenantId, 'connection_id' => $event->connectionId])->pluck('workspace_id');
        $workspaceIds = AccountingSync::whereIn('workspace_id', $linked)
            ->whereIn('operation', self::OPERATIONS[$type])
            ->where('erp_id', $document['erp_id'])
            ->where('request->connection_id', $event->connectionId)
            ->pluck('workspace_id')
            ->merge(DB::table('accounting_documents')->whereIn('workspace_id', $linked)
                ->where(['connection_id' => $event->connectionId, 'document_type' => $type, 'erp_id' => $document['erp_id']])->pluck('workspace_id'))
            ->unique()->values()->all();
        if ($workspaceIds !== []) {
            // A deleted document cannot be read back; the event's deletion is
            // verified by the runtime and final, so it is stored as is.
            $deleted = ($document['status'] ?? null) === 'deleted' ? $document : null;
            self::refresh($event->tenantId, $event->connectionId, $type, $document['erp_id'], $workspaceIds, $deleted);
        }
    }

    /** The refreshable document type an outbox operation writes, or null. */
    public static function documentTypeOf(string $operation): ?string
    {
        foreach (self::OPERATIONS as $type => $operations) {
            if (in_array($operation, $operations, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Read the document once from the provider and store that read for each
     * workspace. The read and the write happen while the document's rows are
     * locked (SELECT ... FOR UPDATE on MySQL/PostgreSQL), so reads of one
     * document are sequential across workers. As a backstop where row locks
     * do not exist (SQLite), a read never replaces a strictly newer
     * provider_updated_at. A deleted document stays deleted. A transient
     * failure throws and rolls back, so the queued listener is retried; a
     * refusal retrying cannot fix (unlinked, not found) is skipped.
     *
     * @param list<string> $workspaceIds
     * @param array<string, mixed>|null $deleted the event's verified deletion, stored instead of a read
     */
    public static function refresh(string $tenantId, string $connectionId, string $documentType, string $erpId, array $workspaceIds, ?array $deleted = null): void
    {
        $document = ['connection_id' => $connectionId, 'document_type' => $documentType, 'erp_id' => $erpId];
        DB::transaction(function () use ($document, $workspaceIds, $tenantId, $connectionId, $documentType, $erpId, $deleted) {
            // The rows to lock; 'unknown' until the first read is stored (rolled back on failure).
            foreach ($workspaceIds as $workspaceId) {
                DB::table('accounting_documents')->insertOrIgnore($document + ['workspace_id' => $workspaceId, 'status' => 'unknown', 'created_at' => now(), 'updated_at' => now()]);
            }
            $rows = DB::table('accounting_documents')->where($document)->whereIn('workspace_id', $workspaceIds)
                ->orderBy('id')->lockForUpdate()->get()
                ->reject(fn ($row) => $row->status === 'deleted');
            if ($rows->isEmpty()) {
                return;
            }
            $doc = $deleted ?? self::read($tenantId, $connectionId, $documentType, $erpId);
            if ($doc === null) {
                DB::table('accounting_documents')->whereIn('id', $rows->where('status', 'unknown')->pluck('id'))->delete(); // nothing to track

                return;
            }
            $at = isset($doc['provider_updated_at']) ? CarbonImmutable::parse($doc['provider_updated_at'])->utc()->format('Y-m-d H:i:s.u') : null;
            foreach ($rows as $row) {
                $update = DB::table('accounting_documents')->where('id', $row->id);
                if ($deleted === null) {
                    $update->where(fn ($q) => $q->whereNull('provider_updated_at')->orWhere('provider_updated_at', '<=', $at ?? '0000'));
                }
                $update->update([
                    'provider_id' => is_string($doc['provider_id'] ?? null) ? $doc['provider_id'] : $row->provider_id,
                    'revision' => $doc['revision'] ?? null,
                    'status' => $doc['status'],
                    'balance' => $doc['balance'] ?? null,
                    'provider_updated_at' => $at ?? $row->provider_updated_at,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** @return array<string, mixed>|null null when retrying cannot help */
    private static function read(string $tenantId, string $connectionId, string $documentType, string $erpId): ?array
    {
        try {
            return IntegrationRuntime::accounting()->refresh($tenantId, $connectionId, $documentType, $erpId);
        } catch (RuntimeProblemException $e) {
            if (in_array($e->decision(), [RuntimeProblemException::FIX_SETUP, RuntimeProblemException::FIX_REQUEST], true)) {
                return null;
            }
            throw $e;
        }
    }
}
