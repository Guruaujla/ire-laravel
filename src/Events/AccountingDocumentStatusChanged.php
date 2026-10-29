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
 * provider_id, revision, status, currency, total_amount, balance. Treat the
 * event as a signal: re-read with accounting()->refresh() and store that
 * read, one read per document at a time (e.g. under a row lock).
 * Deliveries can repeat and arrive out of order, and `sequence` orders events
 * only among themselves, not against a write's own result. A `deleted`
 * status cannot be read back; it is verified and final, so store it as is.
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
