<?php

namespace App\Http\Controllers;

use App\Models\AccountingDocument;
use App\Models\AccountingLink;
use App\Models\AccountingSync;
use App\Services\AccountingSyncService;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Replace workspaceId() with your own auth (current team/company). */
class AccountingController
{
    public function connect(Request $request)
    {
        $workspaceId = $this->workspaceId($request);
        $tenant = IntegrationRuntime::tenants()->registerOrResolveTenant($workspaceId, $request->user()->currentTeam->name ?? $workspaceId);
        $state = Str::random(40);
        AccountingLink::updateOrCreate(['workspace_id' => $workspaceId], ['tenant_id' => $tenant['tenant_id'], 'connect_state' => $state]);

        try {
            $session = IntegrationRuntime::connections()->start($tenant['tenant_id'], route('accounting.return'), $state);
        } catch (RuntimeProblemException $e) {
            if ($e->problemCode !== 'accounting.connection_exists') {
                throw $e;
            }
            // One accounting provider per customer: the admin disconnects the current one first.
            $current = $e->problem['provider_key'] ?? 'another provider';

            return redirect('/')->with('status', "Disconnect {$current} before connecting a different accounting provider.");
        }

        return redirect()->away($session['authorize_url']);
    }

    public function return(Request $request)
    {
        $link = AccountingLink::where('workspace_id', $this->workspaceId($request))->firstOrFail();
        // One-time state check, then confirm with an authoritative read.
        abort_unless($link->connect_state !== null && hash_equals($link->connect_state, (string) $request->query('state')), 403);
        $link->update(['connect_state' => null]);
        abort_unless(in_array($request->query('status'), ['connected', 'already_connected'], true), 422, 'The accounting provider was not connected.');

        $connectionId = (string) $request->query('connection_id');
        $connection = IntegrationRuntime::connections()->get($link->tenant_id, $connectionId);
        abort_unless(($connection['status'] ?? null) === 'connected', 422, 'The accounting connection is not active.');
        $link->update(['connection_id' => $connectionId]);

        return redirect('/')->with('status', 'Accounting connected');
    }

    /**
     * Onboarding: propose provider matches for ERP customers, items or tax codes. Binds nothing.
     * Up to 1,000 records against a ready snapshot, 100 against the provider.
     */
    public function match(Request $request, string $entity)
    {
        $link = $this->link($request);
        $input = $request->validate([
            'records' => 'required|array|max:1000',
            'source' => 'nullable|in:auto,snapshot,live', // 'snapshot' once a snapshot is ready
        ]);

        return IntegrationRuntime::accounting()->matchRecords($link->tenant_id, $link->connection_id, $entity, $input['records'], $input['source'] ?? null);
    }

    /** Onboarding: bind the pairs a person confirmed (25 per call). */
    public function bind(Request $request, string $entity)
    {
        $link = $this->link($request);
        $bindings = $request->validate(['bindings' => 'required|array|max:25'])['bindings'];

        return IntegrationRuntime::accounting()->bindRecords($link->tenant_id, $link->connection_id, $entity, $bindings);
    }

    /** Large lists: read every provider customer or item once (refresh=1 to read again). */
    public function startSnapshot(Request $request, string $entity)
    {
        $link = $this->link($request);

        return IntegrationRuntime::accounting()->startSnapshot($link->tenant_id, $link->connection_id, $entity, $request->boolean('refresh'));
    }

    /** Large lists: is the snapshot ready yet? */
    public function latestSnapshot(Request $request, string $entity)
    {
        $link = $this->link($request);

        return IntegrationRuntime::accounting()->latestSnapshot($link->tenant_id, $link->connection_id, $entity);
    }

    /** Large lists: search the snapshot for the "Change" picker (exact email or 3+ character name prefix). */
    public function search(Request $request, string $entity)
    {
        $link = $this->link($request);
        $input = $request->validate(['q' => 'required|string|min:3|max:254', 'cursor' => 'nullable|string|max:512']);

        return IntegrationRuntime::accounting()->listRecords($link->tenant_id, $link->connection_id, $entity, $input['cursor'] ?? null, null, null, $input['q']);
    }

    /** Large lists: bind up to 5,000 confirmed pairs in one background job. */
    public function startBindJob(Request $request, string $entity)
    {
        $link = $this->link($request);
        $bindings = $request->validate(['bindings' => 'required|array|max:5000'])['bindings'];

        return IntegrationRuntime::accounting()->startBindJob($link->tenant_id, $link->connection_id, $entity, $bindings);
    }

    /** Large lists: one page (500 rows) of a bind job's results; follow next_cursor. */
    public function bindJobResults(Request $request, string $entity, string $jobId)
    {
        $link = $this->link($request);
        $cursor = $request->validate(['cursor' => 'nullable|string|max:256'])['cursor'] ?? null;

        return IntegrationRuntime::accounting()->bindJobResults($link->tenant_id, $link->connection_id, $entity, $jobId, $cursor);
    }

    /** Example: push one invoice. Build $payload from your invoice (see accounting-operations.md). */
    public function pushInvoice(Request $request, string $invoiceId, AccountingSyncService $sync)
    {
        $input = $request->validate(['payload' => 'required|array', 'event_id' => 'sometimes|string|max:200']);
        // A corrected resubmit after review_and_resubmit sends a new event_id.
        $row = $sync->push($this->workspaceId($request), 'accounting.invoice.create', $invoiceId, $input['event_id'] ?? 'create', $input['payload']);

        return response()->json($row->only(['status', 'job_id', 'provider_id', 'error_code']), 202);
    }

    public function show(Request $request, string $syncId)
    {
        return AccountingSync::where('workspace_id', $this->workspaceId($request))->findOrFail($syncId);
    }

    /** The invoice's last provider read: status, balance, and the revision for the next write. */
    public function document(Request $request, string $invoiceId)
    {
        $link = AccountingLink::where('workspace_id', $this->workspaceId($request))->firstOrFail();

        // The current connection's state; never another company's. Disconnected: the last one known.
        return AccountingDocument::where(['workspace_id' => $link->workspace_id, 'document_type' => 'invoice', 'erp_id' => $invoiceId])
            ->when($link->connection_id !== null, fn ($q) => $q->where('connection_id', $link->connection_id))
            ->where('status', '!=', 'unknown')
            ->latest('updated_at')
            ->firstOrFail();
    }

    private function link(Request $request): AccountingLink
    {
        return AccountingLink::where('workspace_id', $this->workspaceId($request))->whereNotNull('connection_id')->firstOrFail();
    }

    private function workspaceId(Request $request): string
    {
        return (string) ($request->user()->current_team_id ?? $request->user()->id);
    }
}
