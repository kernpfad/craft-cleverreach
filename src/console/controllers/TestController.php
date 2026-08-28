<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\console\controllers;

use craft\console\Controller;
use kernpfad\cleverreach\Plugin;
use yii\console\ExitCode;

/**
 * `php craft cleverreach/test` — verifies the configured OAuth client
 * credentials work by making a single lightweight, read-only API call
 * (the same one the Formie list picker relies on), without any side
 * effects on the CleverReach account.
 */
class TestController extends Controller
{
    public function actionIndex(): int
    {
        $result = Plugin::getInstance()->cleverReachApi->testConnection();

        if ($result['success']) {
            $this->stdout($result['message'] . "\n");

            return ExitCode::OK;
        }

        $this->stderr($result['message'] . "\n");

        return ExitCode::UNAVAILABLE;
    }
}
