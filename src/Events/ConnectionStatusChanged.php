<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

use Ezee\IntegrationRuntime\WebhookEvent;

/**
 * A connection's status changed: `connection.connected`,
 * `connection.reconnect_required` or `connection.disconnected`. Delivered only
 * when the webhook endpoint lists those events (they are opt-in).
 *
 * Fired for every connection event. A `connection.reconnect_required`
 * delivery also fires {@see ConnectionReconnectRequired}, so listen to one or
 * the other, not both, for the same work.
 *
 * Treat it as a signal: the delivery can repeat or arrive out of order, so
 * read the connection (`IntegrationRuntime::connections()->get()`) before you
 * act on it. `status` is connected, reconnect_required or disconnected;
 * `previousStatus` is null for the first connect; `reason` is invalid_grant,
 * refresh_token_expired, disconnect or null.
 */
class ConnectionStatusChanged
{
    final public function __construct(
        public readonly string $tenantId,
        public readonly string $connectionId,
        public readonly ?string $providerKey,
        public readonly ?string $status,
        public readonly ?string $previousStatus,
        public readonly ?string $reason,
        public readonly WebhookEvent $event,
    ) {}

    /**
     * @internal Built by the webhook controller from a verified delivery.
     *
     * Reads `data.connection` from the verified body, so it works with every
     * SDK version that accepts the event type.
     */
    public static function fromWebhook(WebhookEvent $event): static
    {
        $data = $event->payload['data'] ?? null;
        $connection = is_array($data) && is_array($data['connection'] ?? null) ? $data['connection'] : [];
        $string = static fn(string $key): ?string => isset($connection[$key]) && is_string($connection[$key]) ? $connection[$key] : null;

        return new static(
            $event->tenantId,
            $string('id') ?? '',
            $string('provider_key'),
            $string('status'),
            $string('previous_status'),
            $string('reason'),
            $event,
        );
    }
}
