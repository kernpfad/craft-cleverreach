<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Parses comma-separated CleverReach tag settings into a clean list.
 */
final class TagListParser
{
    /**
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return self::normalize(explode(',', $raw));
    }

    /**
     * @param array<int|string, mixed> $tags
     * @return list<string>
     */
    public static function normalize(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (!is_string($tag) && !is_numeric($tag)) {
                continue;
            }

            $value = trim((string) $tag);
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }
}
