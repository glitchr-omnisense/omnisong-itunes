<?php

namespace Omnisong\Itunes;

use Omnisense\Contracts\Http\Data as KernelData;

/**
 * The iTunes Search API's decoded answers read without trusting their
 * shape: the kernel's Data, and the reads that keep PHP's casts of a scalar
 * as they are ("" kept, nothing trimmed), for the values the catalogue
 * turned into strings and integers with (string) and (int) before.
 *
 * @internal
 */
final readonly class Data extends KernelData
{
    /** (string) of a scalar or a Stringable, "" for anything else. */
    public static function cast(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** (int) of a scalar, 0 for anything else. */
    public static function integer(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }

    /**
     * A decoded JSON object as it is, its keys as PHP keeps them (an empty
     * array when it is not an array).
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * The objects of a list, each as map() reads it (what is not an array is skipped).
     *
     * @return list<array<string, mixed>>
     */
    public static function maps(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map(self::map(...), array_filter($value, 'is_array'))) : [];
    }
}
