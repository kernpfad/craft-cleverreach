# Integration tests

These tests boot Craft against the shared Kernpfad Craft test site
(`CRAFT_TEST_SITE_PATH`). They are **not** run by `composer check` / CI
unit jobs.

## Run

```sh
export CRAFT_TEST_SITE_PATH=/path/to/craft-test-site
composer test:integration
```

If `CRAFT_TEST_SITE_PATH` is unset, the script exits 0 and prints a skip
message (same behaviour as `.githooks/pre-push`).

## Agent prompt

Full case list (IT-01–IT-07) and stubbing guidance:

`docs/superpowers/prompts/2026-08-26-craft-integration-tests-agent.md`

Concrete PHPUnit cases should be added by an agent with the Craft test
site available; this directory is the home for that suite.
