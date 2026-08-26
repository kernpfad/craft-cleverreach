<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\WebhookSecretGuard;
use PHPUnit\Framework\TestCase;

final class WebhookSecretGuardTest extends TestCase
{
    public function testEmptyConfiguredIsDisabled(): void
    {
        $this->assertSame(
            WebhookSecretGuard::RESULT_DISABLED,
            WebhookSecretGuard::check('', 'anything')
        );
    }

    public function testMismatchIsInvalid(): void
    {
        $this->assertSame(
            WebhookSecretGuard::RESULT_INVALID,
            WebhookSecretGuard::check('correct-secret', 'wrong-secret')
        );
    }

    public function testMatchIsOk(): void
    {
        $this->assertSame(
            WebhookSecretGuard::RESULT_OK,
            WebhookSecretGuard::check('correct-secret', 'correct-secret')
        );
    }
}
