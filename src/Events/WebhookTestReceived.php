<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

use Ezee\IntegrationRuntime\WebhookEvent;

/**
 * A `webhook.test` delivery an app owner sent from the Console. The
 * controller has already verified it and answers 200; nothing else needs to
 * happen. Listen to it to confirm the receiver works end to end.
 */
final class WebhookTestReceived
{
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $endpointKey,
        public readonly WebhookEvent $event,
    ) {}
}
