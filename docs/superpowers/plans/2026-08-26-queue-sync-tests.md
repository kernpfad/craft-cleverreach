# Queue User-Sync + Tests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Enqueue debounced `SyncUserJob` on user save; add unit-testable webhook/enqueue helpers; scaffold integration suite for the Craft-env agent.

**Architecture:** `Plugin` pushes `SyncUserJob` (`userId` only, 5s delay) gated by cache `add()` debounce. Pure `WebhookSecretGuard` + `SyncEnqueueGate` for unit tests. Integration cases documented in the existing agent prompt.

**Tech Stack:** Craft 5 `craft\queue\BaseJob`, `craft\helpers\Queue`, PHPUnit 11, PHP 8.1+.

## Global Constraints

- `declare(strict_types=1);`
- Unit tests: no Craft bootstrap
- Integration tests: Craft agent / `CRAFT_TEST_SITE_PATH` (prompt already written)
- Do not queue order push in this plan

## File map

| File | Role |
|---|---|
| `src/util/WebhookSecretGuard.php` | Secret empty/mismatch/match |
| `src/util/SyncEnqueueGate.php` | Cache key + TTL/delay constants |
| `src/jobs/SyncUserJob.php` | Queue job + `enqueue()` |
| `src/Plugin.php` | Call `SyncUserJob::enqueue` |
| `src/controllers/WebhookController.php` | Use WebhookSecretGuard |
| `tests/unit/WebhookSecretGuardTest.php` | Unit |
| `tests/unit/SyncEnqueueGateTest.php` | Unit |
| `tests/integration/README.md` | Points to agent prompt |
| `composer.json` / `phpunit.xml.dist` | `test:integration` script |
| Docs | CHANGELOG, README, CONTRIBUTING |

---

### Task 1: WebhookSecretGuard (TDD)

- [ ] Failing tests: empty → disabled; mismatch → invalid; match → ok
- [ ] Implement `WebhookSecretGuard::check(string $configured, string $provided): string` returning `disabled|invalid|ok`
- [ ] Wire `WebhookController::requireValidSecret` to throw `NotFoundHttpException` on disabled/invalid
- [ ] Commit

### Task 2: SyncEnqueueGate + SyncUserJob

- [ ] Tests for `cacheKey(userId)` and constants (TTL 30, DELAY 5)
- [ ] `SyncUserJob` with `enqueue(int $userId)`, `execute`, `defaultDescription`
- [ ] `Plugin` handler calls `SyncUserJob::enqueue((int)$user->id)` instead of syncUser
- [ ] Commit

### Task 3: Integration scaffold + docs

- [ ] `composer test:integration` + phpunit integration suite (soft-exit / README if no Craft site)
- [ ] CHANGELOG / README (queue worker note) / CONTRIBUTING
- [ ] Commit, push, update PR #12

### Task 4: (Other agent)

- Follow `docs/superpowers/prompts/2026-08-26-craft-integration-tests-agent.md`
