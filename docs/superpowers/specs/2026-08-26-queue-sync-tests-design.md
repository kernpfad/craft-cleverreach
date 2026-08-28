# Queue User-Sync + Expanded Tests

**Package:** `kernpfad/craft-cleverreach`  
**Branch:** `cursor/queue-sync-and-tests-2610`  
**Date:** 2026-08-26

## Goals

1. **Decouple user attribute sync from the HTTP/console request** — `User::EVENT_AFTER_SAVE` enqueues a Craft queue job instead of calling CleverReach synchronously.
2. **Expand tests** — bootstrap-free unit helpers + Craft integration tests against the shared Kernpfad Craft test site (`CRAFT_TEST_SITE_PATH`).

## Decisions (from brainstorming)

| Topic | Choice |
|---|---|
| Dedup | **C** — if a waiting `SyncUserJob` for the same `userId` already exists, do not push another |
| Job payload | **`userId` only** — attributes loaded at execute time so later metadata changes are not lost |
| Delay | **5 seconds** before the job becomes available, so rapid saves settle |
| Test scope | **C** — unit helpers here + Craft integration tests (may run in a Craft-env agent) |

## Architecture

### Queue path

```
User::EVENT_AFTER_SAVE
  → draft/revision guard (existing)
  → SyncUserJob::enqueue((int) $user->id)
       → if pending SyncUserJob for this userId exists → return
       → else Queue::push(new SyncUserJob(['userId' => …]), delay: 5)
SyncUserJob::execute
  → load User by id (null → no-op)
  → ElementHelper::isDraftOrRevision → no-op
  → Plugin::getInstance()->userSync->syncUser($user)
```

**Dedup implementation:** Scan the Craft queue for waiting jobs whose description/class and `userId` match. Prefer a small dedicated helper `SyncUserJob::hasPendingFor(int $userId): bool` that inspects `Craft::$app->getQueue()` (or the DB queue table via Craft’s queue API) so it stays testable behind a thin seam.

If the queue driver does not expose introspection reliably, fall back to a short-lived cache key `cleverreach_sync_user_pending_{userId}` set when pushing (TTL ≈ delay + small buffer) and cleared at job start — still correct with userId-only payload.

**Preferred:** cache-key debounce for driver-agnostic behavior + optional queue scan when available. Spec settles on:

- **Primary debounce:** `Craft::$app->cache->add("cleverreach_sync_enqueue_{userId}", 1, 30)` — only push if the key was newly set (atomic). Job clears the key at the start of `execute`. Combined with `delay: 5`, rapid saves coalesce.
- Still push only `userId`; attributes always fresh at execute.

This avoids fragile queue-driver introspection while meeting the dedup intent.

### Extracted helpers (unit-testable)

| Helper | Responsibility |
|---|---|
| `WebhookSecretGuard` | `assertConfiguredAndMatches(string $configured, string $provided): void` — empty → treat as disabled; mismatch → invalid; match OK. Controllers map to `NotFoundHttpException`. |
| Existing `ReceiverSyncDecision` | Keep; extend tests if needed |
| Optional `ApiHttpFailure` / message sanitizer | Only if a pure extract from `CleverReachApiService` is clean; otherwise skip deep API mocking without Craft |

### Integration tests (Craft test site)

- Add `composer test:integration` script (hook already expects it).
- Suite under `tests/integration/` that boots via the shared site’s PHPUnit/Craft bootstrap (follow `craft-plugin-blueprint` / test-site conventions).
- Cases (minimum):
  1. User save with consent enqueues (or after delay, runs) sync path without blocking save when API is mocked/unavailable.
  2. Two rapid saves → only one pending enqueue (debounce).
  3. Webhook: no secret → 404; wrong secret → 404; correct secret + email → `unsubscribedAt` set.
  4. Soft-sync: pending receiver path records `doiConfirmed = false` on `cleverreach_user_sync` when API is stubbed (if stubbing is practical in the test site).

Detailed agent prompt: `docs/superpowers/prompts/2026-08-26-craft-integration-tests-agent.md`.

## Out of scope

- Queueing Commerce order push (still synchronous; separate follow-up).
- CR-09 Auth-Code flow.
- Changing consent-log semantics.
- Live CleverReach credentials in CI (use mocks/stubs in the test site).

## Docs

- README: note that user sync is queued (queue worker required for sync to complete).
- CHANGELOG: queue decoupling + tests.
- CONTRIBUTING: document `test:integration` once the script exists.

## Success criteria

- User save no longer waits on CleverReach HTTP.
- Rapid saves do not enqueue unbounded jobs for the same user.
- Unit tests cover webhook secret guard (+ decision helpers).
- Integration suite runs when `CRAFT_TEST_SITE_PATH` is set; CI without the site still passes unit/`composer check`.
