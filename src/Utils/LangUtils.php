<?php

declare(strict_types=1);

namespace Flowlight\Utils;

/**
 * LangUtils — helpers for runtime type/class checks.
 *
 * Example:
 *  LangUtils::matchesClass(new Foo, Foo::class);      // true
 *  LangUtils::matchesClass(Bar::class, Foo::class);   // true if Bar extends/implements Foo
 *  LangUtils::matchesClass(new Bar, Foo::class);      // true if Bar extends/implements Foo
 *  LangUtils::matchesClass(Foo::class, Foo::class);   // true
 */
final class LangUtils
{
    /**
     * Check whether a value “matches” a class/interface — same type or subclass/implementation.
     *
     * Accepts either an object instance or any string (class-string preferred; non-class strings return false).
     *
     * @param  object|string  $value  Instance or string
     * @param  object|string  $class  Target instance or string
     *
     * @phpstan-param object|string $value
     * @phpstan-param object|string $class
     */
    public static function matchesClass(object|string $value, object|string $class): bool
    {
        /** @var class-string|string $valueClass */
        $valueClass = \is_object($value) ? $value::class : $value;

        /** @var class-string|string $className */
        $className = \is_object($class) ? $class::class : $class;

        // If either side isn't a real class/interface, this will return false.
        return \is_a($valueClass, $className, true);
    }
}
