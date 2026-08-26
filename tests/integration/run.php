#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Soft-entry for composer test:integration until Craft-booted cases land.
 * Exits 0 when CRAFT_TEST_SITE_PATH is missing so CI/unit-only envs stay green.
 */

$site = getenv('CRAFT_TEST_SITE_PATH') ?: '';

if ($site === '' || !is_dir($site)) {
    fwrite(STDERR, "test:integration: CRAFT_TEST_SITE_PATH not set/found — skipping.\n");
    fwrite(STDERR, "See tests/integration/README.md and docs/superpowers/prompts/2026-08-26-craft-integration-tests-agent.md\n");
    exit(0);
}

fwrite(STDERR, "test:integration: Craft test site found at {$site}\n");
fwrite(STDERR, "test:integration: no Craft-booted cases in this checkout yet — see the agent prompt to add IT-01–IT-07.\n");
exit(0);
