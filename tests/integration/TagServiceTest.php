<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration;

/**
 * Boots a real Craft *console* application and drives
 * `TagService::apply()` directly (its callers —
 * `CommerceOrderPushService`, `SubscriberService`, `UserSyncService` — are
 * covered by their own integration suites; this one is for `TagService`'s
 * own rules: no receiver created from tags alone, and
 * `Plugin::EVENT_BEFORE_APPLY_TAGS`).
 *
 * The CleverReach API is swapped for {@see fakes\FakeCleverReachApiService}
 * so nothing here ever makes a real network call.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft install with
 * this plugin linked in via a Composer path repository. Skips itself if
 * that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here.
 */

use craft\helpers\Db;
use DateTime;
use kernpfad\cleverreach\events\BeforeApplyTagsEvent;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;
use kernpfad\cleverreach\tests\integration\fakes\FakeCleverReachApiService;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
class TagServiceTest extends TestCase
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

    public function testApplyWithNoConsentRecordNeverCallsTheApi(): void
    {
        // Never create a receiver from tags alone — same "existing consent
        // required" rule as CommerceOrderPushService/UserSyncService.
        $email = $this->uniqueEmail('no-consent');

        Plugin::getInstance()->tags->apply($email, ['vip'], 'custom', 801);

        self::assertSame([], $this->fakeApi->calls);
    }

    public function testApplyForAnUnsubscribedConsentRecordNeverCallsTheApi(): void
    {
        $email = $this->uniqueEmail('unsubscribed');
        $this->givenConsent($email, groupId: 802, unsubscribed: true);

        Plugin::getInstance()->tags->apply($email, ['vip'], 'custom', 802);

        self::assertSame([], $this->fakeApi->calls);
    }

    public function testApplyWithAConsentRecordCallsAddTags(): void
    {
        $email = $this->uniqueEmail('consenting');
        $this->givenConsent($email, groupId: 803);

        Plugin::getInstance()->tags->apply($email, ['vip', 'vip'], 'custom', 803);

        self::assertCount(1, $this->fakeApi->calls);
        self::assertSame('addTags', $this->fakeApi->calls[0]['method']);
        self::assertSame(['vip'], $this->fakeApi->calls[0]['tags'], 'Expected tags to be de-duplicated.');
        self::assertSame(803, $this->fakeApi->calls[0]['groupId']);
        self::assertSame($email, $this->fakeApi->calls[0]['email']);
    }

    public function testBeforeApplyTagsCanCancelTheCall(): void
    {
        $email = $this->uniqueEmail('cancelled');
        $this->givenConsent($email, groupId: 804);

        $handler = function(BeforeApplyTagsEvent $event): void {
            $event->isValid = false;
        };
        $plugin = Plugin::getInstance();
        $plugin->on(Plugin::EVENT_BEFORE_APPLY_TAGS, $handler);

        try {
            $plugin->tags->apply($email, ['vip'], 'custom', 804);
        } finally {
            $plugin->off(Plugin::EVENT_BEFORE_APPLY_TAGS, $handler);
        }

        self::assertSame([], $this->fakeApi->calls, 'A cancelled EVENT_BEFORE_APPLY_TAGS must not call addTags.');
    }

    public function testBeforeApplyTagsCanMutateTheTagsAndGroupId(): void
    {
        $email = $this->uniqueEmail('mutated');
        $this->givenConsent($email, groupId: 805);

        $handler = function(BeforeApplyTagsEvent $event): void {
            $event->tags = ['overridden'];
            $event->groupId = 999;
        };
        $plugin = Plugin::getInstance();
        $plugin->on(Plugin::EVENT_BEFORE_APPLY_TAGS, $handler);

        try {
            // No explicit groupId passed — resolves from the consent record
            // (805) unless the handler below overrides it, so a passing
            // assertion on 999 proves the mutation actually took effect.
            $plugin->tags->apply($email, ['original'], 'custom');
        } finally {
            $plugin->off(Plugin::EVENT_BEFORE_APPLY_TAGS, $handler);
        }

        self::assertCount(1, $this->fakeApi->calls);
        self::assertSame(['overridden'], $this->fakeApi->calls[0]['tags']);
        self::assertSame(999, $this->fakeApi->calls[0]['groupId']);
    }

    private function uniqueEmail(string $label): string
    {
        $email = sprintf('cleverreach-it-tags-%s-%s@example.test', $label, bin2hex(random_bytes(4)));
        $this->createdEmails[] = $email;

        return $email;
    }

    private function givenConsent(string $email, int $groupId, bool $unsubscribed = false): void
    {
        Plugin::getInstance()->consent->logConsent(
            email: $email,
            ipAddress: '127.0.0.1',
            source: 'integration-test',
            consentTextVersion: null,
            groupId: $groupId,
        );

        if (!$unsubscribed) {
            return;
        }

        $record = Plugin::getInstance()->consent->getLatestConsent(null, $email);
        self::assertNotNull($record);
        $record->unsubscribedAt = Db::prepareDateForDb(new DateTime());
        self::assertTrue($record->save(false));
    }
}
