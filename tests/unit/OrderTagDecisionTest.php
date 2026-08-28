<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\OrderTagDecision;
use PHPUnit\Framework\TestCase;

final class OrderTagDecisionTest extends TestCase
{
    public function testTagsOnlyAfterSuccessfulPush(): void
    {
        $this->assertTrue(OrderTagDecision::shouldApplyTags(true));
        $this->assertFalse(OrderTagDecision::shouldApplyTags(false));
    }
}
