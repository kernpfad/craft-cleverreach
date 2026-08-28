<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\controllers;

use Craft;
use craft\web\Controller;
use kernpfad\cleverreach\Plugin;
use Throwable;
use yii\web\Response;

/**
 * Public-facing endpoint any Craft form can POST to for a CleverReach
 * double-opt-in newsletter signup:
 *
 *   POST /actions/cleverreach/subscribe/subscribe
 *     email               (required)
 *     consent             (required, must be truthy — explicit opt-in checkbox)
 *     consentTextVersion  (optional, identifies which consent text was shown)
 *     groupId             (optional, overrides the configured default group)
 *     source              (optional, free text, defaults to the referring URL)
 *     fields[...]         (optional, mapped to CleverReach attributes via settings)
 */
class SubscribeController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public function actionSubscribe(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $email = trim((string) $request->getRequiredBodyParam('email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->asFailure(Craft::t('cleverreach', 'Please enter a valid email address.'));
        }

        if (!$request->getBodyParam('consent')) {
            return $this->asFailure(Craft::t('cleverreach', 'Please confirm you agree to receive the newsletter.'));
        }

        $groupIdParam = $request->getBodyParam('groupId');
        $groupId = $groupIdParam !== null ? (int) $groupIdParam : null;

        $consentTextVersion = $request->getBodyParam('consentTextVersion');
        $formData = $request->getBodyParam('fields', []);
        $source = (string) $request->getBodyParam('source', $request->getReferrer() ?? 'unknown');

        try {
            $success = Plugin::getInstance()->subscriber->subscribe(
                $email,
                is_array($formData) ? $formData : [],
                $source,
                $request->getUserIP() ?? '',
                $groupId,
                $consentTextVersion !== null ? (string) $consentTextVersion : null
            );
        } catch (Throwable $e) {
            Craft::error('CleverReach signup failed: ' . $e->getMessage(), __METHOD__);

            return $this->asFailure(Craft::t('cleverreach', 'Something went wrong, please try again later.'));
        }

        if (!$success) {
            return $this->asFailure(Craft::t('cleverreach', 'Newsletter signup is not configured yet.'));
        }

        return $this->asSuccess(Craft::t('cleverreach', 'Please check your inbox to confirm your subscription.'));
    }
}
