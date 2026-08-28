<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration;

/**
 * Boots a real Craft *console* application and drives the actual
 * production pipeline: saving a Craft `User` fires `User::EVENT_AFTER_SAVE`,
 * which enqueues the debounced {@see \kernpfad\cleverreach\jobs\SyncUserJob}
 * exactly as it does in production — nothing here calls
 * `SyncUserJob::execute()` or `UserSyncService::syncUser()` directly. The
 * console app is needed (not the web boot used by WebhookUnsubscribeTest)
 * because `queue/run` is a console command.
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

use Craft;
use craft\db\Query;
use craft\elements\User;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;
use kernpfad\cleverreach\records\UserSyncRecord;
use kernpfad\cleverreach\tests\integration\fakes\FakeCleverReachApiService;
use kernpfad\cleverreach\util\SyncEnqueueGate;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
class UserSyncQueueTest extends TestCase
{
    private static bool $booted = false;

    private FakeCleverReachApiService $fakeApi;

    /** @var list<int> */
    private array $createdUserIds = [];

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

        $this->createdUserIds = [];
        $this->createdEmails = [];
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            // setUp() skipped before booting Craft (no CRAFT_TEST_SITE_PATH)
            // — nothing was created, and Plugin/Craft aren't loaded to clean up with.
            return;
        }

        foreach ($this->createdUserIds as $userId) {
            $user = User::find()->id($userId)->status(null)->one();
            if ($user instanceof User) {
                Craft::$app->getElements()->deleteElement($user, true);
            }

            Craft::$app->getCache()?->delete(SyncEnqueueGate::cacheKey($userId));
            $this->deleteQueueRowsFor($userId);
        }

        foreach ($this->createdEmails as $email) {
            ConsentLogRecord::deleteAll(['email' => $email]);
        }
    }

    public function testSavingAUserWithoutConsentNeverProducesASyncRecord(): void
    {
        // Note: SyncUserJob is enqueued on *every* user save regardless of
        // consent (Plugin::attachUserEventHandlers has no consent check) —
        // only running the job is a no-op without consent. This asserts
        // that observable effect, not the presence/absence of a queue row.
        $user = $this->createUser('it01');

        $this->runDueJobsFor((int) $user->id);

        self::assertNull(
            UserSyncRecord::findOne(['userId' => $user->id]),
            'A user with no CleverReach consent record must not get a sync row.'
        );
        self::assertSame([], $this->fakeApi->calls, 'No CleverReach API call should have been made.');
    }

    public function testSavingAUserWithConsentEnqueuesAndSyncsOnRun(): void
    {
        $user = $this->createUser('it02');
        $this->givenConsent($user, groupId: 501);

        self::assertTrue(
            Craft::$app->getCache()->exists(SyncEnqueueGate::cacheKey((int) $user->id)),
            'Expected the debounce cache key to be set right after save.'
        );
        self::assertSame(
            1,
            $this->pendingJobCountFor((int) $user->id),
            'Expected exactly one pending SyncUserJob for the saved user.'
        );

        $this->runDueJobsFor((int) $user->id);

        self::assertCount(1, $this->fakeApi->calls);
        self::assertSame('activateReceiver', $this->fakeApi->calls[0]['method']);
        self::assertSame($user->email, $this->fakeApi->calls[0]['email']);
        self::assertSame(501, $this->fakeApi->calls[0]['groupId']);

        $record = UserSyncRecord::findOne(['userId' => $user->id]);
        self::assertNotNull($record);
        self::assertSame('ok', $record->status);
        self::assertTrue((bool) $record->doiConfirmed);

        self::assertFalse(
            Craft::$app->getCache()->exists(SyncEnqueueGate::cacheKey((int) $user->id)),
            'The debounce cache key should be cleared once the job has run.'
        );
    }

    public function testTwoSavesWithinTheDebounceWindowOnlyEverEnqueueOneJob(): void
    {
        $user = $this->createUser('it03');
        $this->givenConsent($user, groupId: 502);

        self::assertSame(
            1,
            $this->pendingJobCountFor((int) $user->id),
            'Expected exactly one pending job after the first save.'
        );

        // Second save happens immediately, well inside the 30s debounce
        // window — the cache gate must stop a second job from stacking up.
        $user->firstName = 'Changed';
        self::assertTrue(Craft::$app->getElements()->saveElement($user));

        self::assertSame(
            1,
            $this->pendingJobCountFor((int) $user->id),
            'A second save inside the debounce window must not enqueue a second job.'
        );

        $this->runDueJobsFor((int) $user->id);

        self::assertCount(1, $this->fakeApi->calls, 'Only one sync attempt should have run.');
    }

    public function testAPendingReceiverIsSoftUpdatedWithoutActivation(): void
    {
        $user = $this->createUser('it07-pending');
        $this->givenConsent($user, groupId: 503);
        $this->fakeApi->receiverToReturn = ['email' => $user->email, 'activated' => false];

        $this->runDueJobsFor((int) $user->id);

        self::assertCount(1, $this->fakeApi->calls);
        self::assertSame('updateReceiverAttributes', $this->fakeApi->calls[0]['method']);

        $record = UserSyncRecord::findOne(['userId' => $user->id]);
        self::assertNotNull($record);
        self::assertFalse((bool) $record->doiConfirmed);
    }

    public function testAConfirmedReceiverIsActivated(): void
    {
        $user = $this->createUser('it07-confirmed');
        $this->givenConsent($user, groupId: 504);
        $this->fakeApi->receiverToReturn = ['email' => $user->email, 'activated' => time()];

        $this->runDueJobsFor((int) $user->id);

        self::assertCount(1, $this->fakeApi->calls);
        self::assertSame('activateReceiver', $this->fakeApi->calls[0]['method']);

        $record = UserSyncRecord::findOne(['userId' => $user->id]);
        self::assertNotNull($record);
        self::assertTrue((bool) $record->doiConfirmed);
    }

    public function testUserSyncAppliesConfiguredTagsAfterASuccessfulSync(): void
    {
        $user = $this->createUser('it-tags');
        $this->givenConsent($user, groupId: 505);
        $this->fakeApi->receiverToReturn = ['email' => $user->email, 'activated' => time()];

        $settings = Plugin::getInstance()->getSettings();
        $previousTags = $settings->userSyncTags;
        $settings->userSyncTags = 'synced, crm';

        try {
            $this->runDueJobsFor((int) $user->id);
        } finally {
            $settings->userSyncTags = $previousTags;
        }

        $methods = array_column($this->fakeApi->calls, 'method');
        self::assertSame(['activateReceiver', 'addTags'], $methods);
        self::assertSame(['synced', 'crm'], $this->fakeApi->calls[1]['tags']);
        self::assertSame($user->email, $this->fakeApi->calls[1]['email']);
        self::assertSame(505, $this->fakeApi->calls[1]['groupId']);
    }

    public function testUserSyncTagsAreNotAppliedWhenUserSyncTagsIsEmpty(): void
    {
        // Default install state — asserted explicitly so a future default
        // change doesn't silently start tagging every synced user.
        $user = $this->createUser('it-no-tags');
        $this->givenConsent($user, groupId: 506);
        $this->fakeApi->receiverToReturn = ['email' => $user->email, 'activated' => time()];

        $settings = Plugin::getInstance()->getSettings();
        self::assertSame('', $settings->userSyncTags, 'Expected the shared test install to have userSyncTags unconfigured.');

        $this->runDueJobsFor((int) $user->id);

        self::assertSame(['activateReceiver'], array_column($this->fakeApi->calls, 'method'));
    }

    private function createUser(string $label): User
    {
        $email = sprintf('cleverreach-it-%s-%s@example.test', $label, bin2hex(random_bytes(4)));

        $user = new User();
        $user->username = $email;
        $user->email = $email;
        $user->firstName = 'Integration';
        $user->lastName = 'Test';

        self::assertTrue(
            Craft::$app->getElements()->saveElement($user),
            'Could not create the test user: ' . json_encode($user->getErrors())
        );

        $this->createdUserIds[] = (int) $user->id;
        $this->createdEmails[] = $email;

        return $user;
    }

    private function givenConsent(User $user, int $groupId): void
    {
        // createUser() already triggered one (no-consent) save/enqueue as a
        // side effect of getting the user its ID — clear that debounce
        // state so the save below, the one each test actually means to
        // exercise, is the one that's under test.
        Craft::$app->getCache()?->delete(SyncEnqueueGate::cacheKey((int) $user->id));
        $this->deleteQueueRowsFor((int) $user->id);

        Plugin::getInstance()->consent->logConsent(
            email: (string) $user->email,
            ipAddress: '127.0.0.1',
            source: 'integration-test',
            consentTextVersion: null,
            groupId: $groupId,
            userId: (int) $user->id,
        );

        // Re-save now that consent exists, to trigger the debounced enqueue
        // under test on a user CleverReach actually has a consent record for.
        self::assertTrue(Craft::$app->getElements()->saveElement($user));
    }

    private function pendingJobCountFor(int $userId): int
    {
        return (int) (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'description', '%user ' . $userId . '%', false])
            ->andWhere(['fail' => false])
            ->count();
    }

    /**
     * Makes any pending SyncUserJob for this user immediately due (bypassing
     * the real 5s delay) and runs it via the real `queue/run` console
     * command path — not a direct call into SyncUserJob::execute().
     */
    private function runDueJobsFor(int $userId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%queue}}', ['delay' => 0, 'timePushed' => 0], [
                'and',
                ['like', 'description', '%user ' . $userId . '%', false],
                ['fail' => false],
            ])
            ->execute();

        // isolate=false: yii2-queue's default isolate mode forks each job
        // into a child process via `<entrypoint> queue/exec ...`, using the
        // currently-running script as the entrypoint — that's the PHPUnit
        // binary here, not `craft`, so it must be disabled to run in-process.
        Craft::$app->runAction('queue/run', ['isolate' => false]);
    }

    private function deleteQueueRowsFor(int $userId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', ['like', 'description', '%user ' . $userId . '%', false])
            ->execute();
    }
}
