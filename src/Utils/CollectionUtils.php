<?php

declare(strict_types=1);

namespace Flowlight\Utils;

use Illuminate\Support\Collection;

/**
 * CollectionUtils — tiny helpers for common immutable transforms.
 *
 * Notes
 *  - Methods never mutate the provided Collections; they return new instances.
 *  - “Compact” variants drop only nulls, preserving original ordering.
 *  - De-duplication is strict (===) and keeps the first occurrence.
 */
final class CollectionUtils
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Concatenate values and drop only nulls, preserving order (stable).
     * Keys are re-indexed from 0..n.
     *
     * @template T
     *
     * @param  Collection<array-key, T|null>  $base
     * @param  iterable<array-key, T|null>  $values
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
     * Shallow-merge attributes (map-style) without mutating the original.
     * Later keys in $attrs overwrite keys in $base.
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
     * Concatenate, drop nulls, and de-duplicate (strict ===), keeping first occurrence.
     * Keys are re-indexed from 0..n.
     *
     * @template T
     *
     * @param  Collection<array-key, T|null>  $base
     * @param  iterable<array-key, T|null>  $values
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
