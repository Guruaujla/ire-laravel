# ezee/laravel-integration-runtime

Laravel package for the Integration Runtime. It wraps the PHP SDK
(`ezee/integration-runtime-sdk`) so a Laravel ERP or communication app can sync
with its accounting provider (QuickBooks today) with very little code:

- configuration from `.env`, with signed requests set up for you
- `IntegrationRuntime::accounting()`: provider-neutral accounting writes, reads and onboarding
- `IntegrationRuntime::quickbooks()`: the QuickBooks API (deprecated since 1.3.0; see [`docs/accounting-migration.md`](docs/accounting-migration.md))
- verified completion webhooks, fired as Laravel events and deduplicated
- a queued poller that fires the same events when webhooks are not configured
- `artisan` commands to check setup, inspect jobs, and install rules for coding agents

Laravel 12 (PHP 8.2+) or 13 (PHP 8.3+). Laravel 11 is not supported: Composer blocks every 11.x release on security advisories.

## Install

Both packages are public Git repositories,
[`Guruaujla/ire-laravel`](https://github.com/Guruaujla/ire-laravel) and
[`Guruaujla/ire-php-sdk`](https://github.com/Guruaujla/ire-php-sdk). No token is
needed. Add both to the app's Composer repositories (Composer does not read a
dependency's repositories, so the SDK repository must be listed too):

```bash
composer config repositories.ire-php-sdk vcs https://github.com/Guruaujla/ire-php-sdk
composer config repositories.ire-laravel vcs https://github.com/Guruaujla/ire-laravel
composer require ezee/laravel-integration-runtime:^1.0
php artisan vendor:publish --tag=integration-runtime-config   # optional
php artisan integration-runtime:agent-rules                     # Claude Code / Codex rules
```

`.env` (create them in Console **My apps**: Create app shows these four lines once):

```dotenv
INTEGRATION_RUNTIME_URL=https://staging.integrationengine.ca
INTEGRATION_RUNTIME_APP_ID=app_your_product
INTEGRATION_RUNTIME_KEY_ID=key_...
INTEGRATION_RUNTIME_SECRET=...

# Optional: completion webhooks
INTEGRATION_RUNTIME_WEBHOOKS=true
INTEGRATION_RUNTIME_WEBHOOK_KEY_ID=<the webhook Name from the Console>
INTEGRATION_RUNTIME_WEBHOOK_SECRET=...
```

Then prove the setup. These calls are read-only:

```bash
php artisan integration-runtime:check --tenant=<runtime tenant_id>
```

## Use

```php
use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

// 1. Map your workspace to a runtime tenant, once.
$tenant = IntegrationRuntime::tenants()->registerOrResolveTenant((string) $team->id, $team->name);

// 2. Connect the provider: redirect the admin, then confirm on return.
$session = IntegrationRuntime::connections()->start($tenant['tenant_id'], route('accounting.return'), $state);
// Xero instead (SDK 1.4.0+): ->start($tenantId, route('accounting.return'), $state, 'xero')
return redirect()->away($session['authorize_url']);
// On return: check $state, then require status === 'connected':
$connection = IntegrationRuntime::connections()->get($tenantId, $request->query('connection_id'));

// 3. Onboard: bind customers, items and tax codes; map payment methods and accounts.
$accounting = IntegrationRuntime::accounting();
$matches = $accounting->matchRecords($tenantId, $connectionId, 'customer', $erpCustomers);
$accounting->bindRecords($tenantId, $connectionId, 'customer', $confirmedBindings);
$accounting->setMapping($tenantId, $connectionId, 'payment_method', 'VISA', $providerRef);

// 4. Write. ERP ids and decimal money only. Persist the request first; a retry resends the same array.
// Pass the stored connection id: a retried request stays bound to the company it was first sent to.
$request = Accounting::buildRequest('accounting.invoice.create', $tenantId,
    (string) $invoice->id, "invoice:{$invoice->id}:create", $payload, $connectionId);
$invoice->update(['accounting_request' => $request]);
$receipt = $accounting->submit($request);
$invoice->update(['accounting_job_id' => $receipt->jobId]);
IntegrationRuntime::track($receipt, $tenantId);   // polling fallback; harmless with webhooks

// 5. On SyncJobCompleted: read the neutral result and store the revision.
$status = $accounting->status($tenantId, $event->jobId);
```

Writes: `upsertCustomer`, `upsertItem`, `createInvoice`, `updateInvoice`,
`voidInvoice`, `createPayment`, `createRefund`, `createCreditMemo`,
`applyCreditMemo`. Reads and onboarding: `status`, `providers`,
`connectionId`, `refresh`, `link`, `referenceOptions`/`referenceData`, `getMapping`/`setMapping`/
`deleteMapping`, `settings`/`updateSettings`, `listRecords`/`matchRecords`/
`bindRecords`. No method takes a provider. Payload shapes, with one
validator-checked example per operation, are in this package's
[`docs/accounting-operations.md`](docs/accounting-operations.md) and
[`docs/accounting-operations.json`](docs/accounting-operations.json).

`IntegrationRuntime::quickbooks()` still works and is documented in
[`docs/quickbooks-operations.md`](docs/quickbooks-operations.md). Moving to
`accounting()`, including linking invoices created through it:
[`docs/accounting-migration.md`](docs/accounting-migration.md).
The ERP screens to build (connection, settings, matching, mappings, sync
status): [`docs/accounting-erp-screens.md`](docs/accounting-erp-screens.md).
The webhook receiver contract (events, payloads, headers, signing, retries,
dedupe): [`docs/webhooks.md`](docs/webhooks.md).
A working version of all of this, as drop-in files for a Laravel 12/13 app
(connect, onboarding, an outbox, result and document-state listeners), is in
[`examples/laravel-erp`](https://github.com/Guruaujla/ire-laravel/tree/main/examples/laravel-erp).
Composer does not install it into `vendor/`; copy the files from the repository.

## Results as events

```php
use Ezee\IntegrationRuntime\Laravel\Events\{AccountingDocumentStatusChanged, SyncJobCompleted, SyncJobDeadLettered};
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

Event::listen(function (SyncJobCompleted $e) {
    $sync = AccountingSync::where('job_id', $e->jobId)->firstOrFail();
    $result = IntegrationRuntime::accounting()->status($e->tenantId, $e->jobId)['result'];
    $sync->update(['provider_id' => $result['provider_id'], 'lines' => $result['lines'] ?? null]);
    // Then re-read the document with accounting()->refresh() for its revision, status and balance.
});
Event::listen(function (SyncJobDeadLettered $e) {
    // Do not resubmit with a new key. Flag for reconciliation.
});
Event::listen(function (AccountingDocumentStatusChanged $e) {
    // A signal: re-read with accounting()->refresh() and store that read, one read per document
    // at a time (under a row lock). Do not store $e->document, except a final 'deleted' status.
});
```

| Event | When |
| --- | --- |
| `SyncJobCompleted` | Job completed. For an `accounting()` write, read the neutral result with `accounting()->status($tenantId, $jobId)`. |
| `SyncJobDeadLettered` | Terminal failure. Read `lastErrorCode`; reconcile. |
| `SyncJobFailed` | `failed` or `cancelled`, seen by polling. |
| `SyncJobResolved` | An operator closed the job manually. |
| `AccountingDocumentStatusChanged` | A document the runtime wrote changed in the provider (paid, voided, edited). Needs the endpoint subscribed to `accounting.document.status_changed`. Has `tenantId`, `connectionId`, `sequence`, `document`. |
| `WebhookReceived` | Every verified, first-seen delivery (raw `WebhookEvent`). |

Each `SyncJob*` event has `tenantId`, `jobId`, `status`, `operationKey`,
`externalObjectId`, `lastErrorCode`, `source` (`webhook` or `poll`) and `job`.
For `accounting()` writes, `operationKey` and `externalObjectId` are the
provider's native values (`quickbooks.*`, the QuickBooks ID): key your
listeners on `jobId` and take ids from the neutral `status()` result, so the
code stays provider-neutral. They are kept for the deprecated `quickbooks()`.
The same job can arrive from both sources, and webhooks are at-least-once, so
listeners must be idempotent on `jobId`.

**Webhook receiver.** With `INTEGRATION_RUNTIME_WEBHOOKS=true` the package
registers `POST /integration-runtime/webhooks` (no CSRF or session middleware).
Add `https://your-app/integration-runtime/webhooks` on your app page in the
Console (**Webhooks**). The webhook's Name is its key ID; the page shows the
`.env` lines once, including the secret. The controller verifies
the signature over the raw request target and body, and returns 401 on failure.
It then reads the job (one signed GET) and fires events from the job's current
state: the webhook body carries no `result`, and a delayed delivery can be
older than the job. An `accounting.document.status_changed` delivery fires
`AccountingDocumentStatusChanged` directly. It fires the events, then records `delivery_id` in the cache for 7 days and
returns 200; a repeat of a recorded delivery gets 200 without events. While a
delivery is being handled it holds a short claim (`claim_ttl_seconds`, 120 s),
and a concurrent repeat gets 429 so the runtime retries it later. If a listener
throws, or the worker dies, nothing is recorded and the runtime retries. Put
slow work on queued listeners.

**Poller.** `IntegrationRuntime::track()` queues `WaitForSyncJob`. It re-reads
the polling URL with growing delays, waits out 429/5xx, and fires the events
once the job is terminal. It never resubmits the operation.

## Errors

Calls throw `Ezee\IntegrationRuntime\Exception\RuntimeProblemException` with
`problemCode`, `requestId`, `retryAfterSeconds` and `decision()`:

| `decision()` | Do |
| --- | --- |
| `retry_same_request` | Wait (`retryAfterSeconds`), then `submit()` the same array. |
| `fix_request` | Correct the input; keep the same idempotency key. |
| `fix_setup` | `accounting.*` only: fix the mapping, settings, link or connection the problem names, then `submit()` the same saved array. |
| `review_and_resubmit` | `accounting.*` only: the document changed or cannot be written as sent (revision, amount, tax, number, closed period). Refresh, have a person review, send the corrected document as a new business event with a new key. |
| `do_not_sync` | `accounting.*` only: before the sync start date or unsupported by the provider. Keep it in the ERP only. |
| `ask_operator` | A release gate or provisioning refused it. Do not loop. |
| `stop_and_reconcile` | Identity conflict or uncertain outcome. Never mint a new key. |
| `fix_credentials` | Check app/key/secret and the server clock. |

New values may be added; treat an unknown one as `stop_and_reconcile`. Code
mapping: [`docs/accounting-operations.md`](docs/accounting-operations.md) "Errors".

## Commands

| Command | Does |
| --- | --- |
| `integration-runtime:check [--tenant=]` | Health, signing and tenant-scope reads. Never writes. |
| `integration-runtime:job {tenant} {job}` | Prints one job. |
| `integration-runtime:agent-rules [--force]` | Writes `.claude/skills/accounting-integration-runtime/SKILL.md` and a marked section in `AGENTS.md`. Removes the copy versions up to 1.3.0 wrote under `.claude/skills/quickbooks-integration-runtime/`. |

## Publishing

`.github/workflows/sdk-php-split.yml` mirrors `sdks/php` to `ire-php-sdk` and
`sdks/laravel` to `ire-laravel` on a `php-sdk-v*` tag of the runtime repository.
Tag `php-sdk-v1.2.0` publishes `v1.2.0` in both. Never edit the mirrors
directly; the next split overwrites their `main`.

## Development

Develop in the runtime repository, not the `ire-laravel` mirror: the package
resolves the SDK from `../php` through a path repository.

```bash
composer install   # uses ../php through a path repository
composer test
composer lint
```
