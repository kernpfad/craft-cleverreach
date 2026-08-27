# Tags + Queued Order Push

**Package:** `kernpfad/craft-cleverreach`  
**Branch:** `cursor/tags-and-order-queue-2610`  
**Date:** 2026-08-27  
**IDs:** CR-10 (Tags), CR-11 (Order-Push Queue)

## Goals

1. **CR-10 — Tags:** Apply CleverReach receiver tags from configurable events (order complete, subscribe, optional user sync) and via a public `TagService` + mutator event for project-specific tags — so CleverReach automations can trigger without rebuilding CR UI in Craft.
2. **CR-11 — Queued order push:** Move Commerce order push off the request thread into a debounced queue job that, on success, applies configured order-complete tags in the same job.

## Decisions

| Topic | Choice |
|---|---|
| Tag sources | Settings for order/subscribe/(optional) user-sync **plus** public `TagService` + `EVENT_BEFORE_APPLY_TAGS` |
| Order + tags | **One job:** push order first, then order tags only if push succeeded |
| Job payload | `orderId` only (reload Order at execute time) |
| Debounce | Same pattern as `SyncUserJob` / `SyncEnqueueGate` (cache `add` + delay) |
| Tag API calls | **One receiver at a time** — CleverReach does not fire automation triggers for batch tag writes |

## Architecture

### New / changed pieces

| Piece | Path / API |
|---|---|
| Tag normalize helper | `src/util/TagListParser.php` — split comma string → unique trimmed non-empty tags |
| Order enqueue gate | Extend pattern: `OrderEnqueueGate` (or generalize `SyncEnqueueGate` into a small shared gate with prefixes) — prefer **`OrderEnqueueGate`** next to existing gate to avoid breaking user-sync tests |
| API | `CleverReachApiService::addTags(int $groupId, string $email, array $tags): array` — dedicated receiver tag endpoint (verify exact path against CR v3 explorer during impl; do **not** rely on multi-receiver batch for triggers) |
| Service | `TagService::apply(string $email, array $tags, string $context, ?int $groupId = null): void` |
| Event | `Plugin::EVENT_BEFORE_APPLY_TAGS` + `BeforeApplyTagsEvent` (`email`, `tags`, `context`, `groupId`, `isValid`) |
| Settings | `orderCompleteTags`, `subscribeTags`, `userSyncTags` as `string` (comma-separated; empty = off) |
| Job | `jobs/PushOrderJob` — `enqueue(int $orderId)`, `execute` → `commerceOrderPush->pushOrderAndTag(...)` or push then tag |
| Plugin | Commerce handler → `PushOrderJob::enqueue`; register `tag` component |
| Subscribe | After successful consent write, apply `subscribeTags` if non-empty |
| User sync | After successful sync record `ok`, optionally apply `userSyncTags` |

### Order job flow

```
Order::EVENT_AFTER_COMPLETE_ORDER
  → PushOrderJob::enqueue(orderId)
       cache key cleverreach_order_enqueue_{orderId}, TTL ~30s, delay 5s
  → execute:
       clear gate key
       load Order by id (missing → no-op)
       existing gates: email, consent, unsubscribed, DOI confirmed
       pushOrderToReceiver(...)
       on success → TagService::applyFromSettings('orderComplete', email, groupId)
       on throw → Craft::error; do NOT apply order tags
```

### Tag apply flow

```
TagService::apply / applyFromSettings
  → parse/normalize tags (empty → return)
  → resolve groupId (arg → consent → settings default)
  → no group / no consent / unsubscribed → return (no create-from-tag)
  → EVENT_BEFORE_APPLY_TAGS (handlers may mutate tags or set isValid=false)
  → CleverReachApiService::addTags(...)  // single receiver
  → failures logged, not thrown to callers (except optional rethrow flag: no — keep soft)
```

### Settings UI

New section “Tags / Automationen” on settings screen: three text fields with instructions that tags must match CleverReach automation tag names; comma-separated.

## Testing

**Unit (no Craft boot):**

- `TagListParserTest` — trim, empties, dedupe, order preserved for first occurrence
- `OrderEnqueueGateTest` — cache key + TTL > delay
- Optional pure helper: `shouldApplyOrderTags(bool $pushSucceeded): bool`

**Integration (Craft test site, extend existing fakes):**

- Fake API records `addTags` / `pushOrderToReceiver` calls
- Complete order with consent + activated receiver → job run → push then tags
- Push throws → no `addTags` call
- Subscribe with `subscribeTags` set → tags applied after signup path (stubbed API)

Update `FakeCleverReachApiService` with `addTags()` recorder.

## Docs

- `ROADMAP.md` — CR-10 / CR-11 ✅ when done
- `CHANGELOG.md` — Unreleased notes
- `README.md` — queue worker already required; document tags + order job; warn that batch imports must not use TagService if automation re-trigger is undesired (single-receiver only is already the design)

## Out of scope

- CR-09 Auth-Code flow
- Consent-log CP browser
- Creating receivers from tags alone
- Queuing catalog / unrelated webhooks
- Removing synchronous path entirely from `CommerceOrderPushService::pushOrder` — keep method for job + tests; only the event handler switches to enqueue

## Success criteria

- Order complete does not block on CleverReach HTTP
- Configured order tags run only after successful push, same job
- Subscribe / optional user-sync tags work from settings
- Projects can add/cancel tags via `EVENT_BEFORE_APPLY_TAGS` or `TagService::apply`
- `composer check` green; integration cases extended where Craft site is available
