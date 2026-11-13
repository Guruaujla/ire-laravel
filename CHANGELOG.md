# Changelog: ezee/laravel-integration-runtime

Releases are published to `Guruaujla/ire-laravel` as `v` tags.

## Unreleased (1.8.0)

- No package code changes. `docs/accounting-operations.md` describes the
  new `tax_lines` on invoice create and update results and on the invoice
  document read (send them back as `before.totals`), and updates of paid
  invoices: QuickBooks when every link is a Payment and the new total is at
  least the amount paid; Xero due date and line descriptions only.
  `docs/quickbooks-operations.md` describes the native update rule and its two
  new refusals. Results are pass-through arrays, so `IntegrationRuntime`
  callers see the new fields with no upgrade.

## 1.7.0

- Requires `ezee/integration-runtime-sdk` `^1.7.0` (was `^1.6.0`).
- `IntegrationRuntime::connections()->disconnect($tenantId, $connectionId,
  $idempotencyKey, $providerAccountId)` disconnects an accounting connection
  (needs `ezee/integration-runtime-sdk` 1.7.0). `docs/accounting-erp-screens.md`
  and `docs/accounting-operations.md` use it instead of a hand-built
  `client()->send()` call.

## 1.6.1

- `composer.json` requires `ezee/integration-runtime-sdk` `^1.6.0` (was
  `^1.3.1`), so the bulk onboarding methods, `Connections::reconnect()` and the
  `connection.*` webhook parsing are always present. Run `composer update
  ezee/integration-runtime-sdk`. No package code changes.
- With SDK 1.6.1, `IntegrationRuntime::jobs()->wait()` and `->get()` also
  accept an `accounting()` receipt's `polling_url`. `IntegrationRuntime::track()`
  already handled it.

## 1.6.0

- No package code changes. The bulk onboarding methods need
  `ezee/integration-runtime-sdk` 1.6.0 or later (`composer update
  ezee/integration-runtime-sdk`) (`startSnapshot`, `latestSnapshot`, `startBindJob`,
  `bindJobResults`, and the `source`/`q` arguments of `matchRecords` and
  `listRecords`).
- `docs/accounting-erp-screens.md` adds "Large customer and item lists"; the
  example app adds snapshot, search and bind-job routes.

## 1.5.1

- No code changes. `docs/accounting-operations.md` and
  `docs/accounting-erp-screens.md` describe `bound_provider_ref` on
  `accounting.mapping_conflict` bind refusals, and that a binding made before
  the last reconnect no longer blocks binding the ERP id again.

## 1.5.0

- The webhook receiver fires `ConnectionStatusChanged` for every
  `connection.connected`, `connection.reconnect_required` and
  `connection.disconnected` delivery (`tenantId`, `connectionId`,
  `providerKey`, `status`, `previousStatus`, `reason`), and also
  `ConnectionReconnectRequired` (a subclass) for `connection.reconnect_required`.
  The events are opt-in: tick them on the webhook in the Console. Subscribe
  after upgrading to 1.5.0; with 1.4.0 the deliveries are acknowledged and
  only fire `WebhookReceived`.
- The receiver fires `WebhookTestReceived` (`tenantId`, `endpointKey`) for a
  `webhook.test` delivery an owner sends from the Console.
- SDK minimums: `composer.json` still allows `ezee/integration-runtime-sdk`
  `^1.3.1`, but the receiver needs 1.3.3 or later to accept these deliveries
  (1.3.2 and earlier answer `401` to an event type they do not know). The
  events read the verified body directly, so 1.3.3 is enough for them;
  `Connections::reconnect()` (the Reconnect button) needs SDK 1.5.0. Run
  `composer update ezee/integration-runtime-sdk`.
- Docs: `docs/webhooks.md` documents the `connection.*` events and a
  "Reconnect a customer" recipe (a Reconnect button and an email template);
  the README event table lists the new events.

## 1.4.0

- No code changes in this package. With `ezee/integration-runtime-sdk` 1.4.0,
  `IntegrationRuntime::connections()->start($tenantId, $returnUrl, $state, 'xero')`
  starts a Xero connection; without the fourth argument it connects
  QuickBooks, as before.
- `docs/accounting-operations.md` and `docs/accounting-erp-screens.md` show
  the Xero connect call.
- Docs: new `docs/webhooks.md`, the webhook receiver contract (event catalog,
  payloads, headers, signature verification, retries, dedupe), linked from the
  README. No code changes.
- Docs: `docs/webhooks.md` describes the `webhook.test` event an app owner can
  send from the Console. The receiver answers `200` to it with
  `ezee/integration-runtime-sdk` 1.3.3 or later (`composer update
  ezee/integration-runtime-sdk`); with 1.3.2 or earlier it answers `401`.

## 1.3.4

- The Laravel ERP example now ships in this repository, at
  `examples/laravel-erp`, so each release tag carries the example tested with
  it. Copy its files into your Laravel 12/13 app; see its README. Composer
  does not install `examples/` into `vendor/` (`export-ignore`).
- `docs/accounting-erp-screens.md` links the example at its new location.
- No code changes in this package. 1.3.4 is the first tag with
  `examples/laravel-erp`; earlier tags have no `examples/` folder.

## 1.3.3

- Webhooks: with `ezee/integration-runtime-sdk` 1.3.3 (run
  `composer update ezee/integration-runtime-sdk`), the receiver accepts
  `quickbooks.transaction.status_changed` and event types this version does
  not know yet, and fires `WebhookReceived` for them. Before, an endpoint
  subscribed to all events answered 401 to those deliveries.
- Docs and config comments: add the webhook in the Console on your app page
  (**Webhooks**); its Name is `INTEGRATION_RUNTIME_WEBHOOK_KEY_ID`.
- No code changes in this package; works with `ezee/integration-runtime-sdk ^1.3.1`.

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
