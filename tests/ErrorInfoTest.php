<?php

declare(strict_types=1);

namespace Tests;

use Flowlight\ErrorInfo;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

$makeException = function (): Throwable {
    // Create a nested call stack to ensure we have a non-trivial trace.
    $deep = static function (): void {
        $deeper = static function (): void {
            throw new RuntimeException('boom');
        };
        $deeper();
    };

    try {
        $deep();
        // this line is unreachable, but silences PHPStan
        throw new RuntimeException('expected to throw');
    } catch (Throwable $e) {
        return $e;
    }
};

describe('__construct / basic properties', function () use ($makeException) {
    it('initializes type, message, and title from the Throwable', function () use ($makeException) {
        $e = $makeException();
        $info = new ErrorInfo($e);

        expect($info->error)->toBe($e)
            ->and($info->type)->toBe($e::class)
            ->and($info->message)->toBe($e->getMessage())
            ->and($info->title)->toBe(sprintf('%s : %s', $e::class, $e->getMessage()));
    });

    it('allows overriding the message', function () use ($makeException) {
        $e = $makeException();
        $info = new ErrorInfo($e, 'custom message');

        expect($info->message)->toBe('custom message')
            ->and($info->title)->toBe(sprintf('%s : %s', $e::class, $e->getMessage()));
    });
});

describe('errors()', function () {
    it('returns Collection<string, array<int,string>> for ValidationException (no facades needed)', function () {
        // Minimal subclass that skips the heavy parent constructor and just
        // returns a valid errors() payload. This still satisfies instanceof ValidationException.
        $ex = new class extends ValidationException
        {
            public function __construct()
            { /* no-op */
            }

            /** @return array<string, array<int, string>> */
            public function errors(): array
            {
                return ['field' => ['bad', 'worse']];
            }
        };

        $info = new ErrorInfo($ex);
        $errors = $info->errors();

        expect($errors)->toBeInstanceOf(Collection::class)
            ->and($errors->keys()->all())->toBe(['field'])
            ->and($errors->get('field'))->toBe(['bad', 'worse']);
    });

    it('falls back to Collection with base => [message] for non-validation errors', function () {
        $ex = new RuntimeException('plain failure');

        $info = new ErrorInfo($ex);
        $errors = $info->errors();

        expect($errors)->toBeInstanceOf(Collection::class)
            ->and($errors->keys()->all())->toBe(['base'])
            // ensure array-of-strings shape, not a bare string
            ->and($errors->get('base'))->toBe(['plain failure']);
    });
});

describe('toCollection()', function () use ($makeException) {
    it('returns a structured summary and limits backtrace to 5 lines', function () use ($makeException) {
        $e = $makeException();
        $info = new ErrorInfo($e);

        $summary = $info->toCollection();

        expect($summary)->toBeInstanceOf(Collection::class)
            ->and($summary->get('type'))->toBe($e::class)
            ->and($summary->get('message'))->toBe($e->getMessage())
            ->and($summary->get('exception'))->toBe(sprintf('%s : %s', $e::class, $e->getMessage()))
            ->and($summary->get('backtrace'))->toBeString();

        /** @var string $backtrace */
        $backtrace = $summary->get('backtrace');
        $lines = preg_split('/\R/u', $backtrace) ?: [];

        expect(count($lines))->toBeLessThanOrEqual(5);
    });
});

describe('backtrace() / cleanBacktrace()', function () use ($makeException) {
    it('backtrace() returns a Collection of strings (when available)', function () use ($makeException) {
        $e = $makeException();
        $info = new ErrorInfo($e);

        $lines = $info->backtrace();

        expect($lines)->toBeInstanceOf(Collection::class);

        if ($lines->isNotEmpty()) {
            expect($lines->first())->toBeString();
        }
    });

    it('cleanBacktrace() returns a Collection of strings and uses BacktraceCleaner', function () use ($makeException) {
        $e = $makeException();
        $info = new ErrorInfo($e);

        $clean = $info->cleanBacktrace();

        expect($clean)->toBeInstanceOf(Collection::class);

        if ($clean->isNotEmpty()) {
            expect($clean->first())->toBeString();
        }
    });
});

describe('errorSummary()', function () use ($makeException) {
    it('includes the title and a cleaned backtrace', function () use ($makeException) {
        $e = $makeException();
        $info = new ErrorInfo($e);

        $summary = $info->errorSummary();

        expect($summary)->toContain('SERVER ERROR FOUND')
            ->and($summary)->toContain($info->title)
            ->and($summary)->toContain('FULL STACK TRACE');
    });
});
