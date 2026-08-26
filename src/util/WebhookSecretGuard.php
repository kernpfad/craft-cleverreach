<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Timing-safe webhook secret check for the unsubscribe endpoint (CR-07).
 * Empty configured secret means the endpoint is disabled.
 */
final class WebhookSecretGuard
{
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_OK = 'ok';

    public static function check(string $configured, string $provided): string
    {
        if ($configured === '') {
            return self::RESULT_DISABLED;
        }

        if (!hash_equals($configured, $provided)) {
            return self::RESULT_INVALID;
        }

        return self::RESULT_OK;
    }
}
