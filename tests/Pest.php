<?php

use Illuminate\Support\Collection;
use Pest\Expectation;
use PHPUnit\Framework\Assert;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/**
 * expect($actual)->toEqualDiff($expected)
 *
 * - If $actual/$expected are arrays or Collections, it prints:
 *   Expected: [...]
 *   Actual:   [...]
 *   Diff:
 *     - removed
 *     + added
 * - Otherwise falls back to PHPUnit assertEquals.
 */
expect()->extend('toEqualDiff', function ($expected): Expectation {
    $normalize = static function ($v) {
        if ($v instanceof Collection) {
            return $v->toArray();
        }
        if ($v instanceof Traversable) {
            return iterator_to_array($v);
        }

        return $v;
    };

    $actualNorm = $normalize($this->value);
    $expectedNorm = $normalize($expected);

    $isArrayLike = is_array($actualNorm) && is_array($expectedNorm);

    if (! $isArrayLike) {
        Assert::assertEquals($expected, $this->value);

        return $this;
    }

    if ($actualNorm === $expectedNorm) {
        Assert::assertSame(
            json_encode($expectedNorm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($actualNorm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $this;
    }

    $pretty = static fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $expectedJson = $pretty($expectedNorm);
    $actualJson = $pretty($actualNorm);

    $builder = new UnifiedDiffOutputBuilder("--- Expected\n+++ Actual\n");
    $differ = new Differ($builder);
    $diff = $differ->diff($expectedJson, $actualJson);

    // ANSI colors
    $green = "\033[32m";
    $red = "\033[31m";
    $reset = "\033[0m";

    $message = <<<MSG
{$green}Expected: {$expectedJson}{$reset}

{$red}Actual: {$actualJson}{$reset}

Diff:
{$diff}
MSG;

    Assert::fail($message);
});

/**
 * Intercept `toEqual` for array/collection values to print a
 * rich diff (and colorize Expected/Actual). Non-array cases
 * fall through to Pest's original `toEqual`.
 */
expect()->intercept(
    'toEqual',
    // Filter: when should we take over?
    /**
     * @param  mixed  $value
     * @param  mixed  $expected
     */
    function ($value, $expected): bool {
        $norm = static function ($v) {
            if ($v instanceof Collection) {
                return $v->toArray();
            }
            if ($v instanceof Traversable) {
                return iterator_to_array($v);
            }

            return $v;
        };

        $a = $norm($value);
        $e = $norm($expected);

        return is_array($a) && is_array($e);
    },

    // Handler: our custom equality + diff output
    function ($expected): void {
        $normalize = static function ($v) {
            if ($v instanceof Collection) {
                return $v->toArray();
            }
            if ($v instanceof Traversable) {
                return iterator_to_array($v);
            }

            return $v;
        };

        $actualNorm = $normalize($this->value);
        $expectedNorm = $normalize($expected);

        // Fast path: identical arrays → assert and return
        if ($actualNorm === $expectedNorm) {
            Assert::assertSame($expectedNorm, $actualNorm);

            return;
        }

        $pretty = static fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $expectedJson = $pretty($expectedNorm);
        $actualJson = $pretty($actualNorm);

        // Build a classic unified diff (uses sebastian/diff)
        $builder = new UnifiedDiffOutputBuilder("--- Expected\n+++ Actual\n");
        $differ = new Differ($builder);
        $diff = $differ->diff($expectedJson, $actualJson);

        // ANSI colors
        $green = "\033[32m";
        $red = "\033[31m";
        $reset = "\033[0m";

        // Put JSON directly after the labels on the same line
        $message = <<<MSG
{$green}Expected: {$expectedJson}{$reset}

{$red}Actual: {$actualJson}{$reset}

Diff:
{$diff}
MSG;

        Assert::fail($message);
    }
);

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
