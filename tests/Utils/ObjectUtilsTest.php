<?php

use Flowlight\Utils\ObjectUtils;
use Illuminate\Support\Collection;

describe('dot', function () {
    it('returns flat object with dot as separator', function () {
        $input = ['a' => ['b' => ['c' => 1]]];
        $expected = ['a.b.c' => 1];
        expect(ObjectUtils::dot($input))->toEqual($expected);
    });

    describe('with underscore as separator', function () {
        it('returns flat object with underscore as separator', function () {
            $input = ['a' => ['b' => ['c' => 1]]];
            $expected = ['a_b_c' => 1];
            expect(ObjectUtils::dot($input, ['separator' => '_']))->toEqual($expected);
        });
    });

    it('handles arrays with numeric indices', function () {
        $input = ['entries' => [['x' => 1], ['x' => 2]]];
        $expected = [
            'entries.0.x' => 1,
            'entries.1.x' => 2,
        ];
        expect(ObjectUtils::dot($input))->toEqual($expected);
    });

    it('supports bracket form for lists', function () {
        $input = ['entries' => [['x' => 1]]];
        $expected = ['entries[0].x' => 1];
        expect(ObjectUtils::dot($input, ['useBrackets' => true]))->toEqual($expected);
    });

    it('returns empty array for empty input', function () {
        expect(ObjectUtils::dot([]))->toEqual([]);
    });
});

describe('dottedKeys', function () {
    describe('with underscore as separator', function () {
        it('returns flat keys with underscore as separator', function () {
            $input = ['entries' => [['ledger_account_id' => 1]]];
            $expected = ['entries_0_ledger_account_id'];
            expect(ObjectUtils::dottedKeys($input, ['separator' => '_']))->toEqual($expected);
        });
    });

    it('converts an object to dot notation', function () {
        $input = ['entries' => [['ledger_account_id' => 1]]];
        $expected = ['entries.0.ledger_account_id'];
        expect(ObjectUtils::dottedKeys($input))->toEqual($expected);
    });

    describe('with useBrackets option set', function () {
        it('converts an object to bracketed notation', function () {
            $input = ['entries' => [['ledger_account_id' => 1]]];
            $expected = ['entries[0].ledger_account_id'];
            expect(ObjectUtils::dottedKeys($input, ['useBrackets' => true]))->toEqual($expected);
        });
    });

    it('handles ignoring integer keys from array values', function () {
        $input = ['entries' => [['ledger_account_id' => [1, 2, 3]]]];
        $expected = ['entries.*.ledger_account_id.*'];
        expect(ObjectUtils::dottedKeys($input, ['ignoreArrayIndexKeys' => true]))->toEqual($expected);
    });

    it('converts multiple elements', function () {
        $input = ['entries' => [['ledger_account_id' => 1, 'amount' => 10000.0]]];
        $expected = ['entries.0.ledger_account_id', 'entries.0.amount'];
        expect(ObjectUtils::dottedKeys($input))->toEqual($expected);
    });

    it('returns full transaction dotted keys', function () {
        $transaction = [
            'transaction' => [
                'id' => 123,
                'transaction_entries' => [
                    ['ledger_account_number' => 110000, 'amount' => 10000, 'type' => 'Bank'],
                    ['ledger_account_number' => 210000, 'amount' => 4000, 'type' => 'Payable'],
                    ['ledger_account_number' => 220000, 'amount' => 6000, 'type' => 'Payable'],
                ],
            ],
        ];

        $expected = [
            'transaction.id',
            'transaction.transaction_entries.0.ledger_account_number',
            'transaction.transaction_entries.0.amount',
            'transaction.transaction_entries.0.type',
            'transaction.transaction_entries.1.ledger_account_number',
            'transaction.transaction_entries.1.amount',
            'transaction.transaction_entries.1.type',
            'transaction.transaction_entries.2.ledger_account_number',
            'transaction.transaction_entries.2.amount',
            'transaction.transaction_entries.2.type',
        ];

        expect(ObjectUtils::dottedKeys($transaction))->toEqual($expected);
    });

    it('supports ignoreArrayIndexKeys with useBrackets', function () {
        $input = ['entries' => [['id' => 1], ['id' => 2]]];
        $expected = ['entries[*].id']; // [0]/[1] collapsed to [*]
        expect(ObjectUtils::dottedKeys($input, ['useBrackets' => true, 'ignoreArrayIndexKeys' => true]))->toEqual($expected);
    });
});

