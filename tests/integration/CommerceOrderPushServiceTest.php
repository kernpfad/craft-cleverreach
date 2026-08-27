<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration;

/**
 * Boots a real Craft + Commerce console application and drives
 * `CommerceOrderPushService` through the actual production pipeline:
 * create a real product/order, complete it, and let
 * `Order::EVENT_AFTER_COMPLETE_ORDER` fire through
 * `Plugin::attachCommerceEventHandlers()` into a debounced
 * {@see \kernpfad\cleverreach\jobs\PushOrderJob}. Tests force the job due
 * and run it via `queue/run` — they never call `pushOrder()` directly.
 *
 * `Plugin::attachCommerceEventHandlers()` only runs at `Plugin::init()` if
 * `enableOrderPush` was already `true` in project config *before* boot —
 * toggling the setting at runtime here would be too late to attach the
 * listener. The shared test install already has it enabled
 * (`config/project/project.yaml`); if it doesn't, these tests skip
 * themselves with an explanation rather than silently testing nothing.
 *
 * The CleverReach API is swapped for
 * {@see fakes\FakeCleverReachApiService} so nothing here ever makes a real
 * network call.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft + Commerce
 * install with this plugin linked in via a Composer path repository.
 * Skips itself if that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here.
 */

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;
use kernpfad\cleverreach\tests\integration\fakes\FakeCleverReachApiService;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
class CommerceOrderPushServiceTest extends TestCase
{
    private static bool $booted = false;

    private FakeCleverReachApiService $fakeApi;

    /** @var list<string> */
    private array $createdEmails = [];

    /** @var list<int> */
    private array $createdUserIds = [];

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

        if (!class_exists(Commerce::class) || Commerce::getInstance() === null) {
            $this->markTestSkipped('Craft Commerce is not installed on the test install; skipping.');
        }

        $plugin = Plugin::getInstance();
        self::assertNotNull($plugin, 'CleverReach plugin is not installed on the test install.');

        if (!$plugin->getSettings()->enableOrderPush) {
            $this->markTestSkipped(
                'enableOrderPush is off on this install, so Plugin::attachCommerceEventHandlers() '
                . 'never ran at boot and these tests would exercise nothing real. Enable it in '
                . 'config/project/project.yaml on the shared test install.'
            );
        }

        $this->fakeApi = new FakeCleverReachApiService();
        $plugin->set('cleverReachApi', $this->fakeApi);

        // The shared test install has other kernpfad plugins (notably
        // craft-commerce-doofinder) listening on every Product/Variant
        // EVENT_AFTER_SAVE too, blindly reading field handles from their
        // own config (categoriesFieldHandle, fieldMappingRows,
        // imageFieldHandle) that this test's ad-hoc product type has no
        // reason to carry — an undefined handle throws
        // InvalidFieldException there, unrelated to anything under test.
        // cleverreach itself only listens on Order::EVENT_AFTER_COMPLETE_ORDER
        // (a different event entirely), so detaching these in this
        // RunClassInSeparateProcess-isolated process is safe: nothing this
        // test asserts on depends on Doofinder's listeners, and the
        // detachment doesn't survive past this process.
        \yii\base\Event::off(\craft\commerce\elements\Product::class, \craft\base\Element::EVENT_AFTER_SAVE);
        \yii\base\Event::off(\craft\commerce\elements\Variant::class, \craft\base\Element::EVENT_AFTER_SAVE);

