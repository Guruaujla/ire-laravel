# Meetings API

The provider-neutral Meetings API creates a video meeting on a tenant's
meeting connection and gives you its join link. Your code is the same for
every provider: Google Meet first, then Microsoft Teams and Zoom. Any app can
use any meeting provider that is enabled for it; nothing is tied to one
product.

Requests carry your own meeting id (`external_ref`) and provider-neutral
details. They never carry provider ids.

> The Meetings API is available now; the first provider (Google Meet) is
> being added. Until a provider is listed by `GET /v1/meetings/providers`, a
> tenant has no meeting connection to use.

## Steps

1. Sign every `/v1` request. Use an SDK: PHP (`Meetings`) or the Laravel
   package (`IntegrationRuntime::meetings()`).
2. Register or resolve the runtime `tenant_id` (`POST /v1/tenants`).
3. Connect a meeting provider: `GET /v1/providers?tenant_id=…` lists every
   provider with `category: "meetings"`; start the connection with
   `POST /v1/connections/connect-session` and that `provider_key`.
4. `GET /v1/meetings/providers?tenant_id=…` returns each meeting provider's
   capabilities and the tenant's active meeting `connections`. Store the
   `connection_id` the tenant chose.
5. Create the meeting and store `meeting_ref` and `meeting.join_url`.

## Capabilities

Each provider in `GET /v1/meetings/providers` has:

| Field | Meaning |
|---|---|
| `operations` | `meeting.create`, `meeting.end` |
| `fields_honored` | Which optional details the provider applies (`title`, `start`, `end`, `timezone`, `access`) |
| `access_levels` | Which `access` values it can apply |
| `link_revocable` | Whether ending a meeting stops its link from working |
| `personal_accounts` | Whether a personal (non-business) account can connect |

A detail the provider cannot apply is refused with `422
meetings.capability_unsupported` (the problem names the `field`); send only
what `fields_honored` lists.

## Create a meeting

```http
POST /v1/meetings/operations
{
  "operation": "meeting.create",
  "tenant_id": "tnt_…",
  "connection_id": "conn_…",
  "idempotency_key": "appointment-42:create",
  "meeting": {
    "external_ref": "appointment-42",
    "title": "Site visit",
    "start": "2026-11-20T15:00:00-05:00",
    "end": "2026-11-20T15:30:00-05:00",
    "timezone": "America/Toronto",
    "access": "trusted"
  }
}
```

Only `external_ref` is required. `start` and `end` go together. `access`:
`open` (anyone with the link), `trusted` (the organizer's organization and
invited people) or `restricted` (only invited people); the provider's default
when absent.

The answer is the operation status. `200` means it already finished
(`status: "completed"` with `meeting`, or `failed` with `error`); `202` means
it is still running: poll `GET /v1/meetings/operations/{job_id}?tenant_id=…`
(the `polling_url`).

```json
{
  "job_id": "job_…",
  "operation": "meeting.create",
  "meeting_ref": "6f1c…",
  "external_ref": "appointment-42",
  "provider_key": "google-meet",
  "status": "completed",
  "idempotent_replay": false,
  "existing_meeting": false,
  "meeting": {
    "meeting_ref": "6f1c…",
    "status": "active",
    "join_url": "https://meet.google.com/abc-defg-hij",
    "meeting_code": "abc-defg-hij",
    "dial_in": [],
    "fields_applied": ["title"],
    "fields_ignored": []
  }
}
```

`fields_ignored` lists details the provider accepted but did not apply.

### Retries and duplicates

- Resend the same body with the same `idempotency_key` after a lost response:
  you get the same operation (`idempotent_replay: true`). A different body
  under the same key is `409 meetings.idempotency_conflict`. A key that was
  answered with an existing meeting keeps that answer on retry.
- Meeting jobs cannot be replayed with `POST /v1/sync-jobs/{id}/replay`
  (`409 sync_job.not_replayable`); send the operation again with a new key.
- Native `POST /v1/operations/execute` refuses an `idempotency_key` that
  starts with `meetings:` (`400`); that prefix is reserved for meetings.
- One meeting per `external_ref` per tenant. A create naming an
  `external_ref` that already has a meeting (being created, active, ending or
  ended) returns that meeting's create operation (`existing_meeting: true`)
  instead of creating another. If the first create has not been admitted yet
  you get `409 meetings.create_in_progress`; retry later. After a failed
  create, a new key creates a fresh meeting.

## End a meeting

```http
POST /v1/meetings/operations
{
  "operation": "meeting.end",
  "tenant_id": "tnt_…",
  "connection_id": "conn_…",
  "idempotency_key": "appointment-42:end",
  "meeting_ref": "6f1c…"
}
```

Only an `active` meeting can be ended (`409 meetings.meeting_not_active`
otherwise, with `meeting_status`).

## Read a meeting

`GET /v1/meetings/connections/{connection_id}/meetings/{meeting_ref}?tenant_id=…`
returns `status` (`creating`, `active`, `ending`, `ended`, `failed`), the join
link, meeting code and dial-in numbers.

## Errors

| Status and code | What to do |
|---|---|
| 404 `meetings.connection_not_found` | The connection is not a meeting connection of this tenant; list connections again |
| 409 `meetings.connection_not_active` | Ask the tenant to reconnect the provider |
| 503 `meetings.provider_unavailable` | The provider is not enabled for your app; contact support |
| 403 `meetings.operation_disabled` | The operation is not enabled yet; do not loop. Retry later with the same key: the meeting stays reserved for it |
| 422 `meetings.capability_unsupported` | Drop the named `field` |
| 409 `meetings.idempotency_conflict` | Use a new key for a different request |
| 409 `meetings.create_in_progress` | Retry later |
| 409 `meetings.external_ref_conflict` | The `external_ref` belongs to a meeting on another connection |
| 404 `meetings.meeting_not_found` | Wrong `meeting_ref` or connection |
| 409 `meetings.meeting_not_active` | Nothing to end |
| 409 `meetings.account_changed` | The tenant reconnected another account; reconnect the account that created the meeting to end it |
| 429 `meetings.rate_limited` | Wait for `Retry-After`, then retry with the same key |
| 503 `meetings.state_unavailable` | Retry with the same key |
| 400 (validation) | Fix the request body |

A finished operation that did not produce a meeting has `error.code` in its
status: the provider's refusal in neutral form, or
`meetings.result_unverifiable` when the provider's answer could not be
verified (a create is marked `failed`: create again with a new key; an end
leaves the meeting `active`).
`error.message`, when present, is safe to show your user.

## SDK

```php
use Ezee\IntegrationRuntime\Laravel\Facades\IntegrationRuntime;

$meetings = IntegrationRuntime::meetings();
$op = $meetings->create($tenantId, $connectionId, "appointment:{$id}:create", [
    'external_ref' => "appointment-{$id}",
    'title' => 'Site visit',
]);
if ($op['status'] !== 'completed') {
    $op = $meetings->operation($tenantId, $op['job_id']); // poll later, e.g. in a queued job
}
$joinUrl = $op['meeting']['join_url'] ?? null;
```
