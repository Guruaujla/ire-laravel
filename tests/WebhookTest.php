<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Tests;

use Ezee\IntegrationRuntime\Laravel\Events\AccountingDocumentStatusChanged;
use Ezee\IntegrationRuntime\Laravel\Events\ConnectionReconnectRequired;
use Ezee\IntegrationRuntime\Laravel\Events\ConnectionStatusChanged;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobCompleted;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobDeadLettered;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobResolved;
use Ezee\IntegrationRuntime\Laravel\Events\WebhookReceived;
use Ezee\IntegrationRuntime\Laravel\Events\WebhookTestReceived;
use Ezee\IntegrationRuntime\Signer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

final class WebhookTest extends TestCase
{
    private static function body(string $deliveryId = 'dlv_1', string $status = 'completed'): string
    {
        return json_encode([
            'schema_version' => 1,
            'delivery_id' => $deliveryId,
            'event_id' => 'job_1:sync_job.' . $status,
            'event_type' => $status === 'completed' ? 'sync_job.completed' : 'sync_job.dead_lettered',
            'occurred_at' => '2026-10-26T09:00:00.000Z',
            'app_id' => 'app_erp',
            'tenant_id' => 'tnt_1',
            'data' => ['sync_job' => [
                'id' => 'job_1', 'status' => $status, 'operation_key' => 'quickbooks.invoice.create',
                'object_type' => 'invoice', 'connection_id' => 'c1', 'provider_key' => 'quickbooks',
                'external_object_id' => null, 'attempt_count' => 1,
                'last_error_code' => $status === 'completed' ? null : 'invoice_payload_conflict',
                'created_at' => '2026-10-26T08:59:58.000Z', 'completed_at' => '2026-10-26T09:00:00.000Z',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** The job read the receiver makes for a completion: the ID is in result.external_id. */
    private function jobReads(int $count, string $status = 'completed'): void
    {
        $read = self::runtimeJson(200, [
            'id' => 'job_1', 'status' => $status, 'operation_key' => 'quickbooks.invoice.create',
            'external_object_id' => null,
            'last_error_code' => $status === 'completed' ? null : 'invoice_payload_conflict',
            'result' => $status === 'completed' ? ['external_id' => '1042'] : null,
        ]);
        $this->fakeRuntime(...array_fill(0, $count, $read));
    }

    /** Sign the way src/outbound/sign.ts does (the PHP SDK's verifier is pinned to it). */
    private function deliver(string $body, string $target = '/integration-runtime/webhooks', ?string $secret = null): TestResponse
    {
        $timestamp = (string) time();
        $nonce = Signer::generateNonce();
        $signature = Signer::base64UrlEncode(hash_hmac(
            'sha256',
            Signer::computeCanonicalPayload('POST', $target, $timestamp, $nonce, $body),
            Signer::decodeSecret($secret ?? self::WEBHOOK_SECRET),
            true,
        ));

        return $this->call('POST', $target, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_IR_TIMESTAMP' => $timestamp,
            'HTTP_X_IR_NONCE' => $nonce,
            'HTTP_X_IR_SIGNATURE' => $signature,
            'HTTP_X_IR_KEY_ID' => 'whk_test',
        ], $body);
    }

    public function test_verified_delivery_fires_events_once(): void
    {
        $this->jobReads(1);
        Event::fake();
        $this->deliver(self::body())->assertOk();
        $this->deliver(self::body())->assertOk(); // redelivery, same delivery_id

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatchedTimes(SyncJobCompleted::class, 1);
        Event::assertDispatched(SyncJobCompleted::class, fn(SyncJobCompleted $e) => $e->tenantId === 'tnt_1'
            && $e->jobId === 'job_1'
            && $e->externalObjectId === '1042'
            && $e->operationKey === 'quickbooks.invoice.create'
            && $e->source === 'webhook');
    }

    public function test_neutral_document_status_fires_its_event_without_a_job_read(): void
    {
        $this->fakeRuntime(); // any runtime call would fail the test
        Event::fake();
        $body = json_encode([
            'schema_version' => 1,
            'delivery_id' => 'dlv_doc',
            'event_id' => 'evt_1:accounting.document.status_changed',
            'event_type' => 'accounting.document.status_changed',
            'occurred_at' => '2026-10-28T10:00:00.000Z',
            'app_id' => 'app_erp',
            'tenant_id' => 'tnt_1',
            'callback_epoch' => 1,
            'data' => ['connection_id' => 'c1', 'sequence' => 4, 'document' => [
                'provider_key' => 'quickbooks', 'document_type' => 'invoice', 'erp_id' => 'INV-1',
                'provider_id' => '101', 'revision' => '3', 'status' => 'paid', 'balance' => '0.00',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk(); // redelivery, same delivery_id

        Event::assertDispatchedTimes(AccountingDocumentStatusChanged::class, 1);
        Event::assertDispatched(AccountingDocumentStatusChanged::class, fn(AccountingDocumentStatusChanged $e) => $e->tenantId === 'tnt_1'
            && $e->connectionId === 'c1'
            && $e->sequence === 4
            && $e->document['erp_id'] === 'INV-1'
            && $e->document['status'] === 'paid');
        Event::assertNotDispatched(SyncJobCompleted::class);
    }

    /** @param array<string, mixed> $connection */
    private static function connectionBody(string $deliveryId, string $type, array $connection): string
    {
        return json_encode([
            'schema_version' => 1,
            'delivery_id' => $deliveryId,
            'event_id' => 'connection:0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d07',
            'event_type' => $type,
            'occurred_at' => '2026-11-09T12:00:00.005Z',
            'app_id' => 'app_erp',
            'tenant_id' => 'tnt_1',
            'data' => ['connection' => $connection],
        ], JSON_THROW_ON_ERROR);
    }

    public function test_reconnect_required_fires_both_connection_events_without_a_read(): void
    {
        $this->fakeRuntime(); // any runtime call would fail the test
        Event::fake();
        $body = self::connectionBody('dlv_conn_1', 'connection.reconnect_required', [
            'id' => 'conn_1', 'provider_key' => 'quickbooks', 'status' => 'reconnect_required',
            'previous_status' => 'connected', 'reason' => 'invalid_grant',
        ]);
        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk(); // redelivery, same delivery_id

        Event::assertDispatchedTimes(ConnectionStatusChanged::class, 1);
        Event::assertDispatchedTimes(ConnectionReconnectRequired::class, 1);
        Event::assertDispatched(ConnectionReconnectRequired::class, fn(ConnectionReconnectRequired $e) => $e->tenantId === 'tnt_1'
            && $e->connectionId === 'conn_1'
            && $e->providerKey === 'quickbooks'
            && $e->status === 'reconnect_required'
            && $e->previousStatus === 'connected'
            && $e->reason === 'invalid_grant');
        Event::assertNotDispatched(SyncJobCompleted::class);
    }

    public function test_connected_and_disconnected_fire_only_the_status_event(): void
    {
        $this->fakeRuntime();
        Event::fake();
        $this->deliver(self::connectionBody('dlv_conn_2', 'connection.connected', [
            'id' => 'conn_2', 'provider_key' => 'xero', 'status' => 'connected', 'previous_status' => null, 'reason' => null,
        ]))->assertOk();
        $this->deliver(self::connectionBody('dlv_conn_3', 'connection.disconnected', [
            'id' => 'conn_2', 'provider_key' => 'xero', 'status' => 'disconnected', 'previous_status' => 'connected', 'reason' => 'disconnect',
        ]))->assertOk();

        Event::assertDispatchedTimes(ConnectionStatusChanged::class, 2);
        Event::assertDispatched(ConnectionStatusChanged::class, fn(ConnectionStatusChanged $e) => $e->status === 'connected' && $e->previousStatus === null);
        Event::assertDispatched(ConnectionStatusChanged::class, fn(ConnectionStatusChanged $e) => $e->status === 'disconnected' && $e->reason === 'disconnect');
        Event::assertNotDispatched(ConnectionReconnectRequired::class);
    }

    public function test_the_test_event_is_acknowledged_and_fires_its_event(): void
    {
        $this->fakeRuntime();
        Event::fake();
        $this->deliver(json_encode([
            'schema_version' => 1,
            'delivery_id' => 'dlv_test',
            'event_id' => 'webhook.test:0190d3d0-2b10-7f00-9a11-5c6d7e8f9a0b',
            'event_type' => 'webhook.test',
            'occurred_at' => '2026-08-04T09:15:00.120Z',
            'app_id' => 'app_erp',
            'tenant_id' => 'tnt_1',
            'data' => ['test' => ['endpoint_key' => 'primary', 'requested_by' => 'developer']],
        ], JSON_THROW_ON_ERROR))->assertOk();

        Event::assertDispatched(WebhookTestReceived::class, fn(WebhookTestReceived $e) => $e->tenantId === 'tnt_1' && $e->endpointKey === 'primary');
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertNotDispatched(ConnectionStatusChanged::class);
    }

    public function test_dead_letter_maps_to_its_event(): void
    {
        $this->jobReads(1, 'dead_lettered');
        Event::fake();
        $this->deliver(self::body('dlv_2', 'dead_lettered'))->assertOk();
        Event::assertDispatched(SyncJobDeadLettered::class, fn(SyncJobDeadLettered $e) => $e->lastErrorCode === 'invoice_payload_conflict');
    }

    public function test_a_delayed_delivery_fires_the_current_state(): void
    {
        // The job was dead-lettered, then an operator resolved it; the
        // dead_lettered delivery arrives afterwards.
        $this->jobReads(1, 'manually_resolved');
        Event::fake();
        $this->deliver(self::body('dlv_6', 'dead_lettered'))->assertOk();
        Event::assertNotDispatched(SyncJobDeadLettered::class);
        Event::assertDispatched(SyncJobResolved::class);
    }

    public function test_bad_signature_is_401_and_fires_nothing(): void
    {
        Event::fake();
        $this->deliver(self::body(), secret: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')->assertStatus(401);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(SyncJobCompleted::class);
    }

    public function test_query_string_is_part_of_the_signed_target(): void
    {
        $this->jobReads(1);
        Event::fake();
        $this->deliver(self::body('dlv_3'), '/integration-runtime/webhooks?source=ir')->assertOk();
        Event::assertDispatched(SyncJobCompleted::class);
    }

    public function test_listener_failure_lets_the_runtime_redeliver(): void
    {
        $this->jobReads(2);
        Event::listen(SyncJobCompleted::class, function (): void {
            throw new \RuntimeException('database down');
        });
        $this->withoutExceptionHandling();
        try {
            $this->deliver(self::body('dlv_4'));
            self::fail('expected the listener failure to surface');
        } catch (\RuntimeException) {
        }
        Event::fake();
        $this->deliver(self::body('dlv_4'))->assertOk();
        Event::assertDispatched(SyncJobCompleted::class);
    }

    public function test_delivery_in_progress_is_not_acknowledged(): void
    {
        $this->jobReads(1);
        Event::fake();
        // A worker holds the claim (still running, or killed before finishing).
        Cache::add('integration-runtime:webhook-claim:dlv_5', 1, 120);
        $this->deliver(self::body('dlv_5'))->assertStatus(429);
        Event::assertNotDispatched(SyncJobCompleted::class);

        // Once the claim is gone the retry is processed, then deduped.
        Cache::forget('integration-runtime:webhook-claim:dlv_5');
        $this->deliver(self::body('dlv_5'))->assertOk();
        $this->deliver(self::body('dlv_5'))->assertOk();
        Event::assertDispatchedTimes(SyncJobCompleted::class, 1);
        self::assertFalse(Cache::has('integration-runtime:webhook-claim:dlv_5'));
    }
}
