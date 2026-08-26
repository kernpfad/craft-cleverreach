<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Debounce constants/key for enqueueing {@see \kernpfad\cleverreach\jobs\SyncUserJob}.
 *
 * Rapid User saves share one pending job window; the job loads attributes
 * at execute time so later metadata changes are not lost.
 */
final class SyncEnqueueGate
{
    public const TTL_SECONDS = 30;
    public const DELAY_SECONDS = 5;

    public static function cacheKey(int $userId): string
    {
        return 'cleverreach_sync_enqueue_' . $userId;
    }
}
