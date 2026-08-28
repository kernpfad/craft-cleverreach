<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\SyncEnqueueGate;
use PHPUnit\Framework\TestCase;

final class SyncEnqueueGateTest extends TestCase
{
    public function testCacheKeyIncludesUserId(): void
    {
        $this->assertSame(
            'cleverreach_sync_enqueue_42',
            SyncEnqueueGate::cacheKey(42)
        );
    }

    public function testTtlCoversDelayWindow(): void
    {
        $this->assertGreaterThan(
            SyncEnqueueGate::DELAY_SECONDS,
            SyncEnqueueGate::TTL_SECONDS
        );
    }
}
