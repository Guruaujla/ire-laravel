<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

use Ezee\IntegrationRuntime\WebhookEvent;

/**
 * A document the runtime wrote changed in the accounting provider (an invoice
 * paid or voided elsewhere, a payment deleted, ...). Delivered only when the
 * webhook endpoint lists `accounting.document.status_changed`.
 *
 * `document` has the neutral refresh shape: document_type, erp_id,
 * provider_id, revision, status, currency, total_amount, balance. Ignore an
 * event whose `sequence` is lower than one already applied for that
 * document; deliveries can arrive out of order and more than once.
 */
final class AccountingDocumentStatusChanged
{
    /** @param array<string, mixed> $document */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $connectionId,
        public readonly ?int $sequence,
        public readonly array $document,
        public readonly WebhookEvent $event,
    ) {}
}
