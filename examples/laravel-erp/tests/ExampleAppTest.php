<?php

declare(strict_types=1);

namespace Tests\Example;

use App\Listeners\RecordAccountingResult;
use App\Listeners\RecordProviderChange;
use App\Models\AccountingDocument;
use App\Models\AccountingLink;
use App\Models\AccountingSync;
use App\Services\AccountingSyncService;
use Ezee\IntegrationRuntime\Laravel\Events\AccountingDocumentStatusChanged;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobCompleted;
use Ezee\IntegrationRuntime\Laravel\Tests\TestCase;
use Ezee\IntegrationRuntime\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Runs from sdks/laravel (SDK Laravel CI): the package's testbench app hosts these files.
const EX = __DIR__ . '/..';
foreach (['app/Models/AccountingLink.php', 'app/Models/AccountingSync.php', 'app/Models/AccountingDocument.php', 'app/Listeners/RecordProviderChange.php', 'app/Listeners/RecordAccountingResult.php', 'app/Services/AccountingSyncService.php'] as $f) {
    require_once EX . '/' . $f;
}

final class ExampleAppTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    private function migrate(bool $legacy = false): void
    {
        if ($legacy) {
            Schema::create('quickbooks_links', function ($t) { $t->id(); $t->string('workspace_id'); $t->string('tenant_id'); $t->string('connection_id')->nullable(); $t->string('connect_state')->nullable(); $t->timestamps(); });
            Schema::create('quickbooks_syncs', function ($t) { $t->id(); $t->string('workspace_id'); $t->string('operation'); $t->string('erp_type'); $t->string('erp_id'); $t->string('idempotency_key'); $t->json('request'); $t->string('job_id')->nullable(); $t->string('polling_url')->nullable(); $t->string('status'); $t->string('quickbooks_id')->nullable(); $t->string('error_code')->nullable(); $t->timestamps(); });
            DB::table('quickbooks_links')->insert(['workspace_id' => 'w1', 'tenant_id' => 't1', 'connection_id' => 'c1']);
            DB::table('quickbooks_syncs')->insert(['workspace_id' => 'w1', 'operation' => 'quickbooks.invoice.create', 'erp_type' => 'invoice', 'erp_id' => 'INV-OLD', 'idempotency_key' => 'k', 'request' => '{}', 'job_id' => 'j0', 'status' => 'completed']);
        }
        (require EX . '/database/migrations/2026_10_29_000000_create_accounting_tables.php')->up();
        if (! $legacy) {
            AccountingLink::create(['workspace_id' => 'w1', 'tenant_id' => 't1', 'connection_id' => 'c1']);
        }
    }

    private static function doc(string $status, string $balance, string $rev, string $at): array
    {
        return ['provider_key' => 'quickbooks', 'document_type' => 'invoice', 'erp_id' => 'INV-1', 'provider_id' => '42', 'revision' => $rev, 'provider_account_id' => '1', 'status' => $status, 'currency' => 'CAD', 'total_amount' => '10.00', 'balance' => $balance, 'provider_updated_at' => $at, 'observed_at' => $at];
    }

    public function test_the_latest_read_is_stored_and_a_deletion_is_final(): void
    {
        $this->migrate();
        $t = $this->fakeRuntime(
            self::runtimeJson(200, self::doc('open', '10.00', '3', '2026-10-29T10:00:05Z')),
            self::runtimeJson(200, self::doc('paid', '0.00', '4', '2026-10-29T10:00:05Z')),
        );
        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']);
        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']);
        self::assertSame(['paid', '4'], [AccountingDocument::first()->status, AccountingDocument::first()->revision]);

        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1'], ['status' => 'deleted', 'revision' => null, 'balance' => null]);
        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']); // no read: deleted is final
        self::assertSame(['deleted', '42'], [AccountingDocument::first()->status, AccountingDocument::first()->provider_id]);
        self::assertNotNull(AccountingDocument::first()->created_at);
        self::assertCount(2, $t->requests);
        self::assertSame(1, AccountingDocument::count());
    }

    public function test_completion_stores_the_result_then_a_fresh_read_and_redelivery_is_safe(): void
    {
        $this->migrate();
        AccountingSync::create(['workspace_id' => 'w1', 'operation' => 'accounting.invoice.void', 'erp_id' => 'INV-1', 'idempotency_key' => 'k1', 'request' => ['connection_id' => 'c1', 'tenant_id' => 't1'], 'job_id' => 'j1', 'status' => 'queued']);
        $status = ['job_id' => 'j1', 'operation' => 'accounting.invoice.void', 'erp_id' => 'INV-1', 'provider_key' => 'quickbooks', 'status' => 'completed', 'error' => null, 'result' => ['provider_id' => '42', 'revision' => '4', 'status' => 'voided', 'balance' => '0.00', 'lines' => [['erp_line_id' => 'L1', 'provider_line_id' => '1']]]];
        $t = $this->fakeRuntime(
            self::runtimeJson(200, $status),
            self::runtimeJson(200, self::doc('voided', '0.00', '4', '2026-10-29T10:00:09Z')),
            self::runtimeJson(200, $status),
        );
        $event = new SyncJobCompleted('t1', 'j1', 'completed', null, null, null, 'webhook', []);
        (new RecordAccountingResult())->handle($event);
        (new RecordAccountingResult())->handle($event); // redelivery: a runtime status read, no provider read
        $sync = AccountingSync::first();
        self::assertSame(['completed', '42'], [$sync->status, $sync->provider_id]);
        self::assertSame([['erp_line_id' => 'L1', 'provider_line_id' => '1']], $sync->lines);
        self::assertSame('voided', AccountingDocument::first()->status);
        self::assertCount(3, $t->requests);
    }

    public function test_a_reconnect_keeps_documents_apart_per_connection(): void
    {
        $this->migrate();
        $this->fakeRuntime(
            self::runtimeJson(200, self::doc('open', '10.00', '3', '2026-10-29T10:00:05Z')),
            self::runtimeJson(200, self::doc('open', '99.00', '1', '2026-10-29T09:00:00Z')),
        );
        RecordProviderChange::refresh('t1', 'c1', 'invoice', 'INV-1', ['w1']);
        RecordProviderChange::refresh('t1', 'c2', 'invoice', 'INV-1', ['w1']);
        self::assertSame('10.00', AccountingDocument::where('connection_id', 'c1')->value('balance'));
        self::assertSame('99.00', AccountingDocument::where('connection_id', 'c2')->value('balance'));
    }

    public function test_a_payment_also_refreshes_the_invoice_it_settled(): void
    {
        $this->migrate();
        AccountingSync::create(['workspace_id' => 'w1', 'operation' => 'accounting.payment.create', 'erp_id' => 'PAY-1', 'idempotency_key' => 'k1', 'request' => ['connection_id' => 'c1', 'tenant_id' => 't1'], 'job_id' => 'j1', 'status' => 'queued']);
        $payment = ['document_type' => 'payment', 'erp_id' => 'PAY-1', 'provider_id' => '9', 'balance' => null] + self::doc('open', '0', '1', '2026-10-29T10:00:00Z');
        $this->fakeRuntime(
            self::runtimeJson(200, ['job_id' => 'j1', 'status' => 'completed', 'error' => null, 'result' => ['provider_id' => '9', 'revision' => '1', 'invoice' => ['erp_id' => 'INV-1', 'provider_id' => '42', 'balance' => '0.00']]]),
            self::runtimeJson(200, $payment),
            self::runtimeJson(200, self::doc('paid', '0.00', '5', '2026-10-29T10:00:00Z')),
        );
        (new RecordAccountingResult())->handle(new SyncJobCompleted('t1', 'j1', 'completed', null, null, null, 'webhook', []));
        self::assertSame('5', AccountingDocument::where(['document_type' => 'invoice', 'erp_id' => 'INV-1'])->value('revision'));
        self::assertTrue(AccountingDocument::where(['document_type' => 'payment', 'erp_id' => 'PAY-1'])->exists());
    }

    public function test_events_for_documents_not_written_here_or_unlinked_are_ignored(): void
    {
        $this->migrate();
        $t = $this->fakeRuntime(self::runtimeJson(409, ['code' => 'accounting.document_unlinked']));
        $we = (new \ReflectionClass(WebhookEvent::class))->newInstanceWithoutConstructor();
        $handler = new RecordProviderChange();
        $handler->handle(new AccountingDocumentStatusChanged('t1', 'c1', 1, ['document_type' => 'invoice', 'erp_id' => 'NOT-OURS'], $we));
        self::assertCount(0, $t->requests);
        AccountingSync::create(['workspace_id' => 'w1', 'operation' => 'accounting.invoice.create', 'erp_id' => 'INV-1', 'idempotency_key' => 'k1', 'request' => ['connection_id' => 'c1'], 'status' => 'completed']);
        $handler->handle(new AccountingDocumentStatusChanged('t1', 'c1', 2, ['document_type' => 'invoice', 'erp_id' => 'INV-1'], $we));
        self::assertCount(1, $t->requests);
        self::assertSame(0, AccountingDocument::count());
    }

    public function test_an_event_refreshes_a_document_tracked_only_through_a_payment(): void
    {
        $this->migrate();
        DB::table('accounting_documents')->insert(['workspace_id' => 'w1', 'connection_id' => 'c1', 'document_type' => 'invoice', 'erp_id' => 'INV-L', 'provider_id' => '7', 'revision' => '1', 'status' => 'open', 'balance' => '5.00', 'provider_updated_at' => '2026-10-29 09:00:00.000000']);
        $this->fakeRuntime(self::runtimeJson(200, ['erp_id' => 'INV-L', 'provider_id' => '7'] + self::doc('voided', '0.00', '2', '2026-10-29T12:00:00Z')));
        $we = (new \ReflectionClass(WebhookEvent::class))->newInstanceWithoutConstructor();
        (new RecordProviderChange())->handle(new AccountingDocumentStatusChanged('t1', 'c1', 3, ['document_type' => 'invoice', 'erp_id' => 'INV-L'], $we));
        self::assertSame('voided', AccountingDocument::where('erp_id', 'INV-L')->value('status'));
    }

    public function test_a_status_event_stores_a_fresh_read(): void
    {
        $this->migrate();
        AccountingSync::create(['workspace_id' => 'w1', 'operation' => 'accounting.invoice.create', 'erp_id' => 'INV-1', 'idempotency_key' => 'k1', 'request' => ['connection_id' => 'c1'], 'status' => 'completed']);
        $this->fakeRuntime(self::runtimeJson(200, self::doc('paid', '0.00', '5', '2026-10-29T11:00:00Z')));
        $we = (new \ReflectionClass(WebhookEvent::class))->newInstanceWithoutConstructor();
        (new RecordProviderChange())->handle(new AccountingDocumentStatusChanged('t1', 'c1', 7, ['document_type' => 'invoice', 'erp_id' => 'INV-1', 'provider_id' => '42', 'status' => 'open'], $we));
        self::assertSame('paid', AccountingDocument::first()->status);
    }

    public function test_keys_differ_per_event_fit_the_limit_and_replay_the_same_row(): void
    {
        $this->migrate();
        $this->fakeRuntime(
            self::runtimeJson(202, ['job_id' => 'j1', 'polling_url' => '/v1/accounting/operations/j1?tenant_id=t1', 'status' => 'queued', 'operation' => 'accounting.invoice.update', 'idempotent_replay' => false]),
            self::runtimeJson(202, ['job_id' => 'j2', 'polling_url' => '/v1/accounting/operations/j2?tenant_id=t1', 'status' => 'queued', 'operation' => 'accounting.invoice.update', 'idempotent_replay' => false]),
        );
        \Illuminate\Support\Facades\Queue::fake();
        $svc = new AccountingSyncService();
        $long = str_repeat('x', 128);
        $a = $svc->push('w1', 'accounting.invoice.update', $long, 'chg-1', ['invoice' => []]);
        $b = $svc->push('w1', 'accounting.invoice.update', $long, 'chg-2', ['invoice' => []]);
        $again = $svc->push('w1', 'accounting.invoice.update', $long, 'chg-1', ['invoice' => ['changed' => true]]);
        self::assertNotSame($a->idempotency_key, $b->idempotency_key);
        self::assertSame($a->id, $again->id);
        self::assertLessThanOrEqual(128, strlen($a->idempotency_key));
        self::assertSame(2, AccountingSync::count());
    }

    public function test_a_second_create_needs_a_refused_first_one(): void
    {
        $this->migrate();
        AccountingSync::create(['workspace_id' => 'w1', 'operation' => 'accounting.invoice.create', 'erp_id' => 'INV-2', 'idempotency_key' => 'k-old', 'request' => [], 'status' => 'completed']);
        try {
            (new AccountingSyncService())->push('w1', 'accounting.invoice.create', 'INV-2', 'retry-2', ['invoice' => []]);
            self::fail('a second create was accepted');
        } catch (\LogicException) {
        }
        AccountingSync::where('idempotency_key', 'k-old')->update(['status' => 'dead_lettered', 'error_code' => 'accounting.provider_unavailable']);
        try {
            (new AccountingSyncService())->push('w1', 'accounting.invoice.create', 'INV-2', 'retry-2', ['invoice' => []]);
            self::fail('an ambiguous dead letter allowed a second create');
        } catch (\LogicException) {
        }
        // A tax mismatch is found on read-back, after the invoice exists.
        AccountingSync::where('idempotency_key', 'k-old')->update(['status' => 'failed', 'error_code' => 'accounting.tax_mismatch']);
        try {
            (new AccountingSyncService())->push('w1', 'accounting.invoice.create', 'INV-2', 'retry-2', ['invoice' => []]);
            self::fail('a post-write failure allowed a second create');
        } catch (\LogicException) {
        }
        // A queued duplicate-number refusal is proven pre-write: renumber and resubmit.
        AccountingSync::where('idempotency_key', 'k-old')->update(['status' => 'dead_lettered', 'error_code' => 'accounting.document_exists']);
        $this->fakeRuntime(self::runtimeJson(202, ['job_id' => 'j9', 'polling_url' => '/v1/accounting/operations/j9?tenant_id=t1', 'status' => 'queued', 'operation' => 'accounting.invoice.create', 'idempotent_replay' => false]));
        \Illuminate\Support\Facades\Queue::fake();
        self::assertSame('j9', (new AccountingSyncService())->push('w1', 'accounting.invoice.create', 'INV-2', 'retry-2', ['invoice' => []])->job_id);
    }

    public function test_upgrade_copies_links_and_refuses_recreating_a_legacy_invoice(): void
    {
        $this->migrate(legacy: true);
        self::assertSame('c1', AccountingLink::where('workspace_id', 'w1')->value('connection_id'));
        // A legacy create whose receipt was lost may exist in the provider too.
        DB::table('quickbooks_syncs')->where('erp_id', 'INV-OLD')->update(['job_id' => null, 'status' => 'pending']);
        $this->expectException(\LogicException::class);
        (new AccountingSyncService())->push('w1', 'accounting.invoice.create', 'INV-OLD', 'create', ['invoice' => []]);
    }
}
