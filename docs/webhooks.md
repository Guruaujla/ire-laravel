# Webhooks

The runtime can send your backend a signed HTTPS `POST` when something you care
about happens: a sync job finishes, an accounting document changes at the
provider, or a customer's connection needs a reconnect. This page is the receiver contract: which events exist, what each
body contains, which headers arrive, how to verify the signature, and how
retries and duplicates behave.

> **Webhooks are faster; polling is the source of truth.** Delivery is
> at-least-once, can arrive out of order, and in rare failure cases an event is
> never sent. Keep `GET /v1/sync-jobs/{id}` (or the document status read) as
> the authority, and use webhooks to learn sooner.

Machine-readable versions of everything below:

- the `webhooks` section of the public OpenAPI spec ([openapi.json](https://docs.integrationengine.ca/openapi.json),
  shown under **Webhooks** in the [API reference](https://docs.integrationengine.ca/reference.html)), with one
  entry per event type and a JSON Schema for each body;
- [outbound-webhook-signing.json](https://docs.integrationengine.ca/outbound-webhook-signing.json): real
  deliveries signed with a fake key, for testing your verifier.

## Add a webhook

Owners add webhooks on the app page in the Console (**Webhooks**):

- **Name**: the endpoint key, 1 to 64 characters from `A-Z a-z 0-9 . _ -`,
  unique in the app. Every delivery carries it as `X-IR-Key-Id`, so a receiver
  with several webhooks can pick the right secret.
- **URL**: `https://`, a public DNS name (not an IP address), no port, no
  user name or password, no `#fragment`, at most 2048 characters. Names on the
  runtime's own domain, `*.workers.dev` and `*.pages.dev` are refused, and so
  are single-label and private names such as `localhost` or `*.internal`. The
  URL is checked again before every delivery. Do not put a token in the query
  string: the URL is not a secret.
- **Events**: tick one or more event types. The Console always registers the
  list you ticked; it cannot register a webhook with no filter, and **Add** stays
  disabled until at least one event is ticked. Ticked by default:
  `sync_job.completed`, `sync_job.dead_lettered`, `sync_job.resolved` and
  `accounting.document.status_changed`. `quickbooks.transaction.status_changed`
  and the three opt-in [connection events](#connection) (`connection.connected`,
  `connection.reconnect_required`, `connection.disconnected`) are offered but
  not ticked. The reserved `sync_job.failed` is not offered.

A webhook with **no filter** (every event type except the explicit-only ones,
below) is never created by the Console's form. It exists only when it was
registered without an event list through the Console's API, carried over by an
import from the Sandbox, or set up by the runtime team. The Console shows its events as "All events except
accounting.document.status_changed, connection.connected,
connection.reconnect_required, connection.disconnected".

An app can have up to 5 webhooks that are active or paused. The page shows the signing **secret** once, in
the `.env` block (`INTEGRATION_RUNTIME_WEBHOOK_SECRET`). It is 32 random bytes
encoded as base64url without padding (43 characters). Store it server-side. It
cannot be shown again.

**Rotate** replaces the secret at once: the old secret stops verifying the
moment you rotate, and a retry still in flight is signed with the new one. To
change secrets with no gap, add a second webhook, move your receiver to it,
then revoke the first. **Pause** stops deliveries to a webhook until you
**Resume** it, and deliveries already waiting for it are skipped. What happens
to events that occur while it is paused depends on the event:

- `sync_job.*` and `connection.*`: not queued for a paused webhook. They are
  not sent after Resume. A connection event that no active webhook wants when
  it happens is not kept for a webhook added later either: read
  `GET /v1/connections/{id}` for the current state.
- `quickbooks.transaction.status_changed` and
  `accounting.document.status_changed`: a status change is kept only while
  **no** active webhook of the app wants it, and it can then be delivered after
  you Resume (or add) a webhook that wants it, possibly late and in a burst.
  For a QuickBooks connection the two status events come from one shared
  change record, so it is kept only while no active webhook wants **either**
  event. As soon as any active webhook wants either one, the change is
  delivered to the active webhooks and consumed: a paused webhook never gets
  it, even after Resume. For example, with an active
  `quickbooks.transaction.status_changed` webhook and a paused
  `accounting.document.status_changed` webhook, the accounting events that
  occur while it is paused are lost to it.

**Revoke** is permanent.

The page also lists recent deliveries for a customer, with their status
(`pending`, `enqueued`, `delivered`, `dead_lettered` or `skipped`), attempt
count, last HTTP status, failure code (see [Failure codes](#failure-codes)) and
how many times an owner redelivered it (see
[Redeliver a failed delivery](#redeliver-a-failed-delivery)).

## Send a test event

On the **Webhooks** page, an owner can click **Send test event** on an active
webhook, pick a customer, and send it one `webhook.test` delivery. It goes
through the same queue, signing, destination check and retries as every other
event, so a `2xx` from your receiver proves the whole path, including signature
verification. The page shows the new `delivery_id`; find it in the recent
deliveries for that customer to see the outcome.

- Only owners can send one, and only to a webhook that is **active** and not
  locked by the runtime team. A webhook the runtime team manages for you
  qualifies too: it already receives your real events. A paused webhook answers `developer.webhook_not_active`,
  a locked one `developer.webhook_locked` and a revoked one
  `developer.webhook_revoked`.
- At most 10 test events per webhook per customer per hour
  (`developer.webhook_test_rate_limited`). The app's request quota applies too.
- It goes to the one webhook you picked. No other webhook receives it, and no
  webhook can subscribe to `webhook.test`.

```json
{
  "schema_version": 1,
  "delivery_id": "0190d3d0-2b11-7a40-8c55-3e2f1a0b9c12",
  "event_id": "webhook.test:0190d3d0-2b10-7f00-9a11-5c6d7e8f9a0b",
  "event_type": "webhook.test",
  "occurred_at": "2026-08-04T09:15:00.120Z",
  "app_id": "app_example",
  "tenant_id": "tnt_example_1",
  "data": {
    "test": { "endpoint_key": "primary", "requested_by": "developer" }
  }
}
```

`data.test.endpoint_key` is the webhook's Name (the same as `X-IR-Key-Id`), and
`requested_by` is always `developer` today. Schema `WebhookTestPayload`. The
body carries no customer data beyond `tenant_id`.

Handle it like any event type you do not process: verify the signature, then
answer `2xx`. **PHP and Laravel:** `WebhookEvent` accepts unknown event types,
including `webhook.test`, from `ezee/integration-runtime-sdk` 1.3.3. With 1.3.2
or earlier the verifier rejects it as `payload_shape_invalid` and the Laravel
receiver answers `401`, which ends the delivery as `http_4xx`. Laravel package
1.3.4 accepts SDK 1.3.1 and later, so run
`composer update ezee/integration-runtime-sdk` to get 1.3.3 or later; the
receiver then answers `200` and fires `WebhookReceived`. From Laravel package
1.5.0 it also fires `WebhookTestReceived` (`tenantId`, `endpointKey`).

## Event catalog

| `event_type` | Sent when | Endpoints with no filter get it |
| --- | --- | --- |
| `sync_job.completed` | A sync job you started completed. | Yes |
| `sync_job.dead_lettered` | A sync job stopped retrying and failed for good. | Yes |
| `sync_job.resolved` | The runtime team marked a job `manually_resolved`. | Yes |
| `sync_job.failed` | **Reserved. Never sent today.** A failed job is reported once, as `sync_job.dead_lettered`. A webhook registered outside the Console can name it, but the Console does not offer it. | Yes |
| `quickbooks.transaction.status_changed` | The runtime's verified view of a QuickBooks Invoice or Payment changed. | Yes |
| `accounting.document.status_changed` | A document the runtime wrote for you (it has your `erp_id`) changed at the provider, in provider-neutral form. | **No: explicit-only** |
| `connection.connected` | A connection became connected: the first connect, a reconnect, or a recovery. | **No: explicit-only** |
| `connection.reconnect_required` | A connection's provider authorization stopped working. The customer must [reconnect](#reconnect-a-customer). | **No: explicit-only** |
| `connection.disconnected` | A connection that had been connected was disconnected. | **No: explicit-only** |
| `webhook.test` | An owner sent a [test event](#send-a-test-event) to this one webhook. **Not subscribable**: not offered in the Console and refused at registration. | **No: never subscribed** |

**Explicit-only.** `accounting.document.status_changed` and the three
`connection.*` events are sent only to webhooks whose event list names them. A
webhook with no filter never receives them, so adding them did not change what
existing receivers get. The Console ticks `accounting.document.status_changed`
by default for a new webhook; the `connection.*` events are opt-in.

A webhook with no filter receives every other event type, and may receive event
types added in a later release. Verify the signature, then answer `2xx` and
ignore an `event_type` you do not handle. Never answer `4xx` to an unknown type: that
ends the delivery for good.

No event is sent for a job that is `queued`, `processing` or `cancelled` (you
cancelled it, so you already know).

## The body

Every body is a JSON object, UTF-8, with these fields first:

| Field | Type | Meaning |
| --- | --- | --- |
| `schema_version` | `1` | Envelope version. New optional fields can appear without a change; ignore fields you do not know. |
| `delivery_id` | string | Unique per event and webhook, and the same on every retry. **Dedupe on this.** |
| `event_id` | string | The same for every webhook that receives this event. Opaque: do not parse it; its format can change. |
| `event_type` | string | One of the [catalog](#event-catalog). |
| `occurred_at` | ISO-8601 UTC | When the event was recorded, not when this attempt was sent. |
| `app_id` | string | Your app. |
| `tenant_id` | string | Your customer. Route on this: webhooks belong to the app, not to one customer. |

The body is fixed when the event is recorded. Every retry sends exactly the same
bytes.

### `sync_job.*`

```json
{
  "schema_version": 1,
  "delivery_id": "0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d07",
  "event_id": "0190d3b7-1a02-7c55-8e91-6d3b0f2a91cc:sync_job.completed",
  "event_type": "sync_job.completed",
  "occurred_at": "2026-08-02T14:31:07.482Z",
  "app_id": "app_example",
  "tenant_id": "tnt_example_1",
  "data": {
    "sync_job": {
      "id": "0190d3b7-1a02-7c55-8e91-6d3b0f2a91cc",
      "status": "completed",
      "operation_key": "quickbooks.invoice.create",
      "object_type": "invoice",
      "connection_id": "conn_example_1",
      "provider_key": "quickbooks",
      "external_object_id": "1042",
      "attempt_count": 1,
      "last_error_code": null,
      "created_at": "2026-08-02T14:31:02.118Z",
      "completed_at": "2026-08-02T14:31:07.482Z"
    }
  }
}
```

`data.sync_job` (schema `SyncJobWebhookSnapshot`):

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | string | The job id. `GET /v1/sync-jobs/{id}` for the full, current job. |
| `status` | string | `completed`, `dead_lettered` or `manually_resolved` at `occurred_at`. A snapshot, not a live read. |
| `operation_key` | string | For example `quickbooks.invoice.create`. |
| `object_type` | string or null | For example `invoice`. |
| `connection_id` | string | The connection the job ran on. |
| `provider_key` | string | For example `quickbooks`. |
| `external_object_id` | string or null | The provider's id, when the job linked one. Often null: read ids from the job's `result`. |
| `attempt_count` | integer | Job attempts, not webhook attempts. |
| `last_error_code` | string or null | The job's error code. No free-text error message is ever sent. |
| `created_at` | ISO-8601 UTC | When the job was created. |
| `completed_at` | ISO-8601 UTC or null | When the job reached its terminal state. |

The body carries no `result`. Read the job to get it. A job that was continued
after a stop sends a new event, with a new `event_id`, for each run. For
information only, today's ids look like `<job_id>:<event_type>` for the first run
and end in `:run<n>` for a later run; `event_id` stays opaque, so never parse it
or build one, and read `data.sync_job.id` for the job.

### `quickbooks.transaction.status_changed`

Adds `callback_epoch` (integer: the connection's authorization generation when
the change was recorded) and `data.transaction`, which has exactly the shape of
the `transaction` returned by
`GET /v1/connections/{id}/quickbooks/status/{Invoice|Payment}/{external_id}`
(schema `QuickBooksTransactionStatus`): `entity_type`, `external_id`,
`internal_id`, `status`, `sync_token`, `currency`, `total_minor`,
`balance_minor`, `unapplied_minor`, allocations, `projection_revision` and the
connection and company ids. Amounts are integer minor units.

`projection_revision` increases with every change of that transaction. Store it,
and ignore an event whose revision is not higher than the one you already have.

### `accounting.document.status_changed`

```json
{
  "schema_version": 1,
  "delivery_id": "0190d3ca-1180-7f20-8e30-1a2b3c4d5e6f",
  "event_id": "xero-status:0190d3ca-1100-7c00-9d00-0a1b2c3d4e5f:accounting.document.status_changed",
  "event_type": "accounting.document.status_changed",
  "occurred_at": "2026-08-03T10:02:33.007Z",
  "app_id": "app_example",
  "tenant_id": "tnt_example_2",
  "callback_epoch": 1,
  "data": {
    "connection_id": "conn_example_2",
    "sequence": 7,
    "document": {
      "provider_key": "xero",
      "document_type": "invoice",
      "erp_id": "INV-1001",
      "provider_id": "8a1f0c2e-0000-4000-8000-00000000c0de",
      "revision": "2026-08-03T10:02:30.0000000",
      "provider_account_id": "tenant-org-example",
      "status": "paid",
      "currency": "CAD",
      "total_amount": "113.00",
      "balance": "0.00",
      "provider_updated_at": "2026-08-03T10:02:30.000Z",
      "observed_at": "2026-08-03T10:02:33.007Z"
    }
  }
}
```

| Field | Meaning |
| --- | --- |
| `callback_epoch` | The connection's authorization generation when the change was recorded. |
| `data.connection_id` | The accounting connection. |
| `data.sequence` | Increases with every change of this document. Ignore an event whose `sequence` is not higher than one you already applied. |
| `data.document` | Schema `AccountingStatusDocument`, the same shape as the document refresh read: `document_type` (`invoice`, `payment` or `credit_application`; more may be added), your `erp_id`, `provider_id`, `revision` (send it as `expected_revision` on your next write; null only for a deleted document), `status`, `currency`, and decimal-string `total_amount` and `balance`. |

Only documents the runtime wrote for you are reported, because the event is keyed
by your `erp_id`. Status events recorded while no active webhook wants them are
kept and may be delivered after you add or Resume one, with the QuickBooks
limit described under Pause above; do not rely on it, and read the status
endpoint when you need the current state.

### `connection.*`

`connection.connected`, `connection.reconnect_required` and
`connection.disconnected` share one body shape (schema
`ConnectionStatusWebhookPayload`):

```json
{
  "schema_version": 1,
  "delivery_id": "0190d3e1-5a22-7b10-9c40-2f3e4a5b6c7d",
  "event_id": "connection:0190d3e1-5a20-7a00-8b11-1c2d3e4f5a6b",
  "event_type": "connection.reconnect_required",
  "occurred_at": "2026-11-09T12:00:00.005Z",
  "app_id": "app_example",
  "tenant_id": "tnt_example_1",
  "data": {
    "connection": {
      "id": "conn_example_1",
      "provider_key": "quickbooks",
      "status": "reconnect_required",
      "previous_status": "connected",
      "reason": "invalid_grant"
    }
  }
}
```

`data.connection` (schema `ConnectionWebhookSnapshot`):

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | string | The connection id. `GET /v1/connections/{id}` for the authoritative state. |
| `provider_key` | string | For example `quickbooks` or `xero`. |
| `status` | string | The status the connection moved to: `connected`, `reconnect_required` or `disconnected`. A snapshot at `occurred_at`. |
| `previous_status` | string or null | The status before: `connected`, `reconnect_required`, `disconnected` or `disabled`. `null` for the connection's first connect. |
| `reason` | string or null | `invalid_grant` or `refresh_token_expired` (the provider authorization stopped working), `disconnect`, or `null`. More values may be added. |

The body carries no company name and no provider account id. Read the
connection for anything else.

**PHP and Laravel:** subscribe after upgrading to 1.5.0
(`composer update ezee/integration-runtime-sdk ezee/laravel-integration-runtime`).
SDK 1.5.0 puts `data.connection` on `WebhookEvent::$connection` and adds
`Connections::reconnect()`; Laravel package 1.5.0 fires
`ConnectionStatusChanged` and `ConnectionReconnectRequired`. Earlier versions
from 1.3.3 accept the deliveries (answer `200`) but fire only
`WebhookReceived`.

- **First connect.** `connection.connected` with `previous_status: null`. This
  holds even when an earlier attempt was abandoned and disconnected, so the
  first connect comes from `disconnected`.
- **No disconnect without a connect.** If a connect is abandoned before the
  customer finished authorizing and the connection is then disconnected, no
  `connection.disconnected` is sent: you never got a `connection.connected`
  for it.
- **Not sent:** a change to or from `disabled` (an administrative state the
  runtime team sets) has no event of its own.
- **Latency.** Connection events are sent by a job that runs every minute, so
  one normally arrives up to about a minute after the change, plus the usual
  delivery time. When many connections change at once (for example a provider
  outage), the job sends a bounded number each minute (a few dozen per
  product, fewer the more webhooks subscribe), oldest first, and the rest
  follow over the next minutes. Delivery of a sent event can also start a
  minute later than usual when the job is busy.
- **Order.** As with every event, a later one can arrive first, for example a
  `connection.connected` after a reconnect before the `connection.reconnect_required`
  that preceded it. Read `GET /v1/connections/{id}` and act on its `status`, not
  on the event.

## Reconnect a customer

`connection.reconnect_required` means the provider no longer accepts the
connection's authorization (for example the customer revoked access in
QuickBooks, or the authorization expired). Calls on that connection fail until
the customer reconnects. The runtime detects a revoke on the first provider
call the provider refuses (a refused call checks the authorization at most once a
minute per connection), and otherwise within an hour, when the stored token is
next refreshed. There is no hosted reconnect page: you build the
button and the email, and both start a new connect session for the **same
tenant and provider**: `POST /v1/connections/connect-session` reconnects the
tenant's existing connection.

1. Subscribe the webhook to `connection.reconnect_required` (and
   `connection.connected`, to clear your banner when it is fixed).
2. On the event, read `GET /v1/connections/{id}`. If `status` is still
   `reconnect_required`, mark the customer's integration as needing attention.
3. Show a **Reconnect** button in your app, and send the customer's admin an
   email that links to a page of yours that starts the reconnect. Never put an
   `authorize_url` in an email: it expires. Start the session when the person
   clicks.
4. After the provider redirects back to your `return_url`, check `state`, then
   read the connection named by the redirect's `connection_id` and require
   `status === "connected"`, exactly as for a first connect.

With the PHP SDK (`ezee/integration-runtime-sdk` 1.5.0 or later),
`Connections::reconnect()` reads the connection's `provider_key` and starts the
session:

```php
// GET /integrations/reconnect, behind your own login and permission check.
$state = bin2hex(random_bytes(16));
session(['ir_connect_state' => $state]);
$session = $runtime->connections()->reconnect(
    $tenantId,                                  // your customer's runtime tenant
    $connectionId,                              // data.connection.id from the event
    'https://erp.example.com/integrations/return',
    $state,
);
return redirect()->away($session['authorize_url']);
```

Without the SDK, read `provider_key` from `GET /v1/connections/{id}` and call
`POST /v1/connections/connect-session` with the same `tenant_id`, that
`provider_key`, your `return_url` and a fresh `state`.

A Laravel listener (package 1.5.0 or later fires `ConnectionReconnectRequired`
for this event, and `ConnectionStatusChanged` for every connection event):

```php
use Ezee\IntegrationRuntime\Laravel\Events\ConnectionReconnectRequired;
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

Event::listen(function (ConnectionReconnectRequired $e) {
    $connection = IntegrationRuntime::connections()->get($e->tenantId, $e->connectionId);
    if (($connection['status'] ?? null) !== 'reconnect_required') {
        return; // already fixed, or a late delivery
    }
    $company = Company::where('runtime_tenant_id', $e->tenantId)->firstOrFail();
    $company->update(['accounting_needs_reconnect' => true]);
    Mail::to($company->billingAdmin())->queue(new ReconnectAccounting($company));
});
```

An email template:

> **Subject:** Reconnect {{provider}} to keep your invoices in sync
>
> Hi {{name}},
>
> {{your app}} can no longer reach your {{provider}} company, so new invoices
> and payments are not being sent to it. This happens when access is revoked
> in {{provider}} or expires.
>
> Sign in and click **Reconnect** on the Integrations page:
> {{integrations_page_url}}
>
> It takes about a minute.

Link to your own page (the Integrations page, or the reconnect route behind your
login), not to the provider. Clear the banner when `connection.connected`
arrives, or when a read shows `status === "connected"`.

## Headers

| Header | Signed | Value |
| --- | --- | --- |
| `X-IR-Timestamp` | **yes** | Unix seconds when this attempt was signed. |
| `X-IR-Nonce` | **yes** | Fresh for every attempt (22 to 64 characters). |
| `X-IR-Signature` | is the signature | base64url, no padding. |
| `X-IR-Key-Id` | no | The webhook's Name. Selects which secret to verify with. |
| `X-IR-App-Id` | no | Your app id. |
| `X-IR-Delivery-Id` | **no** | Copy of `delivery_id`. |
| `X-IR-Event-Id` | **no** | Copy of `event_id`. |
| `X-IR-Event-Type` | **no** | Copy of `event_type`. |
| `X-IR-Attempt` | **no** | 1-based attempt number (at most 5). Restarts at 1 on an owner [redelivery](#redeliver-a-failed-delivery). Not in the body. |
| `X-IR-Correlation-Id` | no | Present when the event carries one. For support requests. |
| `Content-Type` | no | `application/json` |
| `Accept` | no | `application/json` |
| `User-Agent` | no | `integration-runtime-webhooks/1` |

Only the timestamp, the nonce, the request target and the body are covered by
the signature. Anyone on the path could change the other headers without
breaking it, so **dedupe, route and branch on the verified body**, and use the
copies only for logging.

## Verify the signature

It is the same HMAC scheme your backend uses to call the runtime
([signing contract](https://docs.integrationengine.ca/index.html#authentication-hmac-signed-requests)), with the
roles reversed: the runtime signs with the webhook secret, and you verify.

```
v1
POST
<PATH_WITH_QUERY>
<X-IR-Timestamp>
<X-IR-Nonce>
<lowercase hex SHA-256 of the raw body bytes>
```

The six lines are joined with `\n`, with no trailing newline. The signature is
`base64url(HMAC-SHA256(key, canonical))` without padding, where `key` is the
**decoded** secret (32 raw bytes, not the 43-character text).

1. Read the **raw request target**: path and query exactly as received, for
   example `/hooks/ir%20sandbox?tenant=t%2F1&v=1`. Do not decode it, re-encode
   it or rebuild it from parsed parts. A URL with no path signs `/`. Simplest:
   register a plain ASCII path with no query string.
2. Read the **raw body bytes** before parsing. Re-serialized JSON does not
   verify.
3. Refuse a timestamp more than 300 seconds from your clock. Every attempt is
   signed again with a new timestamp and nonce, so a retry is never stale.
4. Compare the decoded signature to your HMAC in **constant time**.
5. Only then parse the body. Answer `401` when verification fails.

```ts
import { createHash, createHmac, timingSafeEqual } from "node:crypto";

// INTEGRATION_RUNTIME_WEBHOOK_SECRET from the Console, decoded to 32 bytes.
// Fail closed: a missing or malformed secret must stop the receiver, never
// fall back to an empty (publicly known) HMAC key.
const secret = process.env.INTEGRATION_RUNTIME_WEBHOOK_SECRET ?? "";
const key = Buffer.from(secret, "base64url");
if (!/^[A-Za-z0-9_-]{43}$/.test(secret) || key.length !== 32) {
  throw new Error("INTEGRATION_RUNTIME_WEBHOOK_SECRET must be the 43-character secret from the Console");
}

export function verifyWebhook(
  rawTarget: string, // path + query exactly as received
  headers: Record<string, string | undefined>, // lower-case names
  rawBody: Buffer, // before any JSON parsing
): boolean {
  const ts = headers["x-ir-timestamp"];
  const nonce = headers["x-ir-nonce"];
  const presented = headers["x-ir-signature"];
  if (!ts || !nonce || !presented || !/^\d{1,15}$/.test(ts)) return false;
  if (Math.abs(Math.floor(Date.now() / 1000) - Number(ts)) > 300) return false;
  const canonical = [
    "v1",
    "POST",
    rawTarget,
    ts,
    nonce,
    createHash("sha256").update(rawBody).digest("hex"),
  ].join("\n");
  const expected = createHmac("sha256", key).update(canonical).digest();
  const got = Buffer.from(presented, "base64url");
  return got.length === expected.length && timingSafeEqual(got, expected);
}
```

In Express, the raw target is `req.originalUrl`; in a Worker, `pathname +
search` of `new URL(request.url)`. Read the body with a raw-body parser.

**PHP and Laravel.** The PHP SDK's `WebhookVerifier` does all of this:
`(new WebhookVerifier([$keyId => $secret]))->verify($method, $rawTarget,
$headers, $rawBody)` returns a `WebhookEvent` or throws
`WebhookVerificationException`. Pass several `keyId => secret` pairs to accept
more than one webhook. The Laravel package registers
`POST /integration-runtime/webhooks`, verifies each delivery and fires Laravel
events; see the
[ire-laravel README](https://github.com/Guruaujla/ire-laravel#readme).

### Test your verifier

[outbound-webhook-signing.json](https://docs.integrationengine.ca/outbound-webhook-signing.json) holds deliveries
generated from the runtime's own delivery code with a fake secret (bytes
`0..31`): one per event family, one with a percent-encoded path and query
string, one to a URL with no path, and one with multi-byte UTF-8 in the body.
Each vector has the URL, every header as sent, the body, its SHA-256, the
canonical payload and the signature. Your verifier must accept each one when
its clock reads the vector's `timestamp`.

`receiverChecks` lists changes to those vectors and the outcome your verifier
must give: a changed metadata header still verifies; a re-serialized body, a
decoded request target, or a timestamp 301 seconds old does not.

## Retries

Each attempt has **10 seconds** to answer. The runtime never follows a redirect
and never reads `Retry-After`.

| Your answer | Outcome |
| --- | --- |
| `2xx` | Delivered. |
| `3xx` | **Terminal** (`http_redirect`). Fix the registered URL. |
| `408` | Retried (`http_408`). |
| `429` | Retried (`http_429`). |
| `5xx` | Retried (`http_5xx`). |
| any other `4xx` | **Terminal** (`http_4xx`). Not retried. |
| no answer in 10 s | Retried (`timeout`). |
| connection error | Retried (`network_error`). |

A delivery gets at most **5 attempts**. After a retryable failure the next
attempt waits 60, 120, 240, then 480 seconds, so the last attempt is about
15 minutes after the first. After the fifth failure the delivery is
`dead_lettered` with `attempts_exhausted`. Retries ride a queue that allows each
message at most 5 retries; in the rare case the queue gives up first, the
delivery ends as `cf_retries_exhausted`. Delays can be longer than listed,
never shorter.

### What your receiver must do

- **Answer `2xx` fast.** Verify, store the `delivery_id`, queue the work, and
  answer. A handler that does the work inline and takes more than 10 seconds
  spends retries on an event it already processed.
- **Answer `2xx` for a duplicate** or for an event type you ignore.
- Answer `5xx`, `408` or `429` only when a retry could succeed.
- Answer another `4xx` only to mean "never send this again". `401` for a bad
  signature is right.
- Never redirect.

## Redeliver a failed delivery

When a delivery is `dead_lettered`, an owner can fix the receiver and then
click **Redeliver** on that row in the recent deliveries for the customer. The
runtime sends the same delivery again through the normal path:

- **Same `delivery_id` and `event_id`, same body.** The stored body is resent
  byte for byte, so a receiver that already processed it dedupes it as usual.
- **Fresh signature.** `X-IR-Timestamp`, `X-IR-Nonce` and `X-IR-Signature` are
  new, like on any retry. Verify it the same way.
- **`X-IR-Attempt` restarts at 1**, and the delivery gets the full
  [retry schedule](#retries) again (5 attempts).
- **At most 5 redeliveries** per delivery. After that the button is gone and the
  runtime answers `developer.redelivery_limit_reached`.
- **Only `dead_lettered` deliveries.** Any other status answers
  `developer.delivery_not_redeliverable`; so does a second click while the first
  redelivery is still in flight. One exception: if you pause the webhook (or the
  runtime team locks it) after clicking **Redeliver** but before it is sent, the
  delivery ends as `skipped` with `endpoint_inactive`. Resume the webhook and
  you can redeliver it again; it counts toward the same limit of 5.
- The webhook must be **active** and not locked: a paused webhook answers
  `developer.webhook_not_active`, a locked one `developer.webhook_locked` and a
  revoked one `developer.webhook_revoked`.
- Only owners can redeliver.
- **Not test events.** A `webhook.test` delivery cannot be redelivered
  (`developer.delivery_not_redeliverable`). [Send a new test event](#send-a-test-event)
  instead.

A `quickbooks.transaction.status_changed` or `accounting.document.status_changed`
delivery is checked again before it is sent: if the connection was
re-authorized or the document link changed since the event, it ends as
`skipped` with a `*_status_binding_stale` code instead.

## Duplicates and ordering

- **Duplicates happen.** The same delivery can arrive more than once, for
  example when your `2xx` is lost on the way back. Keep `delivery_id` with a
  unique constraint, and answer `2xx` without doing the work again when you see
  one twice. An owner [redelivery](#redeliver-a-failed-delivery) also sends the
  same `delivery_id` again.
- **`event_id` is shared** by every webhook that receives the event. If two of
  your webhooks point at the same system (for example while you move from one
  to another), dedupe on `event_id` as well.
- **Order is not guaranteed.** A later event can arrive first. Never infer order
  from arrival. Use `data.sequence` (accounting documents) or
  `projection_revision` (QuickBooks transactions), and for jobs and connections
  read the job or the connection.
- **Events can be lost** in rare failure cases. Poll as a backstop.

## Failure codes

The **Webhooks** page shows these on a delivery that did not succeed:

| Code | Meaning | Retried |
| --- | --- | --- |
| `http_5xx`, `http_429`, `http_408` | Your receiver answered with that status. | Yes |
| `timeout` | No answer within 10 seconds. | Yes |
| `network_error` | DNS, TLS or connection failure. | Yes |
| `http_4xx` | Your receiver answered another `4xx` (or `1xx`). | No |
| `http_redirect` | Your receiver answered `3xx`. | No |
| `attempts_exhausted` | Five attempts failed. | No |
| `cf_retries_exhausted` | The queue gave up on the message. | No |
| `receiver_outcome_pending` | Shown on an `enqueued` delivery while an attempt is in progress: the request may be on its way to you and its outcome is not recorded yet. Not a failure on its own. | Pending |
| `claim_token_missing` | An internal bookkeeping fault left the delivery unable to continue, so it was ended. It may not have been sent. Contact support if you see it. | No |
| `receiver_outcome_unconfirmed` | The runtime may have sent the request but could not record your answer. It may have been delivered. | No |
| `endpoint_url_rejected` | The URL failed the destination rules when it was due to be sent. | No |
| `endpoint_inactive`, `endpoint_not_found` | The webhook was paused, revoked or removed before sending. | No (skipped) |
| `quickbooks_status_binding_stale`, `accounting_status_binding_stale` | The connection was re-authorized or the document link changed before sending, so the old status was not sent. | No (skipped) |
| `secret_decrypt_failed` | The runtime could not use the webhook's secret. Contact support. | No |
