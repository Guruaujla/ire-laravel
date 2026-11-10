# QuickBooks operations reference

> New ERP accounting code should use the provider-neutral API in
> [accounting-operations.md](accounting-operations.md); the SDK's `quickbooks()`
> helper for these native recipes is deprecated since 1.3.0. Moving existing
> code: [accounting-migration.md](accounting-migration.md).

> **CAD only.** QuickBooks Invoice, refund and credit memo writes need a company with
> **CAD** home currency, **multicurrency off** and **sales tax on** (see
> [Supported scope](#supported-scope)); payments have their own checks. Other companies
> connect and read, but those writes are refused before QuickBooks writes anything. The
> developer Console's **Connections** list shows each QuickBooks connection's **Writes**
> badge, so you can see this before the first write.

One page per question an ERP developer (or their coding agent) asks: *which
request do I send, with which identity, and what do I do with the answer?*
Every request below is also in machine-readable form in
[quickbooks-operations.json](quickbooks-operations.json). That file is checked
in CI against the runtime's own admission and payload validators, so a request
copied from it has the right shape. The IDs, rates and amounts in it are made
up. Use the connected company's real references.

A valid request shape does not mean the operation is enabled. Financial writes
also need app-wide release qualification and global enablement. Explicit tenant
Kill/Disarm still refuses them. A refused write returns `403
operation_not_enabled` or `tenant_operation_not_enabled`; do not loop on it.

## Before any write

1. Sign every `/v1` request (see [Authentication](index.md#authentication-hmac-signed-requests)).
   Use an SDK: TypeScript, Python, PHP, or the Laravel package.
2. Register or resolve the runtime `tenant_id` for your workspace
   (`POST /v1/tenants`, `GET /v1/tenants/by-external-id/{external_tenant_id}`).
3. Connect QuickBooks (`POST /v1/connections/connect-session`, then
   `GET /v1/connections/{id}`). Use only a connection whose status is `connected`.
4. Resolve native references for that company. Do not guess them:
   `GET /v1/connections/{id}/quickbooks/master/{Customer|Item|TaxCode|TaxRate}?tenant_id=…&name=…`
   or `…&external_id=…`. Alternatively, create Customers and Items with the
   upsert operations below.

## Supported scope

| Constraint | Rule |
| --- | --- |
| Currency | CAD home currency, multicurrency off. Other companies fail closed with `financial_write_currency_unsupported`. Omit `CurrencyRef` on Invoices. |
| Tax | `GlobalTaxCalculation: "TaxExcluded"`, one `tax_declarations` entry per line, existing `TaxCodeRef`. Unmapped tax codes are refused. Never send `TxnTaxDetail`. |
| App | QuickBooks recipes are admitted for any active app whose product kind is ERP / accounting (including `app_erp`). Other product kinds get `operation_app_not_permitted`. |
| Money movement | None. Payment and Refund record accounting transactions; they never charge or refund a card. |

## The envelope

Every operation is a `POST /v1/operations/execute` with this envelope:

| Field | Value |
| --- | --- |
| `tenant_id` | Runtime tenant ID (not your ERP's customer ID). |
| `provider_key` | `quickbooks` |
| `connection_id` | The tenant's connected company. |
| `recipe_key`, `recipe_version`, `trigger_type`, `object_type` | Fixed per operation; see the table below. |
| `internal_object_id` | Your stable ERP document ID for this business event. |
| `idempotency_key` | **Required for every financial write.** Derive it once from the business event (for example `erp_invoice_42:create`), store it, and reuse it unchanged on every retry. Max 128 characters. |
| `payload` | Operation-specific; see the examples. |

| Operation | `recipe_key` | ver | `trigger_type` | `object_type` |
| --- | --- | --- | --- | --- |
| `quickbooks.invoice.create` | `erp.quickbooks.invoice.created` | 5 | `erp.invoice.created` | `invoice` |
| `quickbooks.invoice.update_sparse` | `erp.quickbooks.invoice.updated` | 1 | `erp.invoice.updated` | `invoice_update` |
| `quickbooks.invoice.void` | `erp.quickbooks.invoice.voided` | 1 | `erp.invoice.voided` | `invoice_void` |
| `quickbooks.payment.create` | `erp.quickbooks.payment.created` | 2 | `erp.payment.created` | `payment` |
| `quickbooks.refund.create` | `erp.quickbooks.refund.created` | 3 | `erp.refund.created` | `refund_receipt` |
| `quickbooks.credit_memo.create` | `erp.quickbooks.credit_memo.created` | 1 | `erp.credit_memo.created` | `credit_memo` |
| `quickbooks.credit_memo.apply` | `erp.quickbooks.credit_memo.applied` | 1 | `erp.credit_memo.applied` | `credit_application` |
| `quickbooks.customer.upsert` | `erp.quickbooks.customer.sync` | 1 | `erp.customer.sync` | `master_customer_command` |
| `quickbooks.item.upsert` | `erp.quickbooks.item.sync` | 1 | `erp.item.sync` | `master_item_command` |

Invoice create uses version 5: same payload and guards as version 4, and it
also publishes the verified QuickBooks Invoice ID in the job `result`. Version 4
publishes no result, so a v4 job never tells you the Invoice ID.

A successful admission returns `202 { job_id, polling_url, status: "queued" }`.
Store both. Never change a recipe version, payload or key for a job that has
already been accepted.

## Operations

### Invoice create

`payload.invoice` uses native `CustomerRef`, `ItemRef` and `TaxCodeRef`. Send no
`Id`, `SyncToken` or `sparse`. `payload.invoice_verification` holds the
accounting-approved `net_amount`, `total_tax`, `total_amount` and per-rate
`tax_lines`. The runtime refuses the write if QuickBooks computes different tax.

### Invoice update (sparse)

For an open, unpaid Invoice with no payment or credit activity. Send
`original_invoice` with the evidence approved at creation, and `invoice` with
`Id`, the current `SyncToken`, `sparse: true` and every existing line `Id`,
`ItemRef` and `TaxCodeRef`, each exactly once. `invoice_verification` holds the
new totals. Use one `idempotency_key` and `internal_object_id` per ERP change.

### Invoice void

`invoice_void` contains only `Id` and the current `SyncToken`, plus
`original_invoice`. The Invoice must be unpaid and unlinked. A stale SyncToken
is a conflict: re-read the Invoice and ask the user. Never refetch the token and
void automatically.

### Payment

`payment` has `CustomerRef`, positive `TotalAmt`, `CurrencyRef: CAD`,
`DepositToAccountRef`, `PaymentMethodRef` and `TxnDate` (YYYY-MM-DD).
`linked_invoice` names one already-linked Invoice with its company, `external_id`,
current `sync_token` and `expected_balance`. Overpayment is refused.

### Refund

`refund` is a RefundReceipt with `DocNumber` (the stable ERP return ID, max 21
characters), `DepositToAccountRef` (an active CAD Bank or Other Current Asset
account) and `PaymentMethodRef`. Also send `original_invoice`, the positive-cash
`original_payment.external_id`, `source_return_id`, `adjustment_verification`, and one
`allocations` entry per line naming the original Invoice line.

### Credit memo create and apply

Create uses the same shape as Refund under `credit_memo`, without the account,
method and `original_payment`. Apply sends `credit_application` with
`TotalAmt: 0` and exactly two equal lines: one linked to the original Invoice
and one to the CreditMemo.

### Customer and Item upsert

`payload.master` is a command: `command_id` (equal to `internal_object_id`),
`erp_id`, `action` (`create` or `update`; updates also need
`expected_external_id` and `expected_sync_token`), `expected_company_id`,
`entity_type`, and `record`. Use the key format
`master:{company}:{Customer|Item}:{command_id}`. Read the stable mapping with
`GET /v1/connections/{id}/quickbooks/master/mappings/{entity}/{erp_id}?tenant_id=…`.

## Getting the result

You can get the result two ways. Use both: webhooks are faster, and polling is
the source of truth.

- **Poll** the returned `polling_url` exactly as given, with a freshly signed GET.
  `completed` is success. The QuickBooks ID is in `result`: `result.external_id`
  (Invoice create/update/void, refund, credit memos), `result.payment.id`
  (Payment) or `result.record.external_id` (Customer/Item).
  `external_object_id` is usually null; do not rely on it.
  `failed`, `dead_lettered`, `cancelled` and `manually_resolved` are terminal.
  `queued` and `processing` mean keep polling the same URL.
- **Webhooks**: the runtime POSTs signed `sync_job.completed`,
  `sync_job.dead_lettered` and `sync_job.resolved` events to an endpoint you
  add on your app page (**Webhooks**; its Name is the key ID). Verify the signature over the raw body, dedupe
  on `delivery_id`, return 2xx quickly, then read the job (the webhook body carries no `result`). The SDKs ship a verifier
  (PHP `WebhookVerifier`; Laravel verifies the route automatically). Delivery is
  at-least-once, can arrive out of order, and can occasionally be lost, so keep a
  slow poll as a backstop.
- **Status of a document**:
  `GET /v1/connections/{id}/quickbooks/status/{Invoice|Payment}/{external_id}?tenant_id=…`
  returns balance and paid state; `&refresh=1` re-reads QuickBooks.

## Retry rules agents must follow

1. Transport retry: always send a fresh nonce, timestamp and signature.
2. Business retry: send the **same** body and the **same** `idempotency_key`. An exact
   replay returns the original `job_id`. It never creates a second document.
3. `409 idempotency_key_conflict`: something in the body changed. Stop. Do not mint
   a new key to get past it.
4. `403 *_not_enabled`: a release gate refused the write. Do not retry; ask the operator.
5. `dead_lettered` or unknown outcome on a financial write: reconcile with the
   operator before any new submission.

Full error table: [Errors and retry decisions](index.md#errors-and-retry-decisions).
