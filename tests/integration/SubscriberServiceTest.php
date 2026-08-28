<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration;

/**
 * Boots a real Craft *console* application and drives
 * `SubscriberService::subscribe()` — the same entry point
 * `SubscribeController` and the Formie email marketing integration both
 * call — directly (there's no queue job in the subscribe path to drive
 * this through an event the way `UserSyncQueueTest`/
 * `CommerceOrderPushServiceTest` do).
 *
 * The CleverReach API is swapped for {@see fakes\FakeCleverReachApiService}
 * so nothing here ever makes a real network call — including the DOI
 * create-receiver and send-mail calls the real subscribe flow always makes.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft install with
 * this plugin linked in via a Composer path repository. Skips itself if
 * that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here.
 */

use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;
use kernpfad\cleverreach\tests\integration\fakes\FakeCleverReachApiService;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
class SubscriberServiceTest extends TestCase
{
    private static bool $booted = false;

    private FakeCleverReachApiService $fakeApi;

    /** @var list<string> */
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $sitePath = getenv('CRAFT_TEST_SITE_PATH');

        if (!$sitePath || !is_dir($sitePath)) {
            $this->markTestSkipped(
                'CRAFT_TEST_SITE_PATH is not set to a working Craft install; skipping integration tests.'
            );
        }

        if (!defined('CRAFT_BASE_PATH')) {
            define('CRAFT_BASE_PATH', $sitePath);
            define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
            require_once CRAFT_VENDOR_PATH . '/autoload.php';

            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();
            }
        }

        if (!self::$booted) {
            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
            self::$booted = true;
        }

        $plugin = Plugin::getInstance();
        self::assertNotNull($plugin, 'CleverReach plugin is not installed on the test install.');

        $this->fakeApi = new FakeCleverReachApiService();
        $plugin->set('cleverReachApi', $this->fakeApi);

        $this->createdEmails = [];
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            return;
        }

        foreach ($this->createdEmails as $email) {
            ConsentLogRecord::deleteAll(['email' => $email]);
        }
    }

    public function testSubscribingWithSubscribeTagsConfiguredAppliesThemAfterSignup(): void
    {
        $email = $this->uniqueEmail('subscribe-tags');

        $settings = Plugin::getInstance()->getSettings();
        $previousTags = $settings->subscribeTags;
        $settings->subscribeTags = 'newsletter, welcome';

        try {
            self::assertTrue(
                Plugin::getInstance()->subscriber->subscribe(
                    email: $email,
                    formData: [],
                    source: 'integration-test',
                    ipAddress: '127.0.0.1',
                    groupId: 701,
                )
            );
        } finally {
            $settings->subscribeTags = $previousTags;
        }

        $methods = array_column($this->fakeApi->calls, 'method');
        self::assertContains('addTags', $methods, 'Expected addTags to be called after a successful subscribe.');

        $tagCall = $this->fakeApi->calls[array_search('addTags', $methods, true)];
        self::assertSame(['newsletter', 'welcome'], $tagCall['tags']);
        self::assertSame($email, $tagCall['email']);
        self::assertSame(701, $tagCall['groupId']);
    }

    public function testSubscribingWithoutSubscribeTagsConfiguredNeverCallsAddTags(): void
    {
        $email = $this->uniqueEmail('no-subscribe-tags');

        $settings = Plugin::getInstance()->getSettings();
        self::assertSame('', $settings->subscribeTags, 'Expected the shared test install to have subscribeTags unconfigured.');

        self::assertTrue(
            Plugin::getInstance()->subscriber->subscribe(
                email: $email,
                formData: [],
                source: 'integration-test',
                ipAddress: '127.0.0.1',
                groupId: 702,
            )
        );

        self::assertNotContains('addTags', array_column($this->fakeApi->calls, 'method'));
    }

    private function uniqueEmail(string $label): string
    {
        $email = sprintf('cleverreach-it-subscribe-%s-%s@example.test', $label, bin2hex(random_bytes(4)));
        $this->createdEmails[] = $email;

        return $email;
    }
}
