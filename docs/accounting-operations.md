# Accounting operations reference

The provider-neutral accounting API. The ERP integrates once; QuickBooks is the
first provider, and adding or swapping a provider later is runtime work, not
ERP work. Requests carry ERP ids, ERP codes and decimal-string money only. The
runtime resolves the tenant's accounting connection, maps ERP ids and codes to
the provider's records, and admits the provider's native operation with every
native guard (release gates, duplicate protection, tax verification, rate
limits, read-back).

Every request below is also in machine-readable form in
[accounting-operations.json](accounting-operations.json). That file is checked
in CI against the runtime's own request schema, so a request copied from it has
the right shape. The ids and amounts in it are made up.

A valid request does not mean the operation is enabled. A write refused by a
release gate returns `403 accounting.operation_disabled`; do not loop on it.

## Before any write

1. Sign every `/v1` request. Use an SDK: PHP (`Accounting`) or the Laravel
   package (`IntegrationRuntime::accounting()`).
2. Register or resolve the runtime `tenant_id` for your workspace
   (`POST /v1/tenants`, `GET /v1/tenants/by-external-id/{external_tenant_id}`).
3. Connect the provider (`POST /v1/connections/connect-session`). Then
   `GET /v1/accounting/providers?tenant_id=…` returns the tenant's accounting
   `connection` and each provider's capabilities and mapping kinds.
4. Onboard master data:
   - Customers, items and tax codes: `GET …/records/{entity}` lists provider
     records, `POST …/records/{entity}/match` proposes matches by email, SKU or
     name, `POST …/records/{entity}/bind` binds ERP ids to provider records.
     Or create customers and items with the upsert operations.
   - Codes (tax codes, payment methods, deposit/refund/income accounts):
     `GET …/reference-data/{kind}?provider_ref=…` or `?name=…` looks up one
     provider record by its exact id or name (exactly one of the two is
     required; it is not a listing), and `PUT …/mappings/{kind}/{erp_code}`
     maps an ERP code to it.
5. Check `GET …/settings`: a write dated before `sync_start_date` is refused
   with `accounting.before_sync_start`, and a write of a disabled record type
   with `accounting.record_type_disabled`.

`…` is `/v1/accounting/connections/{connection_id}`. A missing mapping is
refused with `409 accounting.mapping_missing`, naming the kind and code.

## The envelope

Every write is `POST /v1/accounting/operations`:

| Field | Value |
| --- | --- |
| `tenant_id` | Runtime tenant ID. |
| `connection_id` | Optional. Without it the tenant's one active accounting connection is used. |
| `operation` | One of the operations below. |
| `erp_id` | Your ERP document id for this operation (invoice id, payment id, refund id, ...). |
| `idempotency_key` | Derive it once from the business event, store it, and reuse it unchanged on every retry. Max 128 characters. |
| `payload` | Operation-specific; see the examples. |

The `202` returns `job_id` and `polling_url`. Persist both with the ERP
document before anything else.

## Operations

| Operation | `erp_id` | Payload |
| --- | --- | --- |
| `accounting.customer.upsert` | ERP customer id | `customer` {name, email?, currency} |
| `accounting.item.upsert` | ERP item id | `item` {name, type `service`, income_account (ERP code), unit_price, description?} |
| `accounting.invoice.create` | ERP invoice id | `invoice` {number?, issue_date, due_date?, currency, customer.erp_id, lines}, `expected_totals` |
| `accounting.invoice.update` | ERP invoice id | `change_id`, the full new `invoice` (same customer, issue date, items, tax codes and line ids), `expected_totals`, `before` {expected_revision, totals} |
| `accounting.invoice.void` | ERP invoice id | `void_id`, `invoice` {issue_date, currency, customer, expected_revision, before_totals} |
| `accounting.payment.create` | ERP payment id | `payment` {date, amount, currency, customer, payment_method, deposit_account, reference?}, `invoice` {erp_id, expected_revision, expected_balance} |
| `accounting.refund.create` | ERP refund id | `refund` {number, date, lines with `original_erp_line_id`, payment_method, refund_account}, `expected_totals`, `original_invoice` {erp_id, before_totals}, `original_payment.erp_id` |
| `accounting.credit_memo.create` | ERP credit memo id | `credit_memo` {number, date, lines with `original_erp_line_id`}, `expected_totals`, `original_invoice` {erp_id, before_totals} |
| `accounting.credit_memo.apply` | ERP application id | `application` {date, amount, currency, customer}, `credit_memo.erp_id`, `invoice` {erp_id, before_totals} |

Rules that apply to all of them:

