# Changelog: ezee/laravel-integration-runtime

Releases are published to `Guruaujla/ire-laravel` as `v` tags.

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
