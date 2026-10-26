# ezee/laravel-integration-runtime

Laravel package for the Integration Runtime. It wraps the PHP SDK
(`ezee/integration-runtime-sdk`) so a Laravel ERP or communication app can sync
with QuickBooks with very little code:

- configuration from `.env`, with signed requests set up for you
- `IntegrationRuntime::quickbooks()`: typed methods for every live QuickBooks operation
- verified completion webhooks, fired as Laravel events and deduplicated
- a queued poller that fires the same events when webhooks are not configured
- `artisan` commands to check setup, inspect jobs, and install rules for coding agents

Laravel 12 (PHP 8.2+) or 13 (PHP 8.3+). Laravel 11 is not supported: Composer blocks every 11.x release on security advisories.

## Install

Until the package is published, add both packages from a pinned checkout of
the runtime repository (see [Publishing](#publishing)):

```json
"repositories": [
    { "type": "path", "url": "../Integration-Runtime-Engine/sdks/php", "options": { "symlink": false, "versions": { "ezee/integration-runtime-sdk": "1.0.0" } } },
    { "type": "path", "url": "../Integration-Runtime-Engine/sdks/laravel", "options": { "symlink": false, "versions": { "ezee/laravel-integration-runtime": "1.0.0" } } }
]
```

```bash
composer require ezee/laravel-integration-runtime:^1.0
php artisan vendor:publish --tag=integration-runtime-config   # optional
php artisan integration-runtime:agent-rules                     # Claude Code / Codex rules
```

`.env` (the operator issues these values; the secret is shown once):

```dotenv
INTEGRATION_RUNTIME_URL=https://staging.integrationengine.ca
INTEGRATION_RUNTIME_APP_ID=app_erp
INTEGRATION_RUNTIME_KEY_ID=key_...
INTEGRATION_RUNTIME_SECRET=...

# Optional: completion webhooks
INTEGRATION_RUNTIME_WEBHOOKS=true
INTEGRATION_RUNTIME_WEBHOOK_KEY_ID=whk_...
INTEGRATION_RUNTIME_WEBHOOK_SECRET=...
```

Then prove the setup. These calls are read-only:

```bash
php artisan integration-runtime:check --tenant=<runtime tenant_id>
```

## Use

```php
use Ezee\IntegrationRuntime\QuickBooks;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

// 1. Map your workspace to a runtime tenant, once.
$tenant = IntegrationRuntime::tenants()->registerOrResolveTenant((string) $team->id, $team->name);

// 2. Connect QuickBooks: redirect the admin, then confirm on return.
$session = IntegrationRuntime::connections()->start($tenant['tenant_id'], route('qb.return'), $state);
return redirect()->away($session['authorize_url']);
// On return: check $state, then require status === 'connected':
$connection = IntegrationRuntime::connections()->get($tenantId, $request->query('connection_id'));

// 3. Write. Persist the request first; a retry resends the same array.
$request = QuickBooks::buildRequest('quickbooks.invoice.create', $tenantId, $connectionId,
    (string) $invoice->id, "erp_invoice_{$invoice->id}:create", $payload);
$invoice->update(['qb_request' => $request]);
$receipt = IntegrationRuntime::quickbooks()->submit($request);
$invoice->update(['qb_job_id' => $receipt->jobId, 'qb_polling_url' => $receipt->pollingUrl]);
IntegrationRuntime::track($receipt, $tenantId);   // polling fallback; harmless with webhooks
```

Every operation has a method: `createInvoice`, `updateInvoice`, `voidInvoice`,
`createPayment`, `createRefund`, `createCreditMemo`, `applyCreditMemo`, and
`upsertMaster` (Customer and Item). There are also reads: `findMaster`,
`getMasterMapping` and `status`. Payload shapes, with one validator-checked example per operation,
are in the runtime's `quickbooks-operations.md` and `quickbooks-operations.json`.

## Results as events

```php
use Ezee\IntegrationRuntime\Laravel\Events\{SyncJobCompleted, SyncJobDeadLettered};

Event::listen(function (SyncJobCompleted $e) {
    Invoice::where('qb_job_id', $e->jobId)->update(['qb_id' => $e->externalObjectId]);
});
Event::listen(function (SyncJobDeadLettered $e) {
    // Do not resubmit with a new key. Flag for reconciliation.
});
```

| Event | When |
| --- | --- |
| `SyncJobCompleted` | Job completed. `externalObjectId` is the QuickBooks ID (the job's `result.external_id`). |
| `SyncJobDeadLettered` | Terminal failure. Read `lastErrorCode`; reconcile. |
| `SyncJobFailed` | `failed` or `cancelled`, seen by polling. |
| `SyncJobResolved` | An operator closed the job manually. |
| `WebhookReceived` | Every verified, first-seen delivery (raw `WebhookEvent`). |

Each event has `tenantId`, `jobId`, `status`, `operationKey`,
`externalObjectId`, `lastErrorCode`, `source` (`webhook` or `poll`) and `job`.
The same job can arrive from both sources, and webhooks are at-least-once, so
listeners must be idempotent on `jobId`.

**Webhook receiver.** With `INTEGRATION_RUNTIME_WEBHOOKS=true` the package
registers `POST /integration-runtime/webhooks` (no CSRF or session middleware).
Ask the operator to register `https://your-app/integration-runtime/webhooks`
for your app; they give you the endpoint key and secret. The controller verifies
the signature over the raw request target and body, and returns 401 on failure.
It then reads the job (one signed GET) and fires events from the job's current
state: the webhook body carries no `result` (where the QuickBooks ID is), and a
delayed delivery can be older than the job. It fires the events, then records `delivery_id` in the cache for 7 days and
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
| `ask_operator` | A release gate or provisioning refused it. Do not loop. |
| `stop_and_reconcile` | Identity conflict or uncertain outcome. Never mint a new key. |
| `fix_credentials` | Check app/key/secret and the server clock. |

## Commands

| Command | Does |
| --- | --- |
| `integration-runtime:check [--tenant=]` | Health, signing and tenant-scope reads. Never writes. |
| `integration-runtime:job {tenant} {job}` | Prints one job. |
| `integration-runtime:agent-rules [--force]` | Writes `.claude/skills/quickbooks-integration-runtime/SKILL.md` and a marked section in `AGENTS.md`. |

## Publishing

`.github/workflows/sdk-php-split.yml` mirrors `sdks/php` and `sdks/laravel` to
their own repositories on a `php-sdk-v*` tag. After that, apps install with a
Composer `vcs` repository (or Private Packagist) instead of a path checkout.

## Development

```bash
composer install   # uses ../php through a path repository
composer test
composer lint
```
