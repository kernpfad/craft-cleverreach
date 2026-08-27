<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Debounce constants/key for enqueueing {@see \kernpfad\cleverreach\jobs\PushOrderJob}.
 */
final class OrderEnqueueGate
{
    public const TTL_SECONDS = 30;
    public const DELAY_SECONDS = 5;

    public static function cacheKey(int $orderId): string
    {
        return 'cleverreach_order_enqueue_' . $orderId;
    }
}
