# Integration tests

These tests boot a real Craft application against the shared Kernpfad
Craft test site (`CRAFT_TEST_SITE_PATH`). They are **not** run by
`composer check` / CI unit jobs — only `composer test:integration`.

## Run

```sh
export CRAFT_TEST_SITE_PATH=/path/to/craft-test-site
composer test:integration
```

If `CRAFT_TEST_SITE_PATH` is unset (or not a directory), every test
`markTestSkipped()`s itself and the run still exits 0 — same as CI/unit-only
environments.

The plugin must already be linked into that install via a Composer path
repository and enabled (`php craft plugin/install cleverreach`). If the
install has pending migrations, run `php craft migrate/all` first — Craft
serves a 503 for every request (including these in-process boots) until
that's done.

## What's covered

- `UserSyncQueueTest` — boots a **console** app (needed for the real
  `queue/run` command) and drives `SyncUserJob` through real `User` saves:
  no sync record without consent (IT-01), enqueue + sync-on-run with
  consent (IT-02), two saves inside the debounce window only ever
  enqueueing one job (IT-03), the CR-06 soft-sync split between a
  pending and a confirmed receiver (IT-07), and `userSyncTags` applied
  after a successful sync (CR-10) vs. not applied when unconfigured.
- `WebhookUnsubscribeTest` — boots a **web** app in-process and drives
  `WebhookController::actionUnsubscribe()` via `Craft::$app->runAction()`:
  empty/wrong secret is 404 (IT-04/IT-05), correct secret + known email
  sets `unsubscribedAt` (IT-06).
- `CommerceOrderPushServiceTest` — boots a **console** app and drives
  `PushOrderJob` through a real completed Commerce order + `queue/run`
  (CR-11): no push without consent / when unsubscribed, the CR-06
  pending-receiver guard, a confirmed receiver getting pushed with the
  right payload, two enqueues for the same order inside the debounce
  window only ever producing one effective push, `orderCompleteTags`
  applied only after a successful push (CR-10), and a CleverReach failure
  during push neither applying tags nor breaking order completion.
- `SubscriberServiceTest` — boots a **console** app and drives
  `SubscriberService::subscribe()` directly (there's no queue job in the
  subscribe path): `subscribeTags` applied after a successful signup
  (CR-10) vs. not applied when unconfigured.
- `TagServiceTest` — boots a **console** app and drives `TagService::apply()`
  directly: no receiver created from tags alone (no consent record /
  unsubscribed → no `addTags` call), and `Plugin::EVENT_BEFORE_APPLY_TAGS`
  cancelling (`isValid = false`) or mutating the tags/group ID before the
  call.
- `SettingsTest` — boots a **console** app and validates
  `Settings::validateDoiFormId()`; can't be a unit test because Yii's
  validators (and `Craft::t()` inside that method) need the `Yii`/`Craft`
  classes actually loaded, which `tests/unit` deliberately never does.

All of these stub out `CleverReachApiService` with
`tests/integration/fakes/FakeCleverReachApiService.php` — nothing here
ever makes a real network call to CleverReach.

Two app boot types are used because `queue/run` is a console-only command
and `WebhookController` is a web controller; each test class boots its own
Craft application in-process (`#[RunClassInSeparateProcess]` keeps a web
boot and a console boot from colliding in one PHP process — Craft's
bootstrap files `require` core Yii/Craft classes unconditionally and fatal
on a second load) and skips itself if `CRAFT_TEST_SITE_PATH` isn't
configured.

Settings changed for a test (`orderCompleteTags`, `subscribeTags`,
`userSyncTags`, `webhookSecret`) are always restored in a `finally` block
— these mutate the in-process `Settings` singleton directly, not
persisted project config, so nothing needs cleaning up on disk, but a
left-over value would otherwise leak into whichever test runs next in the
same process.
