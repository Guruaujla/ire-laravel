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
     ERP code to it. A QuickBooks `income_account` can be an Income or an
     Other Income account; a Xero one can be any revenue-class account.
5. Check `GET …/settings`: a write dated before `sync_start_date` is refused
   with `accounting.before_sync_start`, and a write of a disabled record type
   with `accounting.record_type_disabled`.

`…` is `/v1/accounting/connections/{connection_id}`. A missing mapping is
refused with `409 accounting.mapping_missing`, naming the kind and code.
`GET …/mappings/{kind}/{erp_code}` checks a code mapping (payment method,
deposit, refund or income account) against the provider on every read and
reports `state` `inactive` (archived or inactive there) or `drifted` (gone, or
no longer usable for the kind, such as an account whose type changed). When
the provider cannot be read (rate limit, outage) it reports the stored state.

Customer and item upserts are checked against the provider before they are
accepted, so a clash is refused at submit rather than as a failed job.
QuickBooks: a customer create whose name a customer, vendor or employee
already uses (letter case ignored), or an item create whose name an item
uses, is `409 accounting.document_exists`; an item update whose `type`
differs from the QuickBooks item is `409 accounting.unsupported_change`. When
QuickBooks cannot be read at submit, the job runs the same checks, and a
QuickBooks duplicate-name refusal answers `accounting.document_exists` too.

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
| `accounting.customer.upsert` | ERP customer id | `customer` {name, email?, currency, phone?, first_name?, last_name?, company_name? (QuickBooks only), billing_address?, shipping_address?}; an address is {line1, line2?, city?, region?, postal_code?, country?} and replaces the stored one (omitted parts are cleared); phone, names and addresses are written but not read back |
| `accounting.item.upsert` | ERP item id | `item` {name, type `service` or `non_inventory`, income_account (ERP code), unit_price, description?, sku? (QuickBooks only)}; an update keeps the item's QuickBooks type; a different type (or an Inventory item) is refused with `409 accounting.unsupported_change` |
| `accounting.invoice.create` | ERP invoice id | `invoice` {number?, issue_date, due_date?, currency, customer.erp_id, lines}, `expected_totals` |
| `accounting.invoice.update` | ERP invoice id | `change_id`, the full new `invoice` (same customer, issue date, items, tax codes and line ids), `expected_totals`, `before` {expected_revision, totals} |
| `accounting.invoice.void` | ERP invoice id | `void_id`, `invoice` {issue_date, currency, customer, expected_revision, before_totals} |
| `accounting.invoice.delete` | ERP invoice id | `delete_id`, `invoice` {issue_date, currency, customer, expected_revision, before_totals} (QuickBooks only) |
| `accounting.payment.create` | ERP payment id | `payment` {date, amount, currency, customer, payment_method?, deposit_account?, reference?}, `invoice` {erp_id, expected_revision, expected_balance} (see [Payment method and deposit account](#payment-method-and-deposit-account)) |
| `accounting.payment.void` | ERP payment id | `void_id`, `payment.expected_revision` |
| `accounting.payment.delete` | ERP payment id | `delete_id`, `payment.expected_revision` |
| `accounting.payment.update` | ERP payment id | `change_id`, `payment.expected_revision`, and any of `payment.amount`, `.date`, `.payment_method`, `.deposit_account`, `.reference` |
| `accounting.refund.create` | ERP refund id | `refund` {number, date, lines with `original_erp_line_id`, payment_method?, refund_account}, `expected_totals`, `original_invoice` {erp_id, before_totals}, `original_payment.erp_id` |
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

### Reversing payments and invoices

Four operations reverse or change a document written through this API:
`accounting.payment.void`, `accounting.payment.delete`,
`accounting.payment.update` and `accounting.invoice.delete`. Each one ships
disabled. It becomes available for your app only once it is qualified and
then enabled for the app; until then it answers
`403 accounting.operation_disabled`. `GET /v1/accounting/providers` lists the
operations each provider supports, which is not the same as enabled: check
your app's **Operations** section in the developer Console before you offer
one of them in the ERP.

What each provider does with each operation:

| Operation | QuickBooks | Xero |
| --- | --- | --- |
| `accounting.payment.void` | Allowed (`quickbooks.payment.void`). The payment stays in QuickBooks with `total_amount` `0.00`; the result `status` is `voided`. | Allowed, as a delete (`xero.payment.delete`). Xero has no void: the payment is set `DELETED` and the result `status` is `deleted`. |
| `accounting.payment.delete` | Allowed (`quickbooks.payment.delete`). The result `status` is `deleted`. | Allowed (`xero.payment.delete`). The result `status` is `deleted`. |
| `accounting.payment.update` | Allowed (`quickbooks.payment.update`). | Refused: `422 accounting.capability_unsupported`. Xero has no payment update: delete the payment, then create a new one under a new `erp_id`. |
| `accounting.invoice.delete` | Allowed (`quickbooks.invoice.delete`). The result `status` is `deleted`. | Refused: `422 accounting.capability_unsupported`. Xero deletes only draft invoices: void the invoice instead (`accounting.invoice.void`). |

"Allowed" means the runtime translates the operation to that native write.
The provider can still refuse a particular document. Such a refusal writes
nothing, and the job's `error.code` is one of these:

| Case | QuickBooks | Xero | Do |
| --- | --- | --- | --- |
| The payment is in a bank deposit | `409 accounting.unsupported_change` (QuickBooks fault 6540, "Deposited Transaction cannot be changed") | Not applicable | Remove the payment from the deposit in QuickBooks first, then send a new reversal id with a new key. |
| The payment is reconciled | Not applicable | `409 accounting.unsupported_change` | Unreconcile it in Xero first, then send a new reversal id with a new key. |
| The payment is part of a batch payment | Not applicable | `409 accounting.unsupported_change` ("Payments within a batch cannot be deleted") | Remove it from the batch in Xero first, then send a new reversal id with a new key. |
| The document is dated on or before the closing date | `409 accounting.period_closed` (also QuickBooks fault 6200, "Account Period Closed") | `409 accounting.period_closed` (the lock date) | Reopen the period in the provider, then send a new reversal id with a new key. |
| QuickBooks still refuses an updated amount against the payment's charges | `409 accounting.unsupported_change` (QuickBooks fault 6000, "can't be less than the selected charges") | Not applicable (no update) | Nothing changed. Refresh the payment in QuickBooks and review it with a person before sending a new `change_id`. A lowered amount normally lowers the invoice application with it, so this is not expected. |
| A refund written through this API names the payment | `409 accounting.unsupported_change` | `409 accounting.unsupported_change` | The payment cannot be voided, deleted or updated. Keep it. |

The date checked is the payment's own date, or the invoice's date for an
invoice delete. A payment update that changes `date` checks both the current
and the new date.

Rules that hold on both providers:

- **An `erp_id` is never reused after a delete.** A create under the
  `erp_id` of a deleted invoice or payment is
  `409 accounting.document_exists` (for a QuickBooks invoice, also while its
  delete is in flight). Create the new document under a new `erp_id`.
- **A payment named by a runtime refund cannot be reversed.** A void, delete
  or update of a payment that a refund written through this API returns is
  `409 accounting.unsupported_change`. Credit memos do not count: they belong
  to the invoice, not to the payment.
- **One reversal at a time.** While a void, delete or update of a payment is
  in flight, another one of the same payment is
  `409 accounting.operation_in_progress`.
- **A second reversal of a reversed payment.** Repeating the same kind (a
  void of a voided payment, a delete of a deleted one) completes with
  `voided` or `deleted` and writes nothing: on QuickBooks when the runtime has
  recorded that state, on Xero whenever Xero shows the payment deleted (on
  Xero a void and a delete are the same delete). On QuickBooks a delete of a
  voided payment is `409 accounting.unsupported_change`. A void of a deleted
  payment, or a delete of a payment QuickBooks no longer has whose delete the
  runtime has not recorded, is `409 accounting.revision_conflict`: refresh the
  payment.
- **QuickBooks leaves the payment unapplied.** When QuickBooks voids or deletes
  an invoice that has a payment, the payment stays as customer credit with no
  linked invoice. Its new state arrives as an
  `accounting.document.status_changed` event, and it can still be voided or
  deleted. Xero refuses to void such an invoice
  (`409 accounting.invoice_has_payment`): delete the payment first.
- **A reversal made in the provider is recorded too.** A payment voided or
  deleted in the provider's own screens is recorded and sent as an
  `accounting.document.status_changed` event.

### Deleting an invoice

`accounting.invoice.delete` deletes an invoice written through this API.
QuickBooks only; Xero deletes only draft invoices and answers
`422 accounting.capability_unsupported` (void the invoice instead). It ships
disabled and is available for an app only once qualified and enabled (see
[Reversing payments and invoices](#reversing-payments-and-invoices)).

- `delete_id` is your stable id for this delete; retries reuse it. The
  `invoice` before-state is the same as a void's.
- A payment applied to the invoice stays in QuickBooks, unapplied, as
  customer credit. Its new state is sent as an
  `accounting.document.status_changed` event, and it can still be voided or
  deleted. An invoice with a refund or credit memo written through this API,
  an invoice dated on or before the closing date, and a stale
  `expected_revision` are refused (`409`). A void and a delete of the same
  invoice exclude each other: while one is in flight the other is
  `409 accounting.operation_in_progress`, and after one succeeded it is
  `409 accounting.unsupported_change`.
- A delete or void QuickBooks refuses (a closed period, a stale revision)
  writes nothing, and its `delete_id` or `void_id` is finished: a retry of
  that id is `accounting.provider_refused`, naming the original refusal in
  `provider_detail`. Fix the cause, then send a new business event with a new
  `delete_id` (or `void_id`) and a new key; the refused one no longer blocks
  the invoice.
- The result is the invoice with `status` `deleted`; its `revision` is the
  last one you sent. The runtime counts the delete done only when QuickBooks
  answers with the deleted invoice, or its change log shows it deleted; an
  invoice that merely cannot be found is never taken as deleted.
- A deleted invoice's `erp_id` is never reused: a create under it is
  `409 accounting.document_exists`, whether or not you polled the original
  create job, and while the delete is still in flight. Create the new invoice
  under a new `erp_id`.

### Voiding or deleting a payment

`accounting.payment.void` and `accounting.payment.delete` reverse a payment
written through this API (its `erp_id` is the payment's). On QuickBooks they
are a void and a delete. Xero has no void, so on Xero both delete the payment
(see [Xero](#xero) below). Both ship disabled and are available for an app
only once qualified and enabled for each provider (see
[Reversing payments and invoices](#reversing-payments-and-invoices)).

- `void_id` / `delete_id` is your stable id for this reversal; retries reuse
  it. `payment.expected_revision` is the payment's last `revision`.
- The payment must be applied to one invoice or to none. A payment over
  several invoices, a credit application, and a payment with a refund written
  through this API are `409 accounting.unsupported_change`; a payment dated on
  or before the closing date is `409 accounting.period_closed`. A second void
  or delete of the same payment is refused while the first is in flight
  (`409 accounting.operation_in_progress`). After one succeeded, the same kind
  again completes with no write, and on QuickBooks a delete of a voided
  payment is `409 accounting.unsupported_change` (see
  [Reversing payments and invoices](#reversing-payments-and-invoices)).
- The provider can refuse it too: a QuickBooks payment in a bank deposit
  (fault 6540) and a Xero payment that is reconciled or part of a batch
  payment are `409 accounting.unsupported_change`. Remove it from the deposit,
  unreconcile it, or remove it from the batch in the provider first, then send
  a new `void_id` or `delete_id` with a new key.
- A deleted payment's `erp_id` is never reused: a create under it is
  `409 accounting.document_exists`.
- The result is the payment with `status` `voided` (its `total_amount` is
  `0.00`) or `deleted`, and the payment's new `revision`. A deleted Xero
  payment has no revision: its `revision` is the literal `deleted`.
- A void or delete done in the provider itself is recorded too, and sent as an
  `accounting.document.status_changed` event.

### Updating a payment

`accounting.payment.update` changes a payment written through this API.
QuickBooks only; Xero has no payment update and answers
`422 accounting.capability_unsupported` (delete the payment and create a new
one under a new `erp_id`). It ships disabled and is available for an app
only once qualified and enabled (see
[Reversing payments and invoices](#reversing-payments-and-invoices)).

- `change_id` is your stable id for this change; retries reuse it.
  `payment.expected_revision` is the payment's last `revision`. Send at least
  one of `amount`, `date`, `payment_method`, `deposit_account` and
  `reference`; anything you leave out stays as it is.
- A new `amount` is applied to the payment's invoice up to that invoice's
  open balance plus what this payment already applies, and the rest stays
  unapplied as customer credit. There is no cap. Lowering the amount lowers
  the invoice application with it in the same write. A payment applied to no
  invoice changes only its total. An update never moves a payment to another
  invoice.
- If QuickBooks still refuses the amount against the payment's charges
  (fault 6000), the job fails with `accounting.unsupported_change` and nothing
  changes.
- When `date` changes, both the current and the new date must be after the
  closing date.
- The same targets are refused as for a void (`409`): several invoices, a
  credit application, a refund written through this API, a payment voided or
  deleted, or another void, delete or update of it still in flight. Changing
  the amount of a payment that has several lines is refused too.
- The result is the payment with its new `revision`, `total_amount` and
  `unapplied_amount`, and its invoice's `balance` and `revision`. The
  invoice's new balance is also sent as an `accounting.document.status_changed`
  event.

### Payment method and deposit account

`payment.payment_method` and `payment.deposit_account` on
`accounting.payment.create`, and `refund.payment_method` on
`accounting.refund.create`, may be left out. `refund.refund_account` is
always required.

- **QuickBooks.** A ref you leave out is not sent, and QuickBooks applies its
  own default. A payment then has no payment method, and without
  `deposit_account` QuickBooks deposits it to its default account, normally
  Undeposited Funds. Money in Undeposited Funds needs a later Bank Deposit in
  QuickBooks before it reaches a bank account; the runtime does not make that
  deposit. The payment is verified with no payment method and with a deposit
  account present, and its result carries the account QuickBooks chose as
  `deposit_account_ref_observed`. A refund without `payment_method` has none.
- Leaving a ref out is a separate case that is enabled for each app on its
  own. Until it is, a new job fails with `accounting.invalid_input` on its
  first attempt, and its error message names the ref to send (for example
  `payment.deposit_account`). Nothing is sent to QuickBooks or kept for that
  payment or refund. Send it again with the named ref and a new idempotency
  key. A payment or refund that sends every ref is not affected. A payment
  across several invoices needs that case too: while it is not enabled for
  the app, the job fails with `accounting.capability_unsupported` instead;
  once it is, only the left-out ref is refused, as above. Sending the named ref clears this refusal, but the job can still be
  refused for another expanded case, for example a refund with mixed funding,
  which is decided only after the runtime reads the payment from QuickBooks.
- The case is checked once, before the first attempt. A job that was admitted
  keeps that admission: if the case is withdrawn later, its retries and
  recovery still send the same request with the same request id, so a write
  that may already exist in QuickBooks is finished, not abandoned.
- **Xero.** `payment_method` is ignored. A payment without `deposit_account`
  is refused with `400 accounting.invalid_input`.

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

A failed or dead-lettered status has `error`: `code` (the neutral code to
branch on), `provider_detail` (the native code) and `message` (a readable
reason, when the runtime has one it can share; for a provider refusal, what
the provider said, e.g. QuickBooks' Fault message, detail and error number).
`message` is for logs and your user; it may be absent, and you never branch
on it.

Missed a webhook? `GET /v1/sync-jobs?tenant_id=…&completed_since=<epoch ms>`
lists every job that finished at or after that time, with its `status` and
`last_error_code`, newest first (page with `cursor`). Next time, pass the
largest `completed_at` you saw minus at least 5 minutes, and skip job ids you
already handled (jobs can become visible slightly out of order). Then read
each job's status as above.

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

An invoice update result also carries `due_date` (YYYY-MM-DD): the due date
the provider stored, from the verified read-back, so you can confirm what was
saved. It is absent on results stored before it was added.

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
- **QuickBooks** voids an invoice with a payment applied: the payment stays,
  unapplied, as customer credit, its new state is sent as an
  `accounting.document.status_changed` event, and it can still be voided or
  deleted. An invoice with a refund or credit memo written through this API
  is refused (`409`).
- **Xero** voids only invoices with nothing applied. A void of an invoice
  with a payment or credit applied is refused with
  `409 accounting.invoice_has_payment` on the submit, before the revision
  check, so a stale `expected_revision` does not hide it. Nothing is sent.
  Remove the payment in Xero first and resend the void with a new key, or
  credit the invoice instead.

### Adding and removing invoice lines

On Xero, an unpaid AUTHORISED invoice can have lines added, removed or
re-itemed (a different `item_code` or tax code) through the same
`accounting.invoice.update`. Send the full new line list: keep the
`erp_line_id` of every line you keep, give each new line a new `erp_line_id`,
and leave out the lines to remove. A new or re-itemed line needs an active
item mapping with a sales account. The update runs as its own operation,
`xero.invoice.update_lines`, which **ships disabled**: until it is enabled the
request is refused with `403 accounting.operation_disabled` (an update that
keeps the same lines is not affected).

- A paid, part-paid or credited invoice is `409 accounting.unsupported_change`
  (Xero locks its lines).
- The result carries `lines`: every current line with its `erp_line_id` and
  new `provider_line_id`. The runtime records that map, so later updates,
  refunds and credit memos name the current lines.
- If the invoice's lines changed in Xero since the runtime last recorded them,
  the update is `409 accounting.document_unlinked` (nothing is sent); fix the
  invoice in Xero to match, or ask support to reconcile it.
- If Xero changed the lines between your read and the write, the job ends
  `xero.revision_conflict`; refresh and resend with a new key.

On QuickBooks the same request works on an unpaid invoice (no payment or
other transaction linked, and the balance equals the total). It runs as
`quickbooks.invoice.update_lines`, which also **ships disabled** (`403
accounting.operation_disabled` until enabled). The runtime recomputes the
invoice's tax lines from the QuickBooks tax codes of the new line set and
refuses the write if they differ from your `after.totals`.

- An invoice with a payment or other link is `409
  accounting.unsupported_change`. If one is added after admission, the job
  ends `quickbooks.invoice_lines_update_not_unpaid`; nothing is sent.
- If the invoice's lines changed in QuickBooks since your read, the job ends
  `quickbooks.invoice_update_source_mismatch` or
  `quickbooks.invoice_update_stale_revision`; refresh and resend with a new
  key.
- The result carries `lines` with the new `provider_line_id`s, and the
  runtime re-records the line map, as for Xero.

Other reads:

- `GET …/documents/{invoice|payment}/{erp_id}?tenant_id=…` re-reads the
  provider: current `revision`, `status`, `total_amount`, `balance`. For an
  invoice it also returns `net_amount`, `total_tax` and `tax_lines` from that
  read (`tax_lines` is `null` when a rate on the invoice is not mapped to an
  ERP tax code), so you can backfill `before.totals` for invoices created
  before results carried `tax_lines`. For Xero this read makes one more Xero
  call (the tax rates). Whenever `tax_lines` is left out of a result or is
  `null` on a read, the runtime records why (`accounting.tax_lines.omitted`).
- `GET …/documents/invoice/{erp_id}/online-link?tenant_id=…` returns the
  customer's online view-and-pay link of an invoice the runtime wrote
  (`provider_key`, `erp_id`, `provider_id`, `url`): QuickBooks `InvoiceLink`,
  Xero `OnlineInvoiceUrl`. `url` is `null` when the provider has none for the
  invoice now, for example QuickBooks online payments are off or the invoice
  has no customer email, or a Xero invoice is not yet approved. It is a
  fresh provider read (one call) each time, so read it again before you send
  it rather than storing it. PHP SDK: `onlineLink()`.
- `GET …/documents/{invoice|credit_memo}/{erp_id}/pdf?tenant_id=…` returns the
  PDF the provider renders now (`application/pdf`; QuickBooks invoice or
  credit memo, Xero invoice or credit note) for a document the runtime wrote.
  The response header `X-IR-Provider-Id` names the provider document. At most
  4 MiB; a larger PDF is `503 accounting.provider_unavailable`. A document
  deleted in the provider is `410 accounting.document_deleted` here and on the
  online-link read. One provider
  call each time; the runtime does not keep the file. PHP SDK: `pdf()`.
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
old result or exactly as it was created. After a Xero lines change, send the
current lines, each with its `provider_line_id`. Invoices created outside the runtime
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
  The browser comes back to your `return_url` with `status=failed`, that
  `reason`, and your `state` when you sent one, so you can match the return to
  the connect that started it.
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

The operations below translate to a native Xero write, and every one is
seeded disabled. `accounting.payment.update` is not supported (Xero has no
payment update) and answers `422 accounting.capability_unsupported`.
`accounting.invoice.delete` is not supported either (Xero deletes only draft
invoices; void the invoice instead) and answers the same.

- `accounting.customer.upsert` → `xero.contact.upsert` (a Xero Contact). The
  billing address is the contact's POBOX address and the shipping address its
  STREET address; the phone is its DEFAULT phone. `company_name` is not sent
  (the contact name is the company).
- `accounting.item.upsert` → `xero.item.upsert` (a Xero Item). `service` and
  `non_inventory` are both an untracked Xero item; `sku` is not sent.
- `accounting.invoice.create`, `.update`, `.void` → `xero.invoice.create`,
  `xero.invoice.update`, `xero.invoice.void` (ACCREC invoices, created
  AUTHORISED)
- `accounting.payment.create` → `xero.payment.create`
- `accounting.payment.void` and `accounting.payment.delete` →
  `xero.payment.delete` (Xero has no void; both set the payment `DELETED`).
  The `void_id` or `delete_id` is the request id, and on Xero they share one
  idempotency namespace: a void and a delete with the same id are one
  request, so never reuse a `void_id` as a `delete_id` (or the reverse) for
  a different reversal. A payment Xero already shows deleted answers
  `deleted` without a write. Xero refuses a reconciled payment and a payment
  in a batch payment: both are `409 accounting.unsupported_change`; unreconcile
  it, or remove it from the batch, in Xero first. The lock date is checked
  against the payment's own date. A delete whose outcome is uncertain is never
  re-sent; only a read showing the payment deleted completes it.
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
  `payment_method` is accepted and ignored. A payment must name its
  `deposit_account`: without one it is `400 accounting.invalid_input`.
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
| `accounting.mapping_missing` (409) | An ERP customer, item or code has no usable mapping. `mapping_kind`, `mapping_erp_code` and `mapping_state` say what to map; `mapping_state` `inactive` or `drifted` means the mapped provider record was archived, deleted or is no longer usable for the kind. | Map it, then resend the same request. | `fix_setup` |
| `accounting.record_type_disabled` (409) | The connection's settings exclude this record type. | Turn it on with `PUT …/settings` if it should sync, then resend the same request. | `fix_setup` |
| `accounting.document_unlinked` (409) | The referenced invoice (or the payment a refund returns) has no verified provider document on this connection. | Create it through the runtime, or link one created through `quickbooks()`, then resend. | `fix_setup` |
| `accounting.document_deleted` (410) | The provider no longer has the document this connection wrote (it was deleted there). Returned by the online-link and PDF reads. | Treat the document as gone in the provider; do not retry. | `do_not_sync` |
| `accounting.no_accounting_connection`, `accounting.multiple_accounting_connections` (409) | The tenant has no, or several, accounting connections. | Connect one, or disconnect the extra one (`connections()->disconnect()` in the PHP SDK 1.7.0+, with its stored `provider_account_id`); passing `connection_id` does not resolve several. | `fix_setup` |
| `accounting.connection_exists` (409, from `POST /v1/connections/connect-session`) | The customer already has an active connection to a different accounting provider; `connection_id` and `provider_key` name it. | Disconnect it first (`POST /v1/connections/{id}/disconnect`; `connections()->disconnect()` in the PHP SDK 1.7.0+), then connect the new provider. Its documents stay in the old provider. | `fix_setup` |
| `accounting.revision_conflict` (409) | The document changed since your revision or before-state. | Refresh, reconcile with a person, send as a new business event with a new key. Never retry it blindly. | `review_and_resubmit` |
| `accounting.invoice_has_payment` (409) | A Xero void of an invoice that has a payment or credit applied. Xero voids only invoices with nothing applied; nothing was sent. QuickBooks voids and deletes such an invoice and leaves the payment unapplied. | Remove the payment in Xero, then resend the void with a new key; or credit the invoice instead. | `review_and_resubmit` |
| `accounting.amount_exceeded` (409) | A refund over what was paid, or a returned or credited line over what remains. | Refresh the invoice and reduce the amount; new key. | `review_and_resubmit` |
| `accounting.tax_mismatch` (409) | The ERP's tax does not match the mapped tax codes or the provider's verification. | Recompute tax from the mapped codes or send per-rate `tax_lines`; new key. | `review_and_resubmit` |
| `accounting.document_exists` (409) | The invoice number already exists in the provider (voided ones count), or a customer name or item code does (archived ones count), or a create names the `erp_id` of an invoice or payment that was deleted (an `erp_id` is never reused). On QuickBooks a customer's name must also be unused by any vendor or employee (letter case ignored); the title names the record type and id. Returned by the submit itself or as the job's error; `candidate_provider_id` names it when known (for a name, only a customer or item you could bind). | Renumber in the ERP and send with a new key; for a name, rename the record in the ERP, or bind the existing customer or item instead of creating. A Xero credit memo whose number Xero itself refused after the send keeps that answer for its `erp_id`: resend it renumbered under a new `erp_id`. `link` can adopt it only if the runtime created it through `quickbooks()` on this connection; an invoice entered in the provider cannot be linked. | `review_and_resubmit` |
| `accounting.period_closed` (409) | Dated on or before the provider's closing date. A payment reversal checks the payment's own date, an invoice delete the invoice's date, and a payment update that changes `date` both dates; the provider's own closed-period refusal (QuickBooks fault 6200, the Xero lock date) answers this too. | Re-date it, or reopen the period in the provider, then send with a new key (a reversal cannot be re-dated: reopen the period, then send a new reversal id). A Xero refund whose credit note is already written ends `accounting.refund_incomplete` instead: reopen the period, then resume it with its frozen date. | `review_and_resubmit` |
| `accounting.before_sync_start` (409) | Dated before the connection's `sync_start_date`. | Keep it in the ERP only, or move `sync_start_date` earlier (settings PUT) after matching the period's existing provider records. | `do_not_sync` |
| `accounting.capability_unsupported` (422) | The provider does not support this operation or currency (QuickBooks: CAD only), or the request needs a QuickBooks expanded case (such as a payment across several invoices, a refund with mixed funding, or an expanded invoice update) that is not qualified for the app. Xero answers it for `accounting.payment.update` (delete the payment, then create a new one under a new `erp_id`) and `accounting.invoice.delete` (void instead). No write was sent. | Read `GET /v1/accounting/providers` capabilities before offering the action. Do not retry; the request succeeds only after the case is qualified for the app. | `do_not_sync` |
| `accounting.operation_disabled` (403) | A release gate refused the operation. | Check your app's **Operations** section in the developer Console; if it says Off for everyone, contact support. Do not loop. | `ask_operator` |
| `accounting.idempotency_conflict` / `idempotency_key_conflict` (409) | Same key, different request, or the connection now points at another company. | Stop. Resend the original request unchanged; never mint a new key to get past it. | `stop_and_reconcile` |
| `accounting.operation_in_progress` (409) | A customer or item write for this ERP id (a QuickBooks customer or item write that QuickBooks refused with a validation error frees the ERP id, so a corrected resend under a new key is accepted), or (Xero) another update or void, or another credit memo, credit application or refund, of this invoice, or (QuickBooks) a void or delete of this invoice or another adjustment of it, or (Xero) a resume of a refund whose job is still running, or a void, delete or update of this payment (for a refund: of the payment it returns), is still pending at the provider. | Poll it to completion, then resend the same request. | `retry_same_request` |
| `accounting.write_uncertain` (409, job error) | A customer, item or (Xero) invoice, payment, credit memo, credit application or refund credit note write may have reached the provider but was not confirmed, or the invoice the provider holds differs from what was sent. The runtime never re-sends it. A customer or item ERP id answers `accounting.operation_in_progress` until an operator reconciles it; an invoice stays with its reservation for the operator. | Do not resend, and do not resend the invoice under a new key. Check the document in the provider and ask the operator to reconcile. | `stop_and_reconcile` |
| `accounting.refund_incomplete` (409, job error) | A Xero refund is two writes: a credit note, then its cash refund. The credit note was written and verified; the cash refund was proved not written. `candidate_provider_id` names the open credit note, and the refund keeps its budget against the invoice and payment. | When the cash refund was refused before it was sent (no step-2 row, or one with no provider-call clock; the message says `was not written`): fix the cause in Xero, then resume with the same `erp_id`, a new key and the same credit-note part (`refund_account` and `reference` may change): only the cash refund is sent. A cause of `xero.period_closed` (a lock date moved after the credit note) keeps the refund's frozen `date`: reopen the period in Xero, then resume. When Xero refused the cash refund after it was sent (the message says `was refused by Xero`), it is never re-sent for that `erp_id` and a resume sends nothing: ask the operator. Or ask the operator to reconcile it. Never resubmit the refund under a new `erp_id`. A refund credit note that did not verify answers `accounting.write_uncertain` instead, and is resumed only after the operator reconciles it. | `stop_and_reconcile` |
| `accounting.link_conflict` (409) | The invoice is linked with a different line map, or its provider invoice is linked to another ERP id. | Send the recorded line map, or ask support to correct it. | `stop_and_reconcile` |
| `accounting.provider_unavailable` (503) | Provider not enabled for the app, or a provider read was not confirmed. A refused bind may say why in `reason`: `pending_change` or `connection_changed`. | Retry the same request later; escalate if it persists. | `retry_same_request` |
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
| `accounting.mapping_conflict` (409) | The provider record is bound to another ERP id, or the record is ambiguous or changed during verification. Binding an ERP id that is already bound to a different record re-binds it on every provider. | Read `reason` (same values on every provider): `bound_to_other_record` (`bound_erp_id` holds the record: unmap or rebind that ERP id, or choose another record), `record_already_bound` (no longer returned by a bind: keep `bound_provider_ref`), `not_usable_for_kind` or `ambiguous` (choose another record). | `fix_request` |
| `accounting.reference_invalid` (409), `accounting.reference_not_found` (404) | The provider record is unusable for this kind (`reason`: `inactive` or `not_usable_for_kind`), or does not exist. | Choose an active record of the right type. | `fix_request` |
| `accounting.unsupported_change` (409) | The change is not supported, e.g. deleting a company-bound customer or item mapping, or an update of a paid invoice the provider does not allow (see "Updating a paid invoice"), or a QuickBooks item update whose `type` differs from the provider item (on the submit, or as the job's error if the item changed in between), or a payment void, delete or update the runtime or the provider does not allow (the job's error): a payment over several invoices, a credit application, a payment named by a refund written through this API, a QuickBooks delete of a voided payment, a QuickBooks payment in a bank deposit (fault 6540), a QuickBooks payment update whose amount QuickBooks still refuses against the payment's charges (fault 6000), or a Xero payment that is reconciled or part of a batch payment; or a QuickBooks invoice void or delete after the other one succeeded. See [Reversing payments and invoices](#reversing-payments-and-invoices). | Rebind to the correct provider record instead; for a paid invoice, change only what the provider allows, or remove the payment in the provider first; for a deposited payment, remove it from the deposit in QuickBooks first (for a reconciled or batch Xero payment, unreconcile it or remove it from the batch in Xero first), then resend with a new reversal id and a new key. A payment named by a runtime refund cannot be reversed. | `fix_request` |
| `accounting.mapping_not_found` (404) | No mapping for this kind and ERP code. | Create it with `PUT …/mappings/{kind}/{erp_code}`. | `fix_request` |
| `accounting.connection_not_found` (404) | The connection id is not this tenant's accounting connection. | Read it from `GET /v1/accounting/providers`. | `fix_request` |
| `accounting.operation_not_found` (404) | Unknown `job_id` for this tenant. | Use the `job_id` from the `202`. | `fix_request` |
| `accounting.invalid_input` (400) | An unknown or duplicate field or query parameter. For QuickBooks, also a payment that leaves out `payment_method` or `deposit_account`, or a refund that leaves out `payment_method` (its only optional ref), before leaving it out is enabled for the app; the job fails on its first attempt, its message names the field, and no write was sent. | Send only documented neutral fields. For a left-out ref, send the named field under a new idempotency key; the job can still be refused for another expanded case, such as a refund with mixed funding, which is decided after QuickBooks reads. | `fix_request` |
| `accounting.provider_refused` (native status) | Native admission refused it for a reason with no neutral code; `provider_detail` has the native code. A retry of a QuickBooks invoice `void_id` or `delete_id` QuickBooks already refused answers this too (`provider_detail` `quickbooks.adjustment_claim_refused`). | Read `provider_detail`; fix the request or escalate. For a refused void or delete, fix the cause and send a new id with a new key. | by HTTP status |

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
