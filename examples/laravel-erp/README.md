# Laravel ERP example: accounting through the Integration Runtime

Drop-in files for an existing Laravel 12/13 app: copy them into your app. This
is not a standalone project (there is no `composer create-project` skeleton);
the files are tested on this package's testbench at the same release tag. They
show the full path a
product backend needs: connect a workspace, onboard master data, push
documents through an outbox, and record results from webhooks or polling. They
use `IntegrationRuntime::accounting()` from
[`ezee/laravel-integration-runtime`](../../README.md) 1.3.4 or later, so
nothing here changes when a tenant's accounting provider changes (QuickBooks
today).

| File | Role |
| --- | --- |
| `database/migrations/…_create_accounting_tables.php` | `accounting_links` (workspace → runtime tenant + connection), `accounting_syncs` (outbox) and `accounting_documents` (each invoice's and payment's latest provider read, per connection). Copies an earlier `quickbooks_links` table if there is one. |
| `app/Services/AccountingSyncService.php` | Builds and stores the request, then submits it and tracks the job. Calling it again for the same event never sends a second write. |
| `app/Listeners/RecordAccountingResult.php` | Reads the neutral result and stores `provider_id` and the line map, then refreshes the document |
| `app/Listeners/RecordProviderChange.php` | On `AccountingDocumentStatusChanged` (paid, voided, changed in the provider) stores a fresh `accounting()->refresh()` read, never the event (a verified deletion is stored as is). Each read is taken and stored under the document row's lock, so the stored read is always the latest |
| `app/Http/Controllers/AccountingController.php` | Connect, return (state check plus authoritative read), match and bind records (including snapshots and bind jobs for large lists), push an invoice, read an outbox row or an invoice's state |
| `routes/accounting.php` | Routes, behind `web` + `auth` |
| `tests/ExampleAppTest.php` | Ordering, redelivery, key and upgrade behaviour; the package's CI runs it on its testbench (`vendor/bin/phpunit examples/laravel-erp/tests`) |

## Set up

The example ships in the package repository from release 1.3.4 (earlier tags
have no `examples/` folder). Composer installs the package without it, so
take the files from the repository at the tag you installed (1.3.4 or later):

```bash
composer config repositories.ire-php-sdk vcs https://github.com/Guruaujla/ire-php-sdk
composer config repositories.ire-laravel vcs https://github.com/Guruaujla/ire-laravel
composer require ezee/laravel-integration-runtime:^1.7.0 ezee/integration-runtime-sdk:^1.7.0 -W
git clone --depth 1 --branch v1.6.0 https://github.com/Guruaujla/ire-laravel /tmp/ire-laravel
cp -r /tmp/ire-laravel/examples/laravel-erp/{app,database,routes} ./   # merge into your app
# Installed a later version? Clone that tag instead of v1.6.0.
echo "require __DIR__.'/accounting.php';" >> routes/web.php
php artisan migrate
php artisan integration-runtime:agent-rules          # rules for Claude Code / Codex
```

Add the `.env` values from the [package README](../../README.md#install). Laravel discovers the listeners
from their type hints. If you register `RecordAccountingResult` by hand,
register it for each concrete event (`SyncJobCompleted`, `SyncJobDeadLettered`, `SyncJobFailed`,
`SyncJobResolved`): Laravel does not dispatch to listeners of the
`SyncJobFinished` parent class. Then:

```bash
php artisan integration-runtime:check --tenant=<tenant_id>
```

## Flow

1. An admin opens `/accounting/connect`. The app registers or resolves the
   runtime tenant, stores a one-time `state`, and redirects to the provider.
2. `/accounting/return` checks `state`. It then reads the connection, stores
   `connection_id` only when the status is `connected`.
3. Onboarding: `POST /accounting/records/customer/match` with
   `{"records": [{"erp_id": "C-1", "name": "…", "email": "…"}]}` proposes
   matches; a person confirms them and `POST /accounting/records/customer/bind`
   binds them (same for `item` and `tax_code`). Map payment methods and
   accounts with `accounting()->setMapping(...)`. For thousands of customers
   or items: `POST /accounting/records/customer/snapshots` reads the provider
   once (`GET .../snapshots/latest` until `ready`), match with
   `"source": "snapshot"` then takes 1,000 records per call, `GET .../search?q=` searches the snapshot,
   `POST .../bind-jobs` binds up to 5,000 confirmed pairs in the background,
   and `GET .../bind-jobs/{job}/results` pages its per-row results. See
   "Large customer and item lists" in `docs/accounting-erp-screens.md`.
   These routes need `ezee/integration-runtime-sdk` 1.6.0 or later.
4. `POST /accounting/invoices/{id}` with `{"payload": {...}}` builds the request
   for business event `create`, stores it in `accounting_syncs`, then submits
   it. The receipt is stored and a poller is queued.
5. The webhook or the poller fires `SyncJobCompleted`/`SyncJobDeadLettered`.
   The listener reads `accounting()->status()` and records `provider_id` and
   `lines`, or `error_code`. For an invoice or payment it then stores a fresh
   `accounting()->refresh()` read in `accounting_documents`.

Use the same service for the other operations, for example
`push($ws, 'accounting.invoice.update', $invoiceId, $changeId, $payload)`. The
key is derived from the operation, `erp_id` and the event id, so each update,
and each corrected resubmit after `review_and_resubmit`, needs its own event
id. For a write to an existing document, put `accounting_documents.revision`
in the payload as `expected_revision` yourself; `push()` sends the payload
as given. Payload shapes are in
[`docs/accounting-operations.md`](../../docs/accounting-operations.md).

## Upgrading from the quickbooks() example

Run the migration: each workspace's tenant and connection are copied from
`quickbooks_links`; `quickbooks_syncs` is left as history. Do not create
those invoices again. `push()` refuses `accounting.invoice.create` for one
listed there; call `accounting()->link()` for it once, then update, refund and
credit it through `accounting()`.

## Rules this example follows

- The request and its key are stored before the first call, and a retry resends
  the stored request. A changed payload under the same key is never sent; the
  runtime would answer `409`.
- A `dead_lettered` or uncertain financial outcome is left for reconciliation.
  It is not resubmitted with a new key.
- The redirect query string is only a hint. The connection read is authoritative.
- A refused submit stores `decision()`. `fix_setup` (for example a missing
  mapping) is fixed and the same stored request resubmitted;
  `review_and_resubmit` needs a person and a new business event.
- The only provider value stored is the returned `provider_id`.
- Document state is a fresh provider read, taken and stored while the
  document's `accounting_documents` row is locked (`SELECT ... FOR UPDATE`),
  so reads of one document are sequential across workers (on SQLite, which has
  no row locks, a read still never replaces a strictly newer
  `provider_updated_at`). A result or webhook
  payload is never stored as the document's state, since either can be older
  than a change already recorded. A completed write costs one provider read,
  or two when a payment or credit also settles an invoice.
- Both listeners are queued (`ShouldQueue`): the webhook answers at once, and
  a failed read is retried by the queue (8 tries, backoff up to 5 minutes).
  A longer outage moves the listener to `failed_jobs`, and neither the webhook
  nor the poller sends it again: monitor `failed_jobs` and run
  `php artisan queue:retry` once the runtime is back.
