<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Tests;

use Ezee\IntegrationRuntime\Laravel\Events\SyncJobCompleted;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobDeadLettered;
use Ezee\IntegrationRuntime\Laravel\Events\SyncJobResolved;
use Ezee\IntegrationRuntime\Laravel\Events\WebhookReceived;
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
