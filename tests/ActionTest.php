<?php

declare(strict_types=1);

use Flowlight\Action;
use Flowlight\Context;

describe(Action::class, function () {
    describe('::execute', function () {
        it('accepts an array, runs perform, and returns a Context', function () {
            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void
                {
                    $cb = $ctx->input()->get('callback');
                    $value = is_callable($cb) ? $cb() : null;
                    $ctx->withParams(['value' => $value]);
                }
            };

            $value = 'ok';
            $ctx = $Fake::execute(['callback' => fn () => $value]);

            expect($ctx)->toBeInstanceOf(Context::class)
                ->and($ctx->paramsArray())->toBe(['value' => 'ok']);
        });

        it('accepts a Context instance and uses the same instance', function () {
            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void
                {
                    $ctx->withParams(['ran' => true]);
                }
            };

            $given = Context::makeWithDefaults(['seed' => 1]);
            $out = $Fake::execute($given);

            expect($out)->toBe($given)
                ->and($out->paramsArray())->toBe(['ran' => true]);
        });

        it('wires invokedAction onto the context', function () {
            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void {}
            };

            $ctx = $Fake::execute([]);

            expect($ctx->invokedAction)->toBeObject()
                ->and($ctx->invokedAction)->toBeInstanceOf(Action::class);
        });
    });

    describe('lifecycle', function () {
        it('calls before/after/afterFailure on failure (errors present)', function () {
            $calls = [];

            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void
                {
                    $ctx->withErrors(['field' => ['bad']]);
                }
            };

            $Fake::setBeforeExecute(function (Context $c) use (&$calls) {
                $calls[] = 'before';
            });
            $Fake::setAfterExecute(function (Context $c) use (&$calls) {
                $calls[] = 'after';
            });
            $Fake::setAfterSuccess(function (Context $c) use (&$calls) {
                $calls[] = 'success';
            });
            $Fake::setAfterFailure(function (Context $c) use (&$calls) {
                $calls[] = 'failure';
            });

            $ctx = $Fake::execute([]);

            expect($ctx->errorsArray())->toBe(['field' => ['bad']])
                ->and($calls)->toBe(['before', 'after', 'failure']);
        });

        it('calls before/after/afterSuccess on success (no errors)', function () {
            $calls = [];

            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void
                {
                    $ctx->withParams(['ok' => true]);
                }
            };

            $Fake::setBeforeExecute(function (Context $c) use (&$calls) {
                $calls[] = 'before';
            });
            $Fake::setAfterExecute(function (Context $c) use (&$calls) {
                $calls[] = 'after';
            });
            $Fake::setAfterSuccess(function (Context $c) use (&$calls) {
                $calls[] = 'success';
            });
            $Fake::setAfterFailure(function (Context $c) use (&$calls) {
                $calls[] = 'failure';
            });

            $ctx = $Fake::execute([]);

            expect($ctx->paramsArray())->toBe(['ok' => true])
                ->and($calls)->toBe(['before', 'after', 'success']);
        });

        it('calls before/after/afterFailure and rethrows on exception', function () {
            $calls = [];

            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void
                {
                    throw new RuntimeException('boom');
                }
            };

            $Fake::setBeforeExecute(function (Context $c) use (&$calls) {
                $calls[] = 'before';
            });
            $Fake::setAfterExecute(function (Context $c) use (&$calls) {
                $calls[] = 'after';
            });
            $Fake::setAfterSuccess(function (Context $c) use (&$calls) {
                $calls[] = 'success';
            });
            $Fake::setAfterFailure(function (Context $c) use (&$calls) {
                $calls[] = 'failure';
            });

            $thrown = null;
            try {
                $Fake::execute([]);
            } catch (Throwable $e) {
                $thrown = $e;
            }

            expect($thrown)->toBeInstanceOf(RuntimeException::class)
                ->and($calls)->toBe(['before', 'after', 'failure']);
        });
    });

    describe('::setBeforeExecute / ::setAfterExecute / ::setAfterSuccess / ::setAfterFailure', function () {
        it('stores and exposes the hooks via getters', function () {
            $Fake = new class extends Action
            {
                protected function perform(Context $ctx): void {}
            };

            $before = fn (Context $c) => null;
            $after = fn (Context $c) => null;
            $succ = fn (Context $c) => null;
            $fail = fn (Context $c) => null;

            $Fake::setBeforeExecute($before);
            $Fake::setAfterExecute($after);
            $Fake::setAfterSuccess($succ);
            $Fake::setAfterFailure($fail);

            expect($Fake::beforeExecuteBlock())->toBe($before)
                ->and($Fake::afterExecuteBlock())->toBe($after)
                ->and($Fake::afterSuccessBlock())->toBe($succ)
                ->and($Fake::afterFailureBlock())->toBe($fail);
        });
    });
});
