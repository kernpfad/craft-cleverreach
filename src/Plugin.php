<?php

declare(strict_types=1);

namespace kernpfad\cleverreach;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\helpers\ElementHelper;
use kernpfad\cleverreach\jobs\SyncUserJob;
use kernpfad\cleverreach\models\Settings;
use kernpfad\cleverreach\services\CatalogService;
use kernpfad\cleverreach\services\CleverReachApiService;
use kernpfad\cleverreach\services\CommerceOrderPushService;
use kernpfad\cleverreach\services\ConsentService;
use kernpfad\cleverreach\services\SubscriberService;
use kernpfad\cleverreach\services\UserSyncService;
use yii\base\Event;

/**
 * @property-read CleverReachApiService $cleverReachApi
 * @property-read SubscriberService $subscriber
 * @property-read ConsentService $consent
 * @property-read CommerceOrderPushService $commerceOrderPush
 * @property-read CatalogService $catalog
 * @property-read UserSyncService $userSync
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /**
     * Fired before a receiver payload (attributes/orders) is sent to
     * CleverReach's upsert endpoint — lets other code add or override
     * attributes without touching this plugin's core (CR-08).
     */
    public const EVENT_MODIFY_RECEIVER_PAYLOAD = 'modifyReceiverPayload';

    /**
     * Fired when an unsubscribe/bounce notification is received for a
     * receiver (CR-07).
     */
    public const EVENT_RECEIVER_UNSUBSCRIBED = 'receiverUnsubscribed';

    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    public static function getInstance(): static
    {
        $instance = parent::getInstance();
        if (!$instance instanceof static) {
            throw new \RuntimeException('CleverReach plugin is not initialized.');
        }

        return $instance;
    }

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'cleverReachApi' => CleverReachApiService::class,
            'subscriber' => SubscriberService::class,
            'consent' => ConsentService::class,
            'commerceOrderPush' => CommerceOrderPushService::class,
            'catalog' => CatalogService::class,
            'userSync' => UserSyncService::class,
        ]);

        $this->attachUserEventHandlers();

        // Console controllers (the legacy-contact import command) live in a
        // separate namespace from the web SubscribeController, and are only
        // relevant for console requests.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'kernpfad\\cleverreach\\console\\controllers';
        }

        // Craft Commerce is an optional dependency: this plugin works standalone
        // for newsletter signup and only wires up the order push when
        // Commerce is actually installed and enabled.
        if ($this->getSettings()->enableOrderPush && class_exists(\craft\commerce\Plugin::class)) {
            $this->attachCommerceEventHandlers();
        }

        // Formie is likewise optional: sites without it just use the generic
        // actions/cleverreach/subscribe/subscribe endpoint (SubscribeController).
        // Sites with it get a native "Email Marketing" integration too — both
        // paths share the same SubscriberService, so behaviour is identical.
        if (class_exists(\verbb\formie\Formie::class)) {
            $this->attachFormieEventHandlers();
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cleverreach/settings', [
            'settings' => $this->getSettings(),
            'lastError' => $this->cleverReachApi->getLastError(),
        ]);
    }

    private function attachCommerceEventHandlers(): void
    {
        Event::on(
            \craft\commerce\elements\Order::class,
            \craft\commerce\elements\Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                /** @var \craft\commerce\elements\Order $order */
                $order = $event->sender;
                $this->commerceOrderPush->pushOrder($order);
            }
        );
    }

    /**
     * Always on (not Commerce-gated, no separate settings toggle) — both
     * handlers are no-ops for users who were never subscribed via this
     * plugin, so there's no meaningful "off" state beyond that.
     */
    private function attachUserEventHandlers(): void
    {
        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                if (!$event->sender instanceof User || ElementHelper::isDraftOrRevision($event->sender)) {
                    return;
                }

                $user = $event->sender;
                if ($user->id === null) {
                    return;
                }

                // Debounced queue job — keeps the save request off CleverReach's
                // HTTP path; attributes are loaded when the worker runs.
                SyncUserJob::enqueue((int) $user->id);
            }
        );

        Event::on(
            User::class,
            User::EVENT_DEFINE_METADATA,
            function(DefineMetadataEvent $event) {
                /** @var User $user */
                $user = $event->sender;
                $record = $this->consent->getLatestConsent($user->id, $user->email);

                if ($record === null || $record->dateCreated === null) {
                    return;
                }

                $event->metadata[Craft::t('cleverreach', 'Newsletter (CleverReach)')] = Craft::t(
                    'cleverreach',
                    'Subscribed since {date} ({source})',
                    [
                        'date' => Craft::$app->getFormatter()->asDate($record->dateCreated),
                        'source' => $record->source,
                    ]
                );

                // CR-07: an unsubscribe/bounce notification takes priority
                // over showing sync/DOI details that no longer apply.
                if ($record->unsubscribedAt !== null) {
                    $event->metadata[Craft::t('cleverreach', 'Newsletter status')] = Craft::t(
                        'cleverreach',
                        'Unsubscribed {date}',
                        ['date' => Craft::$app->getFormatter()->asDate($record->unsubscribedAt)]
                    );

                    return;
                }

                $sync = $user->id !== null ? $this->userSync->getSyncRecord((int) $user->id) : null;

                // CR-06: last known CleverReach confirmation flag from sync.
                $event->metadata[Craft::t('cleverreach', 'Confirmation status')] = $sync?->doiConfirmed === true
                    ? Craft::t('cleverreach', 'Confirmed {date}', [
                        'date' => Craft::$app->getFormatter()->asDate($sync->dateUpdated),
                    ])
                    : Craft::t('cleverreach', 'Pending confirmation');

                // CR-05: last sync lives on cleverreach_user_sync.
                if ($sync !== null) {
                    $event->metadata[Craft::t('cleverreach', 'Last sync (CleverReach)')] = $sync->status === 'error'
                        ? Craft::t('cleverreach', 'Error {date}: {message}', [
                            'date' => Craft::$app->getFormatter()->asDate($sync->dateUpdated),
                            'message' => $sync->message ?? '',
                        ])
                        : Craft::t('cleverreach', 'OK {date}', [
                            'date' => Craft::$app->getFormatter()->asDate($sync->dateUpdated),
                        ]);
                }
            }
        );
    }

    private function attachFormieEventHandlers(): void
    {
        Event::on(
            \verbb\formie\services\Integrations::class,
            \verbb\formie\services\Integrations::EVENT_REGISTER_INTEGRATIONS,
            function(\verbb\formie\events\RegisterIntegrationsEvent $event) {
                $event->emailMarketing[] = \kernpfad\cleverreach\integrations\formie\CleverReachEmailMarketing::class;
            }
        );
    }
}
