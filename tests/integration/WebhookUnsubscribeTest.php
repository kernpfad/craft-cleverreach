<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration;

/**
 * Boots a real Craft *web* application in-process (not console, which
 * UserSyncQueueTest uses) and drives
 * WebhookController::actionUnsubscribe() through Craft::$app->runAction() —
 * the same route resolution, `allowAnonymous` handling, and CSRF-exemption
 * a real HTTP POST to actions/cleverreach/webhook/unsubscribe goes through,
 * just without an actual HTTP round trip. That keeps the webhook secret
 * swappable per test in-memory, rather than needing a separate php-fpm
 * process to notice a persisted project-config change.
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
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use yii\web\NotFoundHttpException;
use yii\web\Response;

#[RunClassInSeparateProcess]
class WebhookUnsubscribeTest extends TestCase
{
    private static bool $booted = false;

    private ?string $originalWebhookSecret = null;

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
            // Craft's web bootstrap needs enough $_SERVER state to resolve
            // the entry script URL and a site — there's no real nginx/PHP-FPM
            // request behind this process to supply it.
            $_SERVER['SCRIPT_FILENAME'] = $sitePath . '/web/index.php';
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['SERVER_PORT'] = '80';
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
            self::$booted = true;
        }

        $plugin = Plugin::getInstance();
        self::assertNotNull($plugin, 'CleverReach plugin is not installed on the test install.');

        // yii\base\Request::getIsConsoleRequest() falls back to
        // `PHP_SAPI === 'cli'` when never explicitly set, which is true for
        // this PHPUnit process. Plugins are loaded (and Plugin::init() runs)
        // as a side effect of Craft::createObject() above, before this test
        // gets a chance to say otherwise — so Plugin::init() already decided
        // on the console controllerNamespace by the time we get here. Put
        // it back to the web one so runAction() finds WebhookController.
        $plugin->controllerNamespace = 'kernpfad\\cleverreach\\controllers';

        $this->originalWebhookSecret = $plugin->getSettings()->webhookSecret;
        $this->createdEmails = [];
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            // setUp() skipped before booting Craft (no CRAFT_TEST_SITE_PATH)
            // — nothing was created, and Plugin/Craft aren't loaded to clean up with.
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin !== null && $this->originalWebhookSecret !== null) {
            $plugin->getSettings()->webhookSecret = $this->originalWebhookSecret;
        }

        foreach ($this->createdEmails as $email) {
            ConsentLogRecord::deleteAll(['email' => $email]);
        }
    }

    public function testAnEmptyConfiguredSecretIs404WhateverIsSent(): void
    {
        Plugin::getInstance()->getSettings()->webhookSecret = '';

        $this->expectException(NotFoundHttpException::class);
        $this->postUnsubscribe(secret: 'anything', email: 'someone@example.test');
    }

    public function testAWrongSecretIs404(): void
    {
        Plugin::getInstance()->getSettings()->webhookSecret = 'the-real-secret';

        $this->expectException(NotFoundHttpException::class);
        $this->postUnsubscribe(secret: 'not-the-real-secret', email: 'someone@example.test');
    }

    public function testACorrectSecretAndKnownEmailMarksTheConsentRecordUnsubscribed(): void
    {
        Plugin::getInstance()->getSettings()->webhookSecret = 'the-real-secret';

        $email = sprintf('cleverreach-it-webhook-%s@example.test', bin2hex(random_bytes(4)));
        $this->createdEmails[] = $email;

        Plugin::getInstance()->consent->logConsent(
            email: $email,
            ipAddress: '127.0.0.1',
            source: 'integration-test',
            consentTextVersion: null,
            groupId: null,
        );

        $response = $this->postUnsubscribe(secret: 'the-real-secret', email: $email);

        self::assertSame(200, $response->getStatusCode());

        $record = ConsentLogRecord::findOne(['email' => $email]);
        self::assertNotNull($record);
        self::assertNotNull($record->unsubscribedAt, 'Expected unsubscribedAt to be set.');
    }

    private function postUnsubscribe(string $secret, string $email): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = Craft::$app->getRequest();
        $request->setQueryParams(['secret' => $secret]);
        $request->setBodyParams(['email' => $email]);
        $request->getHeaders()->set('Accept', 'application/json');

        /** @var Response $response */
        $response = Craft::$app->runAction('cleverreach/webhook/unsubscribe');

        return $response;
    }
}
