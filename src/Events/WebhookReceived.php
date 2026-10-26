<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

use Ezee\IntegrationRuntime\WebhookEvent;

/** Every verified, first-seen delivery, including event types this package does not map. */
final class WebhookReceived
{
    public function __construct(public readonly WebhookEvent $event) {}
}
