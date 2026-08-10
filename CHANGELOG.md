# Changelog

## 1.0.0 - Unreleased

### Added
- Translation files for English and German (`src/translations/`).
- Unit test infrastructure with PHPUnit and `CsvMappingParser` tests.
- (CR-02) Connection test: a **Test connection** button on the settings screen and `php craft cleverreach/test` — both make a single lightweight, read-only API call to verify the configured OAuth credentials actually work, with no side effects on the CleverReach account.
- (CR-03) API/token errors are now recorded (message + timestamp, secrets already excluded) and shown on the settings screen, not just in the Craft log — a site visitor's failed subscribe attempt is the most likely time this fires, and an admin previously had no way to see it without log access.

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
