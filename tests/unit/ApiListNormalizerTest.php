<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\ApiListNormalizer;
use PHPUnit\Framework\TestCase;

final class ApiListNormalizerTest extends TestCase
{
    public function testNormalizesIdAndName(): void
    {
        $this->assertSame(
            [
                ['id' => '12', 'name' => 'Newsletter'],
                ['id' => '34', 'name' => 'DOI Form'],
            ],
            ApiListNormalizer::normalize([
                ['id' => 12, 'name' => 'Newsletter'],
                ['id' => '34', 'name' => 'DOI Form'],
            ])
        );
    }

    public function testFallsBackToIdWhenNameMissing(): void
    {
        $this->assertSame(
            [
                ['id' => '99', 'name' => '99'],
            ],
            ApiListNormalizer::normalize([
                ['id' => 99],
            ])
        );
    }

    public function testSkipsNonArrayItems(): void
    {
        $this->assertSame(
            [
                ['id' => '1', 'name' => 'A'],
            ],
            ApiListNormalizer::normalize([
                'noise',
                ['id' => 1, 'name' => 'A'],
            ])
        );
    }
}
