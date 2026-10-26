## QuickBooks (Integration Runtime)

QuickBooks sync goes through `ezee/laravel-integration-runtime`; never call the
QuickBooks API directly. Full rules: `.claude/skills/quickbooks-integration-runtime/SKILL.md`.

- Use `IntegrationRuntime::quickbooks()` methods; they set recipe identities.
- One stable `idempotency_key` per business event, persisted before the first
  call and reused unchanged (same payload) on every retry.
- `409 idempotency_key_conflict` = stop. `403 *_not_enabled` = release gate; do not loop.
- Read outcomes from `SyncJobCompleted` / `SyncJobDeadLettered` events or job
  polling, never from the 202. Listeners are idempotent on the job ID.
- Never resubmit a dead-lettered or uncertain financial write with a new key.
- CAD, tax-exclusive lines, existing Customer/Item/TaxCode refs. Payment and
  Refund are accounting records only.
