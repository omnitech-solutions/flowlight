<?php

declare(strict_types=1);

namespace Flowlight\Utils;

/**
 * StringUtils — framework-agnostic string helpers.
 *
 * Provides safe conversion of arbitrary values into strings.
 */
final class StringUtils
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Normalize any value to a string.
     *
     * Rules:
     * - Scalars → cast directly
     * - null → empty string
     * - Objects with __toString → cast
     * - Everything else → JSON-encoded (unicode unescaped), or type name on failure
     *
     * @example
     *  StringUtils::stringify(123);                 // "123"
     *  StringUtils::stringify(true);                // "1"
     *  StringUtils::stringify(null);                // ""
     *  StringUtils::stringify(['a' => 1]);          // "{"a":1}"
     *  StringUtils::stringify($objWithToString);    // uses __toString()
     */
    public static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $json === false ? gettype($value) : $json;
    }
}
