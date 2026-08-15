# Changelog

## 1.0.0 - Unreleased

### Added
- Translation files for English and German (`src/translations/`).
- Unit test infrastructure with PHPUnit and `CsvMappingParser` tests.
- (CR-02) Connection test: a **Test connection** button on the settings screen and `php craft cleverreach/test` — both make a single lightweight, read-only API call to verify the configured OAuth credentials actually work, with no side effects on the CleverReach account.
- (CR-03) API/token errors are now recorded (message + timestamp, secrets already excluded) and shown on the settings screen, not just in the Craft log — a site visitor's failed subscribe attempt is the most likely time this fires, and an admin previously had no way to see it without log access.

### Fixed
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
