<?php

declare(strict_types=1);

namespace Tests\Utils;

use Flowlight\Utils\CollectionUtils;

describe('concatCompact()', function () {
    it('concatenates and removes only nulls, preserving order', function () {
        $base = collect([1, null, 2]);
        $added = [null, 0, '', false, 3];

        $out = CollectionUtils::concatCompact($base, $added);

        expect($out->all())->toBe([1, 2, 0, '', false, 3])
            ->and($base->all())->toBe([1, null, 2]);
    });

    it('works with empty inputs', function () {
        $out = CollectionUtils::concatCompact(collect(), []);
        expect($out->all())->toBe([]);
    });
});

describe('mergeAttrs()', function () {
    it('shallow merges arrays with later keys overriding', function () {
        $base = collect(['a' => 1, 'b' => 2]);
        $attrs = ['b' => 20, 'c' => 3];

        $out = CollectionUtils::mergeAttrs($base, $attrs);

        expect($out->all())->toBe(['a' => 1, 'b' => 20, 'c' => 3])
            ->and($base->all())->toBe(['a' => 1, 'b' => 2]);
    });

    it('accepts Collection for attrs', function () {
        $base = collect(['x' => 1]);
        $attrs = collect(['y' => 2]);

        $out = CollectionUtils::mergeAttrs($base, $attrs);
        expect($out->all())->toBe(['x' => 1, 'y' => 2]);
    });

    it('handles empty attrs gracefully', function () {
        $base = collect(['k' => 'v']);
        $out = CollectionUtils::mergeAttrs($base, []);
        expect($out->all())->toBe(['k' => 'v']);
    });
});

describe('concatUniqueCompact()', function () {
    it('concatenates, removes nulls, and de-duplicates keeping first occurrence', function () {
        $base = collect(['a', 'b', null, 'a']);
        $more = [null, 'b', 'c', 'a'];

        $out = CollectionUtils::concatUniqueCompact($base, $more);

        expect($out->all())->toBe(['a', 'b', 'c'])
            ->and($base->all())->toBe(['a', 'b', null, 'a']);
    });

    it('preserves falsy non-null values and removes only nulls', function () {
        $base = collect([0, false, '', null]);
        $more = [null, 0, false, ''];

        $out = CollectionUtils::concatUniqueCompact($base, $more);

        expect($out->all())->toBe([0, false, '']);
    });
});
