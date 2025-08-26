<?php

declare(strict_types=1);

use Flowlight\Context;
use Flowlight\Traits\WithErrorHandler;

/**
 * Harness that exposes the trait's protected static method.
 */
$makeHarness = static function () {
    return new class
    {
        use WithErrorHandler;

        public static function run(Context $ctx, callable|\Throwable $arg, bool $rethrow = false): void
        {
            self::withErrorHandler($ctx, $arg, $rethrow);
        }
    };
};

describe('::withErrorHandler', function () use ($makeHarness) {
    it('records error info, adds base error, snapshots, and aborts', function () use ($makeHarness) {
        $H = $makeHarness();
        $ctx = Context::makeWithDefaults();
        $ex = new \RuntimeException('xyz');

        $H::run($ctx, $ex);

        $errors = $ctx->errorsArray();
        $info = $ctx->internalOnly()->get('errorInfo');

        // Avoid offset access on mixed
        $baseList = (isset($errors['base']) && is_array($errors['base'])) ? $errors['base'] : [];
        $firstMsg = $baseList[0] ?? '';

        expect($ctx->aborted())->toBeTrue()
            ->and($errors)->toHaveKey('base')
            ->and($baseList)->toBeArray()
            ->and($firstMsg)->toContain('xyz')
            ->and($info)->toBeArray()
            ->and($ctx->lastFailedContext())->not->toBeNull();
    });
});

describe('::withErrorHandler (callable)', function () use ($makeHarness) {
    it('captures a thrown exception into context (mirrors proxy behavior)', function () use ($makeHarness) {
        $H = $makeHarness();
        $ctx = Context::makeWithDefaults();

        $H::run($ctx, static function (Context $c): void {
            throw new \RuntimeException('boom');
        });

        $errors = $ctx->errorsArray();

        // Avoid offset access on mixed
        $baseList = (isset($errors['base']) && is_array($errors['base'])) ? $errors['base'] : [];
        $firstMsg = $baseList[0] ?? '';

        expect($ctx->aborted())->toBeTrue()
            ->and($errors)->toHaveKey('base')
            ->and($firstMsg)->toContain('boom')
            ->and($ctx->lastFailedContext())->not->toBeNull();
    });

    it('allows successful block execution without side effects', function () use ($makeHarness) {
        $H = $makeHarness();
        $ctx = Context::makeWithDefaults();

        $H::run($ctx, static function (Context $c): void {
            $c->withParams(['ok' => true]);
        });

        expect($ctx->aborted())->toBeFalse()
            ->and($ctx->errors()->isEmpty())->toBeTrue()
            ->and($ctx->paramsArray())->toBe(['ok' => true]);
    });
});

describe('::withErrorHandler (callable, rethrow)', function () use ($makeHarness) {
    it('rethrows after recording context state when rethrow=true (callable path)', function () use ($makeHarness) {
        $H = $makeHarness();
        $ctx = Context::makeWithDefaults();
        $caught = null;

        try {
            $H::run($ctx, static function (Context $c): void {
                throw new \RuntimeException('kaboom');
            }, true); // ✅ rethrow=true
        } catch (\Throwable $e) {
            $caught = $e;
        }

        // we must have rethrown
        expect($caught)->not->toBeNull()
            ->and($caught)->toBeInstanceOf(\RuntimeException::class);

        assert($caught instanceof \RuntimeException);
        /** @var \RuntimeException $caught */
        expect($caught->getMessage())->toBe('kaboom');

        // and we still recorded/aborted consistently
        $errors = $ctx->errorsArray();
        $baseList = (isset($errors['base']) && is_array($errors['base'])) ? $errors['base'] : [];
        $firstMsg = $baseList[0] ?? '';

        expect($ctx->aborted())->toBeTrue()
            ->and($errors)->toHaveKey('base')
            ->and($firstMsg)->toContain('kaboom')
            ->and($ctx->lastFailedContext())->not->toBeNull();
    });
});

describe('::withErrorHandler (proxy Throwable, rethrow)', function () use ($makeHarness) {
    it('rethrows the same instance after recording context state when rethrow=true (proxy path)', function () use ($makeHarness) {
        $H = $makeHarness();
        $ctx = Context::makeWithDefaults();
        $ex = new \RuntimeException('xyz');
        $caught = null;

        try {
            $H::run($ctx, $ex, true); // ✅ rethrow=true with raw Throwable
        } catch (\Throwable $e) {
            $caught = $e;
        }

        // ensure identity is preserved (same instance bubbled)
        expect($caught)->toBe($ex);

        // and we still recorded/aborted consistently
        $errors = $ctx->errorsArray();
        $baseList = (isset($errors['base']) && is_array($errors['base'])) ? $errors['base'] : [];
        $firstMsg = $baseList[0] ?? '';

        expect($ctx->aborted())->toBeTrue()
            ->and($errors)->toHaveKey('base')
            ->and($firstMsg)->toContain('xyz')
            ->and($ctx->lastFailedContext())->not->toBeNull();
    });
});
