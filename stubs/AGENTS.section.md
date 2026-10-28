## Accounting (Integration Runtime)

Accounting sync (QuickBooks today, other providers later) goes through
`ezee/laravel-integration-runtime`; never call a provider API directly. Full
rules: `.claude/skills/quickbooks-integration-runtime/SKILL.md`. Payload
examples for every operation: `accounting-operations.md` and `.json` in that
folder.

- Use `IntegrationRuntime::accounting()`. It takes ERP ids, ERP codes and
  decimal-string money, never provider ids. `quickbooks()` is deprecated.
- One stable `idempotency_key` per business event, persisted before the first
  call and reused unchanged (same payload) on every retry.
- `409 idempotency_key_conflict` / `accounting.idempotency_conflict` = stop.
  `409 accounting.revision_conflict` = refresh and reconcile, never retry
  blindly. `403 accounting.operation_disabled` = release gate; do not loop.
- Store the `revision` from each result and send it back on the next write.
- Read outcomes from `accounting()->status()` (after `SyncJobCompleted` /
  `SyncJobDeadLettered` or polling), never from the 202.
- Never resubmit a dead-lettered or uncertain financial write with a new key.
