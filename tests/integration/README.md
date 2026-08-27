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
  enqueueing one job (IT-03), and the CR-06 soft-sync split between a
  pending and a confirmed receiver (IT-07).
- `WebhookUnsubscribeTest` — boots a **web** app in-process and drives
  `WebhookController::actionUnsubscribe()` via `Craft::$app->runAction()`:
  empty/wrong secret is 404 (IT-04/IT-05), correct secret + known email
  sets `unsubscribedAt` (IT-06).

Both stub out `CleverReachApiService` with
`tests/integration/fakes/FakeCleverReachApiService.php` — nothing here
ever makes a real network call to CleverReach.

Two separate app boots are used because `queue/run` is a console-only
command and `WebhookController` is a web controller; each test class boots
its own Craft application in-process and skips itself if
`CRAFT_TEST_SITE_PATH` isn't configured.
