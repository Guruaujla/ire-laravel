<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

use Ezee\IntegrationRuntime\WebhookEvent;

/**
 * A meeting's recordings or transcripts are ready (`meeting.artifacts_ready`).
 * Delivered only when the webhook endpoint lists the event (it is opt-in).
 *
 * At most two per meeting: `stage` `available` when a file is first ready and
 * more may follow, and `final` when the answer stops changing (`status`
 * available or none; `reason` says why none). A meeting already final when it
 * is first checked gets only `final`.
 *
 * `recordings` and `transcripts` are links only (each with `view_url`); who can
 * open them follows the organizer's own sharing rules at the provider. The
 * delivery can repeat or arrive out of order: read
 * `IntegrationRuntime::meetings()->artifacts()` when you need the current answer.
 */
class MeetingArtifactsReady
{
    /**
     * @param list<array<string, mixed>> $recordings
     * @param list<array<string, mixed>> $transcripts
     */
    final public function __construct(
        public readonly string $tenantId,
        public readonly string $connectionId,
        public readonly string $meetingRef,
        public readonly ?string $externalRef,
        public readonly ?string $providerKey,
        public readonly ?string $stage,
        public readonly ?string $status,
        public readonly ?string $reason,
        public readonly bool $final,
        public readonly array $recordings,
        public readonly array $transcripts,
        public readonly WebhookEvent $event,
    ) {}

    /**
     * @internal Built by the webhook controller from a verified delivery.
     *
     * Reads `data.meeting` from the verified body, so it works with every SDK
     * version that accepts the event type.
     */
    public static function fromWebhook(WebhookEvent $event): static
    {
        $data = $event->payload['data'] ?? null;
        $meeting = is_array($data) && is_array($data['meeting'] ?? null) ? $data['meeting'] : [];
        $string = static fn(string $key): ?string => isset($meeting[$key]) && is_string($meeting[$key]) ? $meeting[$key] : null;
        $list = static function (string $key) use ($meeting): array {
            $items = $meeting[$key] ?? null;
            if (! is_array($items)) {
                return [];
            }
            $out = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        };

        return new static(
            $event->tenantId,
            $string('connection_id') ?? '',
            $string('meeting_ref') ?? '',
            $string('external_ref'),
            $string('provider_key'),
            $string('stage'),
            $string('status'),
            $string('reason'),
            ($meeting['final'] ?? false) === true,
            $list('recordings'),
            $list('transcripts'),
            $event,
        );
    }
}
