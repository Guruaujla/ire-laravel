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

For the ERP screens to build on top of this API (connection, settings, record
matching, code mappings, sync status), see
[accounting-erp-screens.md](accounting-erp-screens.md).

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
     `GET …/reference-data/{kind}/options` pages the provider records a code
     kind (`payment_method`, `deposit_account`, `refund_account`,
     `income_account`) can point at, for a mapping dropdown (`items`,
     `next_cursor`; inactive ones are included, offer only active ones; tax
     codes are listed by `records/tax_code`),
     `GET …/reference-data/{kind}?provider_ref=…` or `?name=…` looks up one
     by its exact id or name, and `PUT …/mappings/{kind}/{erp_code}` maps an
     ERP code to it.
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
| `idempotency_key` | Derive it once from the business event, store it, and reuse it unchanged on every retry. Up to 255 characters (the PHP SDK and Laravel package accept up to 128). |
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
  receivers refuse it, so upgrade before subscribing. Treat the event as a
  signal: re-read the document and store that read, one read per document at
  a time (for example under a lock) so a slower read cannot overwrite a newer
  one. Do not store `data.document` itself: deliveries repeat and arrive out
  of order, and `data.sequence` orders events only among themselves, not
  against a write's own result. The exception is `status` `deleted`: the
  document cannot be read back, the deletion is verified and final, so store
  it as is.

## Invoices created through quickbooks()

`PUT …/documents/invoice/{erp_id}/link` records the ERP line ids of an invoice
the runtime created through the native API, so update, refund and credit memo
can name its lines. Send every line, either with the `provider_line_id` from the
old result or exactly as it was created. Invoices created outside the runtime
are `accounting.document_unlinked`. See
[accounting-migration.md](accounting-migration.md).

## Errors

Branch on the problem `code`, or on `RuntimeProblemException::decision()` in
the PHP SDK and Laravel package (1.3.1 and later), never on the message.

| Code (HTTP) | Meaning | Do | `decision()` |
| --- | --- | --- | --- |
| `accounting.mapping_missing` (409) | An ERP customer, item or code has no usable mapping. `mapping_kind`, `mapping_erp_code` and `mapping_state` say what to map. | Map it, then resend the same request. | `fix_setup` |
| `accounting.record_type_disabled` (409) | The connection's settings exclude this record type. | Turn it on with `PUT …/settings` if it should sync, then resend the same request. | `fix_setup` |
| `accounting.document_unlinked` (409) | The referenced invoice (or the payment a refund returns) has no verified provider document on this connection. | Create it through the runtime, or link one created through `quickbooks()`, then resend. | `fix_setup` |
| `accounting.no_accounting_connection`, `accounting.multiple_accounting_connections` (409) | The tenant has no, or several, accounting connections. | Connect one, or disconnect the extra one; passing `connection_id` does not resolve several. | `fix_setup` |
| `accounting.connection_exists` (409, from `POST /v1/connections/connect-session`) | The customer already has an active connection to a different accounting provider; `connection_id` and `provider_key` name it. | Disconnect it first (`POST /v1/connections/{id}/disconnect`), then connect the new provider. Its documents stay in the old provider. | `fix_setup` |
| `accounting.revision_conflict` (409) | The document changed since your revision or before-state. | Refresh, reconcile with a person, send as a new business event with a new key. Never retry it blindly. | `review_and_resubmit` |
| `accounting.amount_exceeded` (409) | A refund over what was paid, or a returned or credited line over what remains. | Refresh the invoice and reduce the amount; new key. | `review_and_resubmit` |
| `accounting.tax_mismatch` (409) | The ERP's tax does not match the mapped tax codes or the provider's verification. | Recompute tax from the mapped codes or send per-rate `tax_lines`; new key. | `review_and_resubmit` |
| `accounting.document_exists` (409) | The invoice number already exists in the provider (voided ones count). Returned by the submit itself or as the job's error; `candidate_provider_id` names it when known. | Renumber in the ERP and send with a new key. `link` can adopt it only if the runtime created it through `quickbooks()` on this connection; an invoice entered in the provider cannot be linked. | `review_and_resubmit` |
| `accounting.period_closed` (409) | Dated on or before the provider's closing date. | Re-date it, or reopen the period in the provider, then send with a new key. | `review_and_resubmit` |
| `accounting.before_sync_start` (409) | Dated before the connection's `sync_start_date`. | Keep it in the ERP only, or enter it in the provider directly. | `do_not_sync` |
| `accounting.capability_unsupported` (422) | The provider does not support this operation or currency (QuickBooks: CAD only). | Read `GET /v1/accounting/providers` capabilities before offering the action. | `do_not_sync` |
| `accounting.operation_disabled` (403) | A release gate refused the operation. | Ask the operator; do not loop. | `ask_operator` |
| `accounting.idempotency_conflict` / `idempotency_key_conflict` (409) | Same key, different request, or the connection now points at another company. | Stop. Resend the original request unchanged; never mint a new key to get past it. | `stop_and_reconcile` |
| `accounting.operation_in_progress` (409) | A customer or item write for this ERP id is still pending at the provider. | Poll it to completion, then resend the same request. | `retry_same_request` |
| `accounting.link_conflict` (409) | The invoice is linked with a different line map, or its provider invoice is linked to another ERP id. | Send the recorded line map, or ask support to correct it. | `stop_and_reconcile` |
| `accounting.provider_unavailable` (503) | Provider not enabled for the app, or a provider read was not confirmed. | Retry the same request later; escalate if it persists. | `retry_same_request` |
| `accounting.rate_limited` (429) | Provider or app quota exhausted. | Wait for `Retry-After`, then resend the same request. | `retry_same_request` |
| `accounting.settings_conflict` (409) | The settings changed since your `expected_revision`. | `GET` the settings again, reapply the change and resend with the new revision. | `fix_request` |
| `accounting.mapping_conflict` (409) | The provider record is bound to another ERP id, ambiguous, or changed during verification. | Resolve the existing binding or choose another record. | `fix_request` |
| `accounting.reference_invalid` (409), `accounting.reference_not_found` (404) | The provider record is unusable for this kind, or does not exist. | Choose an active record of the right type. | `fix_request` |
| `accounting.unsupported_change` (409) | The change is not supported, e.g. deleting a company-bound customer or item mapping. | Rebind to the correct provider record instead. | `fix_request` |
| `accounting.mapping_not_found` (404) | No mapping for this kind and ERP code. | Create it with `PUT …/mappings/{kind}/{erp_code}`. | `fix_request` |
| `accounting.connection_not_found` (404) | The connection id is not this tenant's accounting connection. | Read it from `GET /v1/accounting/providers`. | `fix_request` |
| `accounting.operation_not_found` (404) | Unknown `job_id` for this tenant. | Use the `job_id` from the `202`. | `fix_request` |
| `accounting.invalid_input` (400) | An unknown or duplicate field or query parameter. | Send only documented neutral fields. | `fix_request` |
| `accounting.provider_refused` (native status) | Native admission refused it for a reason with no neutral code; `provider_detail` has the native code. | Read `provider_detail`; fix the request or escalate. | by HTTP status |

The enum is open: handle unknown codes as "stop and show to a person"
(`decision()` returns `stop_and_reconcile` for any unknown `accounting.*`
code, whatever its HTTP status).

## Retry rules

1. One `idempotency_key` per business event, stored before the first call.
2. Persist the request, then submit. A retry resends the same request.
3. A `5xx`, timeout or lost response: retry the same request. An exact retry
   returns the original job (`idempotent_replay: true`).
4. A dead-lettered or unknown financial outcome is reconciled, not resubmitted
   under a new key.
