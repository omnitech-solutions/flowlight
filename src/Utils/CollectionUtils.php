<?php

declare(strict_types=1);

namespace Flowlight\Utils;

use Illuminate\Support\Collection;

final class CollectionUtils
{
    private function __construct() {}

    /**
     * Concatenate values and drop only nulls, preserving order.
     *
     * Keys are re-indexed (0..n).
     *
     * @template T
     *
     * @param  Collection<array-key, T>  $base
     * @param  iterable<array-key, T>  $values
     * @return Collection<int, T>
     */
    public static function concatCompact(Collection $base, iterable $values): Collection
    {
        /** @var Collection<int, T> $out */
        $out = $base
            ->concat($values)
            ->filter(static fn ($v) => $v !== null)
            ->values();

        return $out;
    }

    /**
     * Shallow merge attributes (map-style) without mutating the original.
     * Later keys in $attrs overwrite $base keys.
     *
     * @param  Collection<string, mixed>  $base
     * @param  array<string, mixed>|Collection<string, mixed>  $attrs
     * @return Collection<string, mixed>
     */
    public static function mergeAttrs(Collection $base, array|Collection $attrs): Collection
    {
        /** @var Collection<string, mixed> $attrsCol */
        $attrsCol = $attrs instanceof Collection ? $attrs : collect($attrs);

        /** @var Collection<string, mixed> $out */
        $out = $base->merge($attrsCol);

        return $out;
    }

    /**
     * Concatenate values, drop nulls, and de-duplicate while keeping first occurrence.
     *
     * Keys are re-indexed (0..n).
     *
     * @template T
     *
     * @param  Collection<array-key, T>  $base
     * @param  iterable<array-key, T>  $values
     * @return Collection<int, T>
     */
    public static function concatUniqueCompact(Collection $base, iterable $values): Collection
    {
        /** @var Collection<int, T> $out */
        $out = $base
            ->concat($values)
            ->filter(static fn ($v) => $v !== null)
            ->unique(strict: true)
            ->values();

        return $out;
    }
}
