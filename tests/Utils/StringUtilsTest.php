<?php

declare(strict_types=1);

namespace Flowlight\Tests\Utils;

use Flowlight\Utils\StringUtils;
use ReflectionClass;

describe(StringUtils::class, function () {
    describe('__construct', function () {
        it('is declared private and prevents instantiation', function () {
            $ref = new ReflectionClass(StringUtils::class);

            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull()
                ->and($ctor->isPrivate())->toBeTrue();
        });
    });

    describe('::stringify', function () {
        it('returns strings unchanged', function () {
            expect(StringUtils::stringify('hello'))->toBe('hello');
        });

        it('stringifies scalars (int/float/bool)', function () {
            expect(StringUtils::stringify(123))->toBe('123')
                ->and(StringUtils::stringify(1.5))->toBe('1.5')
                ->and(StringUtils::stringify(true))->toBe('1')
                ->and(StringUtils::stringify(false))->toBe(''); // (string) false === ''
        });

        it('converts null to empty string', function () {
            expect(StringUtils::stringify(null))->toBe('');
        });

        it('uses __toString when available on objects', function () {
            $obj = new class
            {
                public function __toString(): string
                {
                    return 'stringable';
                }
            };

            expect(StringUtils::stringify($obj))->toBe('stringable');
        });

        it('JSON-encodes arrays and non-stringable objects (unicode unescaped)', function () {
            $arr = ['α', 1, true, null];
            $std = (object) ['a' => 1];

            expect(StringUtils::stringify($arr))->toBe(json_encode($arr, JSON_UNESCAPED_UNICODE))
                ->and(StringUtils::stringify($std))->toBe(json_encode($std, JSON_UNESCAPED_UNICODE));
        });

        it('falls back to the PHP type name when JSON encoding fails (recursive array)', function () {
            $a = [];
            $a['self'] = &$a; // recursive structure → json_encode fails

            // When json_encode fails, StringUtils returns gettype($value)
            expect(StringUtils::stringify($a))->toBe('array');
        });
    });
});
