<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Decides how a Craft user attribute sync should talk to CleverReach
 * given the receiver's current activation state (CR-06 soft-sync).
 */
final class ReceiverSyncDecision
{
    public const ACTION_SKIP = 'skip';
    public const ACTION_SOFT_UPDATE = 'soft_update';
    public const ACTION_ACTIVATE = 'activate';

    /**
     * @param array<string, mixed>|null $receiver CleverReach receiver payload, or null if missing
     */
    public static function decide(?array $receiver, bool $unsubscribed): string
    {
        if ($unsubscribed) {
            return self::ACTION_SKIP;
        }

        if ($receiver !== null && !self::isActivated($receiver['activated'] ?? null)) {
            return self::ACTION_SOFT_UPDATE;
        }

        return self::ACTION_ACTIVATE;
    }

    /**
     * CleverReach may return `activated` as bool false, or as a unix
     * timestamp once DOI is confirmed (same convention Formie uses when
     * writing `activated => time()`).
     */
    public static function isActivated(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        return false;
    }
}
