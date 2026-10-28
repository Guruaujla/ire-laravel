# Moving from quickbooks() to accounting()

`accounting()` is the provider-neutral API. Code written against it does not
change when the tenant's accounting provider changes. `quickbooks()` keeps
working; it is deprecated since SDK 1.3.0 and removed no earlier than 2.0.

## What changes

| quickbooks() | accounting() |
| --- | --- |
| `POST /v1/operations/execute` with recipe identity | `POST /v1/accounting/operations` with an `operation` |
| `connection_id` required | `connection_id` optional (the tenant's one accounting connection) |
| QuickBooks ids in the payload (`CustomerRef`, `ItemRef`, `TaxCodeRef`, `SyncToken`) | ERP ids and codes only; the runtime maps them |
| Minor units / QuickBooks tax declarations | Decimal strings and `expected_totals` |
| `internal_object_id` | `erp_id` |
| `GET /v1/sync-jobs/{id}` | `GET /v1/accounting/operations/{id}` with a neutral `result` |
| `quickbooks.transaction.status_changed` | `accounting.document.status_changed` (opt in) |

## Steps

1. **Map reference data once per connection.** Bind customers, items and tax
   codes (`listRecords`, `matchRecords`, `bindRecords`) and map payment
   methods and accounts (`setMapping`). Existing QuickBooks master mappings
   made through `quickbooks()` are reused.
2. **Switch new documents to `accounting()`.** Keep your idempotency key
   scheme; a key used with `quickbooks()` must not be reused for a different
   neutral request.
3. **Keep the `erp_id` invariant.** The neutral `erp_id` of a document is the
   same id you sent as `internal_object_id` through `quickbooks()`. The runtime
   uses it to find the provider document, and to refuse creating it twice.
4. **Link invoices created through quickbooks()** before updating, refunding
   or crediting them through `accounting()`:

   ```php
   IntegrationRuntime::accounting()->link($tenantId, $connectionId, 'INV-1001', [
       ['erp_line_id' => 'L1', 'provider_line_id' => '1'],   // from the old result's sales_lines
   ]);
   ```

   Or send each line exactly as it was created (`erp_line_id`, `item`,
   `tax_code`, `amount`, `quantity`, `unit_price`, `description`) and the
   runtime pairs it with the verified provider line. Repeating a link returns
   `unchanged`. Payments made through `quickbooks()` need no link.
5. **Store `revision` from each result** and send it back as
   `expected_revision` on the next write to that document.
6. **Upgrade to SDK 1.3.0, then subscribe the webhook endpoint to
   `accounting.document.status_changed`** if you used
   `quickbooks.transaction.status_changed`; listen for
   `AccountingDocumentStatusChanged` in Laravel. Both can run side by
   side while you migrate.

## What does not migrate

- Invoices created outside the runtime (directly in QuickBooks) cannot be
  linked; writes that reference them are `accounting.document_unlinked`.
- Invoices created before verified line read-back (invoice recipe v5) cannot
  be linked; handle their adjustments through `quickbooks()` or in QuickBooks.
