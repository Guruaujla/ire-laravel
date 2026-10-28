<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Tests;

use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobCompleted;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;
use Ezee\IntegrationRuntime\Laravel\Jobs\WaitForSyncJob;
use Ezee\IntegrationRuntime\OperationReceipt;
use Ezee\IntegrationRuntime\QuickBooks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

final class PackageTest extends TestCase
{
    public function test_facade_submits_typed_quickbooks_operations(): void
    {
        $transport = $this->fakeRuntime(self::runtimeJson(202, ['job_id' => 'job_1', 'polling_url' => '/v1/sync-jobs/job_1?tenant_id=t1', 'status' => 'queued']));
        $receipt = IntegrationRuntime::quickbooks()->createInvoice('t1', 'c1', 'INV-1', 'erp_invoice_INV-1:create', ['invoice' => ['DocNumber' => 'INV-1']]);

        self::assertSame('job_1', $receipt->jobId);
        $request = $transport->requests[0];
        self::assertSame('/v1/operations/execute', $request->getUri()->getPath());
        self::assertSame('app_erp', $request->getHeaderLine('X-IR-App-Id'));
        self::assertNotSame('', $request->getHeaderLine('X-IR-Signature'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('erp.quickbooks.invoice.created', $body['recipe_key']);
        self::assertSame(5, $body['recipe_version']);
        self::assertInstanceOf(QuickBooks::class, $this->app->make(QuickBooks::class));
    }

    public function test_facade_submits_neutral_accounting_operations(): void
    {
        $transport = $this->fakeRuntime(self::runtimeJson(202, ['job_id' => 'job_2', 'polling_url' => '/v1/accounting/operations/job_2?tenant_id=t1', 'status' => 'queued']));
        $receipt = IntegrationRuntime::accounting()->createInvoice('t1', 'INV-1', 'invoice:INV-1:create', ['invoice' => ['number' => 'INV-1']]);

        self::assertSame('job_2', $receipt->jobId);
        $request = $transport->requests[0];
        self::assertSame('/v1/accounting/operations', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame(['tenant_id' => 't1', 'operation' => 'accounting.invoice.create', 'erp_id' => 'INV-1', 'idempotency_key' => 'invoice:INV-1:create', 'payload' => ['invoice' => ['number' => 'INV-1']]], $body);
        self::assertInstanceOf(Accounting::class, $this->app->make(Accounting::class));
    }

    public function test_missing_credentials_fail_with_the_env_name(): void
    {
        $this->app['config']->set('integration-runtime.secret', null);
        $this->app->forgetInstance(\Ezee\IntegrationRuntime\Client::class);
        $this->expectExceptionMessage('INTEGRATION_RUNTIME_SECRET');
        IntegrationRuntime::client();
    }

    public function test_webhook_route_is_registered_without_csrf(): void
    {
        $route = Route::getRoutes()->getByName('integration-runtime.webhooks');
        self::assertNotNull($route);
        self::assertSame('integration-runtime/webhooks', $route->uri());
        self::assertSame([], $route->gatherMiddleware());
    }

    public function test_track_queues_a_poller(): void
    {
        Queue::fake();
        IntegrationRuntime::track(new OperationReceipt('job_1', '/v1/sync-jobs/job_1?tenant_id=t1', 'queued', null), 't1');
        Queue::assertPushed(WaitForSyncJob::class, fn(WaitForSyncJob $job) => $job->pollingUrl === '/v1/sync-jobs/job_1?tenant_id=t1' && $job->tenantId === 't1');
    }

    public function test_track_polls_an_accounting_receipt_through_its_sync_job(): void
    {
        Queue::fake();
        IntegrationRuntime::track(new OperationReceipt('job 2', '/v1/accounting/operations/job%202?tenant_id=t%201', 'queued', null), 't 1');
        Queue::assertPushed(WaitForSyncJob::class, fn(WaitForSyncJob $job) => $job->pollingUrl === '/v1/sync-jobs/job%202?tenant_id=t%201' && $job->jobId === 'job 2');
    }

    public function test_poller_fires_the_completed_event_when_terminal(): void
    {
        Event::fake();
        $this->fakeRuntime(self::runtimeJson(200, ['id' => 'job_1', 'status' => 'completed', 'operation_key' => 'quickbooks.payment.create', 'external_object_id' => '185']));
        $job = (new WaitForSyncJob('/v1/sync-jobs/job_1?tenant_id=t1', 't1', 'job_1'))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        Event::assertDispatched(SyncJobCompleted::class, fn(SyncJobCompleted $e) => $e->externalObjectId === '185' && $e->source === 'poll');
    }

    public function test_poller_releases_while_running_and_on_rate_limit(): void
    {
        Event::fake();
        $this->fakeRuntime(
            self::runtimeJson(200, ['id' => 'job_1', 'status' => 'processing']),
            self::runtimeJson(429, ['code' => 'rate_limit.quota_exceeded'], ['Retry-After' => '30']),
        );
        $first = (new WaitForSyncJob('/v1/sync-jobs/job_1?tenant_id=t1', 't1', 'job_1'))->withFakeQueueInteractions();
        $this->app->call([$first, 'handle']);
        $first->assertReleased(5);

        $second = (new WaitForSyncJob('/v1/sync-jobs/job_1?tenant_id=t1', 't1', 'job_1'))->withFakeQueueInteractions();
        $this->app->call([$second, 'handle']);
        $second->assertReleased(30);
        Event::assertNothingDispatched();
    }

    public function test_check_command_proves_signing_without_writing(): void
    {
        $transport = $this->fakeRuntime(
            self::runtimeJson(200, ['status' => 'ok']),
            self::runtimeJson(404, ['code' => 'tenant.not_found']),
            self::runtimeJson(200, ['data' => []]),
        );
        $this->artisan('integration-runtime:check', ['--tenant' => 't1'])->assertSuccessful();
        foreach ($transport->requests as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function test_check_command_reports_a_rejected_signature(): void
    {
        $this->fakeRuntime(self::runtimeJson(200, ['status' => 'ok']), self::runtimeJson(401, ['code' => 'hmac.signature_invalid']));
        $this->artisan('integration-runtime:check')->expectsOutputToContain('Signature rejected')->assertFailed();
    }

    public function test_check_command_does_not_confirm_on_a_rate_limit(): void
    {
        $this->fakeRuntime(self::runtimeJson(200, ['status' => 'ok']), self::runtimeJson(429, ['code' => 'rate_limit.quota_exceeded']));
        $this->artisan('integration-runtime:check')->expectsOutputToContain('Signing not confirmed')->assertFailed();
    }

    public function test_agent_rules_install_is_idempotent(): void
    {
        $base = sys_get_temp_dir() . '/ir-agent-rules-' . bin2hex(random_bytes(4));
        mkdir($base);
        file_put_contents($base . '/AGENTS.md', "# App rules\n");
        $this->app->setBasePath($base);

        $this->artisan('integration-runtime:agent-rules')->assertSuccessful();
        $this->artisan('integration-runtime:agent-rules')->assertSuccessful();
        $this->artisan('integration-runtime:agent-rules', ['--force' => true])->assertSuccessful();

        $agents = (string) file_get_contents($base . '/AGENTS.md');
        self::assertStringStartsWith("# App rules\n", $agents);
        self::assertSame(1, substr_count($agents, 'integration-runtime:agent-rules:start'));
        self::assertStringContainsString('idempotency_key', $agents);
        self::assertFileExists($base . '/.claude/skills/quickbooks-integration-runtime/SKILL.md');
        self::assertJson((string) file_get_contents($base . '/.claude/skills/quickbooks-integration-runtime/quickbooks-operations.json'));
        self::assertFileExists($base . '/.claude/skills/quickbooks-integration-runtime/quickbooks-operations.md');
        self::assertJson((string) file_get_contents($base . '/.claude/skills/quickbooks-integration-runtime/accounting-operations.json'));
        self::assertFileExists($base . '/.claude/skills/quickbooks-integration-runtime/accounting-migration.md');
        self::assertStringContainsString('IntegrationRuntime::accounting()', $agents);
    }
}
