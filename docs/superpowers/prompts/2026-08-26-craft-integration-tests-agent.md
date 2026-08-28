# Agent-Prompt: Craft Integration Tests (CleverReach)

Copy-paste this into a Cloud/local agent that already has the **Kernpfad Craft test site** wired (`CRAFT_TEST_SITE_PATH` pointing at the shared Craft + Commerce install from `craft-plugin-blueprint`).

---

## Context

Repo: `kernpfad/craft-cleverreach`  
Branch to use: `cursor/queue-sync-and-tests-2610` (or whichever PR branch implements queue sync — pull latest).  
Design: `docs/superpowers/specs/2026-08-26-queue-sync-tests-design.md`

The plugin now (or will) enqueue `SyncUserJob` on `User::EVENT_AFTER_SAVE` with:

- payload: `userId` only  
- delay: 5s  
- debounce: cache key `cleverreach_sync_enqueue_{userId}` (TTL ~30s), cleared when the job starts  

Unit tests and job/helpers land in the same branch. **Your job is the Craft-booted integration suite** against the shared test site.

## Environment assumptions

- `CRAFT_TEST_SITE_PATH` is set and the directory exists  
- Plugin is path-required / installed as `cleverreach`  
- Prefer isolating plugins via the site’s `enable-only.sh cleverreach` if present (see `.githooks/pre-push`)  
- Release stuck jobs first: `php craft queue/release all` in the test site when needed  
- **Do not** call real CleverReach APIs — stub/mock Guzzle or the API service component

## Tasks

1. **Confirm** `composer test:integration` exists in the plugin `composer.json`. If missing, add it to run PHPUnit with an integration suite that boots Craft via the test site bootstrap (match sibling Kernpfad plugins / blueprint patterns).

2. **Add** `tests/integration/` covering at least:

   | ID | Case | Expect |
   |---|---|---|
   | IT-01 | Save user **without** consent | No sync job / no `cleverreach_user_sync` row |
   | IT-02 | Save user **with** consent | Enqueue debounce key set and/or `SyncUserJob` present / after `queue/run` sync attempted |
   | IT-03 | Two saves within debounce window | Only one effective enqueue (second save does not stack unbounded jobs) |
   | IT-04 | Webhook `POST .../webhook/unsubscribe` with empty plugin secret | HTTP 404 |
   | IT-05 | Wrong `?secret=` | HTTP 404 |
   | IT-06 | Correct secret + known consent email | `unsubscribedAt` set; 200/success |
   | IT-07 | Soft-sync (optional if stubbing is hard) | Pending receiver → `doiConfirmed=false` on sync record; confirmed → `true` |

3. **Stub CleverReach** so tests are deterministic (inject mock `CleverReachApiService` or mock HTTP client). Never commit real OAuth secrets.

4. **Run** `composer test:integration` with `CRAFT_TEST_SITE_PATH` set; fix failures. Keep `composer check` (unit) green.

5. **Commit + push** on the feature branch; update the PR description with how to run integration tests.

## Constraints

- `declare(strict_types=1);`, follow existing namespaces (`kernpfad\cleverreach\…`)  
- Do not reintroduce synchronous sync in `Plugin::attachUserEventHandlers`  
- Do not expand scope to order-push queueing or CR-09  
- Update `CHANGELOG.md` / `CONTRIBUTING.md` only if you add the integration script or docs gaps remain  

## Done when

- `composer test:integration` passes on the Craft test site  
- IT-01–IT-06 green (IT-07 nice-to-have)  
- PR updated with results  
