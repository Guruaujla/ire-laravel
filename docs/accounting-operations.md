# Accounting operations reference

The provider-neutral accounting API. The ERP integrates once; QuickBooks is the
first provider and Xero the second (see [Xero](#xero)), and adding or swapping a
provider later is runtime work, not ERP work. Requests carry ERP ids, ERP codes and decimal-string money only. The
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
[accounting-erp-screens.md](accounting-erp-screens.md#erp-screens-for-accounting).

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
- QuickBooks and Xero: CAD home currency only; unmapped tax codes are refused.

## Getting the result

Poll `GET /v1/accounting/operations/{job_id}?tenant_id=…` (`Accounting::status`),
or listen for the `sync_job.completed` / `sync_job.dead_lettered` webhooks (same
`job_id`) and then read the status. A completed status has `result`:

```json
{ "provider_key": "quickbooks", "document_type": "invoice", "erp_id": "INV-1001",
  "provider_id": "101", "revision": "0", "provider_account_id": "1234567890",
  "currency": "CAD", "net_amount": "100.00", "total_tax": "13.00", "total_amount": "113.00",
  "tax_lines": [{ "tax_code": "HST", "component": "HST ON", "rate_percent": "13",
    "taxable_amount": "100.00", "tax_amount": "13.00" }],
  "lines": [{ "erp_line_id": "L1", "provider_line_id": "1" }], "provider_verified": true }
```

Store `revision`; the next write to that document sends it back. A payment or
credit application also returns the invoice's new `balance`.

Invoice create and update results also carry `tax_lines`: the tax the
provider stored, one entry per tax component of each ERP tax code, in the
same shape as `expected_totals.tax_lines`. Store `net_amount`, `total_tax`,
`total_amount` and `tax_lines` with `revision`, and send them back unchanged
as the next update's `before.totals` (or a void's, refund's or credit memo's
`before_totals`). Each entry names your ERP tax code and the provider's
component (QuickBooks: the tax rate's name; Xero: the tax rate component's
name). Xero stores tax per line, not per component, so for Xero each tax
code's stored tax is split across its components by their rates (largest
remainder); the parts always add up to Xero's stored tax. `tax_lines` is
left out when a provider rate is no longer mapped to an ERP tax code. The
field is additive: results stored before it existed do not have it.

### Updating a paid invoice

- **QuickBooks:** an invoice with payments can be updated when every
  invoice-level link is a Payment and the new total is at least the amount
  paid (total minus balance). The payments stay applied and the result's
  `balance` is the new total minus the amount paid; when that balance is
  `0.00` the result has no `status` (refresh the document to read it). A new
  total below the amount paid, or a link to anything other than a Payment
  (a deposit, an estimate, ...), is `409 accounting.unsupported_change`. All
  other update rules are unchanged.
  - A payment written through this API blocks updates of its invoice only
    while it is in flight or its outcome is uncertain. Once the payment is
    completed, or has failed, the invoice can be updated under the rule
    above. A payment that failed does not block other invoices either.
  - A credit memo applied in QuickBooks (in QuickBooks itself or through its
    API) is applied through a Payment, so the invoice shows it as a Payment
    link. QuickBooks lets such an invoice be edited, so the update is allowed
    and the applied credit counts as paid. A credit memo or refund written
    through this API still blocks updates of its invoice.
- **Xero:** Xero locks amounts on a paid, part-paid or credited invoice. Such
  an invoice takes only a `due_date` change and line `description` changes:
  send the same lines, quantities, prices, tax codes and `expected_totals` as
  the invoice already has. The runtime sends every line back with the values
  Xero holds (and its tracking) and only the new descriptions, then checks
  that Xero kept the descriptions and due date. Any change to a quantity, unit
  price, amount, tax code or total is `409 accounting.unsupported_change`;
  remove the payment in Xero first to change them.
- A void of a paid invoice is still refused by both providers.

Other reads:

- `GET …/documents/{invoice|payment}/{erp_id}?tenant_id=…` re-reads the
  provider: current `revision`, `status`, `total_amount`, `balance`. For an
  invoice it also returns `net_amount`, `total_tax` and `tax_lines` from that
  read (`tax_lines` is `null` when a rate on the invoice is not mapped to an
  ERP tax code), so you can backfill `before.totals` for invoices created
  before results carried `tax_lines`. For Xero this read makes one more Xero
  call (the tax rates). Whenever `tax_lines` is left out of a result or is
  `null` on a read, the runtime records why (`accounting.tax_lines.omitted`).
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
[accounting-migration.md](accounting-migration.md#moving-from-quickbooks-to-accounting).

## Xero

Xero is the second provider behind this API. The ERP sends the same requests
and reads the same results; only the limits below differ. **Every Xero write
ships disabled**: until an operator enables an operation, it is refused with
`403 accounting.operation_disabled`. A Xero refund cannot be enabled until a
two-step qualification probe exists. `GET /v1/accounting/providers` lists
Xero (with `enabled: false` until the app has a Xero provider configuration),
its capabilities, mapping kinds and per-operation limits (call costs
included).

### Connect

- A new self-serve app gets a Xero provider configuration automatically
  once Xero is in that environment's provider catalog. An app created before
  that, or by an operator in the console, gets it from an operator. Without it, a Xero connect is refused
  `403 authz.provider_not_enabled`.
- Start a Xero connection with the same signed
  `POST /v1/connections/connect-session` request as QuickBooks, with
  `provider_key: "xero"`. It returns an `authorize_url` for Xero's consent
  screen; the callback, reconnect and disconnect are the same flows. With the
  PHP SDK (1.4.0 or later), pass the provider as the fourth argument:
  `connections()->start($tenantId, $returnUrl, $state, 'xero')`. Earlier
  versions always send `quickbooks`; send the request yourself for Xero there.
- Xero uses a fixed scope set. Send no `scopes`, or exactly that set; any
  other set is refused `400 connect.scopes_not_permitted` and no session is
  created.
- `503 connect.provider_not_configured` means the runtime is not configured
  for Xero in this environment. Do not retry automatically; contact the
  runtime operator.
- The customer picks one Xero organisation on Xero's consent screen. The
  connection's `provider_account_id` is that organisation's Xero tenant id.
  A reconnect accepts only the connection's own organisation.
- The callback refuses the organisation and stores nothing when the consent
  selected several organisations (`reason=multiple_orgs_selected`), when a new
  connect added no organisation that is not already connected, or when the
  organisation's Xero connection is already in use by another live connection
  of this integration (both `reason=organisation_already_connected`), or when
  the organisation's base currency is not CAD (`reason=unsupported_currency`).
  The browser comes back to your `return_url` with `status=failed` and that
  `reason`.
- For `organisation_already_connected`, tell the customer: "This organisation
  is already connected through this integration. If it should be free,
  disconnect it from the other system first. Do not remove the app in Xero
  unless you know no other system uses it."

### Disconnect

`POST /v1/connections/{id}/disconnect` (`connections()->disconnect()` in the PHP
SDK 1.7.0+) accepts `provider_key: "xero"` with the connection's Xero tenant id
as `provider_account_id`. The runtime never
revokes a Xero token (one revoke would end every grant of that Xero user,
across organisations). It removes only this organisation's Xero connection.

- `204` is complete. The connection is `disconnected` either way.
- `204` with `X-IR-Provider-Cleanup: customer_action_required` means the
  Xero grant was already dead, so the runtime disconnected locally only and
  the organisation may still list the app. The Xero user who connected it
  can review it at https://apps.xero.com/connected; ask them to remove the
  app there only if no other system uses it.
- A plain `204` can also mean another live connection of this integration
  shares the same Xero connection (several connections may share one
  organisation, never one Xero connection); the runtime then leaves it in
  place on purpose, also when this connection's own grant was dead.
- Status events still undelivered when the connection becomes
  `disconnected` are not delivered: status delivery requires a connected
  connection, so pending events are retired and a queued delivery is dropped
  as stale. Changes made in Xero after the last status event are not seen
  either. Re-read the documents you care about (`GET /v1/accounting/...`)
  before you disconnect.
- `409 connection.recovery_pending` means writes to this connection are in
  doubt and an operator must settle them first. The body counts them
  (`invoice_writes`, `adjustment_writes`, `master_commands`); nothing was
  changed. Retry with the same idempotency key after the operator has
  finished.
- `503 oauth.disconnect_retryable` means retry with the same idempotency key
  and fresh HMAC values.

### Operations

All nine neutral operations translate to a native Xero write, and every one
is seeded disabled:

- `accounting.customer.upsert` → `xero.contact.upsert` (a Xero Contact)
- `accounting.item.upsert` → `xero.item.upsert` (a Xero Item)
- `accounting.invoice.create`, `.update`, `.void` → `xero.invoice.create`,
  `xero.invoice.update`, `xero.invoice.void` (ACCREC invoices, created
  AUTHORISED)
- `accounting.payment.create` → `xero.payment.create`
- `accounting.credit_memo.create` → `xero.credit_note.create`
- `accounting.credit_memo.apply` → `xero.credit_note.allocate`
- `accounting.refund.create` → `xero.refund.create`

The native Xero invoice, payment, credit note and refund operations are
reachable only through this API: `POST /v1/operations/execute` refuses them.

### Limits

- **CAD home currency only.** A non-CAD organisation is refused at connect.
  The closing date is the later of Xero's `PeriodLockDate` and
  `EndOfYearLockDate`, re-checked just before each invoice, payment, credit
  memo, credit application and refund write.
- **One organisation per connection.**
- **Daily call budget.** Xero allows 1,000 calls a day per organisation on
  the runtime's tier; the runtime counts them per organisation over a rolling
  25 UTC hours, so connections to the same organisation share them. Reads stop
  at 80% of the day, keeping the last 20% for writes. 25% of the day (250
  calls) is held for background status reads (webhook re-reads and the hourly
  reconcile), so interactive reads can stop at 55% when the background share
  is unused; reaching the background share only delays status events. A read
  refused by the budget answers `accounting.rate_limited`. Each connection's
  calls are paced under Xero's 60 a minute; that pacing is per connection, not
  per organisation.
- **No automatic re-send.** Until Xero confirms in writing how long it keeps
  an `Idempotency-Key`, the runtime's replay bound for Xero is 0: a write that
  may have reached Xero is never sent again by the runtime. It ends
  `accounting.write_uncertain` and an operator reconciles it.
- **Tax codes.** Every line needs a mapped tax code (a Xero sales tax rate);
  an unmapped one is refused with `accounting.mapping_missing`. Tax rates with
  a compound component are not supported.
- **No payment method.** Xero has no payment method mapping kind;
  `payment_method` is accepted and ignored.
- **Refunds are two writes.** A refund is a credit note for the refunded lines,
  then a cash refund of that credit note's whole total from the mapped
  `refund_account`. The credit note is never allocated to the invoice. When the
  credit note is written and the cash refund is not, the refund ends
  `accounting.refund_incomplete` (see the errors below). Credit memos and
  refunds share Xero's one credit note number space.

### Status events

For Xero connections the runtime sends `accounting.document.status_changed`
(see [Getting the result](#getting-the-result)) for documents the runtime
wrote, with
`document_type` `invoice`, `payment` or `credit_application`. A credit note is
never sent itself; the allocations the runtime made from it are sent as
`credit_application`. An
approved or paid invoice with a zero total has status `zero_value`. A payment
or credit application is `deleted` when Xero reads it as deleted, or when it is
missing from two reads at least 5 minutes apart (a payment also only once its
invoice's own read no longer lists it).

The runtime learns of changes two ways, both read-only and both disabled for
now:

- Xero webhooks for invoices and credit notes. The runtime re-reads each
  document by id before it sends an event.
- An hourly reconcile per connection that lists invoices, payments and credit
  notes changed since its last run, for changes no webhook reported. Its first
  run looks back to the oldest document the runtime wrote, at most 7 days.

## Errors

Branch on the problem `code`, or on `RuntimeProblemException::decision()` in
the PHP SDK and Laravel package (1.3.1 and later), never on the message.

| Code (HTTP) | Meaning | Do | `decision()` |
| --- | --- | --- | --- |
| `accounting.mapping_missing` (409) | An ERP customer, item or code has no usable mapping. `mapping_kind`, `mapping_erp_code` and `mapping_state` say what to map. | Map it, then resend the same request. | `fix_setup` |
| `accounting.record_type_disabled` (409) | The connection's settings exclude this record type. | Turn it on with `PUT …/settings` if it should sync, then resend the same request. | `fix_setup` |
| `accounting.document_unlinked` (409) | The referenced invoice (or the payment a refund returns) has no verified provider document on this connection. | Create it through the runtime, or link one created through `quickbooks()`, then resend. | `fix_setup` |
| `accounting.no_accounting_connection`, `accounting.multiple_accounting_connections` (409) | The tenant has no, or several, accounting connections. | Connect one, or disconnect the extra one (`connections()->disconnect()` in the PHP SDK 1.7.0+, with its stored `provider_account_id`); passing `connection_id` does not resolve several. | `fix_setup` |
| `accounting.connection_exists` (409, from `POST /v1/connections/connect-session`) | The customer already has an active connection to a different accounting provider; `connection_id` and `provider_key` name it. | Disconnect it first (`POST /v1/connections/{id}/disconnect`; `connections()->disconnect()` in the PHP SDK 1.7.0+), then connect the new provider. Its documents stay in the old provider. | `fix_setup` |
| `accounting.revision_conflict` (409) | The document changed since your revision or before-state. | Refresh, reconcile with a person, send as a new business event with a new key. Never retry it blindly. | `review_and_resubmit` |
| `accounting.amount_exceeded` (409) | A refund over what was paid, or a returned or credited line over what remains. | Refresh the invoice and reduce the amount; new key. | `review_and_resubmit` |
| `accounting.tax_mismatch` (409) | The ERP's tax does not match the mapped tax codes or the provider's verification. | Recompute tax from the mapped codes or send per-rate `tax_lines`; new key. | `review_and_resubmit` |
| `accounting.document_exists` (409) | The invoice number already exists in the provider (voided ones count), or a customer name or item code does (archived ones count). Returned by the submit itself or as the job's error; `candidate_provider_id` names it when known. | Renumber in the ERP and send with a new key. A Xero credit memo whose number Xero itself refused after the send keeps that answer for its `erp_id`: resend it renumbered under a new `erp_id`. `link` can adopt it only if the runtime created it through `quickbooks()` on this connection; an invoice entered in the provider cannot be linked. | `review_and_resubmit` |
| `accounting.period_closed` (409) | Dated on or before the provider's closing date. | Re-date it, or reopen the period in the provider, then send with a new key. A Xero refund whose credit note is already written ends `accounting.refund_incomplete` instead: reopen the period, then resume it with its frozen date. | `review_and_resubmit` |
| `accounting.before_sync_start` (409) | Dated before the connection's `sync_start_date`. | Keep it in the ERP only, or enter it in the provider directly. | `do_not_sync` |
| `accounting.capability_unsupported` (422) | The provider does not support this operation or currency (QuickBooks: CAD only). | Read `GET /v1/accounting/providers` capabilities before offering the action. | `do_not_sync` |
| `accounting.operation_disabled` (403) | A release gate refused the operation. | Check your app's **Operations** section in the developer Console; if it says Off for everyone, contact support. Do not loop. | `ask_operator` |
| `accounting.idempotency_conflict` / `idempotency_key_conflict` (409) | Same key, different request, or the connection now points at another company. | Stop. Resend the original request unchanged; never mint a new key to get past it. | `stop_and_reconcile` |
| `accounting.operation_in_progress` (409) | A customer or item write for this ERP id, or (Xero) another update or void, or another credit memo, credit application or refund, of this invoice, or (Xero) a resume of a refund whose job is still running, is still pending at the provider. | Poll it to completion, then resend the same request. | `retry_same_request` |
| `accounting.write_uncertain` (409, job error) | A customer, item or (Xero) invoice, payment, credit memo, credit application or refund credit note write may have reached the provider but was not confirmed, or the invoice the provider holds differs from what was sent. The runtime never re-sends it. A customer or item ERP id answers `accounting.operation_in_progress` until an operator reconciles it; an invoice stays with its reservation for the operator. | Do not resend, and do not resend the invoice under a new key. Check the document in the provider and ask the operator to reconcile. | `stop_and_reconcile` |
| `accounting.refund_incomplete` (409, job error) | A Xero refund is two writes: a credit note, then its cash refund. The credit note was written and verified; the cash refund was proved not written. `candidate_provider_id` names the open credit note, and the refund keeps its budget against the invoice and payment. | When the cash refund was refused before it was sent (no step-2 row, or one with no provider-call clock; the message says `was not written`): fix the cause in Xero, then resume with the same `erp_id`, a new key and the same credit-note part (`refund_account` and `reference` may change): only the cash refund is sent. A cause of `xero.period_closed` (a lock date moved after the credit note) keeps the refund's frozen `date`: reopen the period in Xero, then resume. When Xero refused the cash refund after it was sent (the message says `was refused by Xero`), it is never re-sent for that `erp_id` and a resume sends nothing: ask the operator. Or ask the operator to reconcile it. Never resubmit the refund under a new `erp_id`. A refund credit note that did not verify answers `accounting.write_uncertain` instead, and is resumed only after the operator reconciles it. | `stop_and_reconcile` |
| `accounting.link_conflict` (409) | The invoice is linked with a different line map, or its provider invoice is linked to another ERP id. | Send the recorded line map, or ask support to correct it. | `stop_and_reconcile` |
| `accounting.provider_unavailable` (503) | Provider not enabled for the app, or a provider read was not confirmed. | Retry the same request later; escalate if it persists. | `retry_same_request` |
| `accounting.rate_limited` (429) | Provider or app quota exhausted. | Wait for `Retry-After`, then resend the same request. | `retry_same_request` |
| `accounting.snapshot_refresh_too_soon` (409), `accounting.snapshot_conflict` (409), `accounting.snapshot_capacity` (503) | A new record snapshot (refresh, or restart after a failure) inside 15 minutes of the last one (`snapshot_id` names it), a lost start race, or snapshot storage is full. | Use the named snapshot, or wait for `Retry-After` and resend the same request. | `retry_same_request` |
| `accounting.snapshot_not_enabled` (403), `accounting.snapshot_refused` (403) | Record snapshots are not enabled for this app or tenant, or the snapshot job was not admitted (`provider_detail` says why). | Use the paged records list and match routes; contact support. Do not loop. | `ask_operator` |
| `accounting.snapshot_unsupported` (422), `accounting.snapshot_not_found` (404) | Snapshots are not available for this provider or record type, or none is ready (a `source: snapshot` match or a `q` search). | Use the paged records list or `source: live`, or start a snapshot with `POST …/snapshots`. | `fix_request` |
| `accounting.snapshot_not_ready` (409) | A match or list asked for a snapshot (or `auto`) while its pull is still running; `snapshot_id` names it. | Wait for the snapshot job's `sync_job.completed` webhook or `Retry-After`, then resend; or use `source: live`. | `retry_same_request` |
| `accounting.match_too_many` (422) | A live match was sent more than 100 records (`max_records`). | Send at most 100 per live call, or match against a ready snapshot (1,000 per call). | `fix_request` |
| `accounting.bind_job_in_progress` (409) | A bind job is already running on this connection; `job_id` names it when known. | Wait for its `sync_job.completed` or `sync_job.dead_lettered` webhook, then send the next job. | `retry_same_request` |
| `accounting.bind_job_not_enabled` (403), `accounting.bind_job_refused` | Bind jobs are not enabled for this app or tenant, or the bind job was not admitted (`provider_detail` says why). | Use `POST …/bind` (25 rows per call); contact support. Do not loop. | `ask_operator` |
| `accounting.bind_job_unsupported` (422), `accounting.bind_job_too_large` (413), `accounting.bind_job_not_found` (404) | Bind jobs cover customers and items only, take at most 5,000 rows, and keep results 7 days after they end. | Bind other types with `POST …/bind`, split larger sets, or check the `job_id`. | `fix_request` |
| `accounting.settings_conflict` (409) | The settings changed since your `expected_revision`. | `GET` the settings again, reapply the change and resend with the new revision. | `fix_request` |
| `accounting.mapping_conflict` (409) | The provider record is bound to another ERP id, the ERP id is already bound to a different record (`bound_provider_ref` names it), or the record is ambiguous or changed during verification. A binding of the ERP id made before the last reconnect does not count. | Resolve the existing binding or choose another record. When `bound_provider_ref` is present, keep that binding or bind to it. | `fix_request` |
| `accounting.reference_invalid` (409), `accounting.reference_not_found` (404) | The provider record is unusable for this kind, or does not exist. | Choose an active record of the right type. | `fix_request` |
| `accounting.unsupported_change` (409) | The change is not supported, e.g. deleting a company-bound customer or item mapping, or an update of a paid invoice the provider does not allow (see "Updating a paid invoice"). | Rebind to the correct provider record instead; for a paid invoice, change only what the provider allows, or remove the payment in the provider first. | `fix_request` |
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
5. A Xero refund is never resubmitted under a new `erp_id`. A refund ended
   `accounting.refund_incomplete` whose cash refund was not sent is resumed
   with the same `erp_id`, a new key and the same credit-note part; one whose
   cash refund Xero refused after the send is never re-sent, so it goes to the
   operator. A generic sync-job replay of either is refused
   `refund.replay_requires_continue`.
