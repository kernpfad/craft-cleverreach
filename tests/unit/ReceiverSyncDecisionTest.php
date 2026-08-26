<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\ReceiverSyncDecision;
use PHPUnit\Framework\TestCase;

final class ReceiverSyncDecisionTest extends TestCase
{
    public function testUnsubscribedSkips(): void
    {
        $this->assertSame(
            ReceiverSyncDecision::ACTION_SKIP,
            ReceiverSyncDecision::decide(['activated' => true], true)
        );
    }

    public function testPendingSoftUpdates(): void
    {
        $this->assertSame(
            ReceiverSyncDecision::ACTION_SOFT_UPDATE,
            ReceiverSyncDecision::decide(['activated' => false], false)
        );
    }

    public function testConfirmedActivates(): void
    {
        $this->assertSame(
            ReceiverSyncDecision::ACTION_ACTIVATE,
            ReceiverSyncDecision::decide(['activated' => true], false)
        );
    }

    public function testConfirmedTimestampActivates(): void
    {
        $this->assertSame(
            ReceiverSyncDecision::ACTION_ACTIVATE,
            ReceiverSyncDecision::decide(['activated' => 1_710_000_000], false)
        );
    }

    public function testIsActivatedAcceptsTimestamp(): void
    {
        $this->assertTrue(ReceiverSyncDecision::isActivated(1_710_000_000));
        $this->assertFalse(ReceiverSyncDecision::isActivated(0));
        $this->assertFalse(ReceiverSyncDecision::isActivated(false));
        $this->assertTrue(ReceiverSyncDecision::isActivated(true));
    }

    public function testMissingReceiverActivates(): void
    {
        $this->assertSame(
            ReceiverSyncDecision::ACTION_ACTIVATE,
            ReceiverSyncDecision::decide(null, false)
        );
    }
}
