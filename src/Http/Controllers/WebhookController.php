<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Http\Controllers;

use Ezee\IntegrationRuntime\Exception\WebhookVerificationException;
use Ezee\IntegrationRuntime\Jobs;
use Ezee\IntegrationRuntime\Laravel\Events\Factory;
use Ezee\IntegrationRuntime\Laravel\Events\WebhookReceived;
use Ezee\IntegrationRuntime\WebhookVerifier;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Receives `sync_job.*` deliveries: verify first, dedupe on delivery_id,
 * fire events, answer 2xx fast. Heavy listener work belongs on a queue.
 */
final class WebhookController
{
    public function __invoke(
        Request $request,
        WebhookVerifier $verifier,
        Jobs $jobs,
        Dispatcher $events,
        CacheFactory $cache,
        Config $config,
        LoggerInterface $log,
    ): Response {
        // The signature covers the raw request target and raw body bytes.
        // getRequestUri() is REQUEST_URI as received, not a decoded copy.
        try {
            $event = $verifier->verify(
                $request->getMethod(),
                $request->getRequestUri(),
                $request->headers->all(),
                $request->getContent(),
            );
        } catch (WebhookVerificationException $e) {
            $log->warning('integration_runtime.webhook_rejected', ['reason' => $e->reason]);

            return new Response('', 401);
        }

        $store = $cache->store(self::stringOrNull($config->get('integration-runtime.webhooks.cache_store')));
        $ttl = (int) $config->get('integration-runtime.webhooks.dedupe_ttl_seconds', 604800);
        $claimTtl = (int) $config->get('integration-runtime.webhooks.claim_ttl_seconds', 120);
        $done = 'integration-runtime:webhook:' . $event->deliveryId;
        $claim = 'integration-runtime:webhook-claim:' . $event->deliveryId;

        // Acknowledge only work that finished. A delivery still being handled
        // (or whose worker died mid-way) holds a short claim and gets a 429,
        // which the runtime retries; the claim expires if the worker is gone.
        if ($store->has($done)) {
            return new Response('', 200);
        }
        if (! $store->add($claim, 1, $claimTtl)) {
            return new Response('', 429);
        }

        try {
            $events->dispatch(new WebhookReceived($event));
            $jobId = $event->jobId();
            $status = $event->job['status'] ?? null;
            if ($jobId !== null && is_string($status)) {
                // The webhook snapshot carries no `result`, which is where the
                // QuickBooks ID is published: read the job for a completion.
                $snapshot = $event->job;
                if ($status === 'completed') {
                    $snapshot = $jobs->find($event->tenantId, $jobId)->raw;
                    $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : $status;
                }
                $finished = Factory::forStatus($event->tenantId, $jobId, $status, 'webhook', $snapshot);
                if ($finished !== null) {
                    $events->dispatch($finished);
                }
            }
        } catch (\Throwable $e) {
            // Let the runtime retry this delivery rather than lose it.
            $store->forget($claim);
            throw $e;
        }

        $store->put($done, 1, $ttl);
        $store->forget($claim);

        return new Response('', 200);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
