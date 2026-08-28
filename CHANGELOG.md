# Changelog

## 1.0.0 - 2026-08-28

Initial release.

### Added
- Double opt-in newsletter signup with its own consent log, independent of CleverReach's.
- Attribute mapping from Craft fields to CleverReach attributes.
- Native Formie integration, as an alternative to the generic subscribe endpoint.
- Optional Craft Commerce order push for existing subscribers.
- Craft user linking, with attribute sync on user save and a newsletter status panel on the user profile.
- Import command for existing contacts from Craft users, Commerce customers or a CSV file, with a per-run consent mode.
- Optional product catalog exposed through CleverReach's My Content interface.
- Group and DOI form pickers on the settings screen, with manual ID fallback when the API is unreachable.
- Connection test on the settings screen and via `php craft cleverreach/test`.
- Inbound unsubscribe/bounce webhook, gated by a shared secret.
- CleverReach receiver tags for automations (order complete, subscribe, user sync).
- Debounced queue jobs for user attribute sync and Commerce order push.
- Translation files for English and German.
- Unit and integration test infrastructure.
