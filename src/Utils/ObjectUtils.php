<?php

declare(strict_types=1);

namespace Flowlight\Utils;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class ObjectUtils
{
    /**
     * Flatten a nested array into a single-level associative array with dotted keys.
     *
     * Example:
     *   ['a' => ['b' => 1]] → ['a.b' => 1]
     *
     * Options:
     * - separator: string (default ".")  → delimiter to use in keys
     * - useBrackets: bool (default false) → whether to format list indices with brackets (e.g., "arr[0]") instead of "arr.0"
     *
     * @param  array<string,mixed>  $input  Nested input array
     * @param  array{separator?:string,useBrackets?:bool}  $options
     * @return array<string,mixed> Flattened associative array
     */
    public static function dot(array $input, array $options = []): array
    {
        $separator = isset($options['separator']) ? (string) $options['separator'] : '.';
        $useBrackets = isset($options['useBrackets']) ? (bool) $options['useBrackets'] : false;

        /** @var array<string,mixed> $result */
        $result = [];
        self::dotFlatten($input, '', $result, $separator, $useBrackets);

        return $result;
    }

    /**
     * Extract all dotted keys from an object, optionally normalizing array indices.
     *
     * Options:
     * - separator: string (default ".") → delimiter for dotted keys
     * - useBrackets: bool (default false) → whether to use bracketed list indices
     * - ignoreArrayIndexKeys: bool (default false) → collapse numeric indices to "*" placeholders
     *
     * Example:
     *   ['x' => [['y' => 1]]] → ['x.0.y'] or ['x[*].y']
     *
     * @param  array<string,mixed>  $obj
     * @param  array{separator?:string,useBrackets?:bool,ignoreArrayIndexKeys?:bool}  $options
     * @return array<int,string> List of dotted keys
     */
    public static function dottedKeys(array $obj, array $options = []): array
    {
        $separator = isset($options['separator']) ? (string) $options['separator'] : '.';
        $useBrackets = isset($options['useBrackets']) ? (bool) $options['useBrackets'] : false;
        $ignoreArrayIndexKeys = isset($options['ignoreArrayIndexKeys']) ? (bool) $options['ignoreArrayIndexKeys'] : false;

        $flat = self::dot($obj, ['separator' => $separator, 'useBrackets' => $useBrackets]);
        $keys = array_keys($flat);

        if ($ignoreArrayIndexKeys) {
            if ($useBrackets) {
                /** @var array<int,string> $keys */
                $keys = (new Collection($keys))
                    ->map(static fn (string $k): string => (string) preg_replace('/\[\d+]/', '[*]', $k))
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $needle = $separator === '.' ? '/\.\d+/' : '/\\'.$separator.'\d+/';
                /** @var array<int,string> $keys */
                $keys = (new Collection($keys))
                    ->map(static fn (string $k): string => (string) preg_replace($needle, $separator.'*', $k))
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        /** @var array<int,string> $keys */
        return $keys;
    }

    /**
     * Select only the specified dotted paths from an object.
     *
     * - Respects Laravel's Arr::has / Arr::get semantics (dotted strings are treated as paths).
     * - Throws LogicException if a "literal dotted key" exists that conflicts with path semantics.
     *
     * Example:
     *   obj = ['a' => ['b' => 1], 'c' => 2]
     *   dottedPick(obj, ['a.b']) → ['a' => ['b' => 1]]
     *
     * @param  array<string,mixed>  $obj  Source object
     * @param  array<int,string>  $paths  Dotted paths to pick
     * @return array<string,mixed> Object containing only requested paths
     */
    public static function dottedPick(array $obj, array $paths): array
    {
        /** @var array<string,mixed> $out */
        $out = [];
        foreach ($paths as $path) {
            if (Arr::has($obj, $path)) {
                $val = Arr::get($obj, $path);
                Arr::set($out, $path, $val);
            } elseif (array_key_exists($path, $obj)) {
                // @codeCoverageIgnoreStart
                throw new \LogicException("Unexpected literal dotted key '{$path}' encountered in dottedPick().");
                // @codeCoverageIgnoreEnd
            }
        }

        $out = self::withStringKeys($out);
        $out = self::sortKeysDeep($out);

        /** @var array<string,mixed> */
        return self::withStringKeys($out);
    }

    /**
     * Return a copy of an object with specific dotted paths omitted.
     *
     * Example:
     *   obj = ['a' => ['b' => 1, 'c' => 2]]
     *   dottedOmit(obj, ['a.b']) → ['a' => ['c' => 2]]
     *
     * @param  array<string,mixed>  $obj  Source object
     * @param  array<int,string>  $keys  Dotted keys to omit
     * @return array<string,mixed> Object without omitted keys
     */
    public static function dottedOmit(array $obj, array $keys): array
    {
        $flat = self::dot($obj, ['separator' => '.', 'useBrackets' => false]);

        /** @var array<string,mixed> $kept */
        $kept = (new Collection($flat))
            /**
             * @param  mixed  $_v
             * @param  int|string  $path
             */
            ->reject(function (mixed $_v, int|string $path) use ($keys): bool {
                if (! is_string($path)) {
                    // @codeCoverageIgnoreStart
                    throw new \LogicException(
                        'Unexpected non-string key encountered in dottedPick(): '.var_export($path, true)
                    );
                    // @codeCoverageIgnoreEnd
                }

                return self::dottedMatchesSearchPaths($path, $keys);
            })
            ->all();

        /** @var array<string,mixed> $res */
        $res = Arr::undot($kept);
        $res = self::withStringKeys($res);
        $res = self::sortKeysDeep($res);

        /** @var array<string,mixed> */
        return self::withStringKeys($res);
    }

    /**
     * Convert a flattened dotted array back into a nested structure.
     *
     * Options:
     * - separator: string (default ".") → the delimiter used in the input keys
     *
     * Example:
     *   ['a.b' => 1, 'a.c' => 2] → ['a' => ['b' => 1, 'c' => 2]]
     *
     * @param  array<string,mixed>  $flat
     * @param  array{separator?:string}  $options
     * @return array<string,mixed> Nested array
     */
    public static function undot(array $flat, array $options = []): array
    {
        $separator = isset($options['separator']) ? (string) $options['separator'] : '.';

        if ($separator !== '.') {
            /** @var array<string,mixed> $converted */
            $converted = [];
            foreach ($flat as $k => $v) {
                $converted[str_replace($separator, '.', (string) $k)] = $v;
            }
            $flat = $converted;
        }

        /** @var array<string,mixed> $nested */
        $nested = Arr::undot($flat);
        $nested = self::withStringKeys($nested);
        $nested = self::sortKeysDeep($nested);

        /** @var array<string,mixed> */
        return self::withStringKeys($nested);
    }

    /**
     * Internal: recursively flatten nested arrays into dotted form.
     *
     * @param  mixed  $value  Current value
     * @param  string  $prefix  Current dotted prefix
     * @param  array<string,mixed>  $result  Accumulator (passed by ref)
     * @param  string  $sep  Separator string
     * @param  bool  $useBrackets  Whether to use bracket indices
     */
    private static function dotFlatten(mixed $value, string $prefix, array &$result, string $sep, bool $useBrackets): void
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $k => $v) {
                $segment = $isList && is_int($k)
                    ? ($useBrackets ? ($prefix === '' ? "[{$k}]" : "{$prefix}[{$k}]") : ($prefix === '' ? (string) $k : "{$prefix}.{$k}"))
                    : ($prefix === '' ? (string) $k : "{$prefix}.{$k}");

                self::dotFlatten($v, $segment, $result, $sep, $useBrackets);
            }

            return;
        }

