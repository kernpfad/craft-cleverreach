<?php

namespace kernpfad\cleverreach\controllers;

use Craft;
use craft\web\Controller;
use kernpfad\cleverreach\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Implements CleverReach's "My Content" product-search contract
 * (https://developers.cleverreach.com/mycontent/) for Craft Commerce
 * products.
 *
 * A single URL handles both operations CleverReach calls, switched via
 * the `get` query param — matching their contract exactly rather than
 * using separate Craft actions per operation:
 *
 *   POST /actions/cleverreach/catalog/search?get=filter[&password=...]
 *   POST /actions/cleverreach/catalog/search?get=search[&password=...]
 *     (with the chosen filter values as POST params, e.g. q, productTypeId)
 */
class CatalogController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    // CleverReach's servers call this URL directly, with no Craft session
    // and therefore no CSRF token to send. Safe to disable here: the action
    // is read-only (a product search), and the optional password setting
    // serves the anti-abuse role a CSRF token would otherwise play.
    public $enableCsrfValidation = false;

    public function actionSearch(): Response
    {
        $this->requirePostRequest();

        if (!Plugin::getInstance()->getSettings()->enableCatalog) {
            throw new NotFoundHttpException();
        }

        if (!class_exists(\craft\commerce\Plugin::class)) {
            throw new NotFoundHttpException();
        }

        $this->requireValidPassword();

        $request = Craft::$app->getRequest();
        $get = $request->getQueryParam('get');

        return match ($get) {
            'filter' => $this->asJson(Plugin::getInstance()->catalog->getFilters()),
            'search' => $this->asJson(Plugin::getInstance()->catalog->search(
                $request->getBodyParam('q'),
                $request->getBodyParam('productTypeId')
            )),
            default => throw new NotFoundHttpException(),
        };
    }

    private function requireValidPassword(): void
    {
        $configuredPassword = Plugin::getInstance()->getSettings()->getCatalogPassword();

        if ($configuredPassword === '') {
            return;
        }

        $providedPassword = Craft::$app->getRequest()->getQueryParam('password');

        if ($providedPassword !== $configuredPassword) {
            throw new NotFoundHttpException();
        }
    }
}
