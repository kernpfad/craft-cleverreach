<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\unit;

use kernpfad\cleverreach\util\CsvMappingParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsvMappingParserTest extends TestCase
{
    public function testParseValidMapping(): void
    {
        $result = CsvMappingParser::parse('E-Mail:email,Vorname:firstname,Opt-In:consent');

        $this->assertSame([
            'E-Mail' => 'email',
            'Vorname' => 'firstname',
            'Opt-In' => 'consent',
        ], $result);
    }

    public function testParseRequiresEmailColumn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('email');

        CsvMappingParser::parse('Vorname:firstname');
    }

    public function testParseRejectsInvalidEntry(): void
    {
        $this->expectException(RuntimeException::class);

        CsvMappingParser::parse('invalid-entry');
    }

    #[DataProvider('consentValueProvider')]
    public function testParseConsentValue(mixed $input, bool $expected): void
    {
        $this->assertSame($expected, CsvMappingParser::parseConsentValue($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function consentValueProvider(): array
    {
        return [
            'one' => ['1', true],
            'true' => ['true', true],
            'yes' => ['yes', true],
            'ja' => ['ja', true],
            'JA uppercase' => ['JA', true],
            'zero' => ['0', false],
            'empty' => ['', false],
            'no' => ['no', false],
        ];
    }
}
