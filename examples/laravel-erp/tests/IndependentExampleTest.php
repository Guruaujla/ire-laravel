<?php

declare(strict_types=1);

namespace Tests\Example\Independent;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Clean-room tests written from the example's spec (README, migration,
// accounting-operations.md, SDK public surface). Same harness as ExampleAppTest.
foreach (['app/Models/AccountingLink.php', 'app/Models/AccountingSync.php', 'app/Models/AccountingDocument.php', 'app/Listeners/RecordProviderChange.php', 'app/Listeners/RecordAccountingResult.php', 'app/Services/AccountingSyncService.php'] as $f) {
    require_once __DIR__ . '/../' . $f;
}

/**
 * Routes runtime calls by path. Document reads and submits are answered from
 * queues whose entries are a Response or a callable(RequestInterface): Response,
 * so a test can run code (a re-entrant event or refresh) while a read is in flight.
 */
final class RoutedTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> "conn/type/erp" per document read, in order */
    public array $docReads = [];

    /** @var list<array<string,mixed>> decoded bodies of POST /v1/accounting/operations */
    public array $posts = [];

    /** @var array<string, list<Response|callable>> */
    public array $docs = [];

    /** @var list<Response|callable> */
    public array $submits = [];

    /** @var array<string, array<string,mixed>> job id => status body */
    public array $statuses = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        if ($method === 'POST' && $path === '/v1/accounting/operations') {
            $this->posts[] = json_decode((string) $request->getBody(), true);
            $next = array_shift($this->submits) ?? throw new \LogicException('Unexpected submit');

            return is_callable($next) ? $next($request) : $next;
        }
        if ($method === 'GET' && preg_match('#^/v1/accounting/operations/([^/]+)$#', $path, $m)) {
            $job = rawurldecode($m[1]);
            $body = $this->statuses[$job] ?? throw new \LogicException('Unexpected status read ' . $job);

            return new Response(200, ['content-type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        }
        if ($method === 'GET' && preg_match('#^/v1/accounting/connections/([^/]+)/documents/([^/]+)/([^/]+)$#', $path, $m)) {
            $key = rawurldecode($m[1]) . '/' . $m[2] . '/' . rawurldecode($m[3]);
            $this->docReads[] = $key;
            $next = isset($this->docs[$key]) ? array_shift($this->docs[$key]) : null;
            if ($next === null) {
                throw new \LogicException('Unexpected document read ' . $key);
            }

            return is_callable($next) ? $next($request) : $next;
        }

        throw new \LogicException('Unexpected request ' . $method . ' ' . $request->getUri());
    }

    public function readsOf(string $key): int
    {
        return count(array_filter($this->docReads, static fn($k) => $k === $key));
    }
}

final class IndependentExampleTest extends TestCase
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

    private function migrate(array $links = [['w1', 't1', 'c1']], bool $legacy = false): void
    {
        if ($legacy) {
            Schema::create('quickbooks_links', function ($t) { $t->id(); $t->string('workspace_id'); $t->string('tenant_id'); $t->string('connection_id')->nullable(); $t->string('connect_state')->nullable(); $t->timestamps(); });
            Schema::create('quickbooks_syncs', function ($t) { $t->id(); $t->string('workspace_id'); $t->string('operation'); $t->string('erp_type'); $t->string('erp_id'); $t->string('idempotency_key'); $t->json('request'); $t->string('job_id')->nullable(); $t->string('polling_url')->nullable(); $t->string('status'); $t->string('quickbooks_id')->nullable(); $t->string('error_code')->nullable(); $t->timestamps(); });
            foreach ($links as [$w, $t, $c]) {
                DB::table('quickbooks_links')->insert(['workspace_id' => $w, 'tenant_id' => $t, 'connection_id' => $c]);
            }
            DB::table('quickbooks_syncs')->insert(['workspace_id' => 'w1', 'operation' => 'quickbooks.invoice.create', 'erp_type' => 'invoice', 'erp_id' => 'LEG-7', 'idempotency_key' => 'legacy-k', 'request' => '{}', 'job_id' => 'jq', 'status' => 'completed', 'quickbooks_id' => '900']);
        }
        (require __DIR__ . '/../database/migrations/2026_10_29_000000_create_accounting_tables.php')->up();
        if (! $legacy) {
            foreach ($links as [$w, $t, $c]) {
                AccountingLink::create(['workspace_id' => $w, 'tenant_id' => $t, 'connection_id' => $c]);
            }
        }
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

    /** A refresh read body. */
    private static function read(string $type, string $erp, string $rev, string $at, string $status = 'open', string $balance = '10.00', string $providerId = '42'): Response
    {
        return self::jsonResp(200, self::docBody($type, $erp, $rev, $at, $status, $balance, $providerId));
    }

    private static function docBody(string $type, string $erp, string $rev, string $at, string $status = 'open', string $balance = '10.00', string $providerId = '42'): array
    {
        return ['provider_key' => 'quickbooks', 'document_type' => $type, 'erp_id' => $erp, 'provider_id' => $providerId, 'revision' => $rev, 'provider_account_id' => '1', 'status' => $status, 'currency' => 'CAD', 'total_amount' => '10.00', 'balance' => $balance, 'provider_updated_at' => $at, 'observed_at' => $at];
    }

    private function service(): AccountingSyncService
    {
        return $this->app->make(AccountingSyncService::class);
    }

    /** push() and swallow a refusal; returns [sync|null, throwable|null]. */
    private function tryPush(string $ws, string $op, string $erp, string $eventId, array $payload = ['invoice' => ['issue_date' => '2026-10-01', 'currency' => 'CAD']]): array
    {
        try {
            return [$this->service()->push($ws, $op, $erp, $eventId, $payload), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }

    /** Push an operation that the runtime accepts as $jobId. */
    private function pushAccepted(string $ws, string $op, string $erp, string $eventId, string $jobId, array $payload = ['x' => ['y' => 1]]): AccountingSync
    {
        $this->rt->submits[] = self::accepted($jobId);
        $sync = $this->service()->push($ws, $op, $erp, $eventId, $payload);
        $sync->refresh();
        if ($sync->job_id === null) {
            // push() may only store; submit explicitly.
            $this->service()->submit($sync);
            $sync->refresh();
        }
        $this->assertSame($jobId, $sync->job_id, 'receipt job_id is stored');

        return $sync;
    }

    private function completed(string $jobId, string $tenant = 't1'): SyncJobCompleted
    {
        return new SyncJobCompleted($tenant, $jobId, 'completed', 'quickbooks.invoice.create', '42', null, 'webhook', ['job_id' => $jobId, 'status' => 'completed']);
    }

    private function statusCompleted(string $jobId, array $result): void
    {
        $this->rt->statuses[$jobId] = ['job_id' => $jobId, 'status' => 'completed', 'result' => $result];
    }

    private static function invoiceResult(string $erp = 'INV-1', string $rev = '0'): array
    {
        return ['provider_key' => 'quickbooks', 'document_type' => 'invoice', 'erp_id' => $erp, 'provider_id' => '42', 'revision' => $rev, 'provider_account_id' => '1', 'currency' => 'CAD', 'net_amount' => '10.00', 'total_tax' => '0.00', 'total_amount' => '10.00', 'lines' => [['erp_line_id' => 'L1', 'provider_line_id' => '1']], 'provider_verified' => true];
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

    private function storedDoc(string $ws, string $type = 'invoice', string $erp = 'INV-1'): ?AccountingDocument
    {
        return AccountingDocument::where(['workspace_id' => $ws, 'document_type' => $type, 'erp_id' => $erp])->first();
    }

    /** Store an initial read through the example's own refresh path. */
    private function seedRead(array $ws, string $rev, string $at, string $type = 'invoice', string $erp = 'INV-1', string $conn = 'c1'): void
    {
        $this->rt->docs["$conn/$type/$erp"][] = self::read($type, $erp, $rev, $at);
        RecordProviderChange::refresh('t1', $conn, $type, $erp, $ws);
    }

    // ===================================================== 1. interleaving

    public function test_event_read_older_in_flight_while_newer_read_stored_keeps_newer(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $inner = $this->docEvent(self::docBody('invoice', 'INV-1', '99', '2026-10-29T23:00:00Z'));
        $this->rt->docs['c1/invoice/INV-1'][] = function () use ($inner) {
            $this->onEvent($inner); // reads B (newer) and stores it while A is in flight
            return self::read('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z', 'open', '10.00');
        };
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '3', '2026-10-29T12:00:00Z', 'paid', '0.00');

        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '98', '2026-10-29T22:00:00Z')));

        $d = $this->storedDoc('w1');
        $this->assertSame('3', $d->revision, 'older in-flight read A must not overwrite newer B');
        $this->assertSame('paid', $d->status);
        $this->assertSame('0.00', $d->balance);
    }

    public function test_event_read_newer_in_flight_while_older_read_stored_ends_newer(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $inner = $this->docEvent(self::docBody('invoice', 'INV-1', '99', '2026-10-29T23:00:00Z'));
        $this->rt->docs['c1/invoice/INV-1'][] = function () use ($inner) {
            $this->onEvent($inner); // stores A (older)
            return self::read('invoice', 'INV-1', '3', '2026-10-29T12:00:00Z', 'paid', '0.00');
        };
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z');

        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '98', '2026-10-29T22:00:00Z')));

        $d = $this->storedDoc('w1');
        $this->assertSame('3', $d->revision, 'newer read B, arriving last, replaces older A');
        $this->assertSame('paid', $d->status);
    }

    public function test_completion_read_older_interleaved_with_event_read_newer(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $inner = $this->docEvent(self::docBody('invoice', 'INV-1', '99', '2026-10-29T23:00:00Z'));
        $this->rt->docs['c1/invoice/INV-1'][] = function () use ($inner) {
            $this->onEvent($inner);
            return self::read('invoice', 'INV-1', '0', '2026-10-29T11:00:00Z', 'open', '10.00');
        };
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z', 'paid', '0.00');

        $this->onResult($this->completed('j1'));

        $d = $this->storedDoc('w1');
        $this->assertNotNull($d);
        $this->assertSame('1', $d->revision);
        $this->assertSame('paid', $d->status);
        $this->assertSame('42', AccountingSync::where('job_id', 'j1')->first()->provider_id);
    }

    public function test_completion_read_newer_interleaved_with_event_read_older(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $inner = $this->docEvent(self::docBody('invoice', 'INV-1', '99', '2026-10-29T23:00:00Z'));
        $this->rt->docs['c1/invoice/INV-1'][] = function () use ($inner) {
            $this->onEvent($inner);
            return self::read('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z', 'paid', '0.00');
        };
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '0', '2026-10-29T11:00:00Z');

        $this->onResult($this->completed('j1'));

        $this->assertSame('1', $this->storedDoc('w1')->revision);
    }

    public function test_two_workspaces_sharing_a_document_both_keep_newest(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't1', 'c1']]);
        $this->seedRead(['w1', 'w2'], '1', '2026-10-29T10:00:00Z');
        $this->assertNotNull($this->storedDoc('w2'), 'static refresh stores the read for each workspace');
        $this->pushAccepted('w1', 'accounting.invoice.update', 'INV-1', 'chg-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult('INV-1', '2'));
        $inner = $this->docEvent(self::docBody('invoice', 'INV-1', '99', '2026-10-29T23:00:00Z'));
        $this->rt->docs['c1/invoice/INV-1'][] = function () use ($inner) {
            $this->onEvent($inner); // newer read for both workspaces
            return self::read('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z');
        };
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '3', '2026-10-29T12:00:00Z', 'paid', '0.00');

        $this->onResult($this->completed('j1'));

        $this->assertSame('3', $this->storedDoc('w1')->revision);
        $this->assertSame('3', $this->storedDoc('w2')->revision);
    }

    public function test_one_event_for_shared_document_updates_both_workspaces_with_one_read(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't1', 'c1']]);
        $this->seedRead(['w1', 'w2'], '1', '2026-10-29T10:00:00Z');
        $before = count($this->rt->docReads);
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z', 'paid', '0.00');

        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z')));

        $this->assertSame('2', $this->storedDoc('w1')->revision);
        $this->assertSame('2', $this->storedDoc('w2')->revision);
        $this->assertSame(1, count($this->rt->docReads) - $before, 'one provider read serves every workspace on the connection');
    }

    // ===================================================== 2. timestamps

    public function test_subsecond_older_whole_second_read_does_not_overwrite(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '2', '2026-10-29T12:00:00.500Z');
        $this->seedRead(['w1'], '1', '2026-10-29T12:00:00Z');
        $this->assertSame('2', $this->storedDoc('w1')->revision);
    }

    public function test_whole_second_then_subsecond_newer_overwrites(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T12:00:00Z');
        $this->seedRead(['w1'], '2', '2026-10-29T12:00:00.000001Z');
        $this->assertSame('2', $this->storedDoc('w1')->revision);
    }

    public function test_subsecond_ordering_between_fractions(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '2', '2026-10-29T12:00:00.75Z');
        $this->seedRead(['w1'], '1', '2026-10-29T12:00:00.123456Z');
        $this->assertSame('2', $this->storedDoc('w1')->revision, '.75 is later than .123456');
    }

    public function test_negative_offset_newer_instant_overwrites_despite_smaller_string(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T12:00:00Z');
        $this->seedRead(['w1'], '2', '2026-10-29T09:00:00-04:00'); // 13:00Z
        $d = $this->storedDoc('w1');
        $this->assertSame('2', $d->revision);
        $this->assertSame('2026-10-29 13:00:00.000000', (string) DB::table('accounting_documents')->where('id', $d->id)->value('provider_updated_at'), 'stored as Y-m-d H:i:s.u UTC');
    }

    public function test_positive_offset_older_instant_does_not_overwrite_despite_larger_string(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T12:00:00Z');
        $this->seedRead(['w1'], '0', '2026-10-29T15:30:00+05:00'); // 10:30Z
        $this->assertSame('1', $this->storedDoc('w1')->revision);
    }

    public function test_plus_zero_and_z_and_offset_same_instant_same_revision_no_change(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '5', '2026-10-29T12:00:00+00:00');
        $this->assertSame('2026-10-29 12:00:00.000000', (string) DB::table('accounting_documents')->value('provider_updated_at'));
        $this->seedRead(['w1'], '5', '2026-10-29T08:00:00-04:00');
        $this->assertSame('5', $this->storedDoc('w1')->revision);
        $this->assertSame(1, AccountingDocument::count());
    }

    public function test_event_read_with_same_timestamp_and_revision_does_no_extra_read(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '5', '2026-10-29T12:00:00Z');
        $before = count($this->rt->docReads);
        $this->rt->docs['c1/invoice/INV-1'] = array_fill(0, 3, self::read('invoice', 'INV-1', '5', '2026-10-29T12:00:00Z'));
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '5', '2026-10-29T12:00:00Z')));
        $this->assertSame(1, count($this->rt->docReads) - $before, 'the signal costs one read; an identical read needs no tie-breaking re-read');
        $this->assertSame('5', $this->storedDoc('w1')->revision);
    }

    public function test_event_read_with_same_instant_other_offset_and_same_revision_does_no_extra_read(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '5', '2026-10-29T12:00:00.250Z');
        $before = count($this->rt->docReads);
        $this->rt->docs['c1/invoice/INV-1'] = array_fill(0, 3, self::read('invoice', 'INV-1', '5', '2026-10-29T08:00:00.25-04:00'));
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '5', '2026-10-29T08:00:00.25-04:00')));
        $this->assertSame(1, count($this->rt->docReads) - $before, '12:00:00.25Z read equals stored 12:00:00.250Z; no tie re-read');
    }

    public function test_event_tie_with_different_revision_reads_and_stores_newest(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '5', '2026-10-29T12:00:00Z');
        $before = count($this->rt->docReads);
        $this->rt->docs['c1/invoice/INV-1'] = array_fill(0, 3, self::read('invoice', 'INV-1', '6', '2026-10-29T12:00:00Z', 'voided', '0.00'));
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '6', '2026-10-29T12:00:00Z')));
        $this->assertGreaterThanOrEqual(1, count($this->rt->docReads) - $before, 'a different revision is a change even at the same timestamp');
        $this->assertSame('6', $this->storedDoc('w1')->revision);
        $this->assertSame('voided', $this->storedDoc('w1')->status);
    }

    public function test_read_tie_with_higher_revision_replaces(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '5', '2026-10-29T12:00:00Z');
        // A tie may be resolved by reading again; the provider keeps answering with its newest.
        $this->rt->docs['c1/invoice/INV-1'] = array_fill(0, 3, self::read('invoice', 'INV-1', '6', '2026-10-29T12:00:00Z'));
        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']);
        $this->assertSame('6', $this->storedDoc('w1')->revision);
    }

    public function test_a_later_read_at_the_same_instant_is_stored(): void
    {
        // Adapted by the author: reads of one document run under its row lock,
        // so the later read is the newer one; a tie is accepted, not re-read.
        $this->migrate();
        $this->seedRead(['w1'], '5', '2026-10-29T12:00:00Z');
        $this->rt->docs['c1/invoice/INV-1'] = [self::read('invoice', 'INV-1', '6', '2026-10-29T12:00:00Z')];
        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']);
        $this->assertSame('6', $this->storedDoc('w1')->revision);
    }

    // ===================================================== 3. completion path

    public function test_invoice_create_completion_stores_result_ids_and_state_from_read(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult('INV-1', '0'));
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z', 'paid', '0.00');

        $this->onResult($this->completed('j1'));

        $sync = AccountingSync::where('job_id', 'j1')->first();
        $this->assertSame('42', $sync->provider_id);
        $lines = is_string($sync->lines) ? json_decode($sync->lines, true) : $sync->lines;
        $this->assertSame([['erp_line_id' => 'L1', 'provider_line_id' => '1']], $lines);
        $this->assertNotNull($sync->documents_read_at);
        $this->assertSame(['c1/invoice/INV-1'], $this->rt->docReads, 'exactly one provider read');
        $d = $this->storedDoc('w1');
        $this->assertSame('1', $d->revision, 'revision from the read, not the result');
        $this->assertSame('paid', $d->status);
        $this->assertSame('0.00', $d->balance);
        $this->assertSame('42', $d->provider_id);
    }

    public function test_invoice_update_completion_reads_invoice_once(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.update', 'INV-1', 'chg-1', 'j2');
        $this->statusCompleted('j2', self::invoiceResult('INV-1', '3'));
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '3', '2026-10-29T12:00:00Z');
        $this->onResult($this->completed('j2'));
        $this->assertSame(['c1/invoice/INV-1'], $this->rt->docReads);
        $this->assertSame('3', $this->storedDoc('w1')->revision);
    }

    public function test_invoice_void_completion_reads_invoice_once(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.void', 'INV-1', 'void-1', 'j3');
        $this->statusCompleted('j3', self::invoiceResult('INV-1', '4'));
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '4', '2026-10-29T12:00:00Z', 'voided', '0.00');
        $this->onResult($this->completed('j3'));
        $this->assertSame(['c1/invoice/INV-1'], $this->rt->docReads);
        $this->assertSame('voided', $this->storedDoc('w1')->status);
    }

    public function test_payment_completion_reads_payment_and_settled_invoice(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.payment.create', 'PAY-1', 'pay-1', 'j4', ['payment' => ['amount' => '10.00'], 'invoice' => ['erp_id' => 'INV-1', 'expected_revision' => '1', 'expected_balance' => '10.00']]);
        $this->statusCompleted('j4', ['provider_key' => 'quickbooks', 'document_type' => 'payment', 'erp_id' => 'PAY-1', 'provider_id' => '501', 'revision' => '0', 'provider_account_id' => '1', 'currency' => 'CAD', 'total_amount' => '10.00', 'provider_verified' => true, 'invoice' => ['erp_id' => 'INV-1', 'balance' => '0.00']]);
        $this->rt->docs['c1/payment/PAY-1'][] = self::read('payment', 'PAY-1', '0', '2026-10-29T12:00:00Z', 'applied', '0.00', '501');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T12:00:01Z', 'paid', '0.00');

        $this->onResult($this->completed('j4'));

        sort($this->rt->docReads);
        $this->assertSame(['c1/invoice/INV-1', 'c1/payment/PAY-1'], $this->rt->docReads, 'two reads: the payment and the invoice it settles');
        $this->assertSame('501', $this->storedDoc('w1', 'payment', 'PAY-1')->provider_id);
        $this->assertSame('paid', $this->storedDoc('w1', 'invoice', 'INV-1')->status);
        $this->assertSame('2', $this->storedDoc('w1', 'invoice', 'INV-1')->revision);
    }

    public function test_credit_memo_apply_completion_reads_the_invoice(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.credit_memo.apply', 'APP-1', 'apply-1', 'j5', ['application' => ['amount' => '3.00'], 'credit_memo' => ['erp_id' => 'CM-1'], 'invoice' => ['erp_id' => 'INV-1', 'before_totals' => []]]);
        $this->statusCompleted('j5', ['provider_key' => 'quickbooks', 'document_type' => 'credit_memo_application', 'erp_id' => 'APP-1', 'provider_id' => '77', 'revision' => '0', 'provider_account_id' => '1', 'currency' => 'CAD', 'provider_verified' => true, 'invoice' => ['erp_id' => 'INV-1', 'balance' => '7.00']]);
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'open', '7.00');

        $this->onResult($this->completed('j5'));

        $this->assertSame(['c1/invoice/INV-1'], $this->rt->docReads);
        $this->assertSame('7.00', $this->storedDoc('w1')->balance);
        $this->assertNotNull(AccountingSync::where('job_id', 'j5')->first()->documents_read_at);
    }

    public function test_dead_lettered_does_no_provider_read_and_records_error(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j6');
        $this->rt->statuses['j6'] = ['job_id' => 'j6', 'status' => 'dead_lettered', 'error' => ['code' => 'accounting.tax_mismatch']];
        $this->onResult(new SyncJobDeadLettered('t1', 'j6', 'dead_lettered', 'quickbooks.invoice.create', null, 'accounting.tax_mismatch', 'webhook', ['job_id' => 'j6']));
        $this->assertSame([], $this->rt->docReads);
        $this->assertSame([], $this->rt->posts === [] ? [] : array_slice($this->rt->posts, 1), 'never resubmitted');
        $this->assertNotNull(AccountingSync::where('job_id', 'j6')->first()->error_code);
        $this->assertNull($this->storedDoc('w1'));
    }

    public function test_failed_does_no_provider_read(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j7');
        $this->rt->statuses['j7'] = ['job_id' => 'j7', 'status' => 'failed', 'error' => ['code' => 'accounting.provider_unavailable']];
        $this->onResult(new SyncJobFailed('t1', 'j7', 'failed', null, null, 'accounting.provider_unavailable', 'poll', ['job_id' => 'j7']));
        $this->assertSame([], $this->rt->docReads);
        $this->assertCount(1, $this->rt->posts);
    }

    public function test_redelivery_after_reads_finished_does_no_provider_read(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z');
        $this->onResult($this->completed('j1'));
        $this->onResult($this->completed('j1'));
        $this->onResult(new SyncJobCompleted('t1', 'j1', 'completed', null, null, null, 'poll', ['job_id' => 'j1']));
        $this->assertSame(['c1/invoice/INV-1'], $this->rt->docReads);
    }

    public function test_refresh_503_propagates_and_redelivery_retries_reads(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(503, 'accounting.provider_unavailable');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z');

        try {
            $this->onResult($this->completed('j1'));
            $this->fail('a 503 refresh must propagate so the delivery is retried');
        } catch (RuntimeProblemException $e) {
            $this->assertSame('accounting.provider_unavailable', $e->problemCode);
        }
        $this->assertNull(AccountingSync::where('job_id', 'j1')->first()->documents_read_at);

        $this->onResult($this->completed('j1'));
        $this->assertCount(2, $this->rt->docReads);
        $this->assertSame('1', $this->storedDoc('w1')->revision);
        $this->assertNotNull(AccountingSync::where('job_id', 'j1')->first()->documents_read_at);
    }

    public function test_refresh_document_unlinked_is_skipped_without_throwing(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(409, 'accounting.document_unlinked');
        $this->onResult($this->completed('j1'));
        $this->assertNull($this->storedDoc('w1'));
        $this->assertSame('42', AccountingSync::where('job_id', 'j1')->first()->provider_id);
    }

    public function test_refresh_not_found_is_skipped_without_throwing(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(404, 'accounting.connection_not_found');
        $this->onResult($this->completed('j1'));
        $this->assertNull($this->storedDoc('w1'));
    }

    public function test_refresh_generic_not_found_is_skipped_without_throwing(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->statusCompleted('j1', self::invoiceResult());
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(404, 'not_found');
        $this->onResult($this->completed('j1'));
        $this->assertNull($this->storedDoc('w1'));
    }

    public function test_event_refresh_document_unlinked_is_skipped_without_throwing(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->rt->docs['c1/invoice/INV-1'][] = self::problem(409, 'accounting.document_unlinked');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z')));
        $this->assertSame('1', $this->storedDoc('w1')->revision);
    }

    // ===================================================== 4. events

    public function test_event_unsupported_document_type_is_ignored(): void
    {
        $this->migrate();
        $this->onEvent($this->docEvent(self::docBody('credit_memo', 'CM-1', '1', '2026-10-29T12:00:00Z')));
        $this->assertSame([], $this->rt->docReads);
        $this->assertSame(0, AccountingDocument::count());
    }

    public function test_event_null_connection_is_ignored(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'), null));
        $this->assertCount(1, $this->rt->docReads);
    }

    public function test_event_for_another_tenant_is_ignored(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'), 'c1', 't9'));
        $this->assertCount(1, $this->rt->docReads);
        $this->assertSame('1', $this->storedDoc('w1')->revision);
    }

    public function test_event_for_another_connection_is_ignored(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'), 'c9'));
        $this->assertCount(1, $this->rt->docReads);
    }

    public function test_event_for_document_never_written_or_tracked_is_ignored(): void
    {
        $this->migrate();
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-UNKNOWN', '2', '2026-10-29T12:00:00Z')));
        $this->assertSame([], $this->rt->docReads);
        $this->assertSame(0, AccountingDocument::count());
    }

    public function test_event_for_document_this_workspace_wrote_is_read_and_stored(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'create-1', 'j1');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z', 'paid', '0.00');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '1', '2026-10-29T12:00:00Z', 'open', '99.00')));
        $d = $this->storedDoc('w1');
        $this->assertNotNull($d);
        $this->assertSame('paid', $d->status, 'state from the read, never the event document');
        $this->assertSame('0.00', $d->balance);
    }

    public function test_event_is_never_stored_as_state(): void
    {
        $this->migrate();
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z');
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T11:00:00Z', 'open', '4.00');
        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '7', '2026-10-29T13:00:00Z', 'voided', '0.00')));
        $d = $this->storedDoc('w1');
        $this->assertSame('2', $d->revision);
        $this->assertSame('open', $d->status);
        $this->assertSame('4.00', $d->balance);
    }

    public function test_workspace_isolation_same_erp_id_on_different_connections(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't1', 'c2']]);
        $this->seedRead(['w1'], '1', '2026-10-29T10:00:00Z', 'invoice', 'INV-1', 'c1');
        $this->rt->docs['c2/invoice/INV-1'][] = self::read('invoice', 'INV-1', '1', '2026-10-29T10:00:00Z', 'open', '10.00', '88');
        RecordProviderChange::refresh('t1', 'c2', 'invoice', 'INV-1', ['w2']);
        $this->rt->docs['c1/invoice/INV-1'][] = self::read('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z', 'paid', '0.00');

        $this->onEvent($this->docEvent(self::docBody('invoice', 'INV-1', '2', '2026-10-29T12:00:00Z'), 'c1'));

        $this->assertSame('paid', $this->storedDoc('w1')->status);
        $w2 = $this->storedDoc('w2');
        $this->assertSame('open', $w2->status, 'w2 (other connection) untouched');
        $this->assertSame('88', $w2->provider_id);
        $this->assertSame(0, $this->rt->readsOf('c2/invoice/INV-1') - 1);
    }

    // ===================================================== 5. push()

    public function test_push_stores_request_before_submit_and_sends_it(): void
    {
        $this->migrate();
        $seen = null;
        $this->rt->submits[] = function (RequestInterface $r) use (&$seen) {
            $seen = AccountingSync::count();
            return self::accepted('j1');
        };
        [$sync] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $sync ??= AccountingSync::first();
        if ($sync->job_id === null && $this->rt->posts === []) {
            $this->service()->submit($sync);
        }
        $sync->refresh();
        $this->assertSame(1, $seen, 'row stored before the first submit');
        $stored = is_string($sync->request) ? json_decode($sync->request, true) : $sync->request;
        $this->assertSame($stored, $this->rt->posts[0], 'sends the stored request');
        $this->assertSame('t1', $stored['tenant_id']);
        $this->assertSame('c1', $stored['connection_id'] ?? null, 'stored connection id pinned');
        $this->assertSame('accounting.invoice.create', $stored['operation']);
        $this->assertSame('INV-1', $stored['erp_id']);
        $this->assertSame($sync->idempotency_key, $stored['idempotency_key']);
        $this->assertSame('j1', $sync->job_id);
        $this->assertSame('/v1/accounting/operations/j1', $sync->polling_url);
    }

    public function test_push_keys_differ_per_event_id(): void
    {
        $this->migrate();
        $a = $this->pushAccepted('w1', 'accounting.invoice.update', 'INV-1', 'chg-1', 'j1');
        $b = $this->pushAccepted('w1', 'accounting.invoice.update', 'INV-1', 'chg-2', 'j2');
        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->idempotency_key, $b->idempotency_key);
    }

    public function test_push_keys_fit_128_and_long_ids_stay_distinct(): void
    {
        $this->migrate();
        $erp = str_repeat('E', 128);
        $base = str_repeat('x', 200);
        $a = $this->pushAccepted('w1', 'accounting.invoice.update', $erp, $base . 'A', 'j1');
        $b = $this->pushAccepted('w1', 'accounting.invoice.update', $erp, $base . 'B', 'j2');
        $this->assertLessThanOrEqual(128, strlen($a->idempotency_key));
        $this->assertLessThanOrEqual(128, strlen($b->idempotency_key));
        $this->assertNotSame($a->idempotency_key, $b->idempotency_key, 'events differing only past 128 chars get different keys');
    }

    public function test_push_keys_differ_per_operation_and_erp_id_for_same_event(): void
    {
        $this->migrate();
        $a = $this->pushAccepted('w1', 'accounting.invoice.update', 'INV-1', 'evt', 'j1');
        $b = $this->pushAccepted('w1', 'accounting.invoice.void', 'INV-1', 'evt', 'j2');
        $c = $this->pushAccepted('w1', 'accounting.invoice.update', 'INV-2', 'evt', 'j3');
        $this->assertCount(3, array_unique([$a->idempotency_key, $b->idempotency_key, $c->idempotency_key]));
    }

    public function test_push_same_event_replays_same_row_and_body(): void
    {
        $this->migrate();
        $a = $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1', ['invoice' => ['n' => 1]]);
        $this->rt->submits[] = self::accepted('j1');
        [$b, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', ['invoice' => ['n' => 2]]);
        $this->assertNull($err, 'same event is a replay, not a refusal: ' . ($err?->getMessage() ?? ''));
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, AccountingSync::count());
        foreach ($this->rt->posts as $p) {
            $this->assertSame($this->rt->posts[0], $p, 'never a changed body under the same key');
        }
        $stored = AccountingSync::first()->request;
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        $this->assertSame(['n' => 1], $stored['payload']['invoice']);
    }

    public function test_second_create_refused_while_earlier_create_is_live(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err, 'second create refused');
        $this->assertCount(1, $this->rt->posts);
        $this->assertSame(1, AccountingSync::count());
    }

    public function test_second_create_refused_when_earlier_dead_lettered(): void
    {
        $this->migrate();
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->rt->statuses['j1'] = ['job_id' => 'j1', 'status' => 'dead_lettered', 'error' => ['code' => 'accounting.provider_unavailable']];
        $this->onResult(new SyncJobDeadLettered('t1', 'j1', 'dead_lettered', null, null, 'accounting.provider_unavailable', 'webhook', ['job_id' => 'j1']));
        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err, 'dead-lettered is reconciled, not re-created');
        $this->assertCount(1, $this->rt->posts);
    }

    public function test_second_create_allowed_after_review_and_resubmit(): void
    {
        $this->migrate();
        $this->rt->submits[] = self::problem(409, 'accounting.document_exists');
        [$s, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $first = AccountingSync::first();
        $this->assertSame('review_and_resubmit', $first->status);
        $this->assertSame('accounting.document_exists', $first->error_code);

        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-2', 'j2');
        $this->assertSame(2, AccountingSync::count());
    }

    public function test_second_create_allowed_only_after_a_pre_write_job_failure(): void
    {
        // Adapted by the author: a failed job can follow a created invoice (for
        // example a tax mismatch on read-back), so only a pre-write guard counts.
        $this->migrate();
        $a = $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $a->update(['status' => 'failed', 'error_code' => 'accounting.tax_mismatch']);
        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-2');
        $this->assertNotNull($err);
        $a->update(['error_code' => 'accounting.document_exists']);
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-2', 'j2');
        $this->assertSame(2, AccountingSync::count());
    }

    public function test_third_create_refused_when_any_earlier_create_is_not_retryable(): void
    {
        $this->migrate();
        $this->rt->submits[] = self::problem(409, 'accounting.period_closed');
        $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-2', 'j2'); // live
        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-3');
        $this->assertNotNull($err);
        $this->assertSame(2, AccountingSync::count());
    }

    public function test_create_refusal_is_per_workspace(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't2', 'c2']]);
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-1', 'evt-1', 'j1');
        $this->pushAccepted('w2', 'accounting.invoice.create', 'INV-1', 'evt-9', 'j2');
        $this->assertSame(2, AccountingSync::count());
    }

    public function test_refused_submit_stores_fix_setup_and_resubmits_same_request(): void
    {
        $this->migrate();
        $this->rt->submits[] = self::problem(409, 'accounting.mapping_missing');
        $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $sync = AccountingSync::first();
        $this->assertSame('fix_setup', $sync->status);
        $this->assertSame('accounting.mapping_missing', $sync->error_code);

        $this->rt->submits[] = self::accepted('j1');
        $this->service()->submit($sync);
        $this->assertCount(2, $this->rt->posts);
        $this->assertSame($this->rt->posts[0], $this->rt->posts[1], 'identical request and key');
        $this->assertSame('j1', $sync->fresh()->job_id);
    }

    public function test_operation_in_progress_stores_retry_same_request(): void
    {
        $this->migrate();
        $this->rt->submits[] = self::problem(409, 'accounting.operation_in_progress');
        $this->tryPush('w1', 'accounting.customer.upsert', 'C-1', 'evt-1', ['customer' => ['name' => 'A', 'currency' => 'CAD']]);
        $sync = AccountingSync::first();
        $this->assertSame('retry_same_request', $sync->status);
        $this->assertSame('accounting.operation_in_progress', $sync->error_code);
    }

    public function test_unknown_accounting_code_stores_stop_and_reconcile(): void
    {
        $this->migrate();
        $this->rt->submits[] = self::problem(422, 'accounting.something_new');
        $this->tryPush('w1', 'accounting.invoice.create', 'INV-1', 'evt-1');
        $sync = AccountingSync::first();
        $this->assertSame('stop_and_reconcile', $sync->status);
        $this->assertSame('accounting.something_new', $sync->error_code);
    }

    public function test_legacy_quickbooks_invoice_refuses_create(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't1', 'c1']], legacy: true);
        $this->assertSame('c1', AccountingLink::where('workspace_id', 'w1')->value('connection_id'));
        [, $err] = $this->tryPush('w1', 'accounting.invoice.create', 'LEG-7', 'evt-1');
        $this->assertNotNull($err, 'invoice listed in quickbooks_syncs must not be created again');
        $this->assertSame([], $this->rt->posts);
        $this->assertSame(0, AccountingSync::where('erp_id', 'LEG-7')->whereNotNull('job_id')->count());
    }

    public function test_legacy_refusal_scoped_to_workspace_and_create(): void
    {
        $this->migrate([['w1', 't1', 'c1'], ['w2', 't1', 'c1']], legacy: true);
        $this->pushAccepted('w2', 'accounting.invoice.create', 'LEG-7', 'evt-1', 'j1');
        $this->pushAccepted('w1', 'accounting.invoice.update', 'LEG-7', 'chg-1', 'j2');
        $this->pushAccepted('w1', 'accounting.invoice.create', 'INV-NEW', 'evt-2', 'j3');
        $this->assertCount(3, $this->rt->posts);
    }
}