        $key = $prefix;
        if ($sep !== '.') {
            $key = (string) preg_replace('/\.(?![^\[]*\])/', $sep, $key);
        }

        $result[$key] = $value;
    }

    /**
     * Internal: test if a dotted path matches any of the given search prefixes.
     *
     * Rules:
     * - Exact match returns true
     * - Matches if the path starts with needle + "." or needle + "["
     * - Empty needles are ignored
     *
     * @param  string  $path  Candidate path
     * @param  array<int,string>  $searchPaths  Needles to test
     */
    private static function dottedMatchesSearchPaths(string $path, array $searchPaths): bool
    {
        foreach ($searchPaths as $needle) {
            $needle = (string) $needle;
            if ($path === $needle) {
                return true;
            }

            $len = strlen($needle);
            if ($len === 0) {
                continue;
            }
            if (strncmp($path, $needle, $len) === 0) {
                if (isset($path[$len]) && ($path[$len] === '.' || $path[$len] === '[')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Internal: recursively sort associative arrays by key.
     * - Preserves order of list-style arrays
     *
     * @param  array<int|string,mixed>  $arr
     * @return array<int|string,mixed>
     */
    private static function sortKeysDeep(array $arr): array
    {
        if (array_is_list($arr)) {
            return $arr;
        }

        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = self::sortKeysDeep($v);
            }
        }

        return $arr;
    }

    /**
     * Internal: force all top-level keys to string type.
     *
     * @param  array<int|string,mixed>  $arr
     * @return array<string,mixed>
     */
    private static function withStringKeys(array $arr): array
    {
        /** @var array<string,mixed> $out */
        $out = [];
        foreach ($arr as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * Normalize a dotted-key list into an array of strings.
     *
     * - Accepts a single string, array of scalars, or a Collection
     * - Converts ints, floats, bools, null, and objects implementing __toString
     * - Fallback: JSON encodes arrays/objects
     *
     * @param  string|array<int,mixed>|Collection<int,mixed>|null  $keys
     * @return array<int,string>|null Normalized list of strings, or null if no input
     */
    public static function normalizeKeyList(string|array|Collection|null $keys): ?array
    {
        if ($keys === null) {
            return null;
        }
        if (is_string($keys)) {
            return [$keys];
        }
        if ($keys instanceof Collection) {
            /** @var array<int,mixed> $all */
            $all = $keys->all();

            /** @var array<int,string> */
            return array_values(array_map(
                static fn ($v): string => self::stringifyKey($v),
                $all
            ));
        }

        /** @var array<int,mixed> $keys */
        /** @var array<int,string> */
        return array_values(array_map(
            static fn ($v): string => self::stringifyKey($v),
            $keys
        ));
    }

    /**
     * Pick specific keys (dotted or top-level) from a source object.
     *
     * - Always returns all requested keys
     * - Missing keys default to null
     *
     * @param  array<string,mixed>  $source
     * @param  array<int,string>  $keys
     * @return array<string,mixed> Selected keys with values or null
     */
    public static function pickOrNull(array $source, array $keys): array
    {
        /** @var array<string,mixed> $out */
        $out = [];
        foreach ($keys as $key) {
            /** @var string $key */
            $out[$key] = Arr::has($source, $key) ? Arr::get($source, $key) : null;
        }

        return $out;
    }

    /**
     * Internal: normalize a single scalar/object into a string.
     *
     * Rules:
     * - Scalars → string cast
     * - bool → "1"/"0"
     * - null → ""
     * - Stringable object → __toString
     * - Other objects/arrays → JSON encoded
     */
    private static function stringifyKey(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if ($v === null) {
            return '';
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            /** @var object&\Stringable $v */
            return (string) $v;
        }
        $json = json_encode($v);

        return $json === false ? '' : $json;
    }
}
