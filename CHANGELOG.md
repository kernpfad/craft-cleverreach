# Changelog

## 1.3.0 - Unreleased

### Added
- User attribute sync now runs via a debounced Craft queue job (`SyncUserJob`): `User::EVENT_AFTER_SAVE` enqueues by `userId` only (5s delay, ~30s cache gate) so the save request no longer waits on CleverReach HTTP, and later profile edits inside the window are included when the worker runs.
- Unit helpers/tests for webhook secret validation (`WebhookSecretGuard`) and sync enqueue constants (`SyncEnqueueGate`).
- `composer test:integration` now runs a real Craft-booted PHPUnit suite (`tests/integration/`) against `CRAFT_TEST_SITE_PATH`: `SyncUserJob` debounce/consent/soft-sync behaviour driven through real `User` saves and `queue/run`, plus the unsubscribe webhook's secret check and consent update driven through the real controller action. See `tests/integration/README.md`.

### Changed
- Unsubscribe webhook secret check goes through `WebhookSecretGuard` (same 404-on-disabled/mismatch behaviour).

## 1.2.0 - Unreleased

### Added
- (CR-04 delta) DOI form picker on the settings screen (live `GET /forms` via `cleverreach/cp/forms/index`), plus a manual ID text fallback for both groups and forms when the API is unreachable.
- (CR-05 delta) Dedicated `cleverreach_user_sync` table for last sync status / DOI confirmation flag per Craft user. Migration `m260826_000000_user_sync_table` backfills from the previous consent-log columns and drops `lastSyncStatus` / `lastSyncAt` / `lastSyncError` / `doiConfirmedAt` from `cleverreach_consentlog`.
- (CR-06 delta) Soft attribute sync while double opt-in is still pending: `CleverReachApiService::updateReceiverAttributes()` upserts with `activated: false` so profile data is not lost before confirmation. Order push skips pending receivers so it cannot force-activate them.

### Changed
- User metadata "Confirmation status" / "Last sync" now read from `cleverreach_user_sync` instead of the consent log.
- `activated` detection accepts CleverReach's timestamp convention (not only boolean `true`).

## 1.1.0 - Unreleased

### Added
- (CR-04) Group picker on the settings screen: the "Default target group" field is now a `<select>` populated live from the connected CleverReach account (`GET /groups`, via a new CP endpoint `cleverreach/cp/groups/index`), with a refresh button — no more looking up and typing a numeric group ID by hand.
- (CR-05) Last sync status shown on the User edit page's "Newsletter (CleverReach)" metadata panel: `Last sync (CleverReach): OK <date>` or `Error <date>: <message>`, sourced from new `lastSyncStatus` / `lastSyncAt` / `lastSyncError` columns on `cleverreach_consentlog`.
- (CR-06) `UserSyncService` now checks the receiver's actual CleverReach-side confirmation state (via a new `CleverReachApiService::getReceiver()`) before syncing a user: a receiver that's still pending double opt-in is never force-activated by a user save. Once CleverReach reports the receiver as confirmed, the confirmation timestamp is cached in a new `doiConfirmedAt` column and shown on the User edit page as `Confirmation status: Confirmed <date>` / `Pending confirmation`.
- (CR-07) Inbound unsubscribe/bounce endpoint at `actions/cleverreach/webhook/unsubscribe`, gated by a shared secret (`webhookSecret` setting, env-var capable, disabled/404 when unset). Marks the matching consent record's new `unsubscribedAt` column and fires `Plugin::EVENT_RECEIVER_UNSUBSCRIBED`. Both `UserSyncService` and `CommerceOrderPushService` now skip unsubscribed addresses entirely, and the User edit page shows `Newsletter status: Unsubscribed <date>` in place of the confirmation/sync details once set.
- (CR-08) New `Plugin::EVENT_MODIFY_RECEIVER_PAYLOAD` event, fired immediately before every receiver upsert (subscribe, activate, order push) with the group ID, email, activation flag, and the full outgoing payload — lets other code add or override attributes without touching this plugin's core.
- Migration `m260817_000000_add_sync_doi_unsubscribe_columns` adds the five new `cleverreach_consentlog` columns for existing installs (`Install.php` only runs on brand-new installs).

### Roadmap
- CR-09 (optional Authorization-Code OAuth flow) intentionally not implemented — the roadmap itself frames it as conditional ("only if merchants need it"), Client Credentials remains the default, and no concrete need for a browser-based authorize flow has come up. P0–P2 are otherwise complete; see ROADMAP.md.

## 1.0.0 - Unreleased

### Added
- Translation files for English and German (`src/translations/`).
- Unit test infrastructure with PHPUnit and `CsvMappingParser` tests.
- (CR-02) Connection test: a **Test connection** button on the settings screen and `php craft cleverreach/test` — both make a single lightweight, read-only API call to verify the configured OAuth credentials actually work, with no side effects on the CleverReach account.
- (CR-03) API/token errors are now recorded (message + timestamp, secrets already excluded) and shown on the settings screen, not just in the Craft log — a site visitor's failed subscribe attempt is the most likely time this fires, and an admin previously had no way to see it without log access.

### Fixed
- The entire settings screen was untranslated for English control panels. `src/templates/settings.twig` is authored in German source text throughout (every heading, field label, and instruction), passed through `|t('cleverreach')` as usual — but `src/translations/en/cleverreach.php` only ever covered the runtime-facing strings (validation errors, catalog search labels), never backfilled for the settings template itself. An English-language CP therefore showed a near-total mix of raw German prose next to a couple of English labels. Confirmed live by switching a CP user's language to English and loading the settings page before and after. Added English translations for all ~30 settings-screen strings.
- The **Test connection** button on the settings screen did nothing when clicked. `Plugin::settingsHtml()` is always rendered through Craft core's `view->namespaceInputs(..., 'settings')`, which rewrites every `id`/`name`/`for` in the output to `settings-<original>` — the button's actual DOM id became `settings-cleverreach-test-run`, but its inline `<script>` still called `document.getElementById('cleverreach-test-run')` with the literal, unnamespaced string. The lookup returned null, the `if (!btn || !result) return;` guard fired, and the click handler was never attached — no request, no error, no visible failure. Fixed by running the same ids through Craft's `|namespaceInputId` filter in both the HTML and the JS, so they always match regardless of which namespace context the template renders in. Found and confirmed live (the button is now on a real, authenticated CP settings page for the first time) while capturing documentation screenshots.

### Changed
- Order push now uses the consent record's group ID and catches API errors without breaking order completion.
- Consent log writes are validated; failed saves throw and are logged.
- Settings validation requires a DOI form ID when a default group is configured.
- Catalog password comparison uses timing-safe `hash_equals()`.
- DRY refactors in `SubscriberService`, `CleverReachApiService`, and `CatalogService`.
- CleverReach API client validates HTTP status codes and rejects invalid JSON responses.
- `declare(strict_types=1)` across all source files; ECS paths extended to include tests.
- Import command console output unified to English; CSV mapping logic extracted to `CsvMappingParser`.

- Double opt-in newsletter signup with its own consent log, independent of CleverReach's.
- Attribute mapping from Craft fields to CleverReach attributes.
- Native Formie integration, as an alternative to the generic subscribe endpoint.
- Optional Craft Commerce order push for existing subscribers.
- Craft user linking, with attribute sync on user save and a newsletter status panel on the user profile.
- Import command for existing contacts from Craft users, Commerce customers or a CSV file, with a per-run consent mode.
- Optional product catalog exposed through CleverReach's My Content interface.
