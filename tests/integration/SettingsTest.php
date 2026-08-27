<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration;

/**
 * Boots a real Craft console application and validates
 * `kernpfad\cleverreach\models\Settings` — this can't be a unit test
 * because Yii's validators (and `Craft::t()` inside
 * `Settings::validateDoiFormId()`) need the `Yii`/`Craft` classes actually
 * loaded, which tests/unit deliberately never does.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft install with
 * this plugin linked in via a Composer path repository. Skips itself if
 * that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here.
 */

use kernpfad\cleverreach\models\Settings;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
class SettingsTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        $sitePath = getenv('CRAFT_TEST_SITE_PATH');

        if (!$sitePath || !is_dir($sitePath)) {
            $this->markTestSkipped(
                'CRAFT_TEST_SITE_PATH is not set to a working Craft install; skipping integration tests.'
            );
        }

        if (!self::$booted) {
            define('CRAFT_BASE_PATH', $sitePath);
            define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
            require_once CRAFT_VENDOR_PATH . '/autoload.php';

            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();
            }

            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
            self::$booted = true;
        }
    }

    public function testDefaultGroupWithoutDoiFormIsInvalid(): void
    {
        // A default group without a DOI form would create inactive
        // receivers that never receive a confirmation mail — see
        // Settings::validateDoiFormId().
        $settings = new Settings();
        $settings->defaultGroupId = 42;
        $settings->doiFormId = null;

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('doiFormId', $settings->getErrors());
    }

    public function testDefaultGroupWithDoiFormIsValid(): void
    {
        $settings = new Settings();
        $settings->defaultGroupId = 42;
        $settings->doiFormId = 7;

        self::assertTrue($settings->validate(), implode(', ', $settings->getErrorSummary(true)));
    }

    public function testNoDefaultGroupDoesNotRequireADoiForm(): void
    {
        $settings = new Settings();
        $settings->defaultGroupId = null;
        $settings->doiFormId = null;

        self::assertTrue($settings->validate(), implode(', ', $settings->getErrorSummary(true)));
    }
}
