<?php

declare(strict_types=1);

namespace Tests\Example\IndependentDelta;

use App\Listeners\RecordAccountingResult;
use App\Listeners\RecordProviderChange;
use App\Models\AccountingDocument;
use App\Models\AccountingLink;
use App\Models\AccountingSync;
use App\Services\AccountingSyncService;
use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Laravel\Events\AccountingDocumentStatusChanged;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobCompleted;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobDeadLettered;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobFailed;
use Ezee\IntegrationRuntime\Laravel\Tests\TestCase;
use Ezee\IntegrationRuntime\RetryPolicy;
use Ezee\IntegrationRuntime\WebhookEvent;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Tests\Example\Independent\RoutedTransport;

// Clean-room delta tests written from the spec packet only (README, migration,
// accounting-operations.md webhook + Errors, SDK events/exception). Reuses the
// RoutedTransport harness declared in IndependentExampleTest.php (which also
// loads the example's app files).
require_once __DIR__ . '/IndependentExampleTest.php';

final class IndependentExampleDeltaTest extends TestCase
{
    private RoutedTransport $rt;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->rt = new RoutedTransport();
        $factory = new HttpFactory();
        $this->app->instance(Client::class, new Client($this->rt, $factory, $factory, 'https://runtime.example.invalid', 'app_erp', 'key_test', self::SECRET, RetryPolicy::disabled()));
    }

    // ---------------------------------------------------------------- fixtures

    private function migrate(array $links = [['w1', 't1', 'c1']]): void
    {
        (require __DIR__ . '/../database/migrations/2026_10_29_000000_create_accounting_tables.php')->up();
        foreach ($links as [$w, $t, $c]) {
            AccountingLink::create(['workspace_id' => $w, 'tenant_id' => $t, 'connection_id' => $c]);
        }
    }

    private function reconnect(string $ws, string $conn): void
    {
        AccountingLink::where('workspace_id', $ws)->update(['connection_id' => $conn]);
    }

    private static function jsonResp(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function accepted(string $jobId): Response
    {
        return self::jsonResp(202, ['job_id' => $jobId, 'polling_url' => '/v1/accounting/operations/' . $jobId, 'status' => 'queued']);
    }

    private static function problem(int $status, string $code): Response
    {
        return new Response($status, ['content-type' => 'application/problem+json'], json_encode(['type' => 'about:blank', 'title' => 'x', 'status' => $status, 'code' => $code, 'request_id' => 'req_1'], JSON_THROW_ON_ERROR));
    }

    private static function docBody(string $type, string $erp, string $rev, ?string $at, string $status = 'open', ?string $balance = '10.00', string $providerId = '42'): array
    {
        $b = ['provider_key' => 'quickbooks', 'document_type' => $type, 'erp_id' => $erp, 'provider_id' => $providerId, 'revision' => $rev, 'provider_account_id' => '1', 'status' => $status, 'currency' => 'CAD', 'total_amount' => '10.00', 'balance' => $balance];
        if ($at !== null) {
            $b['provider_updated_at'] = $at;
            $b['observed_at'] = $at;
        }

        return $b;
    }

    private static function read(string $type, string $erp, string $rev, string $at, string $status = 'open', string $balance = '10.00', string $providerId = '42'): Response
    {
        return self::jsonResp(200, self::docBody($type, $erp, $rev, $at, $status, $balance, $providerId));
    }

    private function service(): AccountingSyncService
    {
        return $this->app->make(AccountingSyncService::class);
    }

    private function tryPush(string $ws, string $op, string $erp, string $eventId, array $payload = ['invoice' => ['issue_date' => '2026-10-01', 'currency' => 'CAD']]): array
    {
        try {
            return [$this->service()->push($ws, $op, $erp, $eventId, $payload), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }

    private function pushAccepted(string $ws, string $op, string $erp, string $eventId, string $jobId, array $payload = ['invoice' => ['issue_date' => '2026-10-01', 'currency' => 'CAD']]): AccountingSync
    {
        $this->rt->submits[] = self::accepted($jobId);
        $sync = $this->service()->push($ws, $op, $erp, $eventId, $payload);
        $sync->refresh();
        if ($sync->job_id === null) {
            $this->service()->submit($sync);
            $sync->refresh();
        }
        $this->assertSame($jobId, $sync->job_id, 'receipt job_id is stored');

        return $sync;
    }

    private static function invoiceResult(string $erp = 'INV-1', string $rev = '0'): array
    {
        return ['provider_key' => 'quickbooks', 'document_type' => 'invoice', 'erp_id' => $erp, 'provider_id' => '42', 'revision' => $rev, 'provider_account_id' => '1', 'currency' => 'CAD', 'net_amount' => '10.00', 'total_tax' => '0.00', 'total_amount' => '10.00', 'lines' => [['erp_line_id' => 'L1', 'provider_line_id' => '1']], 'provider_verified' => true];
    }

    private function completeJob(string $jobId, array $result, string $tenant = 't1'): void
    {
        $this->rt->statuses[$jobId] = ['job_id' => $jobId, 'status' => 'completed', 'result' => $result];
        $this->onResult(new SyncJobCompleted($tenant, $jobId, 'completed', 'quickbooks.invoice.create', $result['provider_id'] ?? null, null, 'webhook', ['job_id' => $jobId, 'status' => 'completed']));
    }

    private function failJob(string $jobId, string $status, string $code): void
    {
        $this->rt->statuses[$jobId] = ['job_id' => $jobId, 'status' => $status, 'last_error_code' => $code, 'error' => ['code' => $code]];
        $job = ['job_id' => $jobId, 'status' => $status, 'last_error_code' => $code];
        $event = $status === 'dead_lettered'
            ? new SyncJobDeadLettered('t1', $jobId, $status, 'quickbooks.invoice.create', null, $code, 'webhook', $job)
            : new SyncJobFailed('t1', $jobId, $status, 'quickbooks.invoice.create', null, $code, 'poll', $job);
        $this->onResult($event);
    }

    private function docEvent(array $document, ?string $connectionId = 'c1', string $tenant = 't1', ?int $seq = 1): AccountingDocumentStatusChanged
    {
        $we = new WebhookEvent('del_' . bin2hex(random_bytes(4)), 'evt_' . bin2hex(random_bytes(4)), WebhookEvent::ACCOUNTING_DOCUMENT_STATUS_CHANGED, '2026-10-29T12:00:00Z', 'app_erp', $tenant, [], ['data' => ['document' => $document, 'connection_id' => $connectionId, 'sequence' => $seq]], $document);

        return new AccountingDocumentStatusChanged($tenant, $connectionId, $seq, $document, $we);
    }

    private function onEvent(AccountingDocumentStatusChanged $e): void
    {
        $this->app->make(RecordProviderChange::class)->handle($e);
    }

    private function onResult(object $e): void
    {
        $this->app->make(RecordAccountingResult::class)->handle($e);
    }

    private function doc(string $ws, string $conn = 'c1', string $type = 'invoice', string $erp = 'INV-1'): ?AccountingDocument
    {
        return AccountingDocument::where(['workspace_id' => $ws, 'connection_id' => $conn, 'document_type' => $type, 'erp_id' => $erp])->first();
    }

    /** Raw DB row (stable comparison of every column). */
    private function rawDoc(string $ws, string $conn = 'c1', string $type = 'invoice', string $erp = 'INV-1'): ?array
    {
        $r = DB::table('accounting_documents')->where(['workspace_id' => $ws, 'connection_id' => $conn, 'document_type' => $type, 'erp_id' => $erp])->first();

        return $r === null ? null : (array) $r;
    }

    private function seedRead(array $ws, string $rev, string $at, string $conn = 'c1', string $status = 'open', string $balance = '10.00', string $providerId = '42', string $erp = 'INV-1'): void
    {
        $this->rt->docs["$conn/invoice/$erp"][] = self::read('invoice', $erp, $rev, $at, $status, $balance, $providerId);
        RecordProviderChange::refresh('t1', $conn, 'invoice', $erp, $ws);
    }

    private function tryRun(callable $fn): ?\Throwable
    {
        try {
            $fn();

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    // ===================================================== 1. deleted

    public function test_deleted_event_is_stored_as_is_without_a_provider_read(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $reads = count($this->rt->docReads);

        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'deleted', null)));

        $this->assertSame($reads, count($this->rt->docReads), 'a deleted document cannot be read back: no provider read');
        $d = $this->doc('w1');
        $this->assertNotNull($d);
        $this->assertSame('deleted', $d->status);
        $this->assertSame('2', $d->revision, 'stored as is from the event');
        $this->assertSame(1, AccountingDocument::count());
    }

    public function test_deleted_event_in_bare_neutral_shape_without_timestamps_is_stored(): void
    {
        // Event document shape per the SDK event docblock: document_type, erp_id,
        // provider_id, revision, status, currency, total_amount, balance.
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $reads = count($this->rt->docReads);
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', null, 'deleted', null)));
        $this->assertSame($reads, count($this->rt->docReads));
        $this->assertSame('deleted', $this->doc('w1')->status);
    }

    public function test_deleted_for_written_but_untracked_document_is_stored(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '0', '2026-10-29T12:00:00Z', 'deleted', null)));
        $this->assertSame([], $this->rt->docReads);
        $this->assertSame('deleted', $this->doc('w1')?->status);
    }

    public function test_deleted_is_final_against_later_newer_refresh(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'deleted', null)));

        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '9', '2026-10-30T12:00:00Z', 'open', '10.00');
        $this->tryRun(fn() => RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']));

        $d = $this->doc('w1');
        $this->assertSame('deleted', $d->status, 'deletion is verified and final');
        $this->assertSame('2', $d->revision);
        $this->assertSame(1, AccountingDocument::count());
    }

    public function test_deleted_is_final_against_later_non_deleted_event(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'deleted', null)));

        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '9', '2026-10-30T12:00:00Z', 'open', '10.00');
        $this->tryRun(fn() => $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '9', '2026-10-30T12:00:00Z', 'open'), 'c1', 't1', 5)));

        $this->assertSame('deleted', $this->doc('w1')->status);
        $this->assertSame('2', $this->doc('w1')->revision);
    }

    public function test_deleted_is_final_against_completion_read(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '0', '2026-10-29T12:00:00Z', 'deleted', null)));
        $this->assertSame('deleted', $this->doc('w1')?->status, 'precondition: deletion stored');

        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '3', '2026-10-30T12:00:00Z', 'open', '10.00');
        $this->tryRun(fn() => $this->completeJob('j1', self::invoiceResult('INV-1', '0')));

        $this->assertSame('deleted', $this->doc('w1')->status, 'a completion read never resurrects a deleted document');
        $this->assertSame('0', $this->doc('w1')->revision);
        $this->assertSame('42', AccountingSync::where('job_id', 'j1')->first()->provider_id, 'the result itself is still recorded');
    }

    public function test_deleted_event_for_never_written_or_tracked_document_is_ignored(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z'); // INV-1 tracked, INV-X not
        $reads = count($this->rt->docReads);
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-X', '1', '2026-10-29T12:00:00Z', 'deleted', null)));
        $this->assertSame($reads, count($this->rt->docReads));
        $this->assertSame(0, AccountingDocument::where('erp_id', 'INV-X')->count());
        $this->assertSame('open', $this->doc('w1')->status);
    }

    public function test_deleted_event_for_document_tracked_only_by_another_workspace_does_not_create_row_for_this_one(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't1', 'c1']]);
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'deleted', null)));
        $this->assertSame('deleted', $this->doc('w1')->status);
        $this->assertNull($this->doc('w2'), 'w2 never wrote or tracked INV-1');
    }

    // ===================================================== 2. per connection

    public function test_reconnect_keeps_separate_rows_for_same_erp_id(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1', 'paid', '0.00', '42');
        $this->reconnect('w1', 'c2');
        $this->seedRead(['w1'], '5', '2026-10-29T09:00:00Z', 'c2', 'open', '10.00', '88');

        $this->assertSame(2, AccountingDocument::where(['workspace_id' => 'w1', 'erp_id' => 'INV-1'])->count());
        $this->assertSame('paid', $this->doc('w1', 'c1')->status, 'c1 row untouched by an older c2 read');
        $this->assertSame('42', $this->doc('w1', 'c1')->provider_id);
        $this->assertSame('88', $this->doc('w1', 'c2')->provider_id, 'c2 starts fresh: an older read is still stored for it');
        $this->assertSame('5', $this->doc('w1', 'c2')->revision);
    }

    public function test_event_for_new_connection_never_touches_old_connection_row(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1');
        $c1Before = $this->rawDoc('w1', 'c1');
        $this->reconnect('w1', 'c2');
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c2', 'open', '10.00', '88');

        $this->rt->docs['c2/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'paid', '0.00', '88');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'), 'c2'));

        $this->assertSame('paid', $this->doc('w1', 'c2')->status);
        $this->assertSame($c1Before, $this->rawDoc('w1', 'c1'));
        $this->assertSame(0, $this->rt->readsOf('c1/invoice/INV-1') - 1, 'no read on the old connection');
    }

    public function test_event_for_old_connection_after_reconnect_never_touches_new_connection_row(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1');
        $this->reconnect('w1', 'c2');
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c2', 'open', '10.00', '88');
        $c2Before = $this->rawDoc('w1', 'c2');
        $c2Reads = $this->rt->readsOf('c2/invoice/INV-1');

        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '7', '2026-10-29T13:00:00Z', 'voided', '0.00');
        $this->tryRun(fn() => $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '7', '2026-10-29T13:00:00Z', 'voided', '0.00'), 'c1')));

        $this->assertSame($c2Before, $this->rawDoc('w1', 'c2'));
        $this->assertSame($c2Reads, $this->rt->readsOf('c2/invoice/INV-1'));
        $c1 = $this->doc('w1', 'c1');
        $this->assertNotNull($c1);
        $this->assertSame('c1', $c1->connection_id);
    }

    public function test_event_for_old_connection_after_reconnect_creates_nothing_for_new_connection(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1');
        $this->reconnect('w1', 'c2');

        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'paid', '0.00');
        $this->tryRun(fn() => $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'), 'c1')));

        $this->assertSame(0, AccountingDocument::where('connection_id', 'c2')->count());
        $this->assertSame(0, $this->rt->readsOf('c2/invoice/INV-1'));
    }

    public function test_deleted_event_for_old_connection_after_reconnect_leaves_new_connection_row(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1');
        $this->reconnect('w1', 'c2');
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c2', 'open', '10.00', '88');
        $c2Before = $this->rawDoc('w1', 'c2');

        $this->tryRun(fn() => $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'deleted', null), 'c1')));

        $this->assertSame($c2Before, $this->rawDoc('w1', 'c2'), 'a c1 deletion is not a c2 deletion');
    }

    public function test_deleted_event_for_new_connection_leaves_old_connection_row(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1');
        $c1Before = $this->rawDoc('w1', 'c1');
        $this->reconnect('w1', 'c2');
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c2', 'open', '10.00', '88');

        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'deleted', null, '88'), 'c2'));

        $this->assertSame('deleted', $this->doc('w1', 'c2')->status);
        $this->assertSame($c1Before, $this->rawDoc('w1', 'c1'));
    }

    public function test_completion_of_job_submitted_on_old_connection_never_touches_new_connection(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-9', 'create-9', 'j1');
        $this->reconnect('w1', 'c2');

        $this->rt->docs['c1/invoice/INV-9'][] = self::read('invoice', 'INV-9', '0', '2026-10-29T12:00:00Z');
        $this->tryRun(fn() => $this->completeJob('j1', self::invoiceResult('INV-9', '0')));

        $this->assertSame(0, $this->rt->readsOf('c2/invoice/INV-9'), 'no read of the job\'s document on the new connection');
        $this->assertSame(0, AccountingDocument::where(['connection_id' => 'c2'])->count());
    }

    // ===================================================== 3. read failures

    public function test_non_retryable_refusal_on_untracked_static_refresh_leaves_no_row(): void
    {
        $this->migrate();
        foreach ([[409, 'accounting.document_unlinked'], [404, 'accounting.connection_not_found'], [400, 'accounting.invalid_input']] as [$s, $c]) {
            $this->rt->docs['c1/invoice/INV-1'][] = self::problem($s, $c);
            $this->tryRun(fn() => RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']));
            $this->assertSame(0, AccountingDocument::count(), "no placeholder row after $c");
        }
    }

    public function test_non_retryable_refusal_on_written_document_event_leaves_no_row(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(409, 'accounting.document_unlinked');
        $this->tryRun(fn() => $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z'))));
        $this->assertSame(0, AccountingDocument::count());
        $this->assertSame(0, DB::table('accounting_documents')->where('status', 'unknown')->count());
    }

    public function test_non_retryable_refusal_leaves_existing_row_unchanged(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1', 'paid', '0.00');
        $before = $this->rawDoc('w1');
        foreach ([[409, 'accounting.document_unlinked'], [404, 'accounting.connection_not_found']] as [$s, $c]) {
            $this->rt->docs['c1/invoice/INV-1'][] = self::problem($s, $c);
            $this->tryRun(fn() => RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']));
            $this->assertSame($before, $this->rawDoc('w1'), "row unchanged after $c");
        }
        $this->assertSame(1, AccountingDocument::count());
    }

    public function test_transient_503_on_event_propagates_and_keeps_stored_read(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'c1', 'paid', '0.00');
        $before = $this->rawDoc('w1');
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(503, 'accounting.provider_unavailable');

        $e = $this->tryRun(fn() => $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'))));

        $this->assertInstanceOf(RuntimeProblemException::class, $e, 'a transient failure propagates so the queue retries');
        $this->assertSame(503, $e->httpStatus);
        $this->assertSame($before, $this->rawDoc('w1'), 'no partial write');
    }

    public function test_transient_503_on_static_refresh_propagates_and_keeps_stored_read(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $before = $this->rawDoc('w1');
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(503, 'accounting.provider_unavailable');
        $e = $this->tryRun(fn() => RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']));
        $this->assertInstanceOf(RuntimeProblemException::class, $e);
        $this->assertSame($before, $this->rawDoc('w1'));
    }

    public function test_transient_503_on_untracked_refresh_propagates_and_leaves_no_row(): void
    {
        $this->migrate();
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(503, 'accounting.provider_unavailable');
        $e = $this->tryRun(fn() => RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']));
        $this->assertInstanceOf(RuntimeProblemException::class, $e);
        $this->assertSame(0, AccountingDocument::count());
    }

    // ===================================================== 4. create guard

    public function test_create_allowed_after_submit_refused_with_review_and_resubmit(): void
    {
        $this->migrate();
        $this->rt->submits[] = self::problem(409, 'accounting.tax_mismatch');
        $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $first = AccountingSync::first();
        $this->assertNull($first->job_id);
        $this->assertSame('review_and_resubmit', $first->status);

        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-2', 'j2');
        $this->assertSame(2, AccountingSync::count());
    }

    public function test_create_allowed_after_job_failed_with_document_exists(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->failJob('j1', 'failed', 'accounting.document_exists');
        $this->assertSame('accounting.document_exists', AccountingSync::where('job_id', 'j1')->value('error_code'));

        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-2', 'j2');
        $this->assertCount(2, $this->rt->posts);
    }

    public function test_create_allowed_after_job_dead_lettered_with_document_exists(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->failJob('j1', 'dead_lettered', 'accounting.document_exists');

        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-2', 'j2');
        $this->assertCount(2, $this->rt->posts);
    }

    public function test_create_refused_after_job_failed_with_other_error(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->failJob('j1', 'failed', 'accounting.tax_mismatch');

        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err, 'a failed job with another error may have created the invoice');
        $this->assertCount(1, $this->rt->posts);
    }

    public function test_create_refused_after_dead_lettered_with_other_error(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->failJob('j1', 'dead_lettered', 'accounting.tax_mismatch');

        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err);
        $this->assertCount(1, $this->rt->posts);
    }

    public function test_create_refused_after_pending_row_with_lost_receipt(): void
    {
        $this->migrate();
        $this->rt->submits[] = static function (RequestInterface $r) {
            throw new class('connection reset') extends \RuntimeException implements ClientExceptionInterface {};
        };
        $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $first = AccountingSync::first();
        $this->assertNotNull($first, 'request stored before the first call');
        $this->assertNull($first->job_id, 'precondition: no receipt');
        $this->assertSame('pending', $first->status, 'precondition: outcome unknown');

        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err, 'the lost submit may have reached the runtime');
        $this->assertCount(1, $this->rt->posts);
        $this->assertSame(1, AccountingSync::count());
    }

    public function test_create_refused_after_completed_create(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '0', '2026-10-29T12:00:00Z');
        $this->completeJob('j1', self::invoiceResult());
        $this->assertSame('42', AccountingSync::where('job_id', 'j1')->value('provider_id'));

        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err);
        $this->assertCount(1, $this->rt->posts);
    }

    // ===================================================== 5. queued listeners

    public function test_listeners_are_queued_with_retry_policy(): void
    {
        foreach ([RecordProviderChange::class, RecordAccountingResult::class] as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "$class implements ShouldQueue");
            $l = $this->app->make($class);
            $r = new \ReflectionClass($class);
            $tries = $r->hasProperty('tries') ? $r->getProperty('tries')->getValue($l) : ($r->hasMethod('tries') ? $l->tries() : null);
            $hasUntil = $r->hasMethod('retryUntil');
            $this->assertTrue((is_int($tries) && $tries > 1) || $hasUntil, "$class declares retries (tries > 1 or retryUntil)");
            $backoff = $r->hasProperty('backoff') ? $r->getProperty('backoff')->getValue($l) : ($r->hasMethod('backoff') ? $l->backoff() : null);
            $this->assertNotNull($backoff, "$class declares a backoff");
            $this->assertNotSame(0, $backoff);
        }
    }

    // ===================================================== 6. ordering backstop

    public function test_older_read_never_replaces_strictly_newer_row(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '3', '2026-10-29T12:00:00Z', 'c1', 'paid', '0.00', '42');
        $before = $this->rawDoc('w1');
        $this->seedRead(['w1'], '2', '2026-10-29T11:59:59.999999Z', 'c1', 'open', '10.00', '42');
        $this->assertSame($before, $this->rawDoc('w1'));
    }

    public function test_older_event_read_never_replaces_strictly_newer_row(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '3', '2026-10-29T12:00:00Z', 'c1', 'paid', '0.00');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z', 'open', '10.00');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z')));
        $d = $this->doc('w1');
        $this->assertSame('3', $d->revision);
        $this->assertSame('paid', $d->status);
        $this->assertSame('0.00', $d->balance);
    }

    public function test_equal_timestamp_read_applied_later_wins(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '3', '2026-10-29T12:00:00Z', 'c1', 'open', '10.00');
        $this->seedRead(['w1'], '3', '2026-10-29T12:00:00Z', 'c1', 'paid', '0.00');
        $d = $this->doc('w1');
        $this->assertSame('paid', $d->status, 'a tie is not strictly newer: the later read is stored');
        $this->assertSame('0.00', $d->balance);
    }

    public function test_equal_timestamp_event_read_applied_later_wins(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '3', '2026-10-29T12:00:00Z', 'c1', 'open', '10.00');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '4', '2026-10-29T12:00:00Z', 'voided', '0.00');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '4', '2026-10-29T12:00:00Z')));
        $this->assertSame('4', $this->doc('w1')->revision);
        $this->assertSame('voided', $this->doc('w1')->status);
    }
}
