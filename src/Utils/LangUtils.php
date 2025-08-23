<?php

declare(strict_types=1);

namespace Flowlight\Utils;

/**
 * LangUtils — small helpers for runtime class checks.
 */
final class LangUtils
{
    /**
     * True if $value “matches” $class — i.e. is the same type or a subclass/implementation.
     *
     * Both $value and $class can be:
     *  - an object instance
     *  - a class-string (including ::class)
     *  - any other value (returns false)
     *
     * Examples:
     *  matchesClass(new Foo, Foo::class)          => true
     *  matchesClass(Bar::class, Foo::class)       => true if Bar extends/implements Foo
     *  matchesClass(new Bar, Foo::class)          => true if Bar extends/implements Foo
     *  matchesClass(Foo::class, Foo::class)       => true
     */
    /**
     * Check whether a value matches (is instance of / subclass of) the given class.
     *
     * @param  object|string  $value  Instance or class-string
     * @param  object|string  $class  Instance or class-string
     */
    public static function matchesClass(object|string $value, object|string $class): bool
    {
        /** @var class-string $valueClass */
        $valueClass = \is_object($value) ? $value::class : $value;

        /** @var class-string $className */
        $className = \is_object($class) ? $class::class : $class;

        return \is_a($valueClass, $className, true);
    }
}
