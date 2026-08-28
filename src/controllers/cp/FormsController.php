<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\controllers\cp;

use craft\web\Controller;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\ApiListNormalizer;
use Throwable;
use yii\web\Response;

/**
 * Backs the settings screen's DOI form picker (CR-04) — fetches real forms
 * from the connected CleverReach account so the double-opt-in form can be
 * chosen from a list instead of typed as a raw numeric ID.
 */
class FormsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        try {
            $forms = Plugin::getInstance()->cleverReachApi->getForms();
        } catch (Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
                'forms' => [],
            ]);
        }

        return $this->asJson([
            'success' => true,
            'forms' => ApiListNormalizer::normalize($forms),
        ]);
    }
}