describe('dottedPick', function () {
    $obj = [
        'a' => 1,
        'b' => [
            'c' => 3,
            'd' => [1, 2, 3],
            'e' => ['f' => ['g' => 1]],
        ],
        'c' => 2,
    ];

    it('keeps non dotted keys present in paths', function () use ($obj) {
        expect(ObjectUtils::dottedPick($obj, ['b']))->toEqual([
            'b' => [
                'c' => 3,
                'd' => [1, 2, 3],
                'e' => ['f' => ['g' => 1]],
            ],
        ]);
    });

    it('keeps deeply specified dotted keys', function () use ($obj) {
        expect(ObjectUtils::dottedPick($obj, ['a', 'c', 'b.c', 'b.e.f.g']))->toEqual([
            'a' => 1,
            'b' => [
                'c' => 3,
                'e' => ['f' => ['g' => 1]],
            ],
            'c' => 2,
        ]);
    });

    it('returns empty for no matching paths', function () use ($obj) {
        expect(ObjectUtils::dottedPick($obj, ['z', 'y.x']))->toEqual([]);
    });

    it('picks a literal dotted key when no nested path exists', function () {
        $obj = [
            'a.b' => 123,
            // intentionally no nested ['a' => ['b' => ...]]
            'x' => 1,
        ];

        // Arr::has($obj, 'a.b') is false; array_key_exists('a.b', $obj) is true
        expect(ObjectUtils::dottedPick($obj, ['a.b']))->toEqual(['a' => ['b' => 123]]);
    });

    it('prefers nested path when both literal dotted key and nested path exist', function () {
        $obj = [
            'a.b' => 123,
            'a' => ['b' => 456],
        ];

        // Arr::has($obj, 'a.b') is true → nested wins
        expect(ObjectUtils::dottedPick($obj, ['a.b']))->toEqual(['a' => ['b' => 123]]);
    });

    it('returns a literal key when Arr::has() is false but key exists', function () {
        $obj = [
            'a..b' => 123, // Arr::has($obj, 'a..b') === false, but array_key_exists('a..b', $obj) === true
            'x' => 1,
        ];

        expect(ObjectUtils::dottedPick(['a..b' => 123], ['a..b']))
            ->toEqual(['a' => ['' => ['b' => 123]]]);
    });

    it('does not reject items when the key is non-string (guard path)', function () {
        $flat = [
            10 => 'keep-me',      // non-string key -> should NOT be rejected
            'a.b' => 'remove-me', // string key     -> may be rejected depending on needles
        ];
        $needles = ['a']; // will match 'a.b'

        // Recreate the same rejection logic used inside dottedOmit()
        $kept = (new Collection($flat))
            ->reject(function (mixed $_v, int|string $path) use ($needles): bool {
                if (! is_string($path)) {
                    return false; // <-- the guard we’re testing
                }
                // call the private matcher via reflection
                $ref = new \ReflectionClass(\Flowlight\Utils\ObjectUtils::class);
                $m = $ref->getMethod('dottedMatchesSearchPaths');
                $m->setAccessible(true);
                /** @var bool $matched */
                $matched = $m->invoke(null, $path, $needles);

                return $matched;
            })
            ->all();

        expect($kept)->toHaveKey(10, 'keep-me')
            ->and($kept)->not->toHaveKey('a.b');
    });
});

describe('dottedMatchesSearchPaths (private)', function () {
    // Call the private method via reflection to preserve encapsulation.
    // call the private method via reflection
    $invoke = function (string $path, array $needles): bool {
        $ref = new ReflectionClass(ObjectUtils::class);
        $m = $ref->getMethod('dottedMatchesSearchPaths');
        $m->setAccessible(true);

        /** @var bool */
        return $m->invoke(null, $path, $needles);
    };

    foreach (['a', 'a.ba'] as $path) {
        it("with match found for path: {$path}", function () use ($invoke, $path) {
            expect($invoke($path, ['a']))->toBeTrue();
        });
    }

    foreach (['aa', 'b', 'aa.a'] as $path) {
        it("with no match for path: {$path}", function () use ($invoke, $path) {
            expect($invoke($path, ['a']))->toBeFalse();
        });
    }

    it('matches bracketed children', function () use ($invoke) {
        expect($invoke('entries[0].id', ['entries']))->toBeTrue();
    });

    it('exact match works', function () use ($invoke) {
        expect($invoke('transaction.id', ['transaction.id']))->toBeTrue();
    });

    it('returns false when all needles are empty', function () use ($invoke) {
        expect($invoke('a.b', ['']))->toBeFalse();
    });

    it('ignores empty needles among real ones', function () use ($invoke) {
        expect($invoke('a.b', ['', 'a']))->toBeTrue();   // matches 'a'
        expect($invoke('a.b', ['', 'x']))->toBeFalse();  // only '' and non-matching 'x'
    });
});