        $this->createdEmails = [];
        $this->createdUserIds = [];
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            return;
        }

        foreach ($this->createdUserIds as $userId) {
            $user = User::find()->id($userId)->status(null)->one();
            if ($user instanceof User) {
                Craft::$app->getElements()->deleteElement($user, true);
            }
        }

        foreach ($this->createdEmails as $email) {
            ConsentLogRecord::deleteAll(['email' => $email]);
        }
    }

    public function testAnOrderWithNoConsentRecordIsNeverPushed(): void
    {
        $order = $this->createOrder($this->uniqueEmail('no-consent'));

        $order->markAsComplete();
        $this->runQueuedOrderPush((int) $order->id);

        self::assertSame([], $this->fakeApi->calls);
    }

    public function testAnUnsubscribedConsentRecordIsNeverPushed(): void
    {
        $email = $this->uniqueEmail('unsubscribed');
        $this->givenConsent($email, groupId: 601, unsubscribed: true);

        $order = $this->createOrder($email);
        $order->markAsComplete();
        $this->runQueuedOrderPush((int) $order->id);

        self::assertSame([], $this->fakeApi->calls);
    }

    public function testAPendingReceiverIsNeverForceActivatedByAnOrder(): void
    {
        // CR-06: pushOrderToReceiver always sends activated:true, which
        // would force-activate a receiver still waiting on DOI confirmation
        // — must be skipped entirely, unlike a profile sync's soft-update.
        $email = $this->uniqueEmail('pending');
        $this->givenConsent($email, groupId: 602);
        $this->fakeApi->receiverToReturn = ['email' => $email, 'activated' => false];

        $order = $this->createOrder($email);
        $order->markAsComplete();
        $this->runQueuedOrderPush((int) $order->id);

        self::assertSame([], $this->fakeApi->calls);
    }

    public function testAConfirmedReceiverGetsTheOrderPushed(): void
    {
        $email = $this->uniqueEmail('confirmed');
        $this->givenConsent($email, groupId: 603);
        $this->fakeApi->receiverToReturn = ['email' => $email, 'activated' => time()];

        $order = $this->createOrder($email, qty: 2, unitPrice: 15.00);
        $order->markAsComplete();
        $this->runQueuedOrderPush((int) $order->id);

        self::assertCount(1, $this->fakeApi->calls);
        $call = $this->fakeApi->calls[0];
        self::assertSame('pushOrderToReceiver', $call['method']);
        self::assertSame(603, $call['groupId']);
        self::assertSame($email, $call['email']);
        self::assertSame($order->number, $call['orderPayload']['order_id']);
        self::assertSame(30.0, $call['orderPayload']['total']);
        self::assertSame('USD', $call['orderPayload']['currency']);
        self::assertCount(1, $call['orderPayload']['items']);
        self::assertSame(2, $call['orderPayload']['items'][0]['quantity']);
    }

    public function testSuccessfulPushAppliesOrderCompleteTags(): void
    {
        $email = $this->uniqueEmail('tagged');
        $this->givenConsent($email, groupId: 605);
        $this->fakeApi->receiverToReturn = ['email' => $email, 'activated' => true];

        $settings = Plugin::getInstance()->getSettings();
        $previousTags = $settings->orderCompleteTags;
        $settings->orderCompleteTags = 'purchased, customer';

        try {
            $order = $this->createOrder($email);
            $order->markAsComplete();
            $this->runQueuedOrderPush((int) $order->id);
        } finally {
            $settings->orderCompleteTags = $previousTags;
        }

        $methods = array_column($this->fakeApi->calls, 'method');
        self::assertSame(['pushOrderToReceiver', 'addTags'], $methods);
        self::assertSame(['purchased', 'customer'], $this->fakeApi->calls[1]['tags']);
        self::assertSame(605, $this->fakeApi->calls[1]['groupId']);
    }

    public function testAFailedPushDoesNotApplyTagsOrBreakCompletion(): void
    {
        $email = $this->uniqueEmail('api-failure');
        $this->givenConsent($email, groupId: 604);
        $this->fakeApi->receiverToReturn = ['email' => $email, 'activated' => true];
        $this->fakeApi->throwOnPushOrder = true;

        $settings = Plugin::getInstance()->getSettings();
        $previousTags = $settings->orderCompleteTags;
        $settings->orderCompleteTags = 'should-not-apply';

        try {
            $order = $this->createOrder($email);
            $order->markAsComplete();
            $this->runQueuedOrderPush((int) $order->id);
        } finally {
            $settings->orderCompleteTags = $previousTags;
        }

        self::assertTrue($order->isCompleted);
        self::assertSame([], $this->fakeApi->calls);
    }

    /**
     * Clears the order enqueue debounce key, forces any pending PushOrderJob
     * due, and runs it via queue/run (same pattern as UserSyncQueueTest).
     */
    private function runQueuedOrderPush(int $orderId): void
    {
        Craft::$app->getCache()?->delete(\kernpfad\cleverreach\util\OrderEnqueueGate::cacheKey($orderId));

        Craft::$app->getDb()->createCommand()
            ->update('{{%queue}}', ['delay' => 0, 'timePushed' => 0], [
                'and',
                ['fail' => 0],
                ['like', 'description', '%order ' . $orderId . '%', false],
            ])
            ->execute();

        Craft::$app->runAction('queue/run', ['isolate' => false]);

        Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', ['like', 'description', '%order ' . $orderId . '%', false])
            ->execute();
    }

    private function uniqueEmail(string $label): string
    {
        $email = sprintf('cleverreach-it-order-%s-%s@example.test', $label, bin2hex(random_bytes(4)));
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

    private function createOrder(string $email, int $qty = 1, float $unitPrice = 30.00): Order
    {
        $commerce = Commerce::getInstance();
        $site = Craft::$app->getSites()->getPrimarySite();
        $suffix = bin2hex(random_bytes(4));

        $productType = $commerce->getProductTypes()->getProductTypeByHandle('cleverreachOrderPushTests');

        if ($productType === null) {
            $productType = new ProductType();
            $productType->name = 'CleverReach Order Push Tests';
            $productType->handle = 'cleverreachOrderPushTests';
            $productType->setSiteSettings([
                $site->id => new ProductTypeSite(['siteId' => $site->id, 'hasUrls' => false]),
            ]);

            self::assertTrue(
                $commerce->getProductTypes()->saveProductType($productType),
                'Failed to save test product type: ' . implode(', ', $productType->getErrorSummary(true))
            );
            Craft::$app->getProjectConfig()->saveModifiedConfigData();
            $productType = $commerce->getProductTypes()->getProductTypeByHandle('cleverreachOrderPushTests');
        }

        $product = new Product();
        $product->typeId = $productType->id;
        $product->title = "Order Push Test Product {$suffix}";
        $product->siteId = $site->id;

        $variant = new Variant();
        $variant->sku = "order-push-test-{$suffix}";
        $variant->basePrice = $unitPrice;
        $variant->isDefault = true;
        $product->setVariants([$variant]);
        $product->setDirtyAttributes(['variants']);

        self::assertTrue(
            Craft::$app->getElements()->saveElement($product),
            'Failed to save test product: ' . implode(', ', $product->getErrorSummary(true))
        );

        $savedVariant = Variant::find()->productId($product->id)->status(null)->one();
        self::assertNotNull($savedVariant);

        $gateway = $commerce->getGateways()->getGatewayByHandle('dummy');
        self::assertNotNull($gateway, 'Test install needs the dummy Commerce gateway.');

        // Order::getEmail() always prefers the resolved customer's email
        // over $order->email directly, so a distinct-per-test address needs
        // a distinct customer User — same mechanism Order::setEmail() uses
        // (guest checkout creates/reuses one the same way).
        $customer = Craft::$app->getUsers()->ensureUserByEmail($email);
        $this->createdUserIds[] = (int) $customer->id;

        $order = new Order();
        $order->number = Craft::$app->getSecurity()->generateRandomString(32);
        $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
        $order->currency = 'USD';
        $order->paymentCurrency = 'USD';
        $order->gatewayId = $gateway->id;
        $order->orderSiteId = $site->id;
        $order->setCustomer($customer);

        self::assertTrue(Craft::$app->getElements()->saveElement($order));

        $lineItem = $commerce->getLineItems()->createLineItem($order, $savedVariant->id, [], $qty);
        $order->setLineItems([$lineItem]);
        Craft::$app->getElements()->saveElement($order);

        // A completed order needs a successful purchase transaction for
        // downstream Commerce bookkeeping to behave normally.
        $transaction = $commerce->getTransactions()->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
        $transaction->status = TransactionRecord::STATUS_SUCCESS;
        $transaction->reference = 'order-push-test-' . bin2hex(random_bytes(4));
        $commerce->getTransactions()->saveTransaction($transaction);

        return $order;
    }
}
