<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\controllers\cp;

use craft\web\Controller;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\ApiListNormalizer;
use Throwable;
use yii\web\Response;

/**
 * Backs the settings screen's group picker (CR-04) — fetches real groups
 * from the connected CleverReach account so the default target group can
 * be chosen from a list instead of typed in as a raw numeric ID.
 */
class GroupsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        try {
            $groups = Plugin::getInstance()->cleverReachApi->getGroups();
        } catch (Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
                'groups' => [],
            ]);
        }

        return $this->asJson([
            'success' => true,
            'groups' => ApiListNormalizer::normalize($groups),
        ]);
    }
}
