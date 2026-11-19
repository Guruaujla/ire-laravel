# Meetings API

The provider-neutral Meetings API creates a video meeting on a tenant's
meeting connection and gives you its join link. Your code is the same for
every provider: Google Meet first, then Microsoft Teams and Zoom. Any app can
use any meeting provider that is enabled for it; nothing is tied to one
product.

Requests carry your own meeting id (`external_ref`) and provider-neutral
details. They never carry provider ids.

> Google Meet and Microsoft Teams are available. A provider's operations are switched on
> after its staging run; until then `POST /v1/meetings/operations` answers
> `403 meetings.operation_disabled`.

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

To disconnect a meeting connection, send
`POST /v1/connections/{id}/disconnect` with its `provider_key` and the
`provider_account_id` from `GET /v1/meetings/providers` (the SDK's
`Connections::disconnect()` takes it as an argument for meeting connections).

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

## Google Meet

| Capability | Value |
|---|---|
| `fields_honored` | `access` |
| `access_levels` | `open`, `trusted`, `restricted` |
| `link_revocable` | `false` |
| `personal_accounts` | `true` |

- A Meet link is not a calendar event: `title`, `start`, `end` and
  `timezone` are accepted and reported in `fields_ignored`. Put the
  `join_url` on your own appointment or calendar event.
- `meeting_code` is the Meet code (`abc-defg-hij`); `dial_in` is `null`.
- `access` is in `fields_applied` only when Google's answer shows it was
  applied; otherwise it is in `fields_ignored`.
- Ending a meeting ends any call in progress, then sets the space to
  `restricted` so only invited people can join. If Google refuses that change
  (some accounts cannot restrict access), the meeting still ends and the link
  stays open to its previous audience. The link itself is never deleted
  (`link_revocable: false`).
- The tenant connects a Google account (Workspace or personal) with the
  Meet scope; the runtime only creates, reads and ends spaces it made.
- A create or end Google did not confirm (timeout, 5xx, 429) is never sent
  again. The operation fails with `meetings.create_uncertain` (the meeting is
  `failed`; create again with a new key; the link of any space Google made is
  never returned) or `meetings.end_uncertain` (the meeting stays
  `active`; end it again with a new key).

## Microsoft Teams

| Capability | Value |
|---|---|
| `fields_honored` | `title`, `start`, `end`, `access` |
| `access_levels` | `open`, `trusted`, `restricted` |
| `link_revocable` | `false` |
| `personal_accounts` | `false` |

- The meeting is a Teams online meeting (not a calendar event): `title` is
  its subject and `start`/`end` its times. `timezone` is reported in
  `fields_ignored` (times are instants with an offset).
- `access` sets who skips the lobby: `open` everyone, `trusted` people in the
  tenant's organization, `restricted` only the organizer. If Teams refuses or
  ignores that change, the meeting is still created and `access` is in
  `fields_ignored`.
- `meeting_code` is the Teams meeting ID; `dial_in` lists the toll and
  toll-free numbers when the organizer has audio conferencing, else `null`.
- Ending a meeting first sets its lobby so only the organizer joins
  directly (best effort), then deletes it in Teams. Teams does not stop a
  call already in progress, and a deleted meeting's link may still open, so
  the link is not revocable (`link_revocable: false`). A meeting the
  organizer already deleted counts as ended.
- The tenant connects a work or school Microsoft 365 account (personal
  Microsoft accounts are refused) and consents to `OnlineMeetings.ReadWrite`.
  Some organizations require an admin to approve the app first.
- A create Teams did not confirm (timeout, 5xx, 429) is never sent again on
  that key: the operation fails with `meetings.create_uncertain`. Create
  again with a new key and the same `external_ref` on the same connection:
  Teams returns the meeting it already made, so no second meeting exists. A
  Teams answer whose meeting cannot be verified fails with
  `meetings.result_unverifiable`. An end Teams did not
  confirm fails with `meetings.end_uncertain` (the meeting stays `active`;
  end it again with a new key).

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
status:

| `error.code` | What to do |
|---|---|
| `meetings.provider_refused` | The provider refused (for example the account cannot create meetings); `error.message` says why. Fix it, then send again with a new key |
| `meetings.reconnect_required` | The tenant must reconnect the provider, then send again with a new key |
| `meetings.connection_not_active` | The connected account changed or is not usable; ask the tenant to reconnect |
| `meetings.operation_disabled` | The operation was switched off; do not loop |
| `meetings.create_uncertain` | The provider did not confirm the create; the meeting is `failed`. Create again with a new key |
| `meetings.end_uncertain` | The provider did not confirm the end; the meeting stays `active`. End again with a new key |
| `meetings.result_unverifiable` | The provider's answer could not be verified (a create is `failed`: create again with a new key; an end leaves the meeting `active`) |
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
