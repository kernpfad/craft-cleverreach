<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\controllers\cp;

use craft\web\Controller;
use kernpfad\cleverreach\Plugin;
use yii\web\Response;

class TestController extends Controller
{
    public function actionRun(): Response
    {
        $this->requireAdmin(false);

        $result = Plugin::getInstance()->cleverReachApi->testConnection();

        return $this->asJson($result);
    }
}
