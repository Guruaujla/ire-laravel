# Changelog: ezee/laravel-integration-runtime

Releases are published to `Guruaujla/ire-laravel` as `v` tags.

## 1.3.2

- Docs: new `docs/accounting-erp-screens.md` (the ERP screens to build:
  connection, settings, record matching, code mappings, sync status), linked
  from the README and `docs/accounting-operations.md`.
- `docs/accounting-operations.md` errors table: adds
  `accounting.write_uncertain` (`stop_and_reconcile`) and widens
  `accounting.document_exists` and `accounting.operation_in_progress` to the
  cases the runtime now returns.
- No code changes; works with `ezee/integration-runtime-sdk ^1.3.1`
  (1.3.2 adds the explicit `write_uncertain` mapping).

## 1.3.1

- Requires `ezee/integration-runtime-sdk ^1.3.1` for the accounting-aware
  `RuntimeProblemException::decision()` (`fix_setup`, `review_and_resubmit`,
  `do_not_sync`).
- `integration-runtime:agent-rules` installs the skill as
  `.claude/skills/accounting-integration-runtime/` and removes the copy that
  earlier versions wrote under `quickbooks-integration-runtime/` only when its
  SKILL.md is byte-identical to a shipped version. An edited copy is kept and
  reported; other files in that directory, hidden ones included, are kept.
- `integration-runtime:check --tenant=` also reports the tenant's accounting
  connection (connected, none or multiple). It never fails on it, including
  on an unreadable response.
- `accounting()->referenceOptions()` (from the SDK).
- README: neutral listener examples, `AccountingDocumentStatusChanged` in the
  event table, accounting decisions in the error table.

## 1.3.0

- `IntegrationRuntime::accounting()`, the provider-neutral accounting API.
- `AccountingDocumentStatusChanged` event for
  `accounting.document.status_changed` webhooks.
- `quickbooks()` is deprecated; see `docs/accounting-migration.md`.

## 1.2.0 and earlier

Laravel 12/13 package: signed client, typed QuickBooks operations, webhook
events, the queued poller and agent rules. See the git history.
