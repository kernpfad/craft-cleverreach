<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\OrderEnqueueGate;
use PHPUnit\Framework\TestCase;

final class OrderEnqueueGateTest extends TestCase
{
    public function testCacheKeyIncludesOrderId(): void
    {
        $this->assertSame(
            'cleverreach_order_enqueue_99',
            OrderEnqueueGate::cacheKey(99)
        );
    }

    public function testTtlCoversDelayWindow(): void
    {
        $this->assertGreaterThan(
            OrderEnqueueGate::DELAY_SECONDS,
            OrderEnqueueGate::TTL_SECONDS
        );
    }
}
