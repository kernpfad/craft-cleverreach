<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

use RuntimeException;

/**
 * Parses the --mapping CLI argument for the import command into a
 * column-name → target-field map.
 */
final class CsvMappingParser
{
    /**
     * @return array<string, string> CSV column name => target field
     */
    public static function parse(string $mapping): array
    {
        $columnMap = [];

        foreach (explode(',', $mapping) as $pair) {
            [$column, $target] = array_pad(explode(':', trim($pair), 2), 2, null);

            if ($column === null || $target === null || trim($column) === '' || trim($target) === '') {
                throw new RuntimeException("Invalid mapping entry: \"{$pair}\"");
            }

            $columnMap[trim($column)] = trim($target);
        }

        if (!in_array('email', $columnMap, true)) {
            throw new RuntimeException('Mapping must include a column mapped to "email", e.g. "E-Mail:email".');
        }

        return $columnMap;
    }

    public static function parseConsentValue(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'ja'], true);
    }
}
