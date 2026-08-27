<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\TagListParser;
use PHPUnit\Framework\TestCase;

final class TagListParserTest extends TestCase
{
    public function testParsesCommaSeparatedTags(): void
    {
        $this->assertSame(
            ['purchased', 'customer'],
            TagListParser::parse('purchased, customer')
        );
    }

    public function testTrimsAndDropsEmptiesAndDedupes(): void
    {
        $this->assertSame(
            ['a', 'b'],
            TagListParser::parse(' a , , b, a ')
        );
    }

    public function testEmptyStringYieldsEmptyList(): void
    {
        $this->assertSame([], TagListParser::parse(''));
        $this->assertSame([], TagListParser::parse('  , , '));
    }

    public function testNormalizeArray(): void
    {
        $this->assertSame(
            ['x', 'y'],
            TagListParser::normalize([' x ', '', 'y', 'x'])
        );
    }
}
