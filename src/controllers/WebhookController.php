<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\controllers;

use Craft;
use craft\helpers\Db;
use craft\web\Controller;
use DateTime;
use kernpfad\cleverreach\events\ReceiverUnsubscribedEvent;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\WebhookSecretGuard;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Inbound unsubscribe/bounce notification endpoint (CR-07). CleverReach's
 * REST API doesn't itself push webhooks for this — this is a generic,
 * secret-verified target for whatever actually sends the notification in
 * your setup (a CleverReach-side automation calling out, a Zapier-style
 * integration, or a script reacting to a CleverReach export/report). Point
 * it here and it updates local state so this plugin stops treating the
 * address as subscribed.
 *
 *   POST /actions/cleverreach/webhook/unsubscribe?secret=...
 *     email   (required)
 *     reason  (optional, free text, e.g. "bounced")
 *
 * No Craft session calls this, so there's no CSRF token to send - the
 * shared secret plays that role instead, same as the "My Content" password
 * in CatalogController. Disabled (404) entirely when no secret is
 * configured, unlike the catalog password's "no password = no check"
 * default: an unauthenticated endpoint that can mark real subscribers as
 * unsubscribed is a worse default than a disabled one.
 */
class WebhookController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionUnsubscribe(): ?Response
    {
        $this->requirePostRequest();
        $this->requireValidSecret();

        $email = trim((string) Craft::$app->getRequest()->getRequiredBodyParam('email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->asFailure(Craft::t('cleverreach', 'Please enter a valid email address.'));
        }

        $reasonParam = Craft::$app->getRequest()->getBodyParam('reason');
        $reason = $reasonParam !== null ? (string) $reasonParam : null;

        $consentRecord = Plugin::getInstance()->consent->getLatestConsent(null, $email);

        if ($consentRecord === null) {
            // Nothing to mark - but this is still a successful, idempotent
            // "noted" response, not an error: the sender doesn't need to
            // know or care whether this plugin had a record for the address.
            return $this->asSuccess();
        }

        $consentRecord->unsubscribedAt = Db::prepareDateForDb(new DateTime());

        if (!$consentRecord->save(false)) {
            Craft::warning(
                'CleverReach could not persist an unsubscribe notification: '
                . implode(', ', $consentRecord->getFirstErrors()),
                __METHOD__
            );

            return $this->asFailure(Craft::t('cleverreach', 'Something went wrong, please try again later.'));
        }

        $plugin = Plugin::getInstance();

        if ($plugin->hasEventHandlers(Plugin::EVENT_RECEIVER_UNSUBSCRIBED)) {
            $plugin->trigger(
                Plugin::EVENT_RECEIVER_UNSUBSCRIBED,
                new ReceiverUnsubscribedEvent($email, $consentRecord, $reason)
            );
        }

        return $this->asSuccess();
    }

    private function requireValidSecret(): void
    {
        $configuredSecret = Plugin::getInstance()->getSettings()->getWebhookSecret();
        $providedSecret = (string) Craft::$app->getRequest()->getQueryParam('secret');
        $result = WebhookSecretGuard::check($configuredSecret, $providedSecret);

        if ($result !== WebhookSecretGuard::RESULT_OK) {
            throw new NotFoundHttpException();
        }
    }
}