- Money is a decimal string with at most two decimals (`"113.00"`). Lines are
  tax-exclusive; `expected_totals` is what the ERP computed and must match the
  provider's verified totals.
- Lines carry `erp_line_id`. The result maps each to a `provider_line_id`;
  refunds and credit memos name the original line by `original_erp_line_id`.
- Refund and credit memo `erp_id`s use only letters, digits and `_ . : -`.
- Writes to an existing document carry the ERP's own last `revision` and
  before-state. The runtime never fills them in. A mismatch is `409
  accounting.revision_conflict`: refresh the document, show the difference to
  a person, and build a new business event. Never retry it blindly.
- The provider's closing date is checked on every write; a write dated on or
  before it is `409 accounting.period_closed`.
- QuickBooks: CAD home currency only; unmapped tax codes are refused.

## Getting the result

Poll `GET /v1/accounting/operations/{job_id}?tenant_id=…` (`Accounting::status`),
or listen for the `sync_job.completed` / `sync_job.dead_lettered` webhooks (same
`job_id`) and then read the status. A completed status has `result`:

```json
{ "provider_key": "quickbooks", "document_type": "invoice", "erp_id": "INV-1001",
  "provider_id": "101", "revision": "0", "provider_account_id": "1234567890",
  "currency": "CAD", "net_amount": "100.00", "total_tax": "13.00", "total_amount": "113.00",
  "lines": [{ "erp_line_id": "L1", "provider_line_id": "1" }], "provider_verified": true }
```

Store `revision`; the next write to that document sends it back. A payment or
credit application also returns the invoice's new `balance`.

Other reads:

- `GET …/documents/{invoice|payment}/{erp_id}?tenant_id=…` re-reads the
  provider: current `revision`, `status`, `total_amount`, `balance`.
- `accounting.document.status_changed` webhook (opt in by listing it in the
  endpoint's `event_types`): `data.document` has the same shape, for documents
  the runtime wrote. The Laravel package (1.3.0 and later) verifies it and
  fires `AccountingDocumentStatusChanged`; the PHP SDK's `WebhookVerifier`
  returns it with `document`, `connectionId()` and `sequence()`. Older SDK
  receivers refuse it, so upgrade before subscribing. Ignore an event whose `data.sequence` is lower than the
  last one you applied for that document.

## Invoices created through quickbooks()

`PUT …/documents/invoice/{erp_id}/link` records the ERP line ids of an invoice
the runtime created through the native API, so update, refund and credit memo
can name its lines. Send every line, either with the `provider_line_id` from the
old result or exactly as it was created. Invoices created outside the runtime
are `accounting.document_unlinked`. See
[accounting-migration.md](accounting-migration.md).

## Errors

| Code | Meaning | Do |
| --- | --- | --- |
| `accounting.mapping_missing` (409) | An ERP code or record has no provider mapping. | Map it, then retry the same request. |
| `accounting.revision_conflict` (409) | The document changed since your revision. | Refresh, reconcile with a person, new business event. |
| `accounting.period_closed` (409) | Dated in a closed provider period. | Fix the date in the ERP. |
| `accounting.record_type_disabled`, `accounting.before_sync_start` (409) | The connection's settings exclude this write. | Change the settings or the date. |
| `accounting.document_exists` (409) | The invoice number already exists in the provider. | Link or renumber in the ERP. |
| `accounting.operation_disabled` (403) | A release gate refused the operation. | Ask the operator; do not loop. |
| `accounting.document_unlinked` (409) | The referenced document was not written by the runtime on this connection. | Link it, or handle it outside the runtime. |
| `accounting.tax_mismatch` (409) | Provider tax differs from `expected_totals`. | Fix the ERP tax setup or mapping. |
| `accounting.idempotency_conflict` / `idempotency_key_conflict` (409) | Same key, different request. | Stop. Never mint a new key to get past it. |
| `accounting.provider_unavailable` (503) | Provider unreachable or unconfirmed. | Retry the same request later. |
| `accounting.no_accounting_connection`, `accounting.multiple_accounting_connections` (409) | The tenant has no, or several, accounting connections. | Connect one, or disconnect the extra one; passing `connection_id` does not resolve several. |

The enum is open: handle unknown codes as "stop and show to a person".

## Retry rules

1. One `idempotency_key` per business event, stored before the first call.
2. Persist the request, then submit. A retry resends the same request.
3. A `5xx`, timeout or lost response: retry the same request. An exact retry
   returns the original job (`idempotent_replay: true`).
4. A dead-lettered or unknown financial outcome is reconciled, not resubmitted
   under a new key.
