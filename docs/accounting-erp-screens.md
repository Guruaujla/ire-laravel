# ERP screens for accounting

What to build in the ERP so an admin can connect an accounting provider,
onboard master data and see each document's sync state. There is no frontend
SDK. The runtime accepts only signed calls from your backend, so every screen
below is an ordinary ERP page whose controller calls the PHP SDK
(`IntegrationRuntime::accounting()` in Laravel). Apart from the connect
button, nothing here names QuickBooks.

Request and response shapes, and every error code, are in
[accounting-operations.md](accounting-operations.md#accounting-operations-reference). The example Laravel app
in the Laravel package repository
([`examples/laravel-erp`](https://github.com/Guruaujla/ire-laravel/tree/main/examples/laravel-erp))
implements the backend side:
connect, match, bind, push and document state.

| Screen | Who | SDK calls |
| --- | --- | --- |
| 1. Accounting connection | Admin | `connections()->start`, `connections()->get`, `providers`, disconnect |
| 2. Sync settings | Admin | `settings`, `updateSettings` |
| 3. Match and bind records | Admin | `listRecords`, `matchRecords`, `bindRecords` |
| 4. Code mappings | Admin | `referenceOptions`, `listRecords('tax_code')`, `setMapping`, `getMapping`, `deleteMapping` |
| 5. Document sync status | Staff | your outbox row, `status`, `refresh` |
| 6. Sync issues | Admin | your outbox rows |

Show screens 1 to 4 and 6 to admins only. Never show tokens, signatures or
service-key material on any screen.

## Rules every screen follows

- **Read the provider's capabilities, do not hard-code them.**
  `providers($tenantId)` returns each provider's `capabilities` (`operations`,
  `currencies`, `limits`) and `mapping_kinds`. Offer an action only when its
  operation is listed, and show `limits` as text. For QuickBooks today that
  means CAD only, and unmapped tax codes are refused.
- **Branch on the code or `decision()`, never on the message.** A call
  refused at once throws `RuntimeProblemException`; switch on
  `$e->decision()`. An unknown `accounting.*` code returns
  `stop_and_reconcile`: show it to a person.
- **Paginate with `next_cursor`.** Lists return one page and a cursor. A full
  page can be followed by an empty one.
- **Treat unknown enum values as "not usable".** Connection `status`,
  mapping `state` and match `status` are open enums.

## 1. Accounting connection

Show one card with the provider name and the connection state.

**What to store.** When a connect is confirmed, call `providers($tenantId)`
and store its `connection`: `connection_id`, `provider_key` and
`provider_account_id`. `providers()` returns `connection` only while the
connection is `connected`. A disconnect needs the stored company id later,
including when the connection needs a reconnect.

Read the state from your stored `connection_id`:

| State | How you know | Show |
| --- | --- | --- |
| Not connected | No stored connection, or `connections()->get(...)['status'] === 'disconnected'` | A **Connect** button. |
| Connected | `status === 'connected'` | Provider name, company, a **Disconnect** button. |
| Needs reconnect | `status === 'reconnect_required'` | A warning, a **Reconnect** button (same flow as Connect) and **Disconnect**. |
| Disabled | `status === 'disabled'` | "Paused by the operator". No buttons. |
| Several connections | `providers()['connection_state'] === 'multiple'` | An error: writes are refused until one is disconnected. Find them with `connections()->list($tenantId)`. |

If you lost the stored id, `connections()->list($tenantId)` returns the
tenant's connections (`id`, `provider_key`, `status`). It does not return the
company id, so a lost row can be found but not disconnected from the ERP; ask
support.

**Connect** calls `connections()->start($tenantId, $returnUrl, $state)` (add
`'xero'` as a fourth argument to connect Xero, SDK 1.4.0 or later) and
redirects to `authorize_url`. On return, check the one-time `state`, then
confirm with `connections()->get()`. The redirect's `status` is only a hint.
The example app's `AccountingController::connect` and `return` do exactly
this. Without the fourth argument `start()` connects QuickBooks; offer one
**Connect** button per provider the app is configured for (see
`providers`).

**One provider per customer.** If the customer already has a connection to
a different accounting provider that is not disconnected (including one that
needs a reconnect), `start` throws `accounting.connection_exists`. The
problem's `connection_id` and `provider_key` name that connection. Show
"Disconnect {provider} first". Its documents stay in the old provider.

**Disconnect** is a signed call. The SDK has no typed method for it yet.
Make a new key for each disconnect the admin confirms, and store it. Reuse
that key only to retry the same attempt. Reusing an old key, for example
after a reconnect, returns the earlier result and disconnects nothing.

```php
$key = $link->disconnect_key ??= 'disconnect:' . Str::uuid(); // new per confirmed attempt
$link->save();
$response = IntegrationRuntime::client()->send('POST', "/v1/connections/{$link->connection_id}/disconnect", [
    'tenant_id' => $link->tenant_id,
    'provider_key' => $link->provider_key,
    'provider_account_id' => $link->provider_account_id,
    'idempotency_key' => $key,
]);
if ($response->getStatusCode() === 204) {
    $link->update(['disconnect_key' => null]);   // done
} else {
    $code = json_decode((string) $response->getBody(), true)['code'] ?? 'unknown';
    // oauth.lifecycle_busy (409) or oauth.disconnect_retryable (503):
    // retry later with the same $key. Anything else: show it to the admin.
}
```

`send` returns error responses instead of throwing, so always check for
`204`. Ask the admin to confirm first: writes stop at once.

## 2. Sync settings

`settings($tenantId, $connectionId)` returns `sync_start_date`,
`record_types`, `historical_sync` and `revision`.

- Show `sync_start_date` read-only, as "Documents dated before this date are
  not synced". It is set when the customer connects. Earlier documents are
  refused with `accounting.before_sync_start` (`do_not_sync`).
- Show one toggle per record type: invoice, payment, credit memo, refund.
  Save with `updateSettings($tenantId, $connectionId, $enabledTypes,
  $settings['revision'])`.
- On `accounting.settings_conflict`, read the settings again and ask the
  admin to reapply the change.
- The provider's closing date is checked live on every write
  (`accounting.period_closed`), so it has no setting.

## 3. Match and bind records

One tab each for customers, items and tax codes. Its job is to link ERP
records to existing provider records, so the first sync does not create
duplicates.

1. Load a page of ERP records and send them to `matchRecords($tenantId,
   $connectionId, $entity, $records)`, at most 100 per call. Send customers
   as `{erp_id, name, email?}` and items as `{erp_id, name, sku?}`.
2. Show one row per result:

   | `status` | Show |
   | --- | --- |
   | `matched` | Preselect `provider_ref`. Show `matched_on` (email, SKU or name). |
   | `suggested` | A picker of `candidates`. Nothing is preselected. |
   | `none` | "No match". Offer "Create in provider" (`upsertCustomer` / `upsertItem`), or leave it for later. |

   Disable a candidate whose `bound_erp_id` is another ERP id, because it
   already belongs to another record. If `unsupported_keys` is not empty, say
   "matched by name only".
3. **Bind** sends only the rows a person confirmed, at most 25 per call:
   `bindRecords($tenantId, $connectionId, $entity, [['erp_id' => ..., 'provider_ref' => ...], ...])`.
   For tax codes, add `treatment`.
4. Show each row's result `status`. `bound` is done. Retry `rate_limited`
   (after `retry_after` seconds) and `not_attempted`. Show `refused` with its
   `code`; for example, `accounting.mapping_conflict` means the record is
   already bound elsewhere.

`listRecords($tenantId, $connectionId, $entity, $cursor)` pages through all
provider records. Use it for a "browse provider records" view. Inactive
records are included, so mark them.

If a customer or item is neither bound nor created with `upsertCustomer` or
`upsertItem`, an invoice that uses it is refused with
`accounting.mapping_missing`.

## 4. Code mappings

One section per entry in `mapping_kinds` whose `granularity` is `code`. For
QuickBooks these are tax code, payment method, deposit account, refund
account and income account. Use `label` as the heading, and `required_for`
to say which operations need it.

For each ERP code (for example your `HST-ON` tax code or `VISA` payment
method), show a dropdown of provider records:

- Payment method and accounts: `referenceOptions($tenantId, $connectionId,
  $kind, $cursor, 50)`. It returns `items` (`provider_ref`, `name`,
  `active`) and `next_cursor`. Offer only `active` ones.
- Tax codes: `listRecords($tenantId, $connectionId, 'tax_code', $cursor)`.
  Add a treatment select: `taxable`, `zero_rated`, `exempt`,
  `not_applicable`.

Save with `setMapping($tenantId, $connectionId, $kind, $erpCode,
$providerRef, $treatment)`. Show the mapping `state` beside each code:

| `state` | Show |
| --- | --- |
| `active` | Mapped. |
| `inactive`, `drifted` | "The provider record changed or was deactivated: choose again." |
| `missing`, `incomplete`, `pending`, or anything else | "Not usable: map it." |

```php
// Controller for one dropdown page (Laravel)
public function options(string $kind, Request $request)
{
    $link = $this->link($request);
    $page = IntegrationRuntime::accounting()->referenceOptions(
        $link->tenant_id, $link->connection_id, $kind, $request->query('cursor'), 50,
    );

    return [
        'options' => array_values(array_filter($page['items'], fn ($o) => $o['active'])),
        'next_cursor' => $page['next_cursor'],
    ];
}
```

## 5. Document sync status

On each invoice, payment, credit memo and refund page, show a badge from your
own outbox row. That row holds the request, `job_id`, status and error code
you stored when you submitted. Do not call the runtime on every page view.

| Outbox state | Badge | Action |
| --- | --- | --- |
| Not submitted | "Not synced" | **Sync** (if the record type is enabled and the operation is in `capabilities`). |
| `queued`, `processing` | "Syncing" | None. The poller or webhook updates it. |
| `completed` | "Synced", plus the provider id (`provider_id`) and `total_amount` | **Refresh from provider**. |
| Refused or failed | The problem in plain words | See screen 6. |

**Refresh from provider** calls `refresh($tenantId, $connectionId, 'invoice' or 'payment', $erpId)`
and stores the read (`status`, `total_amount`, `balance`, `revision`). Store
the `revision`, because the next write to that document must send it. When
an `accounting.document.status_changed` webhook arrives, do the same refresh,
one at a time per document (for example under a row lock) so a slower read
cannot overwrite a newer one. Do not store the webhook body, with one
exception: `status` `deleted` cannot be read back, so store it as is.

## 6. Sync issues

A list of outbox rows that need a person, each with one clear next step.
A row gets there in one of two ways:

- **Refused at submit.** The SDK threw `RuntimeProblemException`. Group by
  `$e->decision()`, using the first table below.
- **Failed after it was accepted.** `status()` returned a terminal state
  other than `completed`, with `error.code`. `decision()` is not available
  for these. Use the second table.

| `decision()` | Message and action |
| --- | --- |
| `fix_setup` | The setup is missing something. For `accounting.mapping_missing`, say "Map {mapping_kind} {mapping_erp_code}" and link to screen 3 or 4. For a disabled record type, link to screen 2. For an unlinked document or a connection problem, link to screen 1. After the fix, **Retry** resends the same stored request. |
| `review_and_resubmit` | Show the problem: revision conflict, amount, tax, duplicate number or closed period. The person corrects the ERP document. **Resubmit** sends a new request with a new event id. |
| `do_not_sync` | "Kept in the ERP only", with the reason: before the sync start date, or unsupported by the provider. No retry. |
| `retry_same_request` | "Will retry automatically". Resend the same request after `Retry-After`. |
| `ask_operator` | "Contact support". A release gate refused it. Do not retry. |
| `fix_credentials` | "Integration credentials are wrong". This is for your developers, not the admin. |
| `stop_and_reconcile` | "Needs review", with the code. No retry button. A person checks the provider first. |
| `fix_request` | A developer-facing error. Log it with the code. |

| Job `status` | Message and action |
| --- | --- |
| `failed` with a documented `accounting.*` `error.code` | Use that code's action from the errors table in [accounting-operations.md](accounting-operations.md#errors). Offer **Resubmit** only for codes whose action is to correct and resend with a new key, which proves nothing was written. |
| `dead_lettered`, or `failed` with an unfamiliar code | "Needs review". The provider outcome may be unknown. Reconcile with the operator. Never resubmit under a new key. |
| `manually_resolved` with a `result` | An operator confirmed the write in the provider and the runtime verified it. Treat it like `completed`: record `provider_id` and `lines`. No retry. |
| `cancelled`, `manually_resolved` without a `result` | "Needs review". Use the operator's reconciliation result. No retry. |

Never offer a button that mints a new idempotency key for the same business
event. A new key is only for a corrected resubmit after
`review_and_resubmit`, or a failed job whose documented code proves nothing
was written.
