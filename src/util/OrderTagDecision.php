<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Whether order-complete tags may run after a push attempt (CR-10/11).
 */
final class OrderTagDecision
{
    public static function shouldApplyTags(bool $pushSucceeded): bool
    {
        return $pushSucceeded;
    }
}
