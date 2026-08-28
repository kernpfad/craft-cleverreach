<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\util;

/**
 * Maps CleverReach list/form API rows into the `{id, name}` shape used by
 * the settings screen pickers (CR-04).
 */
final class ApiListNormalizer
{
    /**
     * @param array<int|string, mixed> $items
     * @return list<array{id: string, name: string}>
     */
    public static function normalize(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');
            $name = (string) ($item['name'] ?? $id);

            $normalized[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $normalized;
    }
}
