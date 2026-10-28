---
name: quickbooks-integration-runtime
description: Use when writing or changing code in this Laravel app that syncs with an accounting provider (QuickBooks today) through the Integration Runtime (invoices, payments, refunds, credit memos, customers, items, mappings, connect flow, webhooks).
---

# Accounting via the Integration Runtime (Laravel)

This app does not call QuickBooks or any accounting provider directly. It calls
the Integration Runtime through `ezee/laravel-integration-runtime`. The runtime
holds the provider tokens and handles mappings, rate limits, duplicate
protection and tax verification. Code written against `accounting()` does not
change when the tenant's provider changes.

## Facts to rely on

- Operations: `accounting.customer.upsert`, `item.upsert`, `invoice.create`,
  `invoice.update`, `invoice.void`, `payment.create`, `refund.create`,
  `credit_memo.create`, `credit_memo.apply`. Use the methods on
  `IntegrationRuntime::accounting()`.
- Payload shapes, with one valid example per operation:
  [`accounting-operations.md`](accounting-operations.md) and
  [`accounting-operations.json`](accounting-operations.json) next to this file
  (also in `vendor/ezee/laravel-integration-runtime/docs/`).
- Payloads carry ERP ids (`erp_id`), ERP codes (tax code, payment method,
  account) and decimal-string money. Never provider ids or sync tokens. A
  missing mapping is `409 accounting.mapping_missing`: map it, then retry.
- Writes to an existing document send the `revision` and before-state the ERP
  stored from the last result. The runtime never fills them in.
- `IntegrationRuntime::quickbooks()` is deprecated. Moving existing code:
  [`accounting-migration.md`](accounting-migration.md).
- QuickBooks scope: CAD companies, tax-exclusive lines. Payment and Refund only
  record accounting transactions; they do not move money.

## Rules (never break these)

1. **One idempotency key per business event, stored before the first call.**
   Example: `"invoice:{$invoice->id}:create"`. Retries reuse the same key AND
   the same payload. Never add a timestamp, random value or attempt number.
2. **Persist the request, then submit.** Build with `Accounting::buildRequest(...)`,
   save it with the ERP record, `submit()` it, then save `job_id`. To retry,
   call `submit()` with the saved array.
3. **`409 idempotency_key_conflict` / `accounting.idempotency_conflict` means stop.**
   Never mint a new key to get past it.
4. **`409 accounting.revision_conflict` means the document changed.** Refresh it
   (`accounting()->refresh(...)`), show the difference to a person, and start a
   new business event. Never retry it blindly.
5. **`403 accounting.operation_disabled` means a release gate refused it.** Do
   not retry in a loop or try to enable anything.
6. **Provider-side changes** (an invoice paid or voided in the provider) arrive
   as `AccountingDocumentStatusChanged` when the webhook endpoint lists
   `accounting.document.status_changed`. Ignore a lower `sequence` than the
   one already applied.
7. **Results come from the job, not the 202.** Call
   `IntegrationRuntime::accounting()->status($tenantId, $jobId)` after
   `SyncJobCompleted` / `SyncJobDeadLettered` (webhooks) or from
   `IntegrationRuntime::track($receipt, $tenantId)`. Store `result.revision`
   and `result.lines`. Listeners must be idempotent on the job ID.
8. **A dead-lettered or unknown financial outcome is reconciled, not resubmitted.**
9. Branch on `RuntimeProblemException::decision()` or `problemCode`, never on message text.
10. Secrets stay in `.env` and server code. Never log the secret or full payloads.
11. Use the runtime `tenant_id` from `IntegrationRuntime::tenants()->registerOrResolveTenant(...)`,
    not your own workspace ID.

## Common code

```php
use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

$request = Accounting::buildRequest('accounting.invoice.create', $tenantId,
    (string) $invoice->id, "invoice:{$invoice->id}:create", $payload);
$invoice->update(['accounting_request' => $request]);    // persist first
$receipt = IntegrationRuntime::accounting()->submit($request);
$invoice->update(['accounting_job_id' => $receipt->jobId]);
IntegrationRuntime::track($receipt, $tenantId);

// In the SyncJobCompleted listener:
$status = IntegrationRuntime::accounting()->status($tenantId, $event->jobId);
$invoice->update(['accounting_revision' => $status['result']['revision']]);
```

Onboarding a connection: `accounting()->listRecords / matchRecords /
bindRecords` for customers, items and tax codes; `setMapping` (after an
exact-id or exact-name `referenceData` lookup) for payment methods and
accounts; `settings` for the sync start
date and record types.

Check setup with `php artisan integration-runtime:check --tenant=<tenant_id>`
and inspect a job with `php artisan integration-runtime:job <tenant_id> <job_id>`.