describe('dottedOmit', function () {
    $deep = [
        'a' => 1,
        'b' => [
            'c' => 3,
            'd' => [1, 2, 3],
            'e' => ['f' => ['g' => 1]],
        ],
        'c' => 2,
    ];

    it('removes non dotted keys', function () use ($deep) {
        expect(ObjectUtils::dottedOmit($deep, ['b']))->toEqual(['a' => 1, 'c' => 2]);
    });

    it('removes deeply non dotted keys', function () use ($deep) {
        expect(ObjectUtils::dottedOmit($deep, ['a', 'c', 'b.c', 'b.e.f.g']))->toEqual(['b' => ['d' => [1, 2, 3]]]);
    });

    it('keeps everything when keys are empty', function () use ($deep) {
        expect(ObjectUtils::dottedOmit($deep, []))->toEqual($deep);
    });

    it('keeps object unchanged when search paths contain an empty string', function () {
        $obj = [
            'a' => 1,
            'b' => ['c' => 2, 'd' => [3]],
        ];

        // empty needle should be ignored by dottedMatchesSearchPaths
        expect(ObjectUtils::dottedOmit($obj, ['']))->toEqual($obj);
    });
});

describe('undot', function () {
    it('undots flattened data', function () {
        $flat = [
            'companyName' => 'some-company-name',
            'companyAddress.streetLine1' => 'some-street',
            'companyAddress.city' => 'some-city',
        ];

        expect(ObjectUtils::undot($flat))->toEqual([
            'companyAddress' => ['city' => 'some-city', 'streetLine1' => 'some-street'],
            'companyName' => 'some-company-name',
        ]);
    });

    describe('with underscore as separator', function () {
        it('undots flattened data', function () {
            $flat = [
                'companyName' => 'some-company-name',
                'companyAddress_streetLine1' => 'some-street',
                'companyAddress_city' => 'some-city',
            ];

            expect(ObjectUtils::undot($flat, ['separator' => '_']))->toEqual([
                'companyAddress' => ['city' => 'some-city', 'streetLine1' => 'some-street'],
                'companyName' => 'some-company-name',
            ]);
        });
    });

    it('is inverse of dot for simple objects', function () {
        $input = ['x' => ['y' => 1, 'z' => ['q' => 2]]];
        $flat = ObjectUtils::dot($input);
        expect(ObjectUtils::undot($flat))->toEqual($input);
    });
});

describe('normalizeKeyList', function () {
    it('returns null when input is null', function () {
        expect(ObjectUtils::normalizeKeyList(null))->toBeNull();
    });

    it('wraps a single string into a list', function () {
        expect(ObjectUtils::normalizeKeyList('a.b'))->toEqual(['a.b']);
    });

    it('normalizes an array of mixed scalars to strings', function () {
        expect(ObjectUtils::normalizeKeyList(['a', 1, 'c']))->toEqual(['a', '1', 'c']);
    });

    it('normalizes a Collection of mixed scalars to strings preserving order', function () {
        $keys = new Collection(['x.y', 42, 'z']);
        expect(ObjectUtils::normalizeKeyList($keys))->toEqual(['x.y', '42', 'z']);
    });

    it('stringifies booleans and null', function () {
        expect(ObjectUtils::normalizeKeyList([true, false, null]))
            ->toEqual(['1', '0', '']);
    });

    it('stringifies arrays/objects via json or __toString', function () {
        $o = new class
        {
            public function __toString(): string
            {
                return 'OBJ';
            }
        };
        expect(ObjectUtils::normalizeKeyList([['a' => 1], $o]))
            ->toEqual(['{"a":1}', 'OBJ']);
    });
});

describe('pickOrNull', function () {
    it('returns values for dotted and top-level keys, null for missing', function () {
        $source = [
            'a' => 1,
            'b' => ['c' => 2, 'd' => ['e' => 3]],
        ];

        $keys = ['a', 'b.c', 'b.d.e', 'missing', 'b.missing'];
        $expected = [
            'a' => 1,
            'b.c' => 2,
            'b.d.e' => 3,
            'missing' => null,
            'b.missing' => null,
        ];

        expect(ObjectUtils::pickOrNull($source, $keys))->toEqual($expected);
    });

    it('handles empty keys list', function () {
        expect(ObjectUtils::pickOrNull(['x' => 1], []))->toEqual([]);
    });
});
