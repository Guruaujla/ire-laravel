---
name: quickbooks-integration-runtime
description: Use when writing or changing code in this Laravel app that syncs with QuickBooks through the Integration Runtime (invoices, payments, refunds, credit memos, customers, items, connect flow, webhooks).
---

# QuickBooks via the Integration Runtime (Laravel)

This app does not call QuickBooks directly. It calls the Integration Runtime
through `ezee/laravel-integration-runtime`. The runtime holds the QuickBooks
tokens and handles rate limits, duplicate protection and tax verification.

## Facts to rely on

- Operations: `quickbooks.invoice.create`, `invoice.update_sparse`, `invoice.void`,
  `payment.create`, `refund.create`, `credit_memo.create`, `credit_memo.apply`,
  `customer.upsert`, `item.upsert`. Use the methods on
  `IntegrationRuntime::quickbooks()`; they fill recipe key, version, trigger and
  object type for you. Do not hand-write those.
- Payload shapes, with one valid example per operation:
  [`quickbooks-operations.md`](quickbooks-operations.md) and
  [`quickbooks-operations.json`](quickbooks-operations.json) next to this file
  (also in `vendor/ezee/laravel-integration-runtime/docs/`). Copy the shape and
  replace the IDs with the connected company's real references.
- Supported scope: CAD companies, tax-exclusive lines, existing `TaxCodeRef`.
  Omit `CurrencyRef` on invoices. Never send `TxnTaxDetail`.
- Payment and Refund only record accounting transactions. They do not move money.

## Rules (never break these)

1. **One idempotency key per business event, stored before the first call.**
   Example: `"erp_invoice_{$invoice->id}:create"`. Retries reuse the same key AND
   the same payload. Never add a timestamp, random value or attempt number.
2. **Persist the request, then submit.** Build with `QuickBooks::buildRequest(...)`,
   save it with the ERP record, `submit()` it, then save `job_id` and
   `polling_url`. To retry, call `submit()` with the saved array.
3. **`409 idempotency_key_conflict` means stop.** Something changed. Never mint a
   new key to get past it.
4. **`403 operation_not_enabled` / `tenant_operation_not_enabled` means a release
   gate refused it.** Do not retry in a loop or try to enable anything.
5. **Results come from the job, not the 202.** Listen for
   `SyncJobCompleted` / `SyncJobDeadLettered` (webhooks) and call
   `IntegrationRuntime::track($receipt, $tenantId)` as a polling fallback.
   Listeners must be idempotent on `$event->jobId`.
6. **A dead-lettered or unknown financial outcome is reconciled, not resubmitted.**
7. Branch on `RuntimeProblemException::decision()` or `problemCode`, never on message text.
8. Secrets stay in `.env` and server code. Never log the secret or full payloads.
9. Use only a connection whose `IntegrationRuntime::connections()->get(...)['status']`
   is `connected`. The redirect query string is a hint, not proof.
10. Use the runtime `tenant_id` from `IntegrationRuntime::tenants()->registerOrResolveTenant(...)`,
    not your own workspace ID.

## Common code

```php
use Ezee\IntegrationRuntime\QuickBooks;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

$request = QuickBooks::buildRequest('quickbooks.invoice.create', $tenantId, $connectionId,
    (string) $invoice->id, "erp_invoice_{$invoice->id}:create", $payload);
$invoice->update(['qb_request' => $request]);           // persist first
$receipt = IntegrationRuntime::quickbooks()->submit($request);
$invoice->update(['qb_job_id' => $receipt->jobId, 'qb_polling_url' => $receipt->pollingUrl]);
IntegrationRuntime::track($receipt, $tenantId);
```

Check setup with `php artisan integration-runtime:check --tenant=<tenant_id>`
and inspect a job with `php artisan integration-runtime:job <tenant_id> <job_id>`.
